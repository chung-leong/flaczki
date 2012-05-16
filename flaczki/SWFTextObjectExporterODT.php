<?php

class SWFTextObjectExporterODT extends SWFTextObjectExporter {

	protected $document;

	public function __construct($document = null) {
		parent::__construct();		
		$this->document = ($document) ? $document : new ODTDocument;
	}

	protected function exportSections($sections, $fontFamilies) {
		// add font references
		$fontUsage = $this->getFontUsage($sections);
		$this->addFonts($fontFamilies, $fontUsage);
		
		// add default style, using the most frequently used font as the standard font
		$standardFontName = key($fontUsage);
		$this->addDefaultStyles($standardFontName);
		
		// add heading style for the first section--not starting with a page break
		$firstSectionHeadingStyle =  new ODTStyle;
		$firstSectionHeadingStyle->family = 'paragraph';
		$firstSectionHeadingStyle->parentStyleName = 'Heading_20_2';
		$this->addAutomaticStyle($firstSectionHeadingStyle);
		
		// add heading style of subsequent sections--start with a page break 
		$sectionHeadingStyle =  new ODTStyle;
		$sectionHeadingStyle->family = 'paragraph';
		$sectionHeadingStyle->parentStyleName = 'Heading_20_2';
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
					$odtParagraph->spans[] = $odtSpan;
				}
				$odtParagraph->styleName = $this->addAutomaticStyle($odtParagraphStyle);
				$this->document->paragraphs[] = $odtParagraph;
			}
		}
		return $this->document;
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
		static $TEXT_POSITION_TABLE = array(
			'superscript' => 'super',
			'subscript' => 'sub'
		);
		$TEXT_ALIGN_TABLE = $TEXT_ALIGN_TABLE_LTR;
		$TAB_ALIGMENT_TABLE = $TAB_ALIGMENT_TABLE_LTR;
									
		foreach($tlfStyle as $name => $value) {
			if($value !== null && $value !== 'inherited') {
				switch($name) {
					case 'alignmentBaseline':
						break;
					case 'backgroundAlpha':
						break;
					case 'backgroundColor':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->backgroundColor = $value;
						break;
					case 'baselineShift':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->textPosition = $this->lookUpValue($TEXT_POSITION_TABLE, $value);
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
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
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
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->fontName = $value;
						break;
					case 'fontSize':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->fontSize = "{$value}pt";
						break;
					case 'fontStyle':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->fontStyle = $this->lookUpValue($FONT_STYLE_TABLE, $value);
						break;
					case 'fontWeight':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->fontWeight = $this->lookUpValue($FONT_WEIGHT_TABLE, $value);
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
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->lineHeight = $value;
						break;
					case 'lineThrough':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->textLineThroughStyle = $this->lookUpValue($LINE_THROUGH_TABLE, $value);
						break;
					case 'locale':
						break;
					case 'paragraphStartIndent':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->marginRight = $this->convertToCentimeter($value);
						break;
					case 'paragraphSpaceAfter':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->marginBottom = $this->convertToCentimeter($value);
						break;
					case 'paragraphSpaceBefore':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->marginTop = $this->convertToCentimeter($value);
						break;
					case 'paragraphStartIndent':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->marginLeft = $this->convertToCentimeter($value);
						break;
					case 'tabStops':
						break;
					case 'textAlign':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->textAlign = $this->lookUpValue($TEXT_ALIGN_TABLE, $value);
						break;
					case 'textAlignLast':
						break;
					case 'textAlpha':
						break;
					case 'textDecoration':
						if(!$odtStyle->textProperties) $odtStyle->textProperties = new ODTTextProperties;
						$odtStyle->textProperties->textUnderlineType = $this->lookUpValue($TEXT_DECORATION_TABLE1, $value);
						$odtStyle->textProperties->textUnderlineStyle = $this->lookUpValue($TEXT_DECORATION_TABLE2, $value);
						break;
					case 'textIndent':
						if(!$odtStyle->paragraphProperties) $odtStyle->paragraphProperties = new ODTParagraphProperties;
						$odtStyle->paragraphProperties->textIndent = $this->convertToCentimeter($value);
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
	
	protected function convertToCentimeter($point) {
		return sprintf("%.4dcm", $point * 0.0352777778);
	}
	
	protected function lookUpValue($table, $key) {
		if(isset($table[$key])) {
			return $table[$key];
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
					$this->addFontProperties($odtFont, $embeddedFont->panose, $isBold, $isItalic);
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
					$this->addFontProperties($odtFont, $panose);
				}
				$this->document->fonts[$fontFamilyName] = $odtFont;
			}
		}
	}	
	
	protected function addFontProperties($odtFont, $panose, $isBold = false, $isItalic = false) {
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
		} else if($panose[1] == 3) {
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