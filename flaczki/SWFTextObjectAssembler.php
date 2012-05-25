<?php

class SWFTextObjectAssembler extends SWFBasicAssembler {

	protected function finalizeDoABCTag($tag, $tearDown) {
		if(!$tag->byteCodes) {
			// assemble the ABC file using ABCAssembler
			$tag->byteCodes = '';
			$assembler = new ABCAssembler;
			$assembler->assemble($tag->byteCodes, $tag->abcFile);
			$tag->length = 4 + strlen($tag->byteCodeName) + 1 + strlen($tag->byteCodes);
			if($tearDown) {
				$tag->abcFile = null;
			} 
		}
	}
		
	protected function writeDoABCTag($tag) {
		$this->writeUI32($tag->flags);
		$this->writeString($tag->byteCodeName);
		$this->writeBytes($tag->byteCodes);
	}
	
	protected function writeDefineFont4Tag($tag) {
		$this->writeUI16($tag->fontId);
		$this->writeUI8($tag->flags);
		$this->writeString($tag->fontName);
		$this->writeBytes($tag->cffData);
	}
}

?>