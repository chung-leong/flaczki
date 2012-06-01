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
	
	protected function finalizeSymbolClassTag($tag, $tearDown) {
		$tag->name = 'SymbolClass';
		$tag->code = 76;
		$tag->length = array_sum(array_map('strlen', $tag->names)) + count($tag->names) * 3 + 2;
		$tag->headerLength = 6;
	}
	
	protected function finalizeDefineBitsJPEGTag($tag, $tearDown) {
		if($tag->deblockingParam) {
			$tag->code = 90;
			$tag->name = 'DefineBitsJPEG4';
			$tag->length = strlen($tag->imageData) + strlen($tag->alphaData) + 8;
		} else if($tag->alphaData) {
			$tag->code = 32;
			$tag->name = 'DefineBitsJPEG3';
			$tag->length = strlen($tag->imageData) + strlen($tag->alphaData) + 6;
		} else {
			$tag->name = 'DefineBitsJPEG2';
			$tag->code = 21;
			$tag->length = strlen($tag->imageData) + 2;
		}
		$tag->headerLength = 6;
	}
	
	protected function writeDoABCTag($tag) {
		$this->writeUI32($tag->flags);
		$this->writeString($tag->byteCodeName);
		$this->writeBytes($tag->byteCodes);
	}
	
	protected function writeDefineFont4Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8($tag->flags);
		$this->writeString($tag->fontName);
		$this->writeBytes($tag->cffData);
	}

	protected function writeSymbolClassTag($tag) {
		$this->writeUI16(count($tag->characterIds));
		foreach($tag->characterIds as $index => $characterId) {
			$this->writeUI16($characterId);
			$this->writeBytes($tag->names[$index]);
			$this->writeUI8(0);
		}
	}
	
	protected function writeDefineBitsJPEGTag($tag) {		
		$this->writeUI16($tag->characterId);
		if($tag->code == 32 || $tag->code == 90) {
			$this->writeUI32(strlen($tag->imageData));
		}
		if($tag->code == 90) {
			$this->writeUI16($tag->deblockingParam);
		}
		$this->writeBytes($tag->imageData);
		$this->writeBytes($tag->alphaData);
	}
}

?>