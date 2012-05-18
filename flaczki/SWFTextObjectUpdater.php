<?php

abstract class SWFTextObjectUpdater {

	public $fontPolicy;

	protected $tlfParser;
	protected $tlfAssembler;
	protected $fontFamilies;

	public function __construct() {
		$this->tlfParser = new TLFParser;
		$this->tlfAssembler = new TLFAssembler;
		$this->fontPolicy = new SWFTextObjectUpdaterFontPolicy;
	}
	
	abstract public function getSections();
	abstract protected function updateTextObject($tlfObject, $section);

	public function update($textObjects, $fontFamilies) {
		$this->fontFamilies = $fontFamilies;
		$changes = array();
		$updated = array();
	
		$sections = $this->getSections();
		foreach($sections as $section) {
			// look for a text object with matching name and update it
			foreach($textObjects as $tIndex => $textObject) {
				if(strcasecmp($textObject->name, $section->name) == 0) {
					// in case of duplicate names, don't process the same object twice
					// look for a different object with the same name instead
					if(!isset($updated[$tIndex])) {
						// parse the TLF XML
						$tlfObject = $this->tlfParser->parse($textObject->xml);						
						
						// ask subclass to update the object
						$this->updateTextObject($tlfObject, $section);
						
						// then put it back together
						$this->tlfAssembler->assemble($textObject->xml, $tlfObject);
						$updated[$tIndex] = true;
						$changes[] = $textObject;
					}
				}
			}
		}
		$this->fontFamilies = null;
		return $changes;
	}
	
	protected function insertParagraphs($tlfObject, $paragraphs, $unmappedProperties) {
		// learn something about how often certain styles are used in the original text object
		$originalStyleUsage = $this->getStyleUsage($tlfObject, array_merge(array('fontFamily', 'fontLookup', 'fontSize'), $unmappedProperties));
		
		// add the paragraphs
		$tlfObject->textFlow->paragraphs = $paragraphs;
		
		// see how certain font properties are used in the new text
		$newStyleUsage = $this->getStyleUsage($tlfObject, array('fontFamily', 'fontSize'));
		
		// copy any unmapped properties that were used 100% of the time to the new object
		foreach($unmappedProperties as $unmappedProperty) {
			$usageTable = $originalStyleUsage[$unmappedProperty];			
			$value = key($usageTable);
			$frequency = current($usageTable);
			if($frequency == 1) {
				foreach($tlfObject->textFlow->paragraphs as $paragraph) {
					$paragraph->style->$unmappedProperty = $value;
				}
			}
		}
		
		// see if font sizes need to be adjusted
		$fontSizeScaleFactor = 1;
		if($this->maintainOriginalFontSize) {
			$originalMostFrequentSize = key($originalStyleUsage['fontSize']);
			$newMostFrequentSize = key($newStyleUsage['fontSize']);
			if($originalMostFrequentSize != $newMostFrequentSize) {
				$fontSizeScaleFactor = (double) $originalMostFrequentSize / $newMostFrequentSize;
			}
		}
		
		// see how fonts should be mapped
		$fontFamilyMap = array();
		$fontLookupMap = array();
		$embeddedFonts = $this->fontFamilies;
		$allowedDeviceFonts = array_flip($this->fontPolicy->allowedDeviceFonts);
		$originalFontUsage = $originalStyleUsage['fontFamily'];
		$newFontUsage = $newStyleUsage['fontFamily'];		
		foreach($newFontUsage as $fontFamilyName => $frequency) {
			if(isset($originalFontUsage[$fontFamilyName])) {
				// font was used in this text object before--no change needed
				$fontFamilyMap[$fontFamilyName] = $fontFamilyName;
			} else {
				if($this->fontPolicy->allowAnyEmbeddedFont && isset($embeddedFonts[$fontFamilyName])) {
					// font is embedded--no change needed
					$fontFamilyMap[$fontFamilyName] = $fontFamilyName;
				} else if(isset($allowedDeviceFonts[$fontFamilyName])) {
					// device font is allowed--no change needed
					$fontFamilyMap[$fontFamilyName] = $fontFamilyName;
				} else {
					// need to look for a suitable substitute
					$substituteName = null;
					$desiredFontPanose = $this->getFontPanose($fontFamilyName);
					if($desiredFontPanose) {
						// find a font with panose values nearest to the one desired
						$panoseDifferences = array();
						if(!isset($availableFonts)) {
							// any font that was referenced by the text object originally is available
							$availableFonts = array_keys($originalFontUsage);	
							if($this->fontPolicy->allowAnyEmbeddedFont) {
								$availableFonts = array_merge($availableFonts, array_key($embeddedFonts));
							}
							if($this->fontPolicy->allowedDeviceFonts) {
								$availableFonts = array_merge($availableFonts, array_key($allowedDeviceFonts));
							}
							$availableFonts = array_unique($availableFonts);
						}
						foreach($availableFonts as $availableFontName) {
							$panose = $this->getFontPanose($availableFontName);
							if($panose) {
								$panoseDifferences[$availableFontName] = $this->calculatePanoseDifference($desiredFontPanose, $panose);
							}
						}
						arsort($panoseDifferences);
						
						// the one at the beginning of the array is the best match
						$substituteFontName = key($panoseDifferences);
					}
					if(!$substituteFontName) {
						// nothing--just use the font most frequently used in the text object
						$substituteFontName = key($originalFontUsage);
					}
					$fontFamilyMap[$fontFamilyName] = $substituteFontName;
				}
			}
		}
		
		// determine the font look-up method
		foreach($fontFamilyMap as $fontFamilyName => $newFontFamilyName) {
			if(isset($embeddedFonts[$newFontFamilyName])) {
				$fontLookupMap[$newFontFamilyName] = 'embeddedCFF';
			} else {
				$fontLookupMap[$newFontFamilyName] = 'device';
			}
		}
		
		// update the font properties
		foreach($tlfObject->textFlow->paragraphs as $paragraph) {
			foreach($paragraph->spans as $span) {
				if($span->style->fontFamily) {
					$span->style->fontFamily = $fontFamilyMap[$span->style->fontFamily];
					$span->style->fontLookup = $fontLookupMap[$span->style->fontFamily];
					$span->style->renderingMode = ($span->style->fontLookup == 'embeddedCFF') ? 'cff' : null;
					if($fontSizeScaleFactor !== 1) {
						if($span->style->fontSize) {
							$span->style->fontSize = round($span->style->fontSize * $fontSizeScaleFactor);
						}
					}
				}
			}
		}
	}
	
	protected function getStyleUsage($tlfObject, $properties) {
		$table = array();		
		foreach($properties as $name) {
			$table[$name] = array();
		}
			
		// count the number of characters a particular property value is applicable to
		$textLength = 0;
		$tStyle = $tlfObject->textFlow->style;
		foreach($tlfObject->textFlow->paragraphs as $paragraph) {
			$pStyle = $paragraph->style;
			foreach($paragraph->spans as $span) {
				$sStyle = $span->style;
				$sLength = strlen($span->text);
				foreach($properties as $name) {
					if(($value = $sStyle->$name) !== null || ($value = $pStyle->$name) !== null || ($value = $tStyle->$name) !== null) {
						$row =& $table[$name];
						$count =& $row[$value]; 
						$count += $sLength;
					}
				}
				$textLength += $sLength;
			}
		}
		
		// divide the count by the total length
		foreach($table as $name => &$row) {
			// more frequently used item comes first
			arsort($row);
			foreach($row as &$value) {
				$value = (double) $value / $textLength;
			}
		}
		return $table;
	}

	protected function getFontPanose($fontFamilyName) {
		if(isset($this->fontFamilies[$fontFamilyName])) {
			// an embedded font
			$fontFamily = $this->fontFamilies[$fontFamilyName];
			if($fontFamily->normal) {
				return $fontFamily->normal->panose;
			}
		}
		return PanoseDatabase::find($fontFamilyName);
	}
	
	protected function filterName($name) {	
		$name = preg_replace('/\(.*?\)/', '', $name);	// remove any ext inside parentheses
		$name = str_replace('-', '_', $name);		// replace hyphens with underscores
		$name = preg_replace('/\W+/', '', $name);	// remove everything else
		return ($name) ? $name : null;
	}
	
	protected function calculatePanoseDifference($panose1, $panose2) {
		$difference = 0.0;
		for($i = 1; $i <= 10; $i++) {
			$difference = ($difference * 16) + abs($panose1[$i] - $panose2[$i]);
		}
		return $difference;
	}
}

class SWFTextObjectUpdaterFontPolicy {
	public $allowedDeviceFonts = array();
	public $maintainOriginalFontSize = true;
	public $allowAnyEmbeddedFont = true;
}

?>