<?php

class SWFBasicAssembler {

	protected $output;
	protected $written;
	
	public function assemble($output, $swfFile) {
		$this->output = $output;
		$this->written = 0;

		// prepare the tags for writing (making sure the tag lengths are correct, etc.)
		foreach($swfFile->tags as $tag) {
			$this->finalizeTag($tag);
		}

		// signature
		$signature = (($swfFile->compressed) ? 0x535743 : 0x535746) | ($swfFile->version << 24);
		$signature = $this->writeUI32($signature);
		
		// file length (uncompressed)
		$fileLength = 8 + ((($swfFile->frameSize->numBits * 4 + 5) + 7) / 8) + 4;
		foreach($swfFile->tags as $tag) {
			$fileLength += $tag->headerLength + $tag->length;
		}		
		$this->writeUI32($fileLength);
		
		echo "<h3>Length: $fileLength</h3>";
		
		if($swfFile->compressed) {
			fwrite($this->output, "\x78\x9C");		// zlib header
			$filter = stream_filter_append($this->output, "zlib.deflate");
		}

		// frame size		
		$this->writeRect($swfFile->frameSize);
		
		// frame rate and count
		$this->writeUI16($swfFile->frameRate);
		$this->writeUI16($swfFile->frameCount);
		
		foreach($swfFile->tags as $tag) {
			$this->writeTag($tag);
		}
		
		if($swfFile->compressed) {
			stream_filter_remove($filter);
			// should add the Adler-32 checksum here but the Flash Player doesn't seem to miss it
		}
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}
	
	protected function finalizeTag($tag) {
		$methodName = "finalize{$tag->name}Tag";
		if(method_exists($this, $methodName)) {
			$this->$methodName($tag);
		}
		if($tag->length > 63 && $tag->headerLength == 2) {
			// need to use long format instead
			$tag->headerLength = 6;
		}
		echo "$tag->name ($tag->length)<br>";
	}
	
	protected function writeTag($tag) {
		if($tag->headerLength == 6) {
			$tagCodeAndLength = ($tag->code << 6) | 0x003F;
			$this->writeUI16($tagCodeAndLength);
			$this->writeUI32($tag->length);
		} else {
			$tagCodeAndLength = ($tag->code << 6) | $tag->length;
			$this->writeUI16($tagCodeAndLength);
		}
			
		$methodName = "write{$tag->name}Tag";
		if(method_exists($this, $methodName)) {
			$tag = $this->$methodName($tag);
		} else {
			if($tag instanceof SWFGenericTag) {
				$this->writeGenericTag($tag);
			} else {
				throw new Exception("Missing implementation: $methodName()");
			}
		}
	}
	
	protected function writeGenericTag($tag) {
		$this->writeBytes($tag->data);
	}
	
	protected function finalizeDefineSpriteTag($tag) {
		echo "<div style='margin-Left:2em; border: 1px dotted lightgrey'>";
		echo "<h3>Sprite #$tag->spriteId</h3>";
		$tagLength = 4;
		foreach($tag->tags as $child) {
			$this->finalizeTag($child);
			$tagLength += $child->headerLength + $child->length;
		}
		$tag->length = $tagLength;
		echo "</div>";		
	}
	
	protected function writeDefineSpriteTag($tag) {
		$this->writeUI16($tag->spriteId);
		$this->writeUI16($tag->frameCount);
		foreach($tag->tags as $child) {
			$this->writeTag($child);
		}
	}
	
	protected function finalizeDefineBinaryDataTag($tag) {
		if(!$tag->data && $tag->swfFile) {
			// the tag is an embedded SWF file
			// create a memory stream and assemble the file using a clone of $this
			echo "<div style='margin-Left:2em; border: 1px dotted lightgrey'>";
			$path = StreamMemory::add($tag->data);
			$stream = fopen($path, "wb");
			$assembler = clone $this;
			$assembler->assemble($stream, $tag->swfFile);
			$tag->length = 6 + strlen($tag->data);
			echo "</div>";
		}
	}
		
	protected function writeDefineBinaryDataTag($tag) {
		$this->writeUI16($tag->objectId);
		$this->writeUI32($tag->reserved);
		$this->writeBytes($tag->data);
	}
		
	protected function writeBitFields($numBitsOffset, $numBitsWidth, $values) {		
		$valueBefore = $values[2];
		$numBits = $values[1];
		if($numBitsOffset > 0) {
			$bits = sprintf("%0{$numBitsOffset}b%0{$numBitsWidth}b", $valueBefore, $numBits);
		} else {
			$bits = sprintf("%0{$numBitsWidth}b", $numBits);
		}
		for($i = 2; $i < count($values); $i++) {
			$bits .= sprintf("%0{$numBits}b", $values[$i]);
		}
		$chunks = str_split($bits, 8);
		$bytes = '';
		foreach($chunks as $chunk) {
			$bytes .= chr(bindec($chunk));
		}
		$this->writeBytes($bytes);
	}

	protected function writeRect($rect) {
		$values = array(0, $rect->numBits, $rect->left, $rect->right, $rect->top, $rect->bottom);
		$fields = $this->writeBitFields(0, 5, $values);
	}
	
	protected function writeUI8($value) {
		$byte = chr($value);
		$this->writeBytes($byte);
	}

	protected function writeUI16($value) {
		$bytes = pack('v', $value);
		$this->writeBytes($bytes);
	}

	protected function writeUI32($value) {
		$bytes = pack('V', $value);
		$this->writeBytes($bytes);
	}
	
	protected function writeString($value) {
		$this->writeBytes("$value\0");
	}
	
	protected function writeBytes($bytes) {
		$this->written += fwrite($this->output, $bytes);
	}	
}

?>