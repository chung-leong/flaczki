<?php

class FLADynamicTextReconstructor {

	protected $currentTextRun;
	protected $textRuns;
	protected $textAttributes;
	protected $textAttributeStack;

	public function reconstruct($html, $defaultTextAttributes) {
		$this->textAttributes = $defaultTextAttributes;
		$this->textAttributeStack = array();
		$this->textRuns = array();
		//echo $html;
		$parser = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
		xml_set_object($parser, $this);
		xml_set_element_handler($parser, 'processStartTag', 'processEndTag');
		xml_set_character_data_handler($parser, 'processCharacterData');
		xml_parse($parser, "<html>$html</html>", true);
		
		$textRuns = $this->textRuns;
		$this->textRuns = $this->currentTextRun = $this->textAttributes = $this->textAttributeStack = null;
		return $textRuns;
	}

	public function processStartTag($parser, $name, $attributes) {
		if($name == 'html') {
			return;
		}
		array_push($this->textAttributeStack, $this->textAttributes);
		$this->textAttributes = clone $this->textAttributes;
		
		switch($name) {
			case 'p':
				if($this->currentTextRun) {
					$this->currentTextRun->characters .= "\r";
				}
				$this->processAttributes($parser, $attributes);
				break;
			case 'font':
				$this->processAttributes($parser, $attributes);
				break;
			case 'a':
				$this->processAttributes($parser, $attributes);
				break;
			case 'b':
				$this->processAttributes($parser, array('bold' => true));
				break;
			case 'i':
				$this->processAttributes($parser, array('italic' => true));
				break;
			case 'u':
				$this->processAttributes($parser, array('underline' => true));
				break;
		}
		$this->currentTextRun = null;
	}
	
	public function processEndTag($parser, $name) {
		if($name == 'html') {
			return;
		}
		$this->textAttributes = array_pop($this->textAttributeStack);
	}
	
	public function processCharacterData($parser, $text) {
		if($this->currentTextRun) {
			$this->currentTextRun->characters .= $text;
		} else {
			$this->currentTextRun = new FLADOMTextRun;
			$this->currentTextRun->characters = $text;
			$this->currentTextRun->textAttrs = array($this->textAttributes);
			$this->textRuns[] = $this->currentTextRun;
		}
	}
	
	public function processAttributes($parser, $attributes) {
		foreach($attributes as $name => $value) {
			switch($name) {
				case 'align':
					$this->textAttributes->alignment = $value;
					break;
				case 'face':
					$this->textAttributes->face = $value;
					break;
				case 'size':
					$this->textAttributes->size = $value;
					break;
				case 'color':
					$this->textAttributes->fillColor = $value;
					break;
				case 'letterSpacing':
					$this->textAttributes->letterSpacing = $value;
					break;
				case 'letterSpacing':
					$this->textAttributes->letterSpacing = $value;
					break;
				case 'href':
					$this->textAttributes->url = $value;
					break;
				case 'target':
					$this->textAttributes->target = $value;
					break;
				case 'bold':
					$this->textAttributes->face = preg_replace('/(?:-(Italic)|)$/i', '-Bold$1', $this->textAttributes->face, 1);
					break;
				case 'italic':
					$this->textAttributes->face = preg_replace('/(?:-(Bold)|)$/i', '-$1Italic', $this->textAttributes->face, 1);
					break;
			}
		}
	}
}

?>