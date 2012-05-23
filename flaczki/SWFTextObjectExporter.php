<?php

abstract class SWFTextObjectExporter {

	protected $tlfParser;
	
	public function __construct() {
		$this->tlfParser = new TLFParser;
	}

	public function export($textObjects, $fontFamilies) {
		$sections = array();
		foreach($textObjects as $textObject) {
			$section = new SWFTextObjectExportSection;
			$section->name = $textObject->name;
			$section->tlfObject = $this->tlfParser->parse($textObject->xml);
			$sections[] = $section;				
		}
		return $this->exportSections($sections, $fontFamilies);
	}
	
	protected function getFontUsage($sections) {
		$hash = array();
		foreach($sections as $section) {
			$textFlow = $section->tlfObject->textFlow;
			$textFlowFontFamily = $textFlow->style->fontFamily;
			if(!$textFlowFontFamily) {
				$textFlowFontFamily = 'Arial';	// TLF default
			}
			foreach($textFlow->paragraphs as $paragraph) {
				$paragraphFontFamily = $paragraph->style->fontFamily;
				if(!$paragraphFontFamily) {
					$paragraphFontFamily = $textFlowFontFamily;
				}
				foreach($paragraph->spans as $span) {
					$spanFontFamily = $span->style->fontFamily;
					if(!$spanFontFamily) {
						$spanFontFamily = $paragraphFontFamily;
					}
					$value =& $hash[$spanFontFamily];
					$value += strlen($span->text);
				}
			}
		}
		// sort the array it the most frequently used font is listed first
		arsort($hash);
		return $hash;
	}
	
	protected function getStyleUsage($sections, $properties) {
		$table = array();		
		foreach($properties as $name) {
			$table[$name] = array();
		}
			
		// count the number of characters a particular property value is applicable to
		$textLength = 0;
		foreach($sections as $section) {
			$tlfObject = $section->tlfObject;
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
			
	protected function beautifySectionName($name) {
		if(strpos($name, '_') === false) {	// don't change the name if underscores are used
			$name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);	// split up a camel-case name
			$name = preg_replace('/([a-zA-Z])(\d+)$/', '$1 $2', $name);	// put a space in front of trailing number
			$name = ucfirst($name);						// capitalize first letter		
		}
		return $name;
	}
}

class SWFTextObjectExportSection {
	public $name;
	public $tlfObject;
}

?>