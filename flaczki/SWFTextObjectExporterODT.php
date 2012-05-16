<?php

class SWFTextObjectExporterODT extends SWFTextObjectExporter {

	protected $document;

	public function __construct($document) {
		parent::__construct();
		$this->document = $document;
	}

	public function export($textObjects, $fontFamilies) {
		$standardFontName = $this->addFonts($textObjects, $fontFamilies);
		$sectionNameStyleName = $this->addDefaultStyles($standardFontName);
		
		// add heading style of section names (with 'break-before' being the key property)
		$sectionHeadingStyle =  new ODTStyle;
		$sectionHeadingStyle->family = 'paragraph';
		$sectionHeadingStyle->parentStyleName = $sectionNameStyleName;
		$sectionHeadingStyle->paragraphProperties = new ODTParagraphProperties;
		$sectionHeadingStyle->paragraphProperties->breakBefore = "page";
		$sectionHeadingStyleName = $this->addStyle($this->document->automaticStyles, $sectionHeadingStyle);
		
		foreach($textObjects as $textObject) {
			$tlfObject = $this->tlfParser->parse($textObject->xml);
			
			// add section name as heading
			$heading = new ODTHeading;
			$heading->styleName = $sectionHeadingStyleName;
			$span = new ODTSpan;
			$span->text = $this->beautifySectionName($textObject->name);
			$heading->spans[] = $span;
			$this->document->paragraphs[] = $heading;
			
			foreach($tlfObject->textFlow->paragraphs as $tlfParagraph) {
				$odtParagraph = new ODTParagraph;
				$odtParagraphStyle = new ODTStyle;
				$odtParagraphStyle->paragraphProperties = new ODTParagraphProperties;
				$odtParagraphStyle->textProperties = new ODTTextProperties;
				$this->translateProperties($odtParagraphStyle, $tlfParagraph->style);
				$odtParagraph->styleName = $this->addStyle($this->document->automaticStyles, $odtParagraphStyle);
				foreach($tlfParagraph->spans as $tlfSpan) {
					$odtSpan = new ODTSpan;
					$odtSpanStyle = new ODTStyle;
					$odtSpanStyle->paragraphProperties = new ODTParagraphProperties;
					$odtSpanStyle->textProperties = new ODTTextProperties;
					$this->translateProperties($odtSpanStyle, $tlfSpan->style);
					$odtSpan->styleName = $this->addStyle($this->document->automaticStyles, $odtSpanStyle);
					$odtSpan->text = $tlfSpan->text;
					$odtParagraph->spans[] = $odtSpan;
				}
				$this->document->paragraphs[] = $odtParagraph;
			}
		}
		$this->fontFamilies = null;
	}
	
	protected function translateProperties($odtStyle, $tlfStyle) {
		static $FONT_WEIGHT_TABLE = array(
			'normal' => 'normal',
			'bold' => 'bold'
		);		
		static $TEXT_DECORATION_TABLE1 = array(
			'none' => 'none',
			'underline' => 'single'
		);		
		static $TEXT_DECORATION_TABLE2 = array(
			'none' => 'none',
			'underline' => 'solid'
		);		
		static $FONT_STYLE_TABLE = array(
			'normal' => 'normal',
			'italic' => 'italic'
		);
		static $TAB_ALIGMENT_TABLE_LTR = array(
			'start' => 'left',
			'end' => 'right',
			'center' => 'center'
		);
		static $LINE_THROUGH_TABLE = array(
			'false' => 'none',
			'true' => 'solid'
		);
		static $TEXT_ALIGN_TABLE_LTR = array(
			'start' => 'start',
			'end' => 'end',
			'left' => 'left',
			'right' => 'right',
			'center' => 'center',
			'justify' => 'justify'
		);
	
		$TAB_ALIGMENT_TABLE = $TAB_ALIGMENT_TABLE_LTR;
		$TEXT_ALIGN_TABLE = $TEXT_ALIGN_TABLE_LTR;
							
		$properties = get_object_vars($tlfStyle);
		foreach($properties as $name => $value) {
			if($value !== null) {
				switch($name) {
					case 'alignmentBaseline':
						break;
					case 'backgroundAlpha':
						break;
					case 'backgroundColor':
						$odtStyle->textProperties->backgroundColor = $value;
						break;
					case 'baselineShift':
						if($value == 'subscript') {
							$odtStyle->textProperties->textPosition = 'sub';
						} else if($value == 'superscript') {
							$odtStyle->textProperties->textPosition = 'super';
						}
						break;
					case 'blockProgression':
						break;
					case 'breakOpportunity':
						break;
					case 'cffHinting':
						break;
					case 'clearFloats':
						break;
					case 'color':
						$odtStyle->textProperties->color = $value;
						break;
					case 'digitCase':
						break;
					case 'digitWidth':
						break;
					case 'direction':
						break;
					case 'dominantBaseline':
						break;
					case 'firstBaselineOffset':
						break;
					case 'fontFamily':
						$odtStyle->textProperties->fontFamily = $value;
						break;
					case 'fontSize':
						$odtStyle->textProperties->fontSize = $value . "pt";
						break;
					case 'fontStyle':
						$odtStyle->textProperties->fontStyle = $FONT_STYLE_TABLE[$value];
						break;
					case 'fontWeight':
						$odtStyle->textProperties->fontWeight = $FONT_WEIGHT_TABLE[$value];
						break;
					case 'justificationRule':
						break;
					case 'justificationStyle':
						break;
					case 'kerning':
						break;
					case 'leadingModel':
						break;
					case 'ligatureLevel':
						break;
					case 'lineBreak':
						break;
					case 'lineHeight':
						$odtStyle->paragraphProperties->lineHeight = $value;
						break;
					case 'lineThrough':
						$odtStyle->textProperties->textLineThroughStyle = $LINE_THROUGH_TABLE[$value];
						break;
					case 'locale':
						break;
					case 'paragraphStartIndent':
						//$odtStyle->paragraphProperties->marginRight = $value . "pt";
						break;
					case 'paragraphSpaceAfter':
						//$odtStyle->paragraphProperties->marginBottom = $value . "pt";
						break;
					case 'paragraphSpaceBefore':
						//$odtStyle->paragraphProperties->marginTop = $value . "pt";
						break;
					case 'paragraphStartIndent':
						//$odtStyle->paragraphProperties->marginLeft = $value . "pt";
						break;
					case 'tabStops':
						break;
					case 'textAlign':
						$odtStyle->paragraphProperties->textAlign = $TEXT_ALIGN_TABLE[$value];
						break;
					case 'textAlignLast':
						break;
					case 'textAlpha':
						break;
					case 'textDecoration':
						//$odtStyle->textUnderlineType = $TEXT_DECORATION_TABLE1[$value];
						//$odtStyle->textUnderlineStyle = $TEXT_DECORATION_TABLE2[$value];
						break;
					case 'textIndent':
						$odtStyle->textIndent = $value . "pt";
						break;
					case 'textJustify':
						break;
					case 'textRotation':
						break;
					case 'tracking':
						break;
					case 'trackingLeft':
						break;
					case 'trackingRight':
						break;
					case 'typographicCase':
						break;
					case 'verticalAlign':
						break;
					case 'wordSpacing':
						break;
				}
			}
		}
	}
	
	
	protected function addFonts($textObjects, $fontFamilies) {
		$standardFontName = 'Arial';
		
		// add properties of embedded fonts
		foreach($fontFamilies as $fontFamily) {
			if(!isset($document->fonts[$fontFamily->name])) {
				if($fontFamily->normal) {
					$embeddedFont = $fontFamily->normal;
					$odtFont = new ODTFont;			
					$odtFont->name = $fontFamily->name;
					$odtFont->fontFamily = $fontFamily->name;
					if($embeddedFont->panose) {
						$panose = $embeddedFont->panose;
					
						// set generic font family based on panose values
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
						
						// set the weight
						if($panose[3] >= 2 && $panose[3] <= 11) {
							$weight = min(($panose[3] - 1) * 100, 900);
							if($weight >= 400 && $weight <= 500) {
								$odtFont->fontWeight = 'normal';
							} else {
								$odtFont->fontWeight = $weight;
							}
						}
						
						// set the pitch
						if($panose[1] == 2) {
							if($panose[4] == 9) {
								$odtFont->fontPitch = 'fixed';
							} else {
								$odtFont->fontPitch = 'variable';
							}
						} else if($panose[1] == 3) {
							if($panose[4] == 3) {
								$odtFont->fontPitch = 'fixed';
							} else {
								$odtFont->fontPitch = 'variable';
							}
						}
						
						$odtFont->panose1 = sprintf("%d %d %d %d %d %d %d %d %d %d", $panose[1], $panose[2], $panose[3], $panose[4], $panose[5], $panose[6], $panose[7], $panose[8], $panose[9], $panose[10]);
					}
					$this->document->fonts[$fontFamily->name] = $odtFont;
				}
			}
		}
		return $standardFontName;
	}
	
	protected function addDefaultStyles($standardFontName) {
		$standardStyle = new ODTStyle;
		$standardStyle->name = "Standard";
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
		$standardStyleName = $this->addStyle($this->document->commonStyles, $standardStyle);

		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_1";
		$headerStyle->displayName = "Heading 1";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginBottom = "0.212cm";
		$headerStyle->paragraphProperties->marginTop = "0.847cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->fontSize = "18pt";
		$headerStyle->textProperties->fontWeight = "bold";
		$this->addStyle($this->document->commonStyles, $headerStyle);
		
		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_2";
		$headerStyle->displayName = "Heading 2";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginBottom = "0.141cm";
		$headerStyle->paragraphProperties->marginTop = "0.635cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->fontSize = "14pt";
		$headerStyle->textProperties->fontWeight = "bold";
		$sectionNameStyleName = $this->addStyle($this->document->commonStyles, $headerStyle);
		
		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_3";
		$headerStyle->displayName = "Heading 3";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginBottom = "0.141cm";
		$headerStyle->paragraphProperties->marginTop = "0.494cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->fontSize = "12pt";
		$headerStyle->textProperties->fontWeight = "bold";
		$this->addStyle($this->document->commonStyles, $headerStyle);
	
		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_4";
		$headerStyle->displayName = "Heading 4";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginBottom = "0.071cm";
		$headerStyle->paragraphProperties->marginTop = "0.423cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->fontSize = "11pt";
		$headerStyle->textProperties->fontStyle = "italic";
		$this->addStyle($this->document->commonStyles, $headerStyle);
		
		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_5";
		$headerStyle->displayName = "Heading 5";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginBottom = "0.071cm";
		$headerStyle->paragraphProperties->marginTop = "0.388cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->color = "#666666";
		$headerStyle->textProperties->fontSize = "10pt";
		$headerStyle->textProperties->fontWeight = "bold";
		$this->addStyle($this->document->commonStyles, $headerStyle);
		
		$headerStyle = new ODTStyle;
		$headerStyle->name = "Heading_20_6";
		$headerStyle->displayName = "Heading 6";
		$headerStyle->family = "paragraph";
		$headerStyle->parentStyleName = $standardStyleName;
		$headerStyle->paragraphProperties = new ODTParagraphProperties;
		$headerStyle->paragraphProperties->breakBefore = "auto";
		$headerStyle->paragraphProperties->marginTop = "0.353cm";
		$headerStyle->paragraphProperties->marginBottom = "0.071cm";
		$headerStyle->paragraphProperties->writingMode = "lr-tb";
		$headerStyle->textProperties = new ODTTextProperties;
		$headerStyle->textProperties->color = "#666666";
		$headerStyle->textProperties->fontSize = "10pt";
		$headerStyle->textProperties->fontStyle = "italic";
		$this->addStyle($this->document->commonStyles, $headerStyle);		
		
		return $sectionNameStyleName;
	}		
	
	protected function addStyle(&$list, $style) {
		foreach($list as $key => $existingStyle) {
			if($existingStyle->family == $style->family
			&& $existingStyle->parentStyleName == $style->parentStyleName
			&& $existingStyle->textProperties == $style->textProperties
			&& $existingStyle->paragraphProperties == $style->paragraphProperties) {
				return $key;
			}
		}
			
		if($style->name && !isset($list[$style->name])) {
			// add it under the name given
			$styleName = $style->name;
		} else {
			if(!$style->paragraphProperties) {
				// create a unique text style name
				for($i = 1; $i < 2147483647; $i++) {
					$styleName = "T$i";
					if(!isset($list[$styleName])) {
						break;
					}
				}
			} else {
				// create a unique paragraph style name
				for($i = 1; $i < 2147483647; $i++) {
					$styleName = "P$i";
					if(!isset($list[$styleName])) {
						break;
					}
				}
			}
			$style->name = $styleName;
		}
		$list[$styleName] = $style;
		return $styleName;
	}
}

?>