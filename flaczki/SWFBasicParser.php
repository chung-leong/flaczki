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
			throw new Exception("Invalid input");
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
		
		$this->input = null;
		return $swfFile;
	}
	
	protected function readTag() {
		static $TAG_NAMES = array(
			74 => 'CSMTextSettings',		60 => 'DefineVideoStream',
			78 => 'DefineScalingGrid',		59 => 'DoInitAction',
			87 => 'DefineBinaryData',		82 => 'DoABC',
			6  => 'DefineBits',			12 => 'DoAction',
			21 => 'DefineBitsJPEG2',		58 => 'EnableDebugger',
			35 => 'DefineBitsJPEG3',		64 => 'EnableDebugger2',
			90 => 'DefineBitsJPEG4',		0  => 'End',
			20 => 'DefineBitsLossless',		56 => 'ExportAssets',
			36 => 'DefineBitsLossless2',		69 => 'FileAttributes',
			7  => 'DefineButton',			43 => 'FrameLabel',
			34 => 'DefineButton2',			57 => 'ImportAssets',
			23 => 'DefineButtonCxform',		71 => 'ImportAssets2',
			17 => 'DefineButtonSound',		8  => 'JPEGTables',
			37 => 'DefineEditText',			77 => 'Metadata',
			10 => 'DefineFont',			24 => 'Protect',
			48 => 'DefineFont2',			4  => 'PlaceObject',
			75 => 'DefineFont3',			26 => 'PlaceObject2',
			91 => 'DefineFont4',			70 => 'PlaceObject3',
			73 => 'DefineFontAlignZones',		5  => 'RemoveObject',
			13 => 'DefineFontInfo',			28 => 'RemoveObject2',
			62 => 'DefineFontInfo2',		65 => 'ScriptLimits',
			88 => 'DefineFontName',			9  => 'SetBackgroundColor',
			46 => 'DefineMorphShape',		86 => 'SetSceneAndFrameLabelData',
			84 => 'DefineMorphShape2',		66 => 'SetTabIndex',
			2  => 'DefineShape',			1  => 'ShowFrame',
			22 => 'DefineShape2',			15 => 'StartSound',
			32 => 'DefineShape3',			89 => 'StartSound2',
			83 => 'DefineShape4',			18 => 'SoundStreamHead',
			39 => 'DefineSprite',			45 => 'SoundStreamHead2',
			11 => 'DefineText',			19 => 'SoundStreamBlock',
			33 => 'DefineText2',			76 => 'SymbolClass',
			14 => 'DefineSound',			61 => 'VideoFrame',
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
				
				// we will need the character id 
				if(preg_match('/^Define/', $tagName)) {
					$array = unpack('v', $tag->data);
					$tag->characterId = $array[1];
				}
			}
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
		$tag->characterId = $this->readUI16();
		$tag->frameCount = $this->readUI16();
		$bytesRemaining = $tagLength - 4;
		while($bytesRemaining > 0 && ($child = $this->readTag())) {
			$tag->tags[] = $child;
			$bytesRemaining -= $child->headerLength + $child->length;
		}
		if($bytesRemaining) {
			$this->readBytes($bytesRemaining);
		}
		return $tag;
	}
		
	protected function readDefineBinaryDataTag($tagLength) {
		$tag = new SWFDefineBinaryDataTag;
		$tag->characterId = $this->readUI16();
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
				$parser = clone $this;
				$tag->swfFile = $parser->parse($stream);
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
			$chunk = fread($this->input, min($count - $read, 32768));
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
	public $characterId;
	public $data;
}

class SWFDefineSpriteTag extends SWFTag {
	public $characterId;
	public $frameCount;
	public $tags = array();
}

class SWFDefineBinaryDataTag extends SWFTag {
	public $characterId;
	public $reserved;
	public $data;
	public $swfFile;
}

?>