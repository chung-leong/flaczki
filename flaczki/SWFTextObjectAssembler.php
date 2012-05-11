<?php

class SWFTextObjectAssembler extends SWFBasicAssembler {

	protected function finalizeDoABCTag($tag) {
		if(!$tag->byteCodes) {
			// create a memory stream and assemble the ABC file using ABCAssembler
			$path = StreamMemory::add($tag->byteCodes);
			$stream = fopen($path, "wb");
			$assembler = new ABCAssembler;
			$assembler->assemble($stream, $tag->abcFile);
			$tag->length = 4 + strlen($tag->byteCodeName) + 1 + strlen($tag->byteCodes);
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