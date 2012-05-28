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
		$tag->characterId = $this->readUI16();
		$tag->flags = $this->readUI8();
		$tag->fontName = $this->readString($tagLength - 3);
		$bytesRemaining = $tagLength - 3 - strlen($tag->fontName) - 1;
		
		if($bytesRemaining > 0) {
			$tag->cffData = $this->readBytes($bytesRemaining);
		}
		return $tag;
	}
	
	protected function readSymbolClassTag($tagLength) {
		$tag = new SWFSymbolClassTag;
		$numSymbols = $this->readUI16();
		$bytesRemaining = $tagLength - 2;
		
		$data = $this->readBytes($bytesRemaining);
		for($si = 0; $si < $bytesRemaining; $si = $ei + 1) {
			$array = unpack('v', substr($data, $si, 2));
			$tag->characterIds[] = $array[1];
			$si += 2;
			$ei = strpos($data, "\0", $si);
			$tag->names[] = ($ei === false) ? substr($data, $si) : substr($data, $si, $ei - $si);
		}
		strlen($tag->names[0]);
		return $tag;
	}
	
	protected function readDefineBitsJPEG2Tag($tagLength) {
		$tag = new SWFDefineBitsJPEG2Tag;
		$tag->characterId = $this->readUI16();
		$bytesRemaining = $tagLength - 2;
		$tag->imageData = $this->readBytes($bytesRemaining);
		return $tag;
	}

	protected function readDefineBitsJPEG3Tag($tagLength) {
		$tag = new SWFDefineBitsJPEG3Tag;
		$tag->characterId = $this->readUI16();
		$alphaOffset = $this->readUI32();
		$tag->imageData = $this->readBytes($alphaOffset);
		$bytesRemaining = $tagLength - 6 - $alphaOffset;
		$tag->alphaData = $this->readBytes($bytesRemaining);
		return $tag;
	}

	protected function readDefineBitsJPEG4Tag($tagLength) {
		$tag = new SWFDefineBitsJPEG4Tag;
		$tag->characterId = $this->readUI16();
		$alphaOffset = $this->readUI32();
		$tag->deblockingParam = $this->readUI16();
		$tag->imageData = $this->readBytes($alphaOffset);
		$bytesRemaining = $tagLength - 8 - $alphaOffset;
		$tag->alphaData = $this->readBytes($bytesRemaining);
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
	public $characterId;
	public $flags;
	public $fontName;
	public $cffData;
}

class SWFSymbolClassTag extends SWFTag {
	public $characterIds = array();
	public $names = array();
}

class SWFDefineBitsJPEG2Tag extends SWFTag {
	public $characterId;
	public $imageData;
}

class SWFDefineBitsJPEG3Tag extends SWFDefineBitsJPEG2Tag {
	public $alphaData;
}

class SWFDefineBitsJPEG4Tag extends SWFDefineBitsJPEG3Tag {
	public $deblockingParam;
}

?>