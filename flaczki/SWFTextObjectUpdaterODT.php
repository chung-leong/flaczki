<?php

class SWFTextObjectUpdaterODT extends SWFTextObjectUpdater {

	protected $document;

	public function __construct($document) {
		parent::__construct();
		$this->document = $document;
	}
	
	public function getSections() {
		$sections = array();
		$section = null;
		$nextParagraphStyle = null;
		foreach($this->document->paragraphs as $pIndex => $paragraph) {			
			$nextParagraph = ($pIndex + 1 < count($this->document->paragraphs)) ? $this->document->paragraphs[$pIndex + 1] : null;
			$paragraphStyle = ($nextParagraphStyle) ? $nextParagraphStyle : $this->getApplicableStyle($paragraph);
			$nextParagraphStyle = ($nextParagraph) ? $this->getApplicableStyle($nextParagraph) : null;
			
			if(!$section) {
				// look for the name of the section in a heading
				if($paragraph instanceof ODTHeading) {
					$headingText = '';
					foreach($paragraph->spans as $span) {
						$headingText .= $span->text;
					}
					$sectionName = $this->filterName($headingText);
					if($sectionName) {
						$section = new SWFTextObjectUpdaterODTSection;
						$section->name = $sectionName;
						$section->title = $headingText;
					}
				}
			} else {
				$section->paragraphs[] = $paragraph;
				$section->paragraphStyles[] = $paragraphStyle;
				if($paragraphStyle->paragraphProperties->breakAfter == 'page' || !$nextParagraphStyle || $nextParagraphStyle->paragraphProperties->breakBefore == 'page') {
					$sections[] = $section;
					$section = null;
				}
			}
		}
		return $sections;
	}
	
	protected function updateTextObject($tlfObject, $section) {
		// create paragraphs 
		$newParagraphs = array();
		foreach($section->paragraphs as $index => $odtParagraph) {
			$tlfParagraph = new TLFParagraph;
			$odtParagraphStyle = $section->paragraphStyles[$index];
			$this->translateProperties($tlfParagraph->style, $odtParagraphStyle);
			
			$tlfHyperlink = null;
			$odtHyperlink = null;
			foreach($odtParagraph->spans as $odtSpan) {
				$tlfSpan = new TLFSpan;
				$odtSpanStyle = $this->getApplicableStyle($odtSpan, $odtParagraphStyle);
				$this->translateProperties($tlfSpan->style, $odtSpanStyle);
				$tlfSpan->text = $odtSpan->text;
				if($odtSpan->hyperlink !== $odtHyperlink) {
					$odtHyperlink = $odtSpan->hyperlink;
					if($odtHyperlink) {
						$tlfHyperlink = new TLFHyperlink;
						$tlfHyperlink->href = $odtHyperlink->href;
						$tlfHyperlink->target = $odtHyperlink->target;
					} else {
						$tlfHyperlink = null;
					}
				}
				$tlfSpan->hyperlink = $tlfHyperlink;
				$tlfParagraph->spans[] = $tlfSpan;
			}
			$newParagraphs[] = $tlfParagraph;
		}
		
		// the ODT format doesn't contain information for these properties
		// insertParagraphs() will see if it's possible to transfer them from the original text object
		$unmappedProperties = array('backgroundAlpha', 'digitCase', 'digitWidth', 'justificationRule', 'justificationStyle', 'ligatureLevel', 'textAlpha', 'wordSpacing');
		$this->insertParagraphs($tlfObject, $newParagraphs, $unmappedProperties);
	}
	
	protected function translateProperties($tlfStyle, $odtStyle) {
		// paragraph properties
		if($paragraphProperties = $odtStyle->paragraphProperties) {
			if($paragraphProperties->writingMode) {
				switch($paragraphProperties->writingMode) {
					case 'lr':
					case 'lr-tb':
					case 'lr-bt': $tlfStyle->direction = 'ltr'; break;
					case 'rl':
					case 'rl-tb':
					case 'rl-bt': $tlfStyle->direction = 'rtr'; break;
				}
			}
			
			if($paragraphProperties->verticalAlign) {
				switch($paragraphProperties->verticalAlign) {
					case 'baseline': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'roman'; break;
					case 'bottom': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'descent'; break;
					case 'top': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'ascent'; break;
					case 'middle': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline ='ideographicCenter'; break;
				}
			}
			
			if($paragraphProperties->lineHeight) {
				if(($percentage = $this->parsePercentage($paragraphProperties->lineHeight, -1000, 1000)) !== null) {
					$tlfStyle->lineHeight = "$percentage%";
				} else if(($length = $this->parseLength($paragraphProperties->lineHeight, -720, 720)) !== null) {
					$tlfStyle->lineHeight = $length;
				}
			}
			
			if($paragraphProperties->marginLeft) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->paragraphEndIndent = $this->parseLength($paragraphProperties->marginLeft, 0, 8000);
				} else {
					$tlfStyle->paragraphStartIndent = $this->parseLength($paragraphProperties->marginLeft, 0, 8000);
				}
			}
			
			if($paragraphProperties->marginRight) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->paragraphStartIndent = $this->parseLength($paragraphProperties->marginRight, 0, 8000);
				} else {
					$tlfStyle->paragraphEndIndent = $this->parseLength($paragraphProperties->marginRight, 0, 8000);
				}
			}
			
			if($paragraphProperties->marginBottom) {
				$tlfStyle->paragraphSpaceAfter = $this->parseLength($paragraphProperties->marginBottom, 0, 8000);
			}
			
			if($paragraphProperties->marginTop) {
				$tlfStyle->paragraphSpaceBefore = $this->parseLength($paragraphProperties->marginTop, 0, 8000);
			}
			
			if($paragraphProperties->tabStops) {
				$tlfTabStops = array();
				foreach($paragraphProperties->tabStops as $odtTabStop) {
					$position = $this->parseLength($odtTabStop->position, 0, 10000);						
					$tlfTabStop = '';
					if($tlfStyle->direction == 'rtl') {								
						switch($odtTabStop->type) {
							case 'left': $tlfTabStop = "e$position"; break;
							case 'right': $tlfTabStop = "s$position"; break;
							case 'center': $tlfTabStop = "c$position"; break;
							case 'char': $tlfTabStop = "d$position|$odtTabStop->char"; break;
						}
					} else {
						switch($odtTabStop->type) {
							case 'left': $tlfTabStop = "e$position"; break;
							case 'right': $tlfTabStop = "s$position"; break;
							case 'center': $tlfTabStop = "c$position"; break;
							case 'char': $tlfTabStop = "d$position|$odtTabStop->char"; break;
						}
					}
					if($tlfTabStop) {
						$tlfTabStops[] = $tlfTabStop;
					}
				}
				$tlfStyle->tabStops = implode(' ', $tlfTabStops);
			}
			
			if($paragraphProperties->textAlign) {
				switch($paragraphProperties->textAlign) {
					case 'start':
					case 'end':
					case 'left':
					case 'right':
					case 'center':
					case 'justify': $tlfStyle->textAlign = $paragraphProperties->textAlign; break;
				}
			}
			
			if($paragraphProperties->textAlignLast) {
				switch($paragraphProperties->textAlignLast) {
					case 'start':
					case 'end':
					case 'left':
					case 'right':
					case 'center':
					case 'justify': $tlfStyle->textAlignLast = $paragraphProperties->textAlignLast; break;
				}
			}
			
			if($paragraphProperties->textIndent) {
				$tlfStyle->textIndent = $this->parseLength($paragraphProperties->textIndent, -8000, 8000);
			}
		}
		
		// text properties
		if($textProperties = $odtStyle->textProperties) {
			if($textProperties->backgroundColor) {
				$tlfStyle->backgroundColor = $textProperties->backgroundColor;
			}
										
			if($textProperties->color) {
				$tlfStyle->color = $textProperties->color;
			}
			
			if($textProperties->fontFamily) {
				$tlfStyle->fontFamily = $font->fontFamily;
			} else if($textProperties->fontName) {
				if(isset($this->document->fonts[$textProperties->fontName])) {
					$font = $this->document->fonts[$textProperties->fontName];
					$tlfStyle->fontFamily = $font->fontFamily;
				}
			}
			
			if($textProperties->fontSize) {
				$tlfStyle->fontSize = floatval($textProperties->fontSize);
			}
			
			if($textProperties->fontStyle) {
				switch($textProperties->fontStyle) {
					case 'normal': $tlfStyle->fontStyle = 'normal'; break;
					case 'italic': $tlfStyle->fontStyle = 'italic'; break;
				}
			}

			if($textProperties->fontVariant) {
				if(!$textProperties->textTransform || $textProperties->textTransform == 'none') {
					switch($textProperties->fontVariant) {
						case 'normal': $tlfStyle->typographicCase = 'default'; break;
						case 'small-caps': $tlfStyle->typographicCase = 'lowercaseToSmallCaps'; break;
					}
				}
			}
			
			if($textProperties->fontWeight) {
				switch($textProperties->fontWeight) {
					case 'normal': $tlfStyle->fontWeight = 'normal'; break;
					case 'bold': $tlfStyle->fontWeight = 'bold'; break;
					default:
						if(intval($textProperties->fontWeight) > 400) {
							$tlfStyle->fontWeight = 'bold';
						} else {
							$tlfStyle->fontWeight = 'normal';
						}
				}
			}
			
			if($textProperties->language) {
				// TODO
				if($textProperties->country) {
					// TODO
				}
			}
			
			if($textProperties->letterKerning) {
				switch($textProperties->letterKerning) {
					case 'true': $tlfStyle->kerning = 'true'; break;
					case 'false': $tlfStyle->kerning = 'false'; break;
				}
			}
			
			if($textProperties->letterSpacing) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->trackingLeft = $this->parseLength($value, -1000, 1000);
				} else {
					$tlfStyle->trackingRight = $this->parseLength($value, -1000, 1000);
				}
			}
			
			if($textProperties->textLineThroughStyle) {
				switch($textProperties->textLineThroughStyle) {
					case 'none': $tlfStyle->lineThrough = 'false'; break;
					case 'solid':
					case 'dotted':
					case 'dash':
					case 'long-dash':
					case 'dot-dash':
					case 'dot-dot-dash':
					case 'wave': $tlfStyle->lineThrough = 'true'; break;
				}
			}
			
			if($textProperties->textLineThroughType) {
				switch($textProperties->textLineThroughStyle) {
					case 'none': $tlfStyle->lineThrough = 'false'; break;
					case 'single':
					case 'double': $tlfStyle->lineThrough = 'true'; break;
				}
			}
						
			if($textProperties->textPosition) {
				if(preg_match('/sub/', $textProperties->textPosition)) {
					$tlfStyle->baselineShift = 'subscript';
				} else if(preg_match('/super/', $textProperties->textPosition)) {
					$tlfStyle->baselineShift = 'superscript';
				}
			}
			
			if($textProperties->textRotationAngle) {
				// make sure it's a multiple of 90
				$tlfStyle->textRotation = intval($textProperties->textRotationAngle) / 90 * 90;
			}
			
			if($textProperties->textTransform) {
				switch($textProperties->textTransform) {
					case 'none': $tlfStyle->typographicCase = 'default'; break;
					case 'lowercase': $tlfStyle->typographicCase = 'lower'; break;
					case 'uppercase': $tlfStyle->typographicCase = 'upper'; break;
				}
			}
			
			if($textProperties->textUnderlineStyle) {
				switch($textProperties->textUnderlineStyle) {
					case 'none': $tlfStyle->textDecoration = 'none'; break;
					case 'solid':
					case 'dotted':
					case 'dash':
					case 'long-dash':
					case 'dot-dash':
					case 'dot-dot-dash':
					case 'wave': $tlfStyle->textDecoration = 'underline'; break;
				}
			}
			
			if($textProperties->textUnderlineType) {
				switch($textProperties->textUnderlineType) {
					case 'none': $tlfStyle->textDecoration = 'none'; break;
					case 'single':
					case 'double': $tlfStyle->textDecoration = 'underline'; break;
				}
			}			
		}
	}
	
	protected function parseLength($s, $min, $max) {		
		if(preg_match('/cm$/', $s)) {
			$value = round(doubleval($s) * 28.3464567);
		} else if(preg_match('/in$/', $s)) {
			$value = round(doubleval($s) * 72);
		} else if(preg_match('/pt$/', $s)) {
			$value = doubleval($s);
		} else {
			return null;
		}
		if($value < $min) {
			$value = $min;
		}
		if($value > $max) {
			$value = $max;
		}
		return $value;
	}

	protected function parsePercentage($s, $min, $max) {
		if(preg_match('/%$/', $s)) {
			$value = doubleval($s);
		} else {
			return null;
		}
		if($value < $min) {
			$value = $min;
		}
		if($value > $max) {
			$value = $max;
		}
		return $value;
	}
	
	protected function getApplicableStyle($object, $containerStyle = null) {
		$style = new ODTStyle;
		
		// get the applicable style name(s)
		$names = array();
		if($object->styleName) {
			$names[] = $object->styleName;
		}
		if($object->classNames) {
			$names = array_merge($names, explode(' ', $object->classNames));
		}
		
		foreach($names as $name) {
			// should be in one or the other
			$automaticStyle = isset($this->document->automaticStyles[$name]) ? $this->document->automaticStyles[$name] 
											 : $this->document->commonStyles[$name];
			$this->copyMissingProperties($style, $automaticStyle);
			
			if($automaticStyle->parentStyleName) {
				// copy the parent properties as well
				$parentStyle = $this->document->commonStyles[$automaticStyle->parentStyleName];
				$this->copyMissingProperties($style, $parentStyle);
			}
		}
		
		if($containerStyle && $containerStyle->textProperties) {
			// copy the container's text properties
			if($style->textProperties) {
				$this->copyMissingProperties($style->textProperties, $containerStyle->textProperties);
			} else {
				$style->textProperties = clone $containerStyle->textProperties;
			}
		}
		
		if($style->family) {
			// copy the default properties as well
			if(isset($this->document->defaultStyles[$style->family])) {
				$defaultStyle = $this->document->defaultStyles[$style->family];
				$this->copyMissingProperties($style, $defaultStyle);
			}
		}
				
		return $style;
	}
	
	protected function copyMissingProperties($object1, $object2) {
		foreach($object1 as $name => &$value1) {
			if($value1 === null) {
				$value2 = $object2->$name;
				$value1 = is_object($value2) ? clone $value2 : $value2;
			} else if(is_object($value1) && $object2->$name) {
				$this->copyMissingProperties($value1, $object2->$name);
			}
		}
	}
	
	protected function getFontPanose($fontFamilyName) {
		// use the information in the document if available
		foreach($this->document->fonts as $font) {
			if($font->fontFamily == $fontFamilyName) {
				if($font->panose1) {
					
				} else {
					// see if the parent class can look it up
					if($panose = parent::getFontPanose($fontFamily)) {
						return $panose;
					}
					// create one based on what limited info we have
					return $this->generatePanoseFromFontProperties($odtFont);
				}
				break;
			}
		}
		return parent::getFontPanose($fontFamily);
	}
	
	protected function generatePanoseFromFontProperties($odtFont) {
		$panose = array_fill(1, 10, 0);
		switch($odtFont->fontFamilyGeneric) {
			case 'script': $panose[1] = 3; break;
			case 'decorative': $panose[1] = 4; break;
			case 'system': $panose[1] = 5; break;
			case 'swiss': $panose[1] = 2; $panose[2] = 11; break;
			case 'roman': $panose[1] = 2; $panose[2] = 2; break;
		}
		if($panose[1] == 2 && $odtFont->fontStyle == 'oblique') {
			$panose[8] = 9;
		}
		if($odtFont->fontWeight == 'bold') {
			$panose[3] = 8;
		} else if(is_numeric($odtFont->fontWeight)) {
			$value = floatval($odtFont->fontWeight);			
			// convert range 100-900 to 2-11
			$panose[3] = (int) round(2 + (($value - 100) / 900) * 10);
		}
		switch($odtFont->fontPitch) {
			case 'fixed':
				if($panose[1] == 2) {
					$panose[4] = 9;
				} else if($panose[1] == 3 || $panose[1] == 5) {
					$panose[4] = 3;
				}
				break;
			case 'variable':
				if($panose[1] == 2) {
					$panose[4] = 3;
				} else if($panose[1] == 3 || $panose[1] == 5) {
					$panose[4] = 2;
				}
				break;
		}
	}
		
}

class SWFTextObjectUpdaterODTSection {
	public $name;
	public $title;
	public $paragraphs = array();
	public $paragraphStyles = array();
}

?>