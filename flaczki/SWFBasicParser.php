<?php

class SWFBasicParser {

	protected $input;
	
	public function parse(&$input) {
		if(gettype($input) == 'string') {
			$path = StreamMemory::add($input);
			$this->input = fopen($path, "rb");
		} else if(gettype($input) == 'resource') {
			$this->input = $input;
		} else {
			throw new Exception("Invalid output");
		}
		$swfFile = new SWFFile;
	
		// signature
		$signature = $this->readUI32();
		$swfFile->version = ($signature >> 24) & 0xFF;
		$signature = $signature & 0xFFFFFF;
		
		// should be SWF or SWC
		if($signature != 0x535746 && $signature != 0x535743) {
			return false;
		}
		
		// file length (uncompressed)
		$fileLength = $this->readUI32();
			
		if($signature == 0x535743) {
			fread($this->input, 2);		// skip the zlib header
			$path = StreamProxy::add($this->input);
			$this->input = fopen($path, 'rb');
			stream_filter_append($this->input, "zlib.inflate");
			$swfFile->compressed = true;
		} else {
			$swfFile->compressed = false;
		}

		// frame size		
		$swfFile->frameSize = $this->readRect();
		
		// frame rate and count
		$swfFile->frameRate = $this->readUI16();
		$swfFile->frameCount = $this->readUI16();
		
		while($tag = $this->readTag()) {
			$swfFile->tags[] = $tag;
		}
		
		echo "<h3>Length: $fileLength</h3>";
		$this->input = null;
		return $swfFile;
	}
	
	protected function readTag() {
		static $TAG_NAMES = array(
			74 => 'CSMTextSettings',		14 => 'DefineSound',
			78 => 'DefineScalingGrid',		60 => 'DefineVideoStream',
			87 => 'DefineBinaryData',		59 => 'DoInitAction',
			6  => 'DefineBits',			82 => 'DoABC',
			21 => 'DefineBitsJPEG2',		12 => 'DoAction',
			35 => 'DefineBitsJPEG3',		58 => 'EnableDebugger',
			90 => 'DefineBitsJPEG4',		64 => 'EnableDebugger2',
			20 => 'DefineBitsLossless',		0  => 'End',
			36 => 'DefineBitsLossless2',		56 => 'ExportAssets',
			7  => 'DefineButton',			69 => 'FileAttributes',
			34 => 'DefineButton2',			43 => 'FrameLabel',
			23 => 'DefineButtonCxform',		57 => 'ImportAssets',
			17 => 'DefineButtonSound',		71 => 'ImportAssets2',
			37 => 'DefineEditText',			8  => 'JPEGTables',
			10 => 'DefineFont',			77 => 'Metadata',
			48 => 'DefineFont2',			24 => 'Protect',
			75 => 'DefineFont3',			4  => 'PlaceObject',
			91 => 'DefineFont4',			26 => 'PlaceObject2',
			73 => 'DefineFontAlignZones',		70 => 'PlaceObject3',
			13 => 'DefineFontInfo',			5  => 'RemoveObject',
			62 => 'DefineFontInfo2',		28 => 'RemoveObject2',
			88 => 'DefineFontName',			65 => 'ScriptLimits',
			46 => 'DefineMorphShape',		9  => 'SetBackgroundColor',
			84 => 'DefineMorphShape2',		66 => 'SetTabIndex',
			86 => 'DefineSceneAndFrameLabelData',	1  => 'ShowFrame',
			2  => 'DefineShape',			15 => 'StartSound',
			22 => 'DefineShape2',			89 => 'StartSound2',
			32 => 'DefineShape3',			18 => 'SoundStreamHead',
			83 => 'DefineShape4',			45 => 'SoundStreamHead2',
			39 => 'DefineSprite',			19 => 'SoundStreamBlock',
			11 => 'DefineText',			76 => 'SymbolClass',
			33 => 'DefineText2',			61 => 'VideoFrame',
		);
		
		$tagCodeAndLength = $this->readUI16();
		if($tagCodeAndLength !== null) {
			$tagCode = ($tagCodeAndLength & 0xFFC0) >> 6;
			$tagLength = $tagCodeAndLength & 0x003F;
			if($tagLength == 0x003F) {
				// long format
				$tagLength = $this->readUI32();
				$headerLength = 6;
			} else {
				$headerLength = 2;
			}
			if(isset($TAG_NAMES[$tagCode])) {
				$tagName = $TAG_NAMES[$tagCode];
			} else {
				$tagName = "UnknownTag$tagCode";
			}
			
			$methodName = "read{$tagName}Tag";
			if(method_exists($this, $methodName)) {
				$tag = $this->$methodName($tagLength);
			} else {
				$tag = $this->readGenericTag($tagLength);
			}
			echo "$tagName ($tagLength)<br>";
			$tag->code = $tagCode;
			$tag->name = $tagName;
			$tag->length = $tagLength;
			$tag->headerLength = $headerLength;
			return $tag;
		}
	}
	
	protected function readGenericTag($tagLength) {
		$tag = new SWFGenericTag;
		if($tagLength > 0) {
			$tag->data = $this->readBytes($tagLength);
		}
		return $tag;
	}
	
	protected function readDefineSpriteTag($tagLength) {
		$tag = new SWFDefineSpriteTag;
		$tag->spriteId = $this->readUI16();
		$tag->frameCount = $this->readUI16();
		$bytesRemaining = $tagLength - 4;
		echo "<div style='margin-Left:2em; border: 1px dotted lightgrey'>";
		echo "<h3>Sprite #$tag->spriteId</h3>";
		while($bytesRemaining > 0 && ($child = $this->readTag())) {
			$tag->tags[] = $child;
			$bytesRemaining -= $child->headerLength + $child->length;
		}
		if($bytesRemaining) {
			$this->readBytes($bytesRemaining);
		}
		echo "</div>";
		return $tag;
	}
		
	protected function readDefineBinaryDataTag($tagLength) {
		$tag = new SWFDefineBinaryDataTag;
		$tag->objectId = $this->readUI16();
		$tag->reserved = $this->readUI32();
		$bytesRemaining = $tagLength - 6;
		
		if($bytesRemaining > 0) {
			// see if it isn't an embedded SWF file
			$signature = $this->readBytes(3);
			$bytesRemaining -= 3;
			if($signature == 'FWS' || $signature == 'CWS') {
				// create a partial stream and parse the file with a clone of $this
				$path = StreamPartial::add($this->input, $bytesRemaining, $signature);
				$stream = fopen($path, "rb");
				echo "<div style='margin-Left:2em; border: 1px dotted lightgrey'>";
				$parser = clone $this;
				$tag->swfFile = $parser->parse($stream);
				echo "</div>";
			} else {
				$tag->data = ($bytesRemaining) ? $signature . $this->readBytes($bytesRemaining) : $signature;
			}
		}
		return $tag;
	}
		
	protected function readBitFields($numBitsOffset, $numBitsWidth, $numFields) {		
		$first8Bits = sprintf("%08b", $this->readUI8());
		$valueBefore = bindec(substr($first8Bits, 0, $numBitsOffset));
		$numBits = bindec(substr($first8Bits, $numBitsOffset, $numBitsWidth));
		$totalNumBits = $numBits  * $numFields;
		$remainingBits = 8 - $numBitsOffset - $numBitsWidth;
		$numBytes = (($totalNumBits - $remainingBits) + 7) >> 3;			
		$bits = substr($first8Bits,  $numBitsOffset + $numBitsWidth);			
		for($i = 0; $i < $numBytes; $i++) {
			$bits .= sprintf("%08b", $this->readUI8());
		}
		$chunks = str_split($bits, $numBits);
		$results = array($valueBefore, $numBits);
		for($i = 0; $i < $numFields; $i++) {
			$results[] = bindec($chunks[$i]);
		}
		return $results;
	}
	
	protected function readRect() {
		$fields = $this->readBitFields(0, 5, 4);
		if($fields !== null) {
			$rect = new SWFRect;
			$rect->numBits = $fields[1];
			$rect->left = $fields[2];
			$rect->right = $fields[3];
			$rect->top = $fields[4];
			$rect->bottom = $fields[5];
			return $rect;
		}
	}
	
	protected function readUI8() {
		$byte = $this->readBytes(1);
		if($byte !== null) {
			return ord($byte);
		}
	}

	protected function readUI16() {
		$bytes = $this->readBytes(2);
		if($bytes !== null) {		
			$array = unpack('v', $bytes);
			return $array[1];
		}
	}

	protected function readUI32() {
		$bytes = $this->readBytes(4);
		if($bytes !== null) {		
			$array = unpack('V', $bytes);
			return $array[1];
		}
	}

	protected function readString($max) {
		$bytes = null;
		$read = 0;
		while(($byte = $this->readBytes(1)) !== null && $read < $max) {
			if($byte === "\0") {
				$bytes .= '';
				break;
			} else {
				$bytes .=  $byte;
			}
			$read++;
		}
		return $bytes;
	}
	
	protected function readBytes($count) {
		$bytes = fread($this->input, $count);
		$read = strlen($bytes);
		
		while($read < $count) {
			// sometimes fread() doesn't read the requested number of bytes
			// keep calling it til enough bytes are read or eof is encountered
			$chunk = fread($this->input, $count - $read);
			if($chunk != '') {
				$bytes .= $chunk;
				$read += strlen($chunk);
			} else {
				break;
			}			
		}
		return ($bytes != '') ? $bytes : null;
	}	
}

class SWFFile {
	public $version;
	public $compressed;
	public $compressionInfo;
	public $frameSize;
	public $tags = array();
}

class SWFRect {
	public $numBits;
	public $left;
	public $right;
	public $top;
	public $bottom;
}

class SWFTag {
	public $code;
	public $name;
	public $length;	
	public $headerLength;
}

class SWFGenericTag extends SWFTag {
	public $data;
}

class SWFDefineSpriteTag extends SWFTag {
	public $spriteId;
	public $frameCount;
	public $tags = array();
}

class SWFDefineBinaryDataTag extends SWFTag {
	public $objectId;
	public $reserved;
	public $data;
	public $swfFile;
}

?>