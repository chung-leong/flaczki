<?php

class SWFTextObjectUpdaterODT extends SWFTextObjectUpdater {

	protected $document;

	public function __construct($document) {
		parent::__construct();
		$this->document = $document;
	}
	
	protected function getSections() {
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
					$sectionName = '';
					foreach($paragraph->spans as $span) {
						$sectionName .= $span->text;
					}
					$sectionName = $this->filterName($sectionName);
					if($sectionName) {
						$section = new SWFTextObjectUpdaterODTSection;
						$section->name = $sectionName;
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
		$styleUsage = $this->getStyleUsage($tlfObject, 'fontFamily');
		
		$newParagraphs = array();
		foreach($section->paragraphs as $index => $odtParagraph) {
			$tlfParagraph = new TLFParagraph;
			$odtParagraphStyle = $section->paragraphStyles[$index];
			$this->translateProperties($tlfParagraph->style, $odtParagraphStyle->paragraphProperties);
			foreach($odtParagraph->spans as $odtSpan) {
				$tlfSpan = new TLFSpan;
				$odtSpanStyle = $this->getApplicableStyle($odtSpan, $odtParagraphStyle);
				$this->translateProperties($tlfSpan->style, $odtSpanStyle->textProperties);
				$tlfSpan->text = $odtSpan->text;
				$tlfParagraph->spans[] = $tlfSpan;
			}
			$newParagraphs[] = $tlfParagraph;
		}
		$tlfObject->textFlow->paragraphs = $newParagraphs;
	}
	
	protected function translateProperties($tlfStyle, $odtProperties) {	
		static $FONT_WEIGHT_TABLE = array(
			'normal' => 'normal',
			'bold' => 'bold',
			'100' => 'normal',
			'200' => 'normal',
			'300' => 'normal',
			'400' => 'normal',
			'500' => 'normal',
			'600' => 'bold',
			'700' => 'bold',
			'800' => 'bold',
			'900' => 'bold'
		);		
		static $TEXT_DECORATION_TABLE1 = array(
			'none' => 'none',
			'single' => 'underline',
			'double' => 'underline'
		);		
		static $TEXT_DECORATION_TABLE2 = array(
			'none' => 'none',
			'solid' => 'underline',
			'dotted' => 'underline',
			'dash' => 'underline',
			'long-dash' => 'underline',
			'dot-dash' => 'underline',
			'dot-dot-dash' => 'underline',
			'wave' => 'underline'
		);		
		static $FONT_STYLE_TABLE = array(
			'normal' => 'normal',
			'italic' => 'italic',
			'oblique' => 'italic'
		);
		static $TAB_ALIGMENT_TABLE_LTR = array(
			'left' => 'start',
			'right' => 'end',
			'center' => 'center'
		);
		static $LINE_THROUGH_TABLE = array(
			'none' => 'false',
			'solid' => 'true'
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
		
		$tlfStyle->fontFamily = 'Calibri';
		$tlfStyle->fontLookup = 'device';
						
		foreach($odtProperties as $name => $value) {
			if($value !== null) {
				switch($name) {
					//case '?':
					//	$tlfStyle->alignmentBaseline = '';
					//	break;
					//case '?':
					//	$tlfStyle->backgroundAlpha = ''; 
					//	break;
					case 'backgroundColor':
						$tlfStyle->backgroundColor = $this->parseColor($value);
						break;
					case 'textPosition':
						if(substr_compare($value, 'sub', 0, 3) == 0) {
							$tlfStyle->baselineShift = 'subscript';
						} else if(substr_compare($value, 'super', 0, 5) == 0) {
							$tlfStyle->baselineShift = 'superscript';
						}
						break;
					//case '?':
					//	$tlfStyle->blockProgression = '';
					//	break;
					//case '?':
					//	$tlfStyle->breakOpportunity = '';
					//	break;
					//case '?':
					//	$tlfStyle->cffHinting = '';
					//	break;
					//case '?':
					//	$tlfStyle->clearFloats = '';
					//	break;
					case 'color':
						$tlfStyle->color = $this->parseColor($value);
						break;
					//case '?':
					//	$tlfStyle->digitCase = '';
					//	break;
					//case '?':
					//	$tlfStyle->digitWidth = '';
					//	break;
					//case '?':
					//	$tlfStyle->direction = '';
					//	break;
					//case '?':
					//	$tlfStyle->dominantBaseline = '';
					//	break;
					//case '?':
					//	$tlfStyle->firstBaselineOffset = '';
					//	break;
					case 'fontFamily':
						$tlfStyle->fontFamily = $value;
						$tlfStyle->fontLookup = FontLookup.DEVICE;
						break;
					case 'fontSize':
						$tlfStyle->fontSize = $this->parseLength($value);
						break;
					case 'fontStyle':
						$tlfStyle->fontStyle = $FONT_STYLE_TABLE[$value];
						break;
					case 'fontWeight':
						$tlfStyle->fontWeight = $FONT_WEIGHT_TABLE[$value];
						break;
					//case '?':
					//	$tlfStyle->justificationRule = '';
					//	break;
					//case '?':
					//	$tlfStyle->justificationStyle = '';
					//	break;
					//case '?':
					//	$tlfStyle->kerning = '';
					//	break;
					//case '?':
					//	$tlfStyle->leadingModel = '';
					//	break;
					//case '?':
					//	$tlfStyle->ligatureLevel = '';
					//	break;
					//case '?':
					//	$tlfStyle->lineBreak = '';
					//	break;
					//case '?':
					//	$tlfStyle->lineHeight = '';
					//	break;
					case 'textLineThroughStyle':
						$tlfStyle->lineThrough = $LINE_THROUGH_TABLE[$value];
						break;
					//case '?':
					//	$tlfStyle->locale = '';
					//	break;
					case 'marginRight':
						$tlfStyle->paragraphStartIndent = $this->parseLength($value);
						break;
					case 'marginBottom':
						$tlfStyle->paragraphSpaceAfter = $this->parseLength($value);
						break;
					case 'marginTop':
						$tlfStyle->paragraphSpaceBefore =  $this->parseLength($value);
						break;
					case 'marginLeft':
						$tlfStyle->paragraphStartIndent = $this->parseLength($value);
						break;
					case 'tabStops':
						$tlfTabStops = array();
						foreach($value as $odtTabStop) {
							$tlfTabStop = new TLFTabStopFormat;
							$tlfTabStop->alignment = $TAB_ALIGMENT_TABLE[$odtTabStop->type];
							if($odtTabStop->char) {
		 						$tlfTabStop->decimalAlignmentToken = $odtTabStop->char;
		 					}
							$tlfTabStop->position = $this->parseLength($odtTabStop->position);						
							$tlfTabStops[] = $tlfTabStop;
						}
						$tlfStyle->tabStops = $tlfTabStops;
						break;
					case 'textAlign':
						$tlfStyle->textAlign = $TEXT_ALIGN_TABLE[$value];
						break;
					//case '?':
					//	$tlfStyle->textAlignLast = '';
					//	break;
					//case '?':
					//	$tlfStyle->textAlpha = '';
					//	break;
					case 'textUnderlineType':
						$tlfStyle->textDecoration = $TEXT_DECORATION_TABLE1[$value];
						break;
					case 'textUnderlineStyle':
						$tlfStyle->textDecoration = $TEXT_DECORATION_TABLE2[$value];
						break;
					case 'textIndent':
						$tlfStyle->textIndent = $this->parseLength($value);
						break;
					//case '?':
					//	$tlfStyle->textJustify = '';
					//	break;
					//case '?':
					//	$tlfStyle->textRotation = '';
					//	break;
					//case '?':
					//	$tlfStyle->tracking = '';
					//	break;
					//case '?':
					//	$tlfStyle->trackingLeft = '';
					//	break;
					//case '?':
					//	$tlfStyle->trackingRight = '';
					//	break;
					//case '?':
					//	$tlfStyle->typographicCase = '';
					//	break;
					//case '?':
					//	$tlfStyle->verticalAlign = '';
					//	break;
					//case '?':
					//	$tlfStyle->wordSpacing = '';
					//	break;
				}
			}
		}
	}
	
	protected function parseLength($s) {
		static $CM_TO_POINT_RATIO = 28.3464567;
		static $IN_TO_POINT_RATIO = 72;
		static $PT_TO_PIXEL_RATIO = 1.333333333333333;
		
		if(preg_match('/cm$/', $s)) {
			return round(doubleval($s) * $CM_TO_POINT_RATIO);
		} else if(preg_match('/in$/', $s)) {
			return round(doubleval($s) * $IN_TO_POINT_RATIO);
		} else {
			return doubleval($s);
		}
	}
	
	protected function parseColor($s) {
		return $s;
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
}

class SWFTextObjectUpdaterODTSection {
	public $name;
	public $paragraphs = array();
	public $paragraphStyles = array();
}

?>