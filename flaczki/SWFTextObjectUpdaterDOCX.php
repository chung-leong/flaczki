<?php

class SWFTextObjectUpdaterDOCX extends SWFTextObjectUpdater {

	protected $document;

	public function __construct($document) {
		parent::__construct();
		$this->document = $document;
	}
	
	public function getSections() {
		$sections = array();
		$section = null;
		
		// resolve the style cascade
		foreach($this->document->paragraphs as $paragraph) {
			$this->resolveParagraphProperties($paragraph->paragraphProperties);
			foreach($paragraph->spans as $span) {
				$this->resolveTextProperties($span->textProperties, $paragraph->paragraphProperties);
			}
		}
		
		foreach($this->document->paragraphs as $pIndex => $paragraph) {			
			$nextParagraph = ($pIndex + 1 < count($this->document->paragraphs)) ? $this->document->paragraphs[$pIndex + 1] : null;
			
			if(!$section) {
				// look for the name of the section 
				$headingText = '';
				foreach($paragraph->spans as $span) {
					$headingText .= $span->text;
				}
				$sectionName = $this->filterName($headingText);
				if($sectionName) {
					$section = new SWFTextObjectUpdaterDOCXSection;
					$section->name = $sectionName;
					$section->title = $headingText;
				}
			} else {
				$section->paragraphs[] = $paragraph;
				if(!$nextParagraph || $nextParagraph->paragraphProperties->pageBreakBefore) {
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
		foreach($section->paragraphs as $index => $docxParagraph) {
			$tlfParagraph = new TLFParagraph;
			$this->translateProperties($tlfParagraph->style, $docxParagraph->paragraphProperties, null);
			
			$tlfHyperlink = null;
			$docxHyperlink = null;
			foreach($docxParagraph->spans as $docxSpan) {
				$tlfSpan = new TLFSpan;
				$this->translateProperties($tlfSpan->style, null, $docxSpan->textProperties);
				$tlfSpan->text = $docxSpan->text;
				if($docxSpan->hyperlink !== $docxHyperlink) {
					$docxHyperlink = $docxSpan->hyperlink;
					if($docxHyperlink) {
						$tlfHyperlink = new TLFHyperlink;
						$tlfHyperlink->href = $docxHyperlink->href;
						$tlfHyperlink->target = $docxHyperlink->tgtFrame;
					} else {
						$tlfHyperlink = null;
					}
				}
				$tlfSpan->hyperlink = $tlfHyperlink;
				$tlfParagraph->spans[] = $tlfSpan;
			}
			if(count($tlfParagraph->spans) > 0) {
				$newParagraphs[] = $tlfParagraph;
			}
		}
		
		// the DOCX format doesn't contain information for these properties
		// insertParagraphs() will see if it's possible to transfer them from the original text object
		$unmappedProperties = array('backgroundAlpha', 'digitCase', 'digitWidth', 'justificationRule', 'justificationStyle', 'ligatureLevel', 'textAlpha', 'wordSpacing');
		$this->insertParagraphs($tlfObject, $newParagraphs, $unmappedProperties);
	}
	
	protected function translateProperties($tlfStyle, $docxPargraphProperties, $docxTextProperties) {
		// paragraph properties
		if($docxPargraphProperties) {
			if($docxPargraphProperties->bidi) {
				$tlfStyle->direction = 'rtl';
			}
				
			if($docxPargraphProperties->textAlignmentVal) {
				switch($docxPargraphProperties->textAlignmentVal) {
					case 'baseline': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'roman'; break;
					case 'bottom': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'descent'; break;
					case 'top': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline = 'ascent'; break;
					case 'center': $tlfStyle->dominantBaseline = $tlfStyle->alignmentBaseline ='ideographicCenter'; break;
				}
			}
			
			if($docxPargraphProperties->spacingLine) {
				if($docxPargraphProperties->spacingLineRule == 'exactly') {
					$tlfStyle->lineHeight = $this->convertFromTwip($docxPargraphProperties->spacingLine);
				} else {
					// line is the 240 parts of normal line height
					$percentage = round(floatval($docxPargraphProperties->spacingLine) / 240 * 100);
					$tlfStyle->lineHeight = $percentage . '%';
				}
			}
			
			if($docxPargraphProperties->indLeft) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->paragraphEndIndent = $this->convertFromTwip($docxPargraphProperties->indLeft, 0, 8000);
				} else {
					$tlfStyle->paragraphStartIndent = $this->convertFromTwip($docxPargraphProperties->indRight, 0, 8000);
				}
			}
			
			if($docxPargraphProperties->indRight) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->paragraphStartIndent = $this->convertFromTwip($docxPargraphProperties->indRight, 0, 8000);
				} else {
					$tlfStyle->paragraphEndIndent = $this->convertFromTwip($docxPargraphProperties->indLeft, 0, 8000);
				}
			}
			
			if($docxPargraphProperties->spacingAfter) {
				$tlfStyle->paragraphSpaceAfter = $this->convertFromTwip($docxPargraphProperties->spacingAfter, 0, 8000);
			}
			
			if($docxPargraphProperties->spacingBefore) {
				$tlfStyle->paragraphSpaceBefore = $this->convertFromTwip($docxPargraphProperties->spacingBefore, 0, 8000);
			}
			
			if($docxPargraphProperties->tabStops) {
				$tlfTabStops = array();
				foreach($docxPargraphProperties->tabStops as $docxTabStop) {
					$position = $this->convertFromTwip($docxTabStop->pos, 0, 10000);
					$tlfTabStop = '';
					if($tlfStyle->direction == 'rtl') {								
						switch($docxTabStop->val) {
							case 'left': $tlfTabStop = "e$position"; break;
							case 'right': $tlfTabStop = "s$position"; break;
							case 'center': $tlfTabStop = "c$position"; break;
							case 'char': $tlfTabStop = "d$position|."; break;
						}
					} else {
						switch($docxTabStop->val) {
							case 'left': $tlfTabStop = "s$position"; break;
							case 'right': $tlfTabStop = "e$position"; break;
							case 'center': $tlfTabStop = "c$position"; break;
							case 'char': $tlfTabStop = "d$position|."; break;
						}
					}
					if($tlfTabStop) {
						$tlfTabStops[] = $tlfTabStop;
					}
				}
				$tlfStyle->tabStops = implode(' ', $tlfTabStops);
			}
			
			if($docxPargraphProperties->jcVal) {
				switch($docxPargraphProperties->jcVal) {
					case 'left': $tlfStyle->textAlign = 'left'; break;
					case 'right': $tlfStyle->textAlign = 'right'; break;
					case 'center': $tlfStyle->textAlign = 'center'; break;
					case 'both': 
						$tlfStyle->textAlign = 'justify';
						$tlfStyle->textJustify = 'interWord';
						break;
					case 'distribute':
						$tlfStyle->textAlign = 'justify';
						$tlfStyle->textJustify = 'distribute';
						break;
				}
			}
					
			if($docxPargraphProperties->indFirstLine) {
				$tlfStyle->textIndent = $this->convertFromTwip($docxPargraphProperties->indFirstLine, -8000, 8000);
			}
		}
		
		// text properties
		if($docxTextProperties) {
			if($docxTextProperties->shdFill) {
				$tlfStyle->backgroundColor = "#{$docxTextProperties->shdFill}";
			}
										
			if($docxTextProperties->colorVal) {
				$tlfStyle->color = "#{$docxTextProperties->colorVal}";
			}
			
			if($docxTextProperties->highlightVal) {
				static $HIGHLIGHT_COLORS = array(
					'black' => 0x000000,		'darkCyan' => 0x008080,			'darkRed' => 0x800000,			'magenta' => 0xFF00FF,
					'blue' => 0x0000FF,		'darkGray' => 0x808080, 		'darkYellow' =>	0x808000,		'red' => 0xFF0000,
					'cyan' => 0x00FFFF, 		'darkGreen' => 0x008000,		'green' => 0x00FF00,			'white' => 0xFFFFFF, 
					'darkBlue' => 0x000080,		'darkMagenta' => 0x800080,		'lightGray' => 0xC0C0C0,		'yellow' => 0xFFFF00,
				);
				if(isset($HIGHLIGHT_COLORS[$docxTextProperties->highlightVal])) {
					$tlfStyle->backgroundColor = sprintf("#%06x", $HIGHLIGHT_COLORS[$docxTextProperties->highlightVal]);
				}
			}
			
			if($docxTextProperties->rFontsAscii) {
				$tlfStyle->fontFamily = $docxTextProperties->rFontsAscii;
			} else if($docxTextProperties->rFontsCs) {
				$tlfStyle->fontFamily = $docxTextProperties->rFontsCs;
			} else if($docxTextProperties->rFontsEastAsia) {
				$tlfStyle->fontFamily = $docxTextProperties->rFontsEastAsia;
			} else if($docxTextProperties->rFontsHAnsi) {
				$tlfStyle->fontFamily = $docxTextProperties->rFontsHAnsi;
			}
			
			if($docxTextProperties->szVal) {
				$tlfStyle->fontSize = floatval($docxTextProperties->szVal) / 2;
			}
			
			if($docxTextProperties->i || $docxTextProperties->iCs) {
				$tlfStyle->fontStyle = 'italic';
			}

			if($docxTextProperties->smallCaps) {
				$tlfStyle->typographicCase = 'lowercaseToSmallCaps';
			}
			
			if($docxTextProperties->caps) {
				$tlfStyle->typographicCase = 'upper';
			}
			
			if($docxTextProperties->b) {
				$tlfStyle->fontWeight = 'bold';
			}
			
			if($docxTextProperties->langVal) {
				$tlfStyle->locale = $docxTextProperties->langVal;
			} else if($docxTextProperties->langBidi) {
				$tlfStyle->locale = $docxTextProperties->langBidi;
			} else if($docxTextProperties->langEastAsia) {
				$tlfStyle->locale = $docxTextProperties->langEastAsia;
			}
			
			if($docxTextProperties->kernVal) {
				$minimumSize = (float) $docxTextProperties->kernVal;
				$currentSize = (float) $docxTextProperties->szVal;
				if($currentSize > $minimumSize) {
					$tlfStyle->kerning = 'true';
				}
			}
			
			if($docxTextProperties->spacingVal) {
				if($tlfStyle->direction == 'rtl') {
					$tlfStyle->trackingLeft = $this->convertFromTwip($docxTextProperties->spacingVal, -1000, 1000);
				} else {
					$tlfStyle->trackingRight = $this->convertFromTwip($docxTextProperties->spacingVal, -1000, 1000);
				}
			}
			
			if($docxTextProperties->strike || $docxTextProperties->dstrike) {
				$tlfStyle->lineThrough = 'true';
			}
			
			if($docxTextProperties->vertAlignVal) {
				switch($docxTextProperties->vertAlignVal) {
					case 'subscript': $tlfStyle->baselineShift = 'subscript'; break;
					case 'superscript': $tlfStyle->baselineShift = 'superscript'; break;
				}
			}
			
			if($docxTextProperties->u) {
				$tlfStyle->textDecoration = 'underline';
			}
		}
	}
	
	protected function convertFromTwip($s, $min, $max) {		
		$value = (float) $s / 20;
		if($value < $min) {
			$value = $min;
		}
		if($value > $max) {
			$value = $max;
		}
		return $value;
	}

	protected function resolveTextProperties(&$textProperties, $paragraphProperties) {
		if(!$textProperties) {
			$textProperties = new DOCXTextProperties;
		}
		
		// copy properties of a referenced style 
		if($textProperties->rStyleVal) {
			$style = $this->document->styles[$textProperties->rStyleVal];
			$this->copyMissingProperties($textProperties, $style->textProperties);
		}
		
		// copy default text properties for paragraph
		if($paragraphProperties && $paragraphProperties->textProperties) {
			$this->copyMissingProperties($textProperties, $paragraphProperties->textProperties);
		}
		
		// copy default text properties of document
		if($this->document->defaultTextProperties) {
			$this->copyMissingProperties($textProperties, $this->document->defaultTextProperties);
		}
	}
	
	protected function resolveParagraphProperties(&$paragraphProperties) {
		if(!$paragraphProperties) {
			$paragraphProperties = new DOCXParagraphProperties;
		}
		
		// copy properties of a referenced style 
		if($paragraphProperties->pStyleVal) {
			$style = $this->document->styles[$paragraphProperties->pStyleVal];
			$this->copyMissingProperties($paragraphProperties, $style->paragraphProperties);
			
			// copy text properties as well
			if($style->textProperties) {
				if(!$paragraphProperties->textProperties) {
					$paragraphProperties->textProperties = new DOCXTextProperties;
				}
				$this->copyMissingProperties($paragraphProperties->textProperties, $style->textProperties);
			}
			
			// style is linked to another--apply those as well
			if($style->linkVal) {
				$stlye = $this->document->styles[$style->linkVal];
				if($style->paragraphProperties) {
					$this->copyMissingProperties($paragraphProperties, $style->paragraphProperties);
				}
				if($style->textProperties) {
					if(!$paragraphProperties->textProperties) {
						$paragraphProperties->textProperties = new DOCXTextProperties;
					}
					$this->copyMissingProperties($paragraphProperties->textProperties, $style->textProperties);
				}				
			}
		}
		
		// resolve default text properties
		if($paragraphProperties->textProperties) {
			$this->resolveTextProperties($paragraphProperties->textProperties, null);
		}
		
		// copy default paragraph properties of document
		if($this->document->defaultParagraphProperties) {
			$this->copyMissingProperties($paragraphProperties, $this->document->defaultParagraphProperties);
		}
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
					$chunks = str_split($font->panose1, 2);
					if(count($chunks) == 10) {
						$panose = array();
						for($i = 0; $i < 10; $i++) {
							$panose[$i + 1] = hexdec($chunks[$i]);
						}
						print_r($panose);
						return $panose;
					}
				} else {
					// see if the parent class can look it up
					if($panose = parent::getFontPanose($fontFamily)) {
						return $panose;
					}
					// create one based on what limited info we have
					return $this->generatePanoseFromFontProperties($docxFont);
				}
				break;
			}
		}
		return parent::getFontPanose($fontFamily);
	}	
}

class SWFTextObjectUpdaterDOCXSection {
	public $name;
	public $title;
	public $paragraphs = array();
}

?>