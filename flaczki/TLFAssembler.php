<?php

class TLFAssembler {

	protected $output;
	protected $written;

	public function assemble(&$output, $textObject) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$this->output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
			$this->output = $output;
		} else {
			throw new Exception("Invalid output");
		}
		$this->written = 0;
		
		$this->writeStartTag('tlfTextObject', $textObject->style);
		if($textObject->textFlow) {
			$textFlow = $textObject->textFlow;
			$this->writeStartTag('TextFlow', $textFlow->style);
			foreach($textFlow->paragraphs as $paragraph) {
				$this->writeStartTag('p', $paragraph->style);
				$hyperlink = null;
				foreach($paragraph->spans as $span) {
					if($span->hyperlink !== $hyperlink) {
						if($hyperlink) {
							$this->writeEndTag('a');
						}
						$hyperlink = $span->hyperlink;
						if($hyperlink) {
							$this->writeStartTag('a', $hyperlink);
						}
					}
					$this->writeStartTag('span', $span->style);
					$this->writeText($span->text);
					$this->writeEndTag('span');
				}
				if($hyperlink) {
					$this->writeEndTag('a');
				}
				$this->writeEndTag('p');
			}				
			$this->writeEndTag('TextFlow');
		}
		$this->writeEndTag('tlfTextObject');
		
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}
	
	protected function writeStartTag($name, $attributes, $end = false) {
		static $entities = array('"' => '&quot;', "'" => '&apos;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		$s = "<$name";
		foreach($attributes as $name => $value) {
			if($value !== null && is_scalar($value)) {
				$s .= ' ' . $name . '="' . strtr($value, $entities) . '"';
			}
		}
		$s .= ($end) ? "/>" : ">";
		$this->writeBytes($s);
	}
	
	protected function writeEndTag($name) {
		$s = "</$name>";
		$this->writeBytes($s);
	}
	
	protected function writeText($text) {
		static $entities = array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		if($text) {
			$s = strtr($text, $entities);
			$this->writeBytes($s);
		}
	}
	
	protected function writeBytes($bytes) {
		$this->written .= fwrite($this->output, $bytes);
	}
}

?>