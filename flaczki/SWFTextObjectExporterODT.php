<?php

class SWFTextObjectExporterODT extends SWFTextObjectExporter {

	protected $document;

	public function __construct($document = null) {
		parent::__construct();		
		$this->document = ($document) ? $document : new ODTDocument;
	}

	protected function exportSections($sections, $fontFamilies) {
		// add font references
		$styleUsage = $this->getStyleUsage($sections, array('fontFamily'));
		$fontUsage = $styleUsage['fontFamily'];
		$this->addFonts($fontFamilies, $fontUsage);
		
		// add default style, using the most frequently used font as the standard font
		$standardFontName = key($fontUsage);
		$this->addDefaultStyles($standardFontName);
		
		// add heading style for the first section--not starting with a page break
		$firstSectionHeadingStyle =  new ODTStyle;
		$firstSectionHeadingStyle->family = 'paragraph';
		$firstSectionHeadingStyle->parentStyleName = 'Heading_20_1';
		$this->addAutomaticStyle($firstSectionHeadingStyle);
		
		// add heading style of subsequent sections--start with a page break 
		$sectionHeadingStyle =  new ODTStyle;
		$sectionHeadingStyle->family = 'paragraph';
		$sectionHeadingStyle->parentStyleName = 'Heading_20_1';
		$sectionHeadingStyle->paragraphProperties = new ODTParagraphProperties;
		$sectionHeadingStyle->paragraphProperties->breakBefore = "page";
		$this->addAutomaticStyle($sectionHeadingStyle);
				
		foreach($sections as $section) {
			// add section name as heading
			$heading = new ODTHeading;
			$heading->styleName = (count($this->document->paragraphs) > 0) ? $sectionHeadingStyle->name : $firstSectionHeadingStyle->name;
			$heading->outlineLevel = 10;
			$span = new ODTSpan;
			$span->text = $this->beautifySectionName($section->name);
			$heading->spans[] = $span;
			$this->document->paragraphs[] = $heading;
			
			// add the paragraphs
			$textFlow = $section->tlfObject->textFlow;
			foreach($textFlow->paragraphs as $tlfParagraph) {
				$odtParagraph = new ODTParagraph;
				$odtParagraphStyle = new ODTStyle;
				$odtParagraphStyle->family = 'paragraph';
				$this->translateProperties($odtParagraphStyle, $textFlow->style);
				$this->translateProperties($odtParagraphStyle, $tlfParagraph->style);

				$tlfHyperlink = null;
				$odtHyperlink = null;
				foreach($tlfParagraph->spans as $tlfSpan) {
					$odtSpan = new ODTSpan;
					$odtSpanStyle = new ODTStyle;
					$odtSpanStyle->family = 'text';
					$this->translateProperties($odtSpanStyle, $tlfSpan->style);
					if($odtSpanStyle->paragraphProperties) {
						// spans cannot have paragraph style properties
						// take it off and apply the properties to the paragraph instead
						foreach($odtSpanStyle->paragraphProperties as $name => $value) {
							if($value && !$odtParagraphStyle->paragraphProperties->$name) {
								$odtParagraphStyle->paragraphProperties->$name = $value;
							}
						}
						$odtSpanStyle->paragraphProperties = null;
					}
					$odtSpan->styleName = $this->addAutomaticStyle($odtSpanStyle);
					$odtSpan->text = $tlfSpan->text;
					if($tlfSpan->hyperlink !== $tlfHyperlink) {
						$tlfHyperlink = $tlfSpan->hyperlink;
						if($tlfHyperlink) {
							$odtHyperlink = new ODTHyperlink;
							$odtHyperlink->type = 'simple';
							$odtHyperlink->href = $tlfHyperlink->href;
							$odtHyperlink->target = $tlfHyperlink->target;
						} else {
							$odtHyperlink = null;
						}
					}
					$odtSpan->hyperlink = $odtHyperlink;
					$odtParagraph->spans[] = $odtSpan;
				}
				$odtParagraph->styleName = $this->addAutomaticStyle($odtParagraphStyle);
				$this->document->paragraphs[] = $odtParagraph;
			}
		}
		return $this->document;
	}
	
	protected function translateProperties($odtStyle, $tlfStyle) {
		// backgroundAlpha, blockProgression, breakOpportunity, clearFloats, digitCase, digitWidth, firstBaselineOffset, justificationRule, justificationStyle
		// leadingModel, ligatureLevel, lineBreak, textAlpha, wordSpacing
		
		// paragraph properties
		if($tlfStyle->direction) {
			$this->createParagraphProperties($odtStyle);
			switch($tlfStyle->direction) {
				case 'ltr': $odtStyle->paragraphProperties->writingMode = 'lr-tb'; break;
				case 'rtl': $odtStyle->paragraphProperties->writingMode = 'rl-tb'; break;
			}
		}
		
		if($tlfStyle->dominantBaseline) {
			$this->createParagraphProperties($odtStyle);
			switch($tlfStyle->dominantBaseline) {
				case 'roman': $odtStyle->paragraphProperties->verticalAlign = 'baseline'; break;
				case 'descent': 
				case 'ideographicBottom': $odtStyle->paragraphProperties->verticalAlign = 'bottom'; break;
				case 'ascent': 
				case 'ideographicTop': $odtStyle->paragraphProperties->verticalAlign = 'top'; break;
				case 'ideographicCenter': $odtStyle->paragraphProperties->verticalAlign = 'middle'; break;
			}
		}
		
		if($tlfStyle->lineHeight) {
			$this->createParagraphProperties($odtStyle);
			if(preg_match('/\d+%$/', $tlfStyle->lineHeight)) {
				$odtStyle->paragraphProperties->lineHeight = $tlfStyle->lineHeight;
			} else if(is_numeric($tlfStyle->lineHeight)) {
				$odtStyle->paragraphProperties->lineHeight = $this->convertToCentimeter($tlfStyle->lineHeight);
			}
		}
		
		if($tlfStyle->paragraphEndIndent) {
			$this->createParagraphProperties($odtStyle);
			if($tlfStyle->direction == 'rtl') {
				$odtStyle->paragraphProperties->marginLeft = $this->convertToCentimeter($tlfStyle->paragraphEndIndent);
			} else {
				$odtStyle->paragraphProperties->marginRight = $this->convertToCentimeter($tlfStyle->paragraphEndIndent);
			}
		}
		
		if($tlfStyle->paragraphSpaceAfter) {
			$this->createParagraphProperties($odtStyle);
			$odtStyle->paragraphProperties->marginBottom = $this->convertToCentimeter($tlfStyle->paragraphSpaceAfter);
		}
		
		if($tlfStyle->paragraphSpaceBefore) {
			$this->createParagraphProperties($odtStyle);
			$odtStyle->paragraphProperties->marginTop = $this->convertToCentimeter($tlfStyle->paragraphSpaceBefore);
		}
		
		if($tlfStyle->paragraphStartIndent) {
			$this->createParagraphProperties($odtStyle);
			if($tlfStyle->direction == 'rtl') {
				$odtStyle->paragraphProperties->marginRight = $this->convertToCentimeter($tlfStyle->paragraphStartIndent);
			} else {
				$odtStyle->paragraphProperties->marginLeft = $this->convertToCentimeter($tlfStyle->paragraphStartIndent);
			}
		}
		
		if($tlfStyle->tabStops) {
			$this->createParagraphProperties($odtStyle);
			$odtStyle->paragraphProperties->odtTabStops = array();
			$tlfTabStops = explode(' ', $tlfStyle->tabStops);
			foreach($tlfTabStops as $tlfTabStop) {
				// see http://help.adobe.com/en_US/FlashPlatform/reference/actionscript/3/flashx/textLayout/elements/FlowElement.html#tabStops
				if(preg_match('/([sced])([0-9\.]+)(?:\|(.))?/', $tlfTabStop, $m)) {
					$odtTabStop = new ODTTabStop;
					$alignment = $m[1];
					$position = doubleval($m[2]);
					$decimalAlignmentToken = $m[3];	
												
					if($tlfStyle->direction == 'rtl') {								
						switch($alignment) {
							case 's': $odtTabStop->type = 'right'; break;
							case 'e': $odtTabStop->type = 'left'; break;
							case 'c': $odtTabStop->type = 'center'; break;
							case 'd': $odtTabStop->type = 'char'; $odtTabStop->char = $decimalAlignmentToken; break;
						} 
					} else {
						switch($alignment) {
							case 's': $odtTabStop->type = 'left'; break;
							case 'e': $odtTabStop->type = 'right'; break;
							case 'c': $odtTabStop->type = 'center'; break;
							case 'd': $odtTabStop->type = 'char'; $odtTabStop->char = $decimalAlignmentToken; break;
						} 
					}
					$odtTabStop->position = $this->convertToCentimeter($position);
					$odtStyle->paragraphProperties->tabStops[] = $odtTabStop;
				}
			}
		}
		
		if($tlfStyle->textAlign) {
			$this->createParagraphProperties($odtStyle);
			switch($tlfStyle->textAlign) {
				case 'start':
				case 'end':
				case 'left':
				case 'right':
				case 'center':
				case 'justify': $odtStyle->paragraphProperties->textAlign = $tlfStyle->textAlign; break;
			}
		}
		
		if($tlfStyle->textAlignLast) {
			$this->createParagraphProperties($odtStyle);
			switch($tlfStyle->textAlign) {
				case 'start':
				case 'end':
				case 'left':
				case 'right':
				case 'center':
				case 'justify': $odtStyle->paragraphProperties->textAlignLast = $tlfStyle->textAlignLast; break;
			}
		}
		
		if($tlfStyle->textIndent) {
			$this->createParagraphProperties($odtStyle);
			$odtStyle->paragraphProperties->textIndent = $this->convertToCentimeter($tlfStyle->textIndent);
		}
		
		// text properties
		if($tlfStyle->baselineShift) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->baselineShift) {
				case 'superscript': $odtStyle->textProperties->textPosition = 'super'; break;
				case 'subscript': $odtStyle->textProperties->textPosition = 'sub'; break;
			}
		}

		if($tlfStyle->backgroundColor) {
			$this->createTextProperties($odtStyle);
			$odtStyle->textProperties->backgroundColor = $tlfStyle->backgroundColor;
		}
									
		if($tlfStyle->color) {
			$this->createTextProperties($odtStyle);
			$odtStyle->textProperties->color = $tlfStyle->color;
		}
		
		if($tlfStyle->fontFamily) {
			$this->createTextProperties($odtStyle);
			// assume font is going to be named after its family
			$odtStyle->textProperties->fontName = $tlfStyle->fontFamily;
		}
		
		if($tlfStyle->fontSize) {
			$this->createTextProperties($odtStyle);
			$odtStyle->textProperties->fontSize = "{$tlfStyle->fontSize}pt";
		}
		
		if($tlfStyle->fontStyle) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->fontStyle) {
				case 'normal': $odtStyle->textProperties->fontStyle = 'normal'; break;
				case 'italic': $odtStyle->textProperties->fontStyle = 'italic'; break;
			}
		}
		
		if($tlfStyle->fontWeight) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->fontWeight) {
				case 'normal': $odtStyle->textProperties->fontWeight = 'normal'; break;
				case 'bold': $odtStyle->textProperties->fontWeight = 'bold'; break;
			}
		}
		
		if($tlfStyle->kerning) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->kerning) {
				case 'on': $odtStyle->textProperties->letterKerning = 'true'; break;
				case 'off': $odtStyle->textProperties->letterKerning = 'false'; break;
			}
		}
		
		if($tlfStyle->lineThrough) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->lineThrough) {
				case 'true':
					$odtStyle->textProperties->textLineThroughStyle = 'solid';
					$odtStyle->textProperties->textLineThroughType = 'single';
					break;
			}
		}
		
		if($tlfStyle->locale) {
			// TODO
		}
		
		if($tlfStyle->textDecoration) {
			$this->createTextProperties($odtStyle);
			switch($tlfStyle->textDecoration) {
				case 'underline': 
					$odtStyle->textProperties->textUnderlineStyle = 'solid';
					$odtStyle->textProperties->textUnderlineType = 'single';
					break;
			}
		}
		
		if($tlfStyle->textRotation) {
			if(is_numeric($tlfStyle->textRotation)) {
				$this->createTextProperties($odtStyle);
				$odtStyle->textProperties->textRotationAngle = $tlfStyle->textRotation;
			}
		}
		
		if($tlfStyle->trackingLeft && $tlfStyle->direction == 'rtl') {
			$this->createTextProperties($odtStyle);
			$odtStyle->textProperties->letterSpacing = $this->convertToCentimeter($tlfStyle->trackingLeft);
		}
		
		if($tlfStyle->trackingRight && $tlfStyle->direction != 'rtl') {
			$this->createTextProperties($odtStyle);
			$odtStyle->textProperties->letterSpacing = $this->convertToCentimeter($tlfStyle->trackingRight);
		}
		
		if($tlfStyle->typographicCase) {
			switch($tlfStyle->typographicCase) {
				case 'lower': $odtStyle->textProperties->textTransform = 'lowercase'; break;
				case 'upper': $odtStyle->textProperties->textTransform = 'uppercase'; break;
				case 'capsToSmallCaps': 
				case 'lowercaseToSmallCaps': $odtStyle->textProperties->fontVariant = 'small-caps'; break;
			}
		}
		
	}
	
	protected function convertToCentimeter($point) {
		$value = $point * 0.0352777778;		
		return sprintf((round($value) == $value) ? "%fcm" : "%.4fcm", $value);
	}
	
	protected function createTextProperties($odtStyle) {	
		if(!$odtStyle->textProperties) {
			$odtStyle->textProperties = new ODTTextProperties;
		}
	}

	protected function createParagraphProperties($odtStyle) {	
		if(!$odtStyle->paragraphProperties) {
			$odtStyle->paragraphProperties = new ODTParagraphProperties;
		}
	}

	protected $paragraphAutomaticStyleCount = 0;
	protected $textAutomaticStyleCount = 0;
	
	protected function addAutomaticStyle($style) {
		// see if the style already exists
		foreach($this->document->automaticStyles as $styleName => $existingStyle) {
			if($existingStyle->family == $style->family
			&& $existingStyle->parentStyleName == $style->parentStyleName
			&& $existingStyle->textProperties == $style->textProperties
			&& $existingStyle->paragraphProperties == $style->paragraphProperties) {
				return $styleName;
			}
		}
		
		if($style->family == 'paragraph') {
			// create a unique text style name
			for($i = $this->paragraphAutomaticStyleCount + 1; $i < 2147483647; $i++) {
				$styleName = "P$i";
				if(!isset($list[$styleName])) {
					$this->paragraphAutomaticStyleCount = $i;
					break;
				}
			}
		} else {
			// create a unique paragraph style name
			for($i = $this->textAutomaticStyleCount + 1; $i < 2147483647; $i++) {
				$styleName = "T$i";
				if(!isset($list[$styleName])) {
					$this->textAutomaticStyleCount = $i;
					break;
				}
			}
		}
		
		// see if it's based on a common style
		if(!$style->parentStyleName) {
			foreach($this->document->commonStyles as $parentStyleName => $parentStyle) {
				if($parentStyle->family == $style->family
				&& $parentStyle->textProperties == $style->textProperties
				&& $parentStyle->paragraphProperties == $style->paragraphProperties) {
					$style->parentStyleName = $parentStyleName;
					unset($style->textProperties);
					unset($style->paragraphProperties);
				}
			}
		}
		
		$style->name = $styleName;
		$this->document->automaticStyles[$styleName] = $style;
		return $styleName;
	}
	
	protected function addDefaultStyles($standardFontName) {
		if(!isset($this->document->defaultStyles[$family = 'paragraph'])) {
			$defaultStyle = new ODTStyle;
			$defaultStyle->family = $family;
			$defaultStyle->paragraphProperties = new ODTParagraphProperties;
			$defaultStyle->paragraphProperties->tabStopDistance = "1.27cm";
			$defaultStyle->textProperties = new ODTTextProperties;
			$defaultStyle->textProperties->fontSize = "10pt";
			$defaultStyle->textProperties->fontName = $standardFontName;
			$this->document->defaultStyles[$family] = $defaultStyle;
		}
		
		if(!isset($this->document->commonStyles[$standardStyleName = 'Standard'])) {
			$standardStyle = new ODTStyle;
			$standardStyle->name = $standardStyleName;
			$standardStyle->family = "paragraph";
			$standardStyle->paragraphProperties = new ODTParagraphProperties;
			$standardStyle->paragraphProperties->breakBefore = "auto";
			$standardStyle->paragraphProperties->lineHeight = "115%";
			$standardStyle->paragraphProperties->marginBottom = "0.212cm";
			$standardStyle->paragraphProperties->marginLeft = "0cm";
			$standardStyle->paragraphProperties->marginRight = "0cm";
			$standardStyle->paragraphProperties->marginTop = "0cm";
			$standardStyle->paragraphProperties->textIndent = "0cm";
			$standardStyle->paragraphProperties->writingMode = "lr-tb";
			$standardStyle->textProperties = new ODTTextProperties;
			$standardStyle->textProperties->color = "#000000";
			$standardStyle->textProperties->fontName = $standardFontName;
			$standardStyle->textProperties->fontSize = '11pt';
			$standardStyle->textProperties->fontStyle = "normal";
			$standardStyle->textProperties->fontWeight = "normal";
			$standardStyle->textProperties->textLineThroughStyle = "none";
			$standardStyle->textProperties->textUnderlineStyle = "none";
			$this->document->commonStyles[$standardStyleName] = $standardStyle;
		}

		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_1'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 1";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginBottom = "0.212cm";
			$headingStyle->paragraphProperties->marginTop = "0.847cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->fontSize = "18pt";
			$headingStyle->textProperties->fontWeight = "bold";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
		
		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_2'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 2";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginBottom = "0.141cm";
			$headingStyle->paragraphProperties->marginTop = "0.635cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->fontSize = "14pt";
			$headingStyle->textProperties->fontWeight = "bold";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
		
		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_3'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 3";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginBottom = "0.141cm";
			$headingStyle->paragraphProperties->marginTop = "0.494cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->fontSize = "12pt";
			$headingStyle->textProperties->fontWeight = "bold";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
	
		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_4'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 4";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginBottom = "0.071cm";
			$headingStyle->paragraphProperties->marginTop = "0.423cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->fontSize = "11pt";
			$headingStyle->textProperties->fontStyle = "italic";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
		
		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_5'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 5";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginBottom = "0.071cm";
			$headingStyle->paragraphProperties->marginTop = "0.388cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->color = "#666666";
			$headingStyle->textProperties->fontSize = "10pt";
			$headingStyle->textProperties->fontWeight = "bold";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
		
		if(!isset($this->document->commonStyles[$headingStyleName = 'Heading_20_6'])) {
			$headingStyle = new ODTStyle;
			$headingStyle->name = $headingStyleName;
			$headingStyle->displayName = "Heading 6";
			$headingStyle->family = "paragraph";
			$headingStyle->parentStyleName = $standardStyleName;
			$headingStyle->paragraphProperties = new ODTParagraphProperties;
			$headingStyle->paragraphProperties->breakBefore = "auto";
			$headingStyle->paragraphProperties->marginTop = "0.353cm";
			$headingStyle->paragraphProperties->marginBottom = "0.071cm";
			$headingStyle->paragraphProperties->writingMode = "lr-tb";
			$headingStyle->textProperties = new ODTTextProperties;
			$headingStyle->textProperties->color = "#666666";
			$headingStyle->textProperties->fontSize = "10pt";
			$headingStyle->textProperties->fontStyle = "italic";
			$this->document->commonStyles[$headingStyleName] = $headingStyle;
		}
	}
	
	protected function addFonts($fontFamilies, $fontUsage) {
		// add properties of embedded fonts
		foreach($fontFamilies as $fontFamily) {
			if(!isset($document->fonts[$fontFamily->name])) {				
				$odtFont = new ODTFont;
				$odtFont->name = $fontFamily->name;
				$odtFont->fontFamily = $fontFamily->name;

				// use the information from the normal version of the font if possible
				$isBold = $isItalic = false;
				if($fontFamily->normal) {
					$embeddedFont = $fontFamily->normal;
				} else if($fontFamily->bold) {
					$embeddedFont = $fontFamily->bold;
					$isBold = true;
				} else if($fontFamily->italic) {
					$embeddedFont = $fontFamily->italic;
					$isItalic = true;
				} else if($fontFamily->boldItalic) {
					$embeddedFont = $fontFamily->boldItalic;
					$isBold = true;
					$isItalic = true;
				}
				if($embeddedFont->panose) {
					$this->setFontPropertiesFromPanose($odtFont, $embeddedFont->panose, $isBold, $isItalic);
				}
				$this->document->fonts[$fontFamily->name] = $odtFont;
			}
		}
		
		// add properties of other referenced fonts
		foreach($fontUsage as $fontFamilyName => $usage) {
			if(!isset($this->document->fonts[$fontFamilyName])) {
				$odtFont = new ODTFont;
				$odtFont->name = $fontFamilyName;
				$odtFont->fontFamily = $fontFamilyName;
				
				// see if we know this font's appearance
				$panose = PanoseDatabase::find($fontFamilyName);
				if($panose) {
					$this->setFontPropertiesFromPanose($odtFont, $panose);
				}
				$this->document->fonts[$fontFamilyName] = $odtFont;
			}
		}
	}	
	
	protected function setFontPropertiesFromPanose($odtFont, $panose, $isBold = false, $isItalic = false) {
		// set generic font-family based on panose values
		if($panose[1] == 3) {
			$odtFont->fontFamilyGeneric = 'script';
		} else if($panose[1] == 4) {
			$odtFont->fontFamilyGeneric = 'decorative';
		} else if($panose[1] == 5) {
			$odtFont->fontFamilyGeneric = 'system';
		} else if($panose[1] == 2) {
			if($panose[2] >= 11) {
				// sans-serif
				$odtFont->fontFamilyGeneric = 'swiss';
			} else {
				// serif
				$odtFont->fontFamilyGeneric = 'roman';
			}
		}

		// set the style if it is oblique (even when the regular version is used)
		if(!$isItalic) {
			if($panose[1] == 2) {
				if($panose[8] >= 9) {
					$odtFont->fontStyle = 'oblique';
				}
			}
		}
		
		// set the weight if it isn't 'normal' 
		if(!$isBold) {
			if($panose[3] >= 2 && $panose[3] <= 11) {
				$weight = min(($panose[3] - 1) * 100, 900);
				if($weight < 400 || $weight > 500) {
					$odtFont->fontWeight = $weight;
				}
			}
		}
		
		// set the pitch
		if($panose[1] == 2) {
			if($panose[4] == 9) {
				$odtFont->fontPitch = 'fixed';
			} else {
				$odtFont->fontPitch = 'variable';
			}
		} else if($panose[1] == 3 || $panose[1] == 5) {
			if($panose[4] == 3) {
				$odtFont->fontPitch = 'fixed';
			} else {
				$odtFont->fontPitch = 'variable';
			}
		}
		
		$odtFont->panose1 = sprintf("%d %d %d %d %d %d %d %d %d %d", $panose[1], $panose[2], $panose[3], $panose[4], $panose[5], $panose[6], $panose[7], $panose[8], $panose[9], $panose[10]);
	}
}

require_once 'ODTParser.php'	// need ODTDocument

?>