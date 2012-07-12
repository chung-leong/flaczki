<?php

class SWFTextObjectExporterDOCX extends SWFTextObjectExporter {

	protected function addSection($textObject) {
		// add section name 
		$heading = new DOCXParagraph;
		$heading->paragraphProperties = new DOCXParagraphProperties;
		$heading->paragraphProperties->pStyleVal = 'Heading1';
		$heading->paragraphProperties->pageBreakBefore = count($this->document->paragraphs) > 0;
		
		$span = new DOCXSpan;
		$span->textProperties = new DOCXTextProperties;
		$span->textProperties->rStyleVal = 'Heading1Char';
		$span->text = $this->beautifySectionName($textObject->name);
		$heading->spans[] = $span;
		$this->document->paragraphs[] = $heading;
		
		// add the paragraphs
		$textFlow = $textObject->tlfObject->textFlow;
		foreach($textFlow->paragraphs as $tlfParagraph) {
			$docxParagraph = new DOCXParagraph;
			$docxParagraph->paragraphProperties = new DOCXParagraphProperties;
			$docxParagraphTextProperties = new DOCXTextProperties;
			$this->translateProperties($docxParagraph->paragraphProperties, $docxParagraphTextProperties, $textFlow->style);
			$this->translateProperties($docxParagraph->paragraphProperties, $docxParagraphTextProperties, $tlfParagraph->style);

			$tlfHyperlink = null;
			$docxHyperlink = null;
			foreach($tlfParagraph->spans as $tlfSpan) {
				$docxSpan = new DOCXSpan;
				$docxSpan->textProperties = clone $docxParagraphTextProperties;
				$docxSpanParagraphProperties = new DOCXParagraphProperties;
				$this->translateProperties($docxSpanParagraphProperties, $docxSpan->textProperties, $tlfSpan->style);
				
				if($docxSpanParagraphProperties != new DOCXParagraphProperties) {
					// spans cannot have paragraph style properties
					// take it off and apply the properties to the paragraph instead
					foreach($docxSpanParagraphProperties as $name => $value) {
						if($value !== null && $docxParagraph->paragraphProperties->$name !== null) {
							$docxParagraph->paragraphProperties->$name = $value;
						}
					}
				}
				$docxSpan->text = $tlfSpan->text;
				if($tlfSpan->hyperlink !== $tlfHyperlink) {
					$tlfHyperlink = $tlfSpan->hyperlink;
					if($tlfHyperlink) {
						$docxHyperlink = new DOCXHyperlink;
						$docxHyperlink->href = $tlfHyperlink->href;
					} else {
						$docxHyperlink = null;
					}
				}
				$docxSpan->hyperlink = $docxHyperlink;
				$docxParagraph->spans[] = $docxSpan;
			}
			$this->document->paragraphs[] = $docxParagraph;
		}
	}
	
	protected function translateProperties($docxParagraphProperties, $docxTextProperties, $tlfStyle) {
		// backgroundAlpha, blockProgression, breakOpportunity, clearFloats, digitCase, digitWidth, firstBaselineOffset, justificationRule, justificationStyle
		// leadingModel, ligatureLevel, lineBreak, textAlpha, wordSpacing, textRotation
		
		// paragraph properties
		if($tlfStyle->direction) {
			switch($tlfStyle->direction) {
				case 'rtl': $docxParagraphProperties->bidi = true; break;
			}
		}
		
		if($tlfStyle->dominantBaseline) {
			switch($tlfStyle->dominantBaseline) {
				case 'roman': $docxParagraphProperties->textAlignmentVal = 'baseline'; break;
				case 'descent': 
				case 'ideographicBottom': $docxParagraphProperties->textAlignmentVal = 'bottom'; break;
				case 'ascent': 
				case 'ideographicTop': $docxParagraphProperties->textAlignmentVal = 'top'; break;
				case 'ideographicCenter': $docxParagraphProperties->textAlignmentVal = 'center'; break;
			}
		}
		
		if($tlfStyle->lineHeight) {
			if(preg_match('/\d+%$/', $tlfStyle->lineHeight)) {
				// when lineRule is "auto", line means 240th of the height of a line
				$percentage = (float) $tlfStyle->lineHeight;
				$docxParagraphProperties->spacingLine = round($percentage * 240 / 100);
				$docxParagraphProperties->spacingLineRule = "auto";
			} else if(is_numeric($tlfStyle->lineHeight)) {
				$docxParagraphProperties->spacingLine = $this->convertToTwip($tlfStyle->lineHeight);
				$docxParagraphProperties->spacingLineRule = "exactly";
			}
		}
		
		if($tlfStyle->paragraphEndIndent) {
			if($tlfStyle->direction == 'rtl') {
				$docxParagraphProperties->indLeft = $this->convertToTwip($tlfStyle->paragraphEndIndent);
			} else {
				$docxParagraphProperties->indRight = $this->convertToTwip($tlfStyle->paragraphEndIndent);
			}
		}
		
		if($tlfStyle->paragraphSpaceAfter) {
			$docxParagraphProperties->spacingAfter = $this->convertToTwip($tlfStyle->paragraphSpaceAfter);
		}
		
		if($tlfStyle->paragraphSpaceBefore) {
			$docxParagraphProperties->spacingBefore = $this->convertToTwip($tlfStyle->paragraphSpaceBefore);
		}
		
		if($tlfStyle->paragraphStartIndent) {
			if($tlfStyle->direction == 'rtl') {
				$docxParagraphProperties->indRight = $this->convertToTwip($tlfStyle->paragraphStartIndent);
			} else {
				$docxParagraphProperties->indLeft = $this->convertToTwip($tlfStyle->paragraphStartIndent);
			}
		}
		
		if($tlfStyle->tabStops) {
			$docxParagraphProperties->tabStops = array();
			$tlfTabStops = explode(' ', $tlfStyle->tabStops);
			foreach($tlfTabStops as $tlfTabStop) {
				// see http://help.adobe.com/en_US/FlashPlatform/reference/actionscript/3/flashx/textLayout/elements/FlowElement.html#tabStops
				if(preg_match('/([sced])([0-9\.]+)(?:\|(.))?/', $tlfTabStop, $m)) {
					$docxTabStop = new DOCXTabStop;
					$alignment = $m[1];
					$position = doubleval($m[2]);
					$decimalAlignmentToken = $m[3];	
												
					if($tlfStyle->direction == 'rtl') {								
						switch($alignment) {
							case 's': $docxTabStop->type = 'right'; break;
							case 'e': $docxTabStop->type = 'left'; break;
							case 'c': $docxTabStop->type = 'center'; break;
							case 'd': $docxTabStop->type = 'num'; break;
						} 
					} else {
						switch($alignment) {
							case 's': $docxTabStop->type = 'left'; break;
							case 'e': $docxTabStop->type = 'right'; break;
							case 'c': $docxTabStop->type = 'center'; break;
							case 'd': $docxTabStop->type = 'num';  break;
						} 
					}
					$docxTabStop->position = $this->convertToTwip($position);
					$docxParagraphProperties->tabStops[] = $docxTabStop;
				}
			}
		}
		
		if($tlfStyle->textAlign) {
			switch($tlfStyle->textAlign) {
				case 'start': $docxParagraphProperties->jcVal = ($tlfStyle->direction == 'rtl') ? 'right' : 'left'; break;
				case 'end': $docxParagraphProperties->jcVal = ($tlfStyle->direction == 'rtl') ? 'left' : 'right'; break;
				case 'left': $docxParagraphProperties->jcVal = 'left'; break;
				case 'right': $docxParagraphProperties->jcVal = 'right';	break;
				case 'center': $docxParagraphProperties->jcVal = 'center'; break;
				case 'justify': $docxParagraphProperties->jcVal = 'both'; break;
			}
		}
		
		if($tlfStyle->textIndent) {
			$docxParagraphProperties->indFirstLine = $this->convertToTwip($tlfStyle->textIndent);
		}
		
		// text properties
		if($tlfStyle->baselineShift) {
			switch($tlfStyle->baselineShift) {
				case 'superscript': $docxTextProperties->vertAlignVal = 'superscript'; break;
				case 'subscript': $docxTextProperties->vertAlignVal = 'subscript'; break;
				default:
					if(preg_match('/\d+%$/', $tlfStyle->baselineShift)) {
						$percentage = (float) $tlfStyle->baselineShift;
						$fontSize = ($tlfStyle->fontSize) ? $tlfStyle->fontSize : 12;
						$docxTextProperties->vertAlignVal = round($fontSize * 2 * $percentage / 100);
					} else if(is_numeric($tlfStyle->baselineShift)) {
						$points = (float) $tlfStyle->baselineShift;
						$docxTextProperties->vertAlignVal = round($points * 2);
					}
			}
		}

		if($tlfStyle->backgroundColor) {
			$docxTextProperties->shdFill = substr($tlfStyle->backgroundColor, 1);
		}
									
		if($tlfStyle->color) {
			$docxTextProperties->colorVal = substr($tlfStyle->color, 1);
		}
		
		if($tlfStyle->direction) {
			switch($tlfStyle->direction) {
				case 'rtl': $docxTextProperties->rtl = true; break;
			}
		}
		
		if($tlfStyle->fontFamily) {
			$docxTextProperties->rFontsAscii = $tlfStyle->fontFamily;
			$docxTextProperties->rFontsCs = $tlfStyle->fontFamily;
			$docxTextProperties->rFontsHAnsi = $tlfStyle->fontFamily;
			$docxTextProperties->rFontsEastAsia = $tlfStyle->fontFamily;
		}
		
		if($tlfStyle->fontSize) {
			$docxTextProperties->szVal = $docxTextProperties->szCsVal = round($tlfStyle->fontSize * 2);
		}
		
		if($tlfStyle->fontStyle) {
			switch($tlfStyle->fontStyle) {
				case 'italic': 
					$docxTextProperties->i = true; 
					$docxTextProperties->iCs = true;
					break;
			}
		}
		
		if($tlfStyle->fontWeight) {
			switch($tlfStyle->fontWeight) {
				case 'bold': 
					$docxTextProperties->b = true; 
					$docxTextProperties->bCs = true; 
					break;
			}
		}
		
		if($tlfStyle->kerning) {
			switch($tlfStyle->kerning) {
				case 'on': $docxTextProperties->kernVal = 8; break;	// the smallest size where kerning applies (set to 4 pt)
			}
		}
		
		if($tlfStyle->lineThrough) {
			switch($tlfStyle->lineThrough) {
				case 'true': $docxTextProperties->strike = true; break;
			}
		}
		
		if($tlfStyle->locale) {
			$docxTextProperties->langVal = $docxTextProperties->langEastAsia = $docxTextProperties->langBidi = $tlfStyle->locale;
		}
		
		if($tlfStyle->textDecoration) {
			switch($tlfStyle->textDecoration) {
				case 'underline': $docxTextProperties->u = true; break;
			}
		}
		
		if($tlfStyle->trackingLeft && $tlfStyle->direction == 'rtl') {
			$docxTextProperties->spacingVal = $this->convertToTwip($tlfStyle->trackingLeft);
		}
		
		if($tlfStyle->trackingRight && $tlfStyle->direction != 'rtl') {
			$docxTextProperties->spacingVal = $this->convertToTwip($tlfStyle->trackingRight);
		}
		
		if($tlfStyle->typographicCase) {
			switch($tlfStyle->typographicCase) {
				case 'upper': $docxTextProperties->caps = true; break;
				case 'lowercaseToSmallCaps': $docxTextProperties->smallCaps = true; break;
			}
		}
	}
	
	protected function convertToTwip($point) {
		return round((float) $point * 20);
	}
		
	protected function addDefaultStyles() {
		// add font references
		$styleUsage = $this->getStyleUsage(array('fontFamily', 'fontSize', 'locale', 'direction', 'lineHeight', 'textIndent'));
	
		$tlfStyle = new TLFFlowElementStyle;
		foreach($styleUsage as $propName => $values) {
			$mostFrequentValue = key($values);
			$frequency = current($values);
			// set it as default value if it's used over ninty percent of the times
			// or if it's the font family
			if($frequency > 0.90 || $propName == 'fontFamily') {
				$tlfStyle->$propName = $mostFrequentValue;
			}
		}
		$this->document->defaultTextProperties = new DOCXTextProperties;
		$this->document->defaultParagraphProperties = new DOCXParagraphProperties;
		$this->translateProperties($this->document->defaultTextProperties, $this->document->defaultParagraphProperties, $tlfStyle);
	
		if(!isset($this->document->styles[$normalStyleId = 'Normal'])) {
			$normalStyle = new DOCXStyle;
			$normalStyle->styleId = $normalStyleId;
			$normalStyle->nameVal = "Normal";
			$normalStyle->type = "paragraph";
			$normalStyle->default = 1;
			$normalStyle->qFormat = true;
			$this->document->styles[$normalStyleId] = $normalStyle;
		}

		if(!isset($this->document->styles[$defaultParagraphFontStyleId = 'DefaultParagraphFont'])) {
			$defaultParagraphFontStyle = new DOCXStyle;
			$defaultParagraphFontStyle->styleId = $defaultParagraphFontStyleId;
			$defaultParagraphFontStyle->nameVal = "Default Paragraph Font";
			$defaultParagraphFontStyle->type = "character";
			$defaultParagraphFontStyle->semiHidden = true;
			$defaultParagraphFontStyle->unhideWhenUsed = true;
			$this->document->styles[$defaultParagraphFontStyleId] = $defaultParagraphFontStyle;
		}
		
		if(!isset($this->document->styles[$headingStyleId = 'Heading1'])) {
			$headingStyle = new DOCXStyle;
			$headingStyle->styleId = $headingStyleId;
			$headingStyle->nameVal = "heading 1";
			$headingStyle->baseOnVal = "Normal";
			$headingStyle->linkVal = "Heading1Char";
			$headingStyle->uiPriorityVal = 9;
			$headingStyle->type = "paragraph";
			$headingStyle->qFormat = true;
			$headingStyle->paragraphProperties = new DOCXParagraphProperties;
			$headingStyle->paragraphProperties->spacingBefore = 480;
			$headingStyle->paragraphProperties->spacingAfter = 0;
			$headingStyle->paragraphProperties->outlineLvlVal = 0;
			$this->document->styles[$headingStyleId] = $headingStyle;
		}

		if(!isset($this->document->styles[$headingStyleId = 'Heading1Char'])) {
			$headingStyle = new DOCXStyle;
			$headingStyle->styleId = $headingStyleId;
			$headingStyle->nameVal = "Heading 1 Char";
			$headingStyle->baseOnVal = "DefaultParagraphFont";
			$headingStyle->linkVal = "Heading1";
			$headingStyle->uiPriorityVal = 9;
			$headingStyle->type = "character";
			$headingStyle->textProperties = new DOCXTextProperties;
			$headingStyle->textProperties->b = true;
			$headingStyle->textProperties->bCs = true;
			$headingStyle->textProperties->szVal = 28;
			$headingStyle->textProperties->szCsVal = 28;
			$headingStyle->textProperties->colorVal = "365F91";
			$this->document->styles[$headingStyleId] = $headingStyle;
		}
		
	}
	
	protected function addFonts() {		
		// add properties of embedded fonts
		foreach($this->assets->fontFamilies as $fontFamily) {
			if(!isset($document->fonts[$fontFamily->name])) {				
				$docxFont = new DOCXFont;
				$docxFont->name = $fontFamily->name;
				$docxFont->fontFamily = $fontFamily->name;

				// use the information from the normal version of the font if possible
				if($fontFamily->normal) {
					$embeddedFont = $fontFamily->normal;
				} else if($fontFamily->bold) {
					$embeddedFont = $fontFamily->bold;
				} else if($fontFamily->italic) {
					$embeddedFont = $fontFamily->italic;
				} else if($fontFamily->boldItalic) {
					$embeddedFont = $fontFamily->boldItalic;
				}
				if($embeddedFont->panose) {
					$this->setFontPropertiesFromPanose($docxFont, $embeddedFont->panose);
				}
				$this->document->fonts[$docxFont->name] = $docxFont;
			}
		}
		
		// add properties of other referenced fonts
		$fontUsage = $this->getFontUsage();
		foreach($fontUsage as $fontFamilyName => $usage) {
			if(!isset($this->document->fonts[$fontFamilyName])) {
				$docxFont = new DOCXFont;
				$docxFont->name = $fontFamilyName;
				
				// see if we know this font's appearance
				$panose = PanoseDatabase::find($fontFamilyName);
				if($panose) {
					$this->setFontPropertiesFromPanose($docxFont, $panose);
				}
				$this->document->fonts[$fontFamilyName] = $docxFont;
			}
		}
	}	
	
	protected function setFontPropertiesFromPanose($docxFont, $panose) {
		// set generic font-family based on panose values
		if($panose[1] == 3) {
			$docxFont->family = 'script';
		} else if($panose[1] == 4) {
			$docxFont->family = 'decorative';
		} else if($panose[1] == 5) {
			$docxFont->family = 'system';
		} else if($panose[1] == 2) {
			if($panose[2] >= 11) {
				// sans-serif
				$docxFont->family = 'swiss';
			} else {
				// serif
				$docxFont->family = 'roman';
			}
		}

		// set the pitch
		if($panose[1] == 2) {
			if($panose[4] == 9) {
				$docxFont->pitch = 'fixed';
			} else {
				$docxFont->pitch = 'variable';
			}
		} else if($panose[1] == 3 || $panose[1] == 5) {
			if($panose[4] == 3) {
				$docxFont->pitch = 'fixed';
			} else {
				$docxFont->pitch = 'variable';
			}
		}
		
		$docxFont->panose1 = sprintf("%02X%02X%02X%02X%02X%02X%02X%02X%02X%02X", $panose[1], $panose[2], $panose[3], $panose[4], $panose[5], $panose[6], $panose[7], $panose[8], $panose[9], $panose[10]);
	}
}

?>