<?php

class TLFAssembler {

	protected $output;
	protected $written;

	public function assemble(&$output, &$extraInfo, $tlfObject) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$this->output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
			$this->output = $output;
		} else {
			throw new Exception("Invalid output");
		}
		$this->written = 0;
		
		$extraInfo = $this->getExtraInfo($tlfObject);
		
		$this->writeStartTag('tlfTextObject', $tlfObject->style);
		if($tlfObject->textFlow) {
			$textFlow = $tlfObject->textFlow;
			$this->writeStartTag('TextFlow', $textFlow->style);
			foreach($textFlow->paragraphs as $paragraph) {
				$this->writeStartTag('p', $paragraph->style);
				$hyperlink = null;
				foreach($paragraph->spans as $span) {
					if($span instanceof TLFInlineGraphicElement) {
						$graphic = $span;
						$customSource = array_search($graphic->className, $extraInfo);
						$attributes = array(
							'source' => 'Object',
							'customSource' => $customSource,
							'float' => $graphic->float,
							'width' => $graphic->width,
							'height' => $graphic->height,
							'paddingLeft' => $graphic->paddingLeft,
							'paddingRight' => $graphic->paddingRight,
							'paddingTop' => $graphic->paddingTop,
							'paddingBottom' => $graphic->paddingBottom,
						);
						$this->writeStartTag('img', $attributes, true);
					} else {
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
	
	protected function getExtraInfo($tlfObject) {
		$extraInfo = array();
		foreach($tlfObject->textFlow->paragraphs as $paragraph) {
			foreach($paragraph->spans as $graphic) {
				if($graphic instanceof TLFInlineGraphicElement) {
					if(!in_array($graphic->className, $extraInfo)) {
						$num = count($extraInfo) + 1;
						$customSource = "image$num";
						$extraInfo[$customSource ] = $graphic->className;
					}
				}
			}
		}
		return $extraInfo;
	}
}

?>