<?php

class TLFParser {

	protected $textObject;
	protected $textFlow;
	protected $paragraph;
	protected $span;

	public function parse($input) {
		$parser = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
		xml_set_object($parser, $this);
		xml_set_element_handler($parser, 'processStartTag', 'processEndTag');
		xml_set_character_data_handler($parser, 'processCharacterData');
		if(gettype($input) == 'resource') {
			while($data = fread($input, 1024)) {
				xml_parse($parser, $data, strlen($data) != 1024);
			}
		} else if(gettype($input) == 'string') {
			xml_parse($parser, $input, true);
		} else {
			throw new Exception("Invalid input");
		}
		return $this->textObject;
	}

	public function processStartTag($parser, $name, $attributes) {
		switch($name) {
			case 'span':
				$this->span = new TLFSpan;
				$this->copyProperties($this->span->style, $attributes);
				break;
			case 'p':
				$this->paragraph = new TLFParagraph;
				$this->copyProperties($this->paragraph->style, $attributes);
				break;
			case 'TextFlow':
				$this->textFlow = new TLFTextFlow;
				$this->copyProperties($this->textFlow->style, $attributes);
				break;
			case 'tlfTextObject':
				$this->textObject = new TLFTextObject;
				$this->copyProperties($this->textObject->style, $attributes);
				break;
		}
	}
	
	public function processEndTag($parser, $name) {
		switch($name) {
			case 'span':
				if($this->paragraph) {
					$this->paragraph->spans[] = $this->span;
				}
				$this->span = null;
				break;
			case 'p':
				if($this->textFlow) {
					$this->textFlow->paragraphs[] = $this->paragraph;
				}
				$this->paragraph = null;
				break;
			case 'TextFlow':
				if($this->textObject) {
					$this->textObject->textFlow = $this->textFlow;
				}
				$this->textFlow = null;
				break;
			case 'tlfTextObject':
				break;
		}
	}
	
	public function processCharacterData($parser, $text) {
		if($this->span) {
			$this->span->text .= $text;
		}
	}
	
	protected function copyProperties($object, $attributes) {
		foreach($attributes as $name => $value) {
			$object->$name = $value;
		}
	}
}

class TLFTextObject {
	public $style;
	public $textFlow;
	
	public function __construct() {
		$this->style = new TLFTextObjectStyle;
	}
}

class TLFTextObjectStyle {
	public $type;
	public $editPolicy;
	public $columnCount;
	public $columnGap;
	public $verticalAlign;
	public $firstBaselineOffset;
	public $paddingLeft;
	public $paddingTop;
	public $paddingRight;
	public $paddingBottom;
	public $background;
	public $backgroundColor;
	public $backgroundAlpha;
	public $border;
	public $borderColor;
	public $borderAlpha;
	public $borderWidth;
	public $paddingLock;
	public $multiline;
	public $antiAliasType;
	public $embedFonts;
}

class TLFFlowElement {
	public $style;
	
	public function __construct() {
		$this->style = new TLFFlowElementStyle;
	}
}

class TLFTextFlow extends TLFFlowElement {
	public $paragraphs = array();
	
	public function __construct() {
		$this->style->whiteSpaceCollapse = "preserve";
		$this->style->version = "2.0.0"; 
		$this->style->xmlns = "http://ns.adobe.com/textLayout/2008";
	}
}

class TLFParagraph extends TLFFlowElement {
	public $spans = array();
}

class TLFSpan extends TLFFlowElement {
	public $text;
}

class TLFFlowElementStyle {
	public $alignmentBaseline;
	public $backgroundAlpha;
	public $backgroundColor;
	public $baselineShift;
	public $blockProgression;
	public $breakOpportunity;
	public $clearFloats;
	public $color;
	public $columnCount;
	public $columnGap;
	public $columnWidth;
	public $digitCase;
	public $digitWidth;
	public $direction;
	public $dominantBaseline;
	public $firstBaselineOffset;
	public $fontFamily;
	public $fontLookup;
	public $fontSize;
	public $fontStyle;
	public $fontWeight;
	public $justificationRule;
	public $justificationStyle;
	public $kerning;
	public $lineBreak;
	public $lineHeight;
	public $lineThrough;
	public $locale;
	public $paddingBottom;
	public $paddingLeft;
	public $paddingRight;
	public $paddingTop;
	public $paragraphEndIndent;
	public $paragraphSpaceAfter;
	public $paragraphSpaceBefore;
	public $paragraphStartIndent;
	public $tabStops;
	public $textAlign;
	public $textAlignLast;
	public $textAlpha;
	public $textDecoration;
	public $textIndent;
	public $textJustify;
	public $textLength;
	public $textRotation;
	public $tracking;
	public $trackingLeft;
	public $trackingRight;
	public $typographicCase;
	public $verticalAlign;
	public $wordSpacing;	
}

class TLFTabStopFormat {
	public $alignment;
 	public $decimalAlignmentToken;
 	public $position;
}

?>