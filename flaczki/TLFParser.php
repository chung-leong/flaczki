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
				$this->span->attributes = $attributes;
				break;
			case 'p':
				$this->paragraph = new TLFParagraph;
				$this->paragraph->attributes = $attributes;
				break;
			case 'TextFlow':
				$this->textFlow = new TLFTextFlow;
				$this->textFlow->attributes = $attributes;
				break;
			case 'tlfTextObject':
				$this->textObject = new TLFTextObject;
				$this->textObject->attributes = $attributes;
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
}

class TLFTextObject {
	public $attributes = array();
	public $textFlow;
}

class TLFTextFlow {
	public $attributes = array();
	public $paragraphs = array();
}

class TLFParagraph {
	public $attributes = array();
	public $spans = array();
}

class TLFSpan {
	public $attributes = array();
	public $text;
}

?>