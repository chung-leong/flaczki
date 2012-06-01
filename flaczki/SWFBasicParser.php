<?php

class SWFBasicParser {

	protected $input;
	protected $bitBuffer = 0;
	protected $bitsRemaining = 0;
	
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
			$start = ftell($this->input);
			if(method_exists($this, $methodName)) {
				$tag = $this->$methodName($tagLength);
			} else {
				$tag = $this->readGenericTag($tagLength);
				
				// we will need the character id -- FIX ME: shouldn't be done like this
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
	
	protected function readDefineShape4Tag($tagLength) {
		$tag = new SWFDefineShapeTag;
		$tag->characterId = $this->readUI16();
		$tag->shapeBounds = $this->readRect();
		$tag->edgeBounds = $this->readRect();
		$tag->flags = $this->readUI8();
		$tag->shape = $this->readShapeWithStyle(4);
		return $tag;
	}
	
	protected function readDefineShape3Tag($tagLength) {
		$tag = new SWFDefineShapeTag;
		$tag->characterId = $this->readUI16();
		$tag->shapeBounds = $this->readRect();
		$tag->shape = $this->readShapeWithStyle(3);
		return $tag;
	}
	
	protected function readDefineShape2Tag($tagLength) {
		$tag = new SWFDefineShapeTag;
		$tag->characterId = $this->readUI16();
		$tag->shapeBounds = $this->readRect();
		$tag->shape = $this->readShapeWithStyle(2);
		return $tag;
	}
	
	protected function readDefineShapeTag($tagLength) {
		$tag = new SWFDefineShapeTag;
		$tag->characterId = $this->readUI16();
		$tag->shapeBounds = $this->readRect();
		$tag->shape = $this->readShapeWithStyle(1);
		return $tag;
	}

	protected function readShapeWithStyle($version) {
		$shape = new SWFShapeWithStyle;
		$shape->fillStyles = $this->readFillStyles($version);
		$shape->lineStyles = $this->readLineStyles($version);
		$shape->numFillBits = $numFillBits = $this->readUB(4);
		$shape->numLineBits = $numLineBits = $this->readUB(4);
		for(;;) {
			if($this->readUB(1)) {
				// edge
				if($this->readUB(1)) {
					// straight
					$line = new SWFStraightEdge;
					$line->numBits = $this->readUB(4) + 2;
					if($this->readUB(1)) {
						// general line
						$line->deltaX = $this->readSB($line->numBits);
						$line->deltaY = $this->readSB($line->numBits);
					} else {
						if($this->readUB(1)) {
							// vertical
							$line->deltaX = 0;
							$line->deltaY = $this->readSB($line->numBits);
						} else {
							// horizontal 
							$line->deltaX = $this->readSB($line->numBits);
							$line->deltaY = 0;
						}
					}
					$shape->records[] = $line;
				} else {
					// curve
					$curve = new SWFQuadraticCurve;
					$curve->numBits = $this->readUB(4) + 2;
					$curve->controlDeltaX = $this->readSB($curve->numBits);
					$curve->controlDeltaY = $this->readSB($curve->numBits);
					$curve->anchorDeltaX = $this->readSB($curve->numBits);
					$curve->anchorDeltaY = $this->readSB($curve->numBits);
					$shape->records[] = $curve;
				}
			} else {
				$flags = $this->readUB(5);
				if(!$flags) {
					break;
				} else {
					// style change
					$change = new SWFStyleChange;
					if($flags & 0x01) {
						$change->numMoveBits = $this->readSB(5);
						$change->moveDeltaX = $this->readSB($change->numMoveBits);
						$change->moveDeltaY = $this->readSB($change->numMoveBits);
					}
					if($flags & 0x02) {
						$change->fillStyle0 = $this->readUB($numFillBits);
					}
					if($flags & 0x04) {
						$change->fillStyle1 = $this->readUB($numFillBits);
					}
					if($flags & 0x08) {
						$change->lineStyle = $this->readUB($numLineBits);
					}
					if($flags & 0x10) {
						$change->newFillStyles = $this->readFillStyles($version);
						$change->newLineStyles = $this->readLineStyles($version);
						$change->numFillBits = $numFillBits = $this->readUB(4);
						$change->numLineBits = $numLineBits = $this->readUB(4);
					}
					$shape->records[] = $change;
				}
			}
		}
		return $shape;
	}
	
	protected function readFillStyles($version) {
		$count = $this->readUI8();
		if($count == 0xFF && $version > 1) {
			$count = $this->readUI16();
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = $this->readFillStyle($version);
		}
		return $styles;
	}

	protected function readFillStyle($version) {
		$style = new SWFFillStyle;
		$style->type = $this->readUI8();
		if($style->type == 0x00) {
			$style->color = ($version >= 3) ? $this->readRGBA() : $this->readRGB();
		} 
		if($style->type == 0x10 || $style->type == 0x12 || $style->type == 0x13) {
			$style->gradientMatrix = $this->readMatrix();
			if($style->type == 0x13) {
				$style->gradient = $this->readFocalGradient($version);
			} else {
				$style->gradient = $this->readGradient($version);
			}
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$style->bitmapId = $this->readUI16();
			$style->bitmapMatrix = $this->readMatrix();
		}
		return $style;
	}
	
	protected function readLineStyles($version) {
		$count = $this->readUI8();
		if($count == 0xFF && $version > 1) {
			$count = $this->readUI16();
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = ($version == 4) ? $this->readLineStyle2($version) : $this->readLineStyle($version);
		}
		return $styles;
	}
		
	protected function readLineStyle2($version) {
		$style = new SWFLineStyle2;
		$style->width = $this->readUI16();
		$style->flags = $this->readUI16();
		if(($style->flags & 0x0030) == 0x0020) {
			$style->miterLimitFactor = $this->readUI16();
		}
		if($style->flags & 0x0008) {
			$style->fillStyle = $this->readFillStyle($version);
		} else {
			$style->color = $this->readRGBA();
		}
		return $style;		
	}
	
	protected function readLineStyle($version) {
		$style = new SWFLineStyle;
		$style->width = $this->readUI16();
		$style->color = ($version >= 3) ? $this->readRGBA() : $this->readRGB();
		return $style;
	}
	
	protected function readGradient($version) {
		$gradient = new SWFGradient;
		$gradient->spreadMode = $this->readUB(2);
		$gradient->interpolationMode = $this->readUB(2);
		$gradient->controlPoints = $this->readGradientControlPoints($version);
		return $gradient;
	}
	
	protected function readFocalGradient($version) {
		$gradient = new SWFFocalGradient;
		$gradient->spreadMode = $this->readUB(2);
		$gradient->interpolationMode = $this->readUB(2);
		$gradient->controlPoints = $this->readGradientControlPoints($version);
		$gradient->focalPoint = $this->readUI16();
		return $gradient;
	}
	
	protected function readGradientControlPoints($version) {
		$controlPoints = array();
		$count = $this->readUB(4);
		for($i = 0; $i < $count; $i++) {
			$controlPoint = new SWFGradientControlPoint;
			$controlPoint->ratio = $this->readUI8();
			$controlPoint->color = ($version >= 3) ? $this->readRGBA() : $this->readRGB();
			$controlPoints[] = $controlPoint;
		}
		return $controlPoints;
	}
	
	protected function readColorTransformAlpha() {
		$transform = new SWFColorTransformAlpha;
		$hasAddTerms = $this->readUB(1);
		$hasMultTerms = $this->readUB(1);
		$transform->numBits = $this->readUB(4);
		if($hasMultTerms) {
			$transform->redMultTerm = $this->readSB($transform->numBits);
			$transform->greenMultTerm = $this->readSB($transform->numBits);
			$transform->blueMultTerm = $this->readSB($transform->numBits);
			$transform->alphaMultTerm = $this->readSB($transform->numBits);
		}
		if($hasAddTerms) {
			$transform->redAddTerm = $this->readSB($transform->numBits);
			$transform->greenAddTerm = $this->readSB($transform->numBits);
			$transform->blueAddTerm = $this->readSB($transform->numBits);
			$transform->alphaAddTerm = $this->readSB($transform->numBits);
		}
		$this->alignToByte();
		return $transform;
	}
	
	protected function readColorTransform() {
		$transform = new SWFColorTransform;
		$hasAddTerms = $this->readUB(1);
		$hasMultTerms = $this->readUB(1);
		$transform->numBits = $this->readUB(4);
		if($hasMultTerms) {
			$transform->redMultTerm = $this->readSB($transform->numBits);
			$transform->greenMultTerm = $this->readSB($transform->numBits);
			$transform->blueMultTerm = $this->readSB($transform->numBits);
		}
		if($hasAddTerms) {
			$transform->redAddTerm = $this->readSB($transform->numBits);
			$transform->greenAddTerm = $this->readSB($transform->numBits);
			$transform->blueAddTerm = $this->readSB($transform->numBits);
		}
		$this->alignToByte();
		return $transform;
	}
	
	protected function readMatrix() {
		$matrix = new SWFMatrix;		
		if($this->readUB(1)) {
			$matrix->nScaleBits = $this->readUB(5);
			$matrix->scaleX = $this->readSB($matrix->nScaleBits);
			$matrix->scaleY = $this->readSB($matrix->nScaleBits);
		}
		if($this->readUB(1)) {
			$matrix->nRotateBits = $this->readUB(5);
			$matrix->rotateSkew0 = $this->readSB($matrix->nRotateBits);
			$matrix->rotateSkew1 = $this->readSB($matrix->nRotateBits);
		}
		$matrix->nTraslateBits = $this->readUB(5);
		$matrix->translateX = $this->readSB($matrix->nTraslateBits);
		$matrix->translateY = $this->readSB($matrix->nTraslateBits);
		$this->alignToByte();
		return $matrix;
	}
	
	protected function readRect() {
		$rect = new SWFRect;
		$rect->numBits = $this->readUB(5);
		$rect->left = $this->readSB($rect->numBits);
		$rect->right = $this->readSB($rect->numBits);
		$rect->top = $this->readSB($rect->numBits);
		$rect->bottom = $this->readSB($rect->numBits);
		$this->alignToByte();
		return $rect;
	}
	
	protected function readARGB() {
		$rgb = new SWFRGBA;
		$rgb->alpha = $this->readUI8();
		$rgb->red = $this->readUI8();
		$rgb->green = $this->readUI8();
		$rgb->blue = $this->readUI8();
		return $rgb;
	}
	
	protected function readRGBA() {
		$rgb = new SWFRGBA;
		$rgb->red = $this->readUI8();
		$rgb->green = $this->readUI8();
		$rgb->blue = $this->readUI8();
		$rgb->alpha = $this->readUI8();
		return $rgb;
	}
		
	protected function readRGB() {
		$rgb = new SWFRGBA;
		$rgb->red = $this->readUI8();
		$rgb->green = $this->readUI8();
		$rgb->blue = $this->readUI8();
		$rgb->alpha = 255;
		return $rgb;
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
	
	protected function readSB($count) {
		$value = $this->readUB($count);
		if($value & (1 << $count)) {
			// negative
			$value |= -1 << $count;
		}
		return $value;
	}
	
	protected function readUB($count) {
		// the next available bit is always at the 31st bit of the buffer
		while($this->bitsRemaining < $count) {
			$this->bitBuffer = $this->bitBuffer | (ord(fread($this->input, 1)) << (24 - $this->bitsRemaining));
			$this->bitsRemaining += 8;
			$this->read++;
		}
		
		$value = ($this->bitBuffer >> (32 - $count)) & ~(-1 << $count);
		$this->bitsRemaining -= $count;
		$this->bitBuffer = (($this->bitBuffer << $count) & (-1 << (32 - $this->bitsRemaining))) & 0xFFFFFFFF;	// mask 32 bits in case of 64 bit system
		return $value;
	}
	
	protected function alignToByte() {
		$this->bitsRemaining = $this->bitBuffer = 0;
	}
	
	protected function readBytes($count) {
		$bytes = fread($this->input, $count);
		$read = strlen($bytes);
		if($this->bitsRemaining) {
			$this->bitsRemaining = $this->bitBuffer = 0;
		}
		
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

class SWFDefineShapeTag extends SWFTag {
	public $characterId;
	public $shapeBounds;
	public $edgeBounds;
	public $flags;
	public $shape;
}

class SWFShapeWithStyle {
	public $lineStyles;
	public $fillStyles;
	public $records;
	public $numFillBits;
	public $numLineBits;
}

class SWFStraightEdge {
	public $numBits;
	public $deltaX;
	public $deltaY;
}

class SWFQuadraticCurve {
	public $numBits;
	public $controlDeltaX;
	public $controlDeltaY;
	public $anchorDeltaX;
	public $anchorDeltaY;
}

class SWFStyleChange {
	public $numMoveBits;
	public $moveDeltaX;
	public $moveDeltaY;
	public $fillStyle0;
	public $fillStyle1;
	public $lineStyle;
	public $newFillStyles;
	public $newLineStyles;
	public $numFillBits;
	public $numLineBits;
}

class SWFFillStyle {
	public $type;
	public $color;
	public $gradientMatrix;
	public $gradient;
	public $bitmapId;
	public $bitmapMatrix;
}

class SWFLineStyle {
	public $width;
	public $color;
}

class SWFLineStyle2 {
	public $width;
	public $flags;
	public $miterLimitFactor;
	public $fillStyle;
	public $style;
}

class SWFFocalGradient extends SWFGradient {
	public $focalPoint;
}

class SWFGradient {
	public $spreadMode;
	public $interpolationMode;
	public $controlPoints;
}

class SWFGradientControlPoint {
	public $ratio;
	public $color;
}

class SWFColorTransform {
	public $numBits;
	public $redMultTerm;
	public $greenMultTerm;
	public $blueMultTerm;
	public $redAddTerm;
	public $greenAddTerm;
	public $blueAddTerm;
}

class SWFColorTransformAlpha extends SWFColorTransform {
	public $alphaMultTerm;
	public $alphaAddTerm;
}

class SWFMatrix {
	public $nScaleBits;
	public $scaleX;
	public $scaleY;
	public $nRotateBits;
	public $rotateSkew0;
	public $rotateSkew1;
	public $nTraslateBits;
	public $translateX;
	public $translateY;
}

class SWFRect {
	public $numBits;
	public $left;
	public $right;
	public $top;
	public $bottom;
}

class SWFRGBA {
	public $red;
	public $green;
	public $blue;
	public $alpha;
}

?>