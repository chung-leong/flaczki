<?php

class SWFTextObjectParser extends SWFBasicParser {
		
	protected function readDoABCTag($tagLength) {
		$tag = new SWFDoABCTag;
		$tag->flags = $this->readUI32();
		$bytesRemaining = $tagLength - 4;
		
		// read the name (zero-terminated string)
		do {
			$byte = $this->readBytes(1);
			if($byte !== "\0") {
				$tag->byteCodeName .= $byte;
			}
			$bytesRemaining--;
		} while($byte !== "\0" && $byte !== null && $bytesRemaining > 0);
		
		if($bytesRemaining > 0) {
			// create a partial stream and parse the bytecodes using ABCParser				
			$path = StreamPartial::add($this->input, $bytesRemaining);
			$stream = fopen($path, "rb");
			$parser = new ABCParser;
			$tag->abcFile = $parser->parse($stream);
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

?>