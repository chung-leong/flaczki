<?php

class SWFTextObjectParser extends SWFBasicParser {
		
	protected function readDoABCTag($tagLength) {
		$tag = new SWFDoABCTag;
		$tag->flags = $this->readUI32();
		$tag->byteCodeName = $this->readString($tagLength - 4);
		$bytesRemaining = $tagLength - 4 - strlen($tag->byteCodeName) - 1;
		
		if($bytesRemaining > 0) {
			// create a partial stream and parse the bytecodes using ABCParser				
			$path = StreamPartial::add($this->input, $bytesRemaining);
			$stream = fopen($path, "rb");
			$parser = new ABCParser;
			$tag->abcFile = $parser->parse($stream);
		}
		return $tag;
	}
	
	protected function readDefineFont4Tag($tagLength) {
		$tag = new SWFDefineFont4Tag;
		$tag->fontId = $this->readUI16();
		$tag->flags = $this->readUI8();
		$tag->fontName = $this->readString($tagLength - 3);
		$bytesRemaining = $tagLength - 3 - strlen($tag->fontName) - 1;
		
		if($bytesRemaining > 0) {
			$tag->cffData = $this->readBytes($bytesRemaining);
		}
		return $tag;
	}
}

class SWFDoABCTag extends SWFTag {
	public $flags;
	public $byteCodeName;
	public $byteCodes;
	
	public $abcFile;
}

class SWFDefineFont4Tag extends SWFTag {
	public $fontId;
	public $flags;
	public $fontName;
	public $cffData;
}

?>