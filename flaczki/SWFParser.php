<?php

class SWFParser {
	protected $input;	
	protected $bitBuffer;
	protected $bitsRemaining;
	protected $swfVersion;
	protected $tagMask;
	
	public function parse(&$input, $tagNames = null) {
		if(gettype($input) == 'string') {
			$path = StreamMemory::add($input);
			$this->input = fopen($path, "rb");
		} else if(gettype($input) == 'resource') {
			$this->input = $input;
		} else {
			throw new Exception("Invalid input");
		}
		$this->bitBuffer = 0;
		$this->bitsRemaining = 0;
		
		$swfFile = new SWFFile;
		$bytesAvailable = 8;
		
		if($tagNames !== null) {
			$this->tagMask = array();
			foreach($tagNames as $tagName) {
				$this->tagMask[$tagName] = true;
			}
		} else {
			$this->tagMask = null;
		}
	
		// signature
		$signature = $this->readUI32($bytesAvailable);
		$swfFile->version = $this->swfVersion = ($signature >> 24) & 0xFF;
		$signature = $signature & 0xFFFFFF;
		
		// should be SWF or SWC
		if($signature != 0x535746 && $signature != 0x535743) {
			return false;
		}
		
		// file length (uncompressed)
		$fileLength = $this->readUI32($bytesAvailable);
		$bytesAvailable = $fileLength;
			
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
		$swfFile->frameSize = $this->readRect($bytesAvailable);
		
		// frame rate and count
		$swfFile->frameRate = $this->readUI16($bytesAvailable);
		$swfFile->frameCount = $this->readUI16($bytesAvailable);
		$swfFile->highestCharacterId = 0;
		
		while($tag = $this->readTag($bytesAvailable)) {
			$swfFile->tags[] = $tag;
			
			// see if the tag holds a character
			if($tag instanceof SWFCharacterTag) {
				if($tag->characterId > $swfFile->highestCharacterId) {
					$swfFile->highestCharacterId = $tag->characterId;
				}
			} else if($tag instanceof SWFGenericTag) {
				$className = "SWF{$tag->name}Tag";
				if(is_subclass_of($className, 'SWFCharacterTag')) {
					$array = unpack('v', $tag->data);
					$characterId = $array[1];
					if($characterId > $swfFile->highestCharacterId) {
						$swfFile->highestCharacterId = $characterId;
					}
				}
			}
		}
		$this->input = null;
		return $swfFile;
	}
	
	protected function readTag(&$bytesAvailable) {
		static $tagNameTable = array(
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
		
		$tagCodeAndLength = $this->readUI16($bytesAvailable);
		if($tagCodeAndLength !== null) {
			$tagCode = ($tagCodeAndLength & 0xFFC0) >> 6;
			$tagLength = $tagCodeAndLength & 0x003F;
			if($tagLength == 0x003F) {
				// long format
				$tagLength = $this->readUI32($bytesAvailable);
				$headerLength = 6;
			} else {
				$headerLength = 2;
			}
			if(isset($tagNameTable[$tagCode])) {
				$tagName = $tagNameTable[$tagCode];
			} else {
				$tagName = "UnknownTag$tagCode";
			}
			$bytesRemaining = $tagLength;
			
			$methodName = "read{$tagName}Tag";
			if(($this->tagMask === null || isset($this->tagMask[$tagName])) && method_exists($this, $methodName)) {
				$tag = $this->$methodName($bytesRemaining);
				if($bytesRemaining != 0) {
					$extra = $this->readBytes($bytesRemaining, $bytesRemaining);
				}
			} else {
				$tag = new SWFGenericTag;
				$tag->data = $this->readBytes($bytesRemaining, $bytesRemaining);
				$tag->code = $tagCode;
				$tag->name = $tagName;
				$tag->headerLength = $headerLength;
				$tag->length = $tagLength;
			}
			$bytesAvailable -= $tagLength;
			return $tag;
		}
	}
	
	protected function readCSMTextSettingsTag(&$bytesAvailable) {
		$tag = new SWFCSMTextSettingsTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->renderer = $this->readUB(2, $bytesAvailable);
		$tag->gridFit = $this->readUB(3, $bytesAvailable);
		$tag->reserved1 = $this->readUB(3, $bytesAvailable);
		$tag->thickness = $this->readFloat($bytesAvailable);
		$tag->sharpness = $this->readFloat($bytesAvailable);
		$tag->reserved2 = $this->readUI8($bytesAvailable);
		return $tag;
	}
	
	protected function readDefineBinaryDataTag(&$bytesAvailable) {
		$tag = new SWFDefineBinaryDataTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->reserved = $this->readUI32($bytesAvailable);
		
		if($bytesAvailable > 3) {
			// see if it isn't an embedded SWF file
			$signature = $this->readBytes(3, $bytesAvailable);
			if($signature == 'FWS' || $signature == 'CWS') {
				// create a partial stream and parse the file with a clone of $this
				$path = StreamPartial::add($this->input, $bytesAvailable, $signature);
				$stream = fopen($path, "rb");
				$parser = clone $this;
				$tag->swfFile = $parser->parse($stream);
				$bytesAvailable = 0;
			} else {
				$tag->data = $signature . $this->readBytes($bytesAvailable, $bytesAvailable);
			}
		} else {
			$tag->data = $this->readBytes($bytesAvailable, $bytesAvailable);
		}
		return $tag;
	}
	
	protected function readDefineBitsTag(&$bytesAvailable) {
		$tag = new SWFDefineBitsTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->imageData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	protected function readDefineBitsLosslessTag(&$bytesAvailable) {
		$tag = new SWFDefineBitsLosslessTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->format = $this->readUI8($bytesAvailable);
		$tag->width = $this->readUI16($bytesAvailable);
		$tag->height = $this->readUI16($bytesAvailable);
		if($tag->format == 3) {
			$tag->colorTableSize = $this->readUI8($bytesAvailable);
		}
		$tag->imageData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineBitsLossless2Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsLossless2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->format = $this->readUI8($bytesAvailable);
		$tag->width = $this->readUI16($bytesAvailable);
		$tag->height = $this->readUI16($bytesAvailable);
		if($tag->format == 3) {
			$tag->colorTableSize = $this->readUI8($bytesAvailable);
		}
		$tag->imageData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}

	protected function removeErroneousJPEGHeader(&$imageData) {
		// from the specs: "Before version 8 of the SWF file format, SWF files could contain an erroneous header of 0xFF, 0xD9, 0xFF, 0xD8 before the JPEG SOI marker."
		if($imageData[0] == "\xFF" && $imageData[1] == "\xD8") {
			$pos = 2;
			$size = strlen($imageData);
			while($pos + 2 < $size) {
				$array = unpack("n2", substr($imageData, $pos, 4));
				$marker = $array[1];
				$length = $array[2];
				if($marker == 0xFFD9) {
					if($length == 0xFFD8) {
						$imageData = substr($imageData, 0, $pos) . substr($imageData, $pos + 22);
						break;
					}
				} else if($marker == 0xFFDA) {
					break;
				} else {
					$pos += $length + 2;
				}
			}
		}
	}
	
	protected function readDefineBitsJPEG2Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsJPEG2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->imageData = $this->readBytes($bytesAvailable, $bytesAvailable);
		$this->removeErroneousJPEGheader($tag->imageData);
		return $tag;
	}

	protected function readDefineBitsJPEG3Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsJPEG3Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$alphaOffset = $this->readUI32($bytesAvailable);
		$tag->imageData = $this->readBytes($alphaOffset, $bytesAvailable);
		$tag->alphaData = $this->readBytes($bytesAvailable, $bytesAvailable);
		$this->removeErroneousJPEGheader($tag->imageData);
		return $tag;
	}

	protected function readDefineBitsJPEG4Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsJPEG4Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$alphaOffset = $this->readUI32($bytesAvailable);
		$tag->deblockingParam = $this->readUI16($bytesAvailable);
		$tag->imageData = $this->readBytes($alphaOffset, $bytesAvailable);
		$tag->alphaData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineButtonTag(&$bytesAvailable) {
		$tag = new SWFDefineButtonTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->characters = $this->readButtonRecords(1, $bytesAvailable);		
		$tag->actions = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineButton2Tag(&$bytesAvailable) {
		$tag = new SWFDefineButton2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$actionOffset = $this->readUI16($bytesAvailable);
		$tag->characters = $this->readButtonRecords(2, $bytesAvailable);
		$tag->actions = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineButtonCxFormTag(&$bytesAvailable) {
		$tag = new SWFDefineButtonCxFormTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->colorTransform = $this->readColorTransform($bytesAvailable);
		return $tag;
	}
	
	protected function readDefineButtonSoundTag(&$bytesAvailable) {
		$tag = new SWFDefineButtonSoundTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->overUpToIdleId = $this->readUI16($bytesAvailable);
		if($tag->overUpToIdleId) {
			$tag->overUpToIdleInfo = $this->readSoundInfo($bytesAvailable);
		}
		$tag->idleToOverUpId = $this->readUI16($bytesAvailable);
		if($tag->idleToOverUpId) {
			$tag->idleToOverUpInfo = $this->readSoundInfo($bytesAvailable);
		}
		$tag->overUpToOverDownId = $this->readUI16($bytesAvailable);
		if($tag->overUpToOverDownId) {
			$tag->overUpToOverDownInfo = $this->readSoundInfo($bytesAvailable);
		}
		$tag->overDownToOverUpId = $this->readUI16($bytesAvailable);
		if($tag->overDownToOverUpId) {
			$tag->overDownToOverUpInfo = $this->readSoundInfo($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readDefineEditTextTag(&$bytesAvailable) {
		$tag = new SWFDefineEditTextTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->bounds = $this->readRect($bytesAvailable);
		$tag->flags = $this->readUI16($bytesAvailable);
		if($tag->flags & SWFDefineEditTextTag::HasFont) {
			$tag->fontId = $this->readUI16($bytesAvailable);
			$tag->fontHeight = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFDefineEditTextTag::HasFontClass) {
			$tag->fontClass = $this->readString($bytesAvailable);
		}
		if($tag->flags & SWFDefineEditTextTag::HasTextColor) {
			$tag->textColor = $this->readRGBA($bytesAvailable);
		}
		if($tag->flags & SWFDefineEditTextTag::HasMaxLength) {
			$tag->maxLength = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFDefineEditTextTag::HasLayout) {
			$tag->align = $this->readUI8($bytesAvailable);
			$tag->leftMargin = $this->readUI16($bytesAvailable);
			$tag->rightMargin = $this->readUI16($bytesAvailable);
			$tag->indent = $this->readUI16($bytesAvailable);
			$tag->leading = $this->readUI16($bytesAvailable);
		}
		$tag->variableName = $this->readString($bytesAvailable);
		if($tag->flags & SWFDefineEditTextTag::HasText) {
			$tag->initialText = $this->readString($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readDefineFontTag($tag) {
		$tag = new SWFDefineFontTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$offsetTable = array();
		for($i = 0; $i < $glyphCount; $i++) {
			$offsetTable[] = $this->readUI16($bytesAvailable);
		}
		$glyphCount = $offsetTable[0] >> 1;
		$tag->glyphTable = array();
		for($i = 0; $i < $glyphCount; $i++) {
			$tag->glyphTable[] = $this->readShape($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readDefineFont2Tag(&$bytesAvailable) {
		$tag = new SWFDefineFont2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->languageCode = $this->readUI8($bytesAvailable);
		$nameLength = $this->readUI8($bytesAvailable);
		$tag->name = $this->readBytes($nameLength, $bytesAvailable);
		$tag->glyphCount = $glyphCount = $this->readUI16($bytesAvailable);
		$bytesAvailableBefore = $bytesAvailable;
		$offsetTable = array();
		if($tag->flags & SWFDefineFont2Tag::WideOffsets) {
			for($i = 0; $i < $glyphCount; $i++) {
				$offsetTable[] = $this->readUI32($bytesAvailable);
			}
			$codeTableOffset = $this->readUI32($bytesAvailable);
		} else {
			for($i = 0; $i < $glyphCount; $i++) {
				$offsetTable[] = $this->readUI16($bytesAvailable);
			}
			$codeTableOffset = $this->readUI16($bytesAvailable);
		}
		$tag->glyphTable = array();
		for($i = 0; $i < $glyphCount; $i++) {
			$tag->glyphTable[] = $this->readShape($bytesAvailable);
		}
		$offset = $bytesAvailableBefore - $bytesAvailable;
		if($tag->flags & SWFDefineFont2Tag::WideCodes) {
			for($i = 0; $i < $glyphCount; $i++) {
				$tag->codeTable[] = $this->readUI16($bytesAvailable);
			}
		} else {
			for($i = 0; $i < $glyphCount; $i++) {
				$tag->codeTable[] = $this->readUI8($bytesAvailable);
			}
		}
		if($tag->flags & SWFDefineFont2Tag::HasLayout || $bytesAvailable) {
			$tag->ascent = $this->readSI16($bytesAvailable);
			$tag->descent = $this->readSI16($bytesAvailable);
			$tag->leading = $this->readSI16($bytesAvailable);
			for($i = 0; $i < $glyphCount; $i++) {
				$tag->advanceTable[] = $this->readUI16($bytesAvailable);
			}
			for($i = 0; $i < $glyphCount; $i++) {
				$tag->boundTable[] = $this->readRect($bytesAvailable);
			}
			$kerningCount = $this->readUI16($bytesAvailable);
			if($tag->flags & SWFDefineFont2Tag::WideCodes) {
				for($i = 0; $i < $kerningCount; $i++) {
					$tag->kerningTable[] = $this->readWideKerningRecord($bytesAvailable);
				}
			} else {
				for($i = 0; $i < $kerningCount; $i++) {
					$tag->kerningTable[] = $this->readKerningRecord($bytesAvailable);
				}
			}
		}
		return $tag;
	}
	
	protected function readDefineFont3Tag(&$bytesAvailable) {
		$tag2 = $this->readDefineFont2Tag($bytesAvailable);
		$tag = new SWFDefineFont3Tag;
		foreach($tag2 as $name => $value) {
			$tag->$name = $value;
		}
		return $tag;
	}
	
	protected function readDefineFont4Tag(&$bytesAvailable) {
		$tag = new SWFDefineFont4Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->name = $this->readString($bytesAvailable);
		$tag->cffData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineFontAlignZonesTag(&$bytesAvailable) {
		$tag = new SWFDefineFontAlignZonesTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->tableHint = $this->readUB(2, $bytesAvailable);
		$tag->zoneTable = $this->readZoneRecords($bytesAvailable);
		return $tag;
	}
	
	protected function readDefineFontInfoTag(&$bytesAvailable) {
		$tag = new SWFDefineFontInfoTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$nameLength = $this->readUI8($bytesAvailable);
		$tag->name = $this->readBytes($nameLength, $bytesAvailable);
		$tag->flags = $this->readUI8();
		if($tag->flags & SWFDefineFontInfoTag::WideCodes) {
			while($bytesAvailable > 0) {
				$tag->codeTable[] = $this->readUI16($bytesAvailable);
			}
		} else {
			while($bytesAvailable > 0) {
				$tag->codeTable[] = $this->readU8($bytesAvailable);
			}
		}
		return $tag;
	}
	
	protected function readDefineFontInfo2Tag(&$bytesAvailable) {
		$tag = new SWFDefineFontInfoTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$nameLength = $this->readUI8($bytesAvailable);
		$tag->name = $this->readBytes($nameLength, $bytesAvailable);
		$tag->flags = $this->readUI8();
		$tag->languageCode = $this->readUI8();
		if($tag->flags & SWFDefineFontInfo2Tag::WideCodes) {
			while($bytesAvailable > 0) {
				$tag->codeTable[] = $this->readUI16($bytesAvailable);
			}
		} else {
			while($bytesAvailable > 0) {
				$tag->codeTable[] = $this->readU8($bytesAvailable);
			}
		}
		return $tag;
	}
	
	protected function readDefineFontNameTag(&$bytesAvailable) {
		$tag = new SWFDefineFontNameTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->name = $this->readString($bytesAvailable);
		$tag->copyright = $this->readString($bytesAvailable);
		return $tag;
	}
	
	protected function readDefineMorphShapeTag(&$bytesAvailable) {
		$tag = new SWFDefineMorphShapeTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->startBounds = $this->readRect($bytesAvailable);
		$tag->endBounds = $this->readRect($bytesAvailable);
		$tag->morphShape = $this->readMorphShapeWithStyle(3, $bytesAvailable);		// use structures of DefineShape3
		return $tag;
	}
	
	protected function readDefineMorphShape2Tag(&$bytesAvailable) {
		$tag = new SWFDefineMorphShape2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->startBounds = $this->readRect($bytesAvailable);
		$tag->endBounds = $this->readRect($bytesAvailable);
		$tag->startEdgeBounds = $this->readRect($bytesAvailable);
		$tag->endEdgeBounds = $this->readRect($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->morphShape = $this->readMorphShapeWithStyle(4, $bytesAvailable);		// use structures of DefineShape4
		return $tag;
	}
	
	protected function readDefineScalingGridTag(&$bytesAvailable) {
		$tag = new SWFDefineScalingGridTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->splitter = $this->readRect($bytesAvailable);
		return $tag;
	}
	
	protected function readDefineSceneAndFrameLabelDataTag(&$bytesAvailable) {
		$tag = new SWFDefineSceneAndFrameLabelDataTag;
		$tag->sceneNames = $this->readEncodedStringTable(&$bytesAvailable);
		$tag->frameLabels = $this->readEncodedStringTable(&$bytesAvailable);
		return $tag;
	}
	
	protected function readDefineShapeTag(&$bytesAvailable) {
		$tag = new SWFDefineShapeTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->shapeBounds = $this->readRect($bytesAvailable);
		$tag->shape = $this->readShapeWithStyle(1, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineShape2Tag(&$bytesAvailable) {
		$tag = new SWFDefineShape2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->shapeBounds = $this->readRect($bytesAvailable);
		$tag->shape = $this->readShapeWithStyle(2, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineShape3Tag(&$bytesAvailable) {
		$tag = new SWFDefineShape3Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->shapeBounds = $this->readRect($bytesAvailable);
		$tag->shape = $this->readShapeWithStyle(3, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineShape4Tag(&$bytesAvailable) {
		$tag = new SWFDefineShape4Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->shapeBounds = $this->readRect($bytesAvailable);
		$tag->edgeBounds = $this->readRect($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->shape = $this->readShapeWithStyle(4, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineSoundTag(&$bytesAvailable) {
		$tag = new SWFDefineSoundTag;
		$tag->format = $this->readUB(4, $bytesAvailable);
		$tag->sampleRate = $this->readUB(2, $bytesAvailable);
		$tag->sampleSize = $this->readUB(1, $bytesAvailable);
		$tag->type = $this->readUB(1, $bytesAvailable);
		$tag->sampleCount = $this->readUI32($bytesAvailable);
		$tag->data = $this->readBytes( $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineSpriteTag(&$bytesAvailable) {
		$tag = new SWFDefineSpriteTag;
		$tag->characterId = $this->readUI16(&$bytesAvailable);
		$tag->frameCount = $this->readUI16(&$bytesAvailable);
		while($bytesAvailable > 0 && ($child = $this->readTag($bytesAvailable))) {
			$tag->tags[] = $child;
		}
		return $tag;
	}
	
	protected function readDefineTextTag(&$bytesAvailable) {
		$tag = new SWFDefineTextTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->bounds = $this->readRect($bytesAvailable);
		$tag->matrix = $this->readMatrix($bytesAvailable);
		$tag->glyphBits = $this->readUI8($bytesAvailable);
		$tag->advanceBits = $this->readUI8($bytesAvailable);
		$tag->textRecords = $this->readTextRecords($tag->glyphBits, $tag->advanceBits, 1, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineText2Tag(&$bytesAvailable) {
		$tag = new SWFDefineText2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->bounds = $this->readRect($bytesAvailable);
		$tag->matrix = $this->readMatrix($bytesAvailable);
		$tag->glyphBits = $this->readUI8($bytesAvailable);
		$tag->advanceBits = $this->readUI8($bytesAvailable);
		$tag->textRecords = $this->readTextRecords($tag->glyphBits, $tag->advanceBits, 2, $bytesAvailable);
		return $tag;
	}
	
	protected function readDefineVideoStreamTag(&$bytesAvailable) {
		$tag = new SWFDefineVideoStreamTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->frameCount = $this->readUI16($bytesAvailable);
		$tag->width = $this->readUI16($bytesAvailable);
		$tag->height = $this->readUI16($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->codecId = $this->readUI8($bytesAvailable);
		return $tag;
	}
	
	protected function readDoABCTag(&$bytesAvailable) {
		$tag = new SWFDoABCTag;
		$tag->flags = $this->readUI32($bytesAvailable);
		$tag->byteCodeName = $this->readString($bytesAvailable);
		
		if($bytesAvailable > 0) {
			// create a partial stream and parse the bytecodes using ABCParser				
			$path = StreamPartial::add($this->input, $bytesAvailable);
			$stream = fopen($path, "rb");
			$parser = new ABCParser;
			$tag->abcFile = $parser->parse($stream);
			$bytesAvailable = 0;
		}
		return $tag;
	}
	
	protected function readDoActionTag(&$bytesAvailable) {
		$tag = new SWFDoActionTag;
		$tag->actions = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readDoInitActionTag(&$bytesAvailable) {
		$tag = new SWFDoInitActionTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->actions = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readEndTag(&$bytesAvailable) {
		$tag = new SWFEndTag;
		return $tag;
	}
	
	protected function readEnableDebuggerTag(&$bytesAvailable) {
		$tag = new SWFEnableDebuggerTag;
		$tag->password = $this->readString($bytesAvailable);
		return $tag;
	}
	
	protected function readEnableDebugger2Tag(&$bytesAvailable) {
		$tag = new SWFEnableDebugger2Tag;
		$tag->reserved = $this->readUI16($bytesAvailable);
		$tag->password = $this->readString($bytesAvailable);
		return $tag;
	}
	
	protected function readExportAssetsTag(&$bytesAvailable) {
		$tag = new SWFExportAssetsTag;
		$tag->names = $this->readStringTable($bytesAvailable);
		return $tag;
	}
	
	protected function readFileAttributesTag(&$bytesAvailable) {
		$tag = new SWFFileAttributesTag;
		$tag->flags = $this->readUI32($bytesAvailable);
		return $tag;
	}
	
	protected function readFrameLabelTag(&$bytesAvailable) {
		$tag = new SWFFrameLabelTag;
		$tag->name = $this->readString($bytesAvailable);
		if($bytesAvailable) {
			$tag->anchor = $tag->readString($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readImportAssetsTag(&$bytesAvailable) {
		$tag = new SWFImportAssetsTag;
		$tag->url = $this->readString($bytesAvailable);
		$tag->names = $this->readStringTable($bytesAvailable);
		return $tag;
	}
	
	protected function readImportAssets2Tag(&$bytesAvailable) {
		$tag = new SWFImportAssets2Tag;
		$tag->url = $this->readString($bytesAvailable);
		$tag->reserved1 = $this->readUI8($bytesAvailable);
		$tag->reserved2 = $this->readUI8($bytesAvailable);
		$tag->names = $this->readStringTable($bytesAvailable);
		return $tag;
	}
	
	protected function readJPEGTablesTag(&$bytesAvailable) {
		$tag = new SWFJPEGTablesTag;
		$tag->jpegData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readMetaDataTag(&$bytesAvailable) {
		$tag = new SWFMetadataTag;
		$tag->metadata = $this->readBytes($bytesAvailable - 1, $bytesAvailable);
		$this->readbytes(1, $bytesAvailable);
		return $tag;
	}
	
	protected function readPlaceObjectTag(&$bytesAvailable) {
		$tag = new SWFPlaceObjectTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->depth = $this->readUI16($bytesAvailable);
		$tag->matrix = $this->readMatrix($bytesAvailable);
		if($bytesAvailable) {
			$tag->colorTransform = $this->readColorTransform($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readPlaceObject2Tag(&$bytesAvailable) {
		$tag = new SWFPlaceObject2Tag;
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->depth = $this->readUI16($bytesAvailable);
		if($tag->flags & SWFPlaceObject2Tag::HasCharacter) {
			$tag->characterId = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasMatrix) {
			$tag->matrix = $this->readMatrix($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasColorTransform) {
			$tag->colorTransform = $this->readColorTransformAlpha($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasRatio) {
			$tag->ratio = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasName) {
			$tag->name = $this->readString($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasClipDepth) {
			$tag->clipDepth = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasClipActions) {
			$reserved = $this->readUI16($bytesAvailable);
			$tag->allEventFlags = ($this->swfVersion >= 6) ? $this->readUI32($bytesAvailable) : $this->readUI16($bytesAvailable);
			$tag->clipActions = $this->readClipActions($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readPlaceObject3Tag(&$bytesAvailable) {
		$tag = new SWFPlaceObject3Tag;
		$tag->flags = $this->readUI16($bytesAvailable);
		$tag->depth = $this->readUI16($bytesAvailable);
		if($tag->flags & SWFPlaceObject3Tag::HasClassName) {
			$tag->className = $this->readString($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasCharacter) {
			$tag->characterId = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasMatrix) {
			$tag->matrix = $this->readMatrix($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasColorTransform) {
			$tag->colorTransform = $this->readColorTransformAlpha($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasRatio) {
			$tag->ratio = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasName) {
			$tag->name = $this->readString($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasClipDepth) {
			$tag->clipDepth = $this->readUI16($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasFilterList) {
			$tag->filters = $this->readFilters($bytesAvailable);			
		}
		if($tag->flags & SWFPlaceObject3Tag::HasBlendMode) {
			$tag->blendMode = $this->readUI8($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasCacheAsBitmap) {
			$tag->bitmapCache = $this->readUI8($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasClipActions) {
			$tag->clipActions = $this->readClipActions($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasVisibility) {
			$tag->visibility = $this->readUI8($bytesAvailable);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasBackgroundColor) {
			$tag->bitmapCacheBackgroundColor = $this->readRGBA($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readProtectTag(&$bytesAvailable) {
		$tag = new SWFProtectTag;
		if($bytesAvailable) {
			$tag->password = $this->readString($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readRemoveObjectTag(&$bytesAvailable) {
		$tag = new SWFRemoveObjectTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->depth = $this->readUI16($bytesAvailable);
		return $tag;
	}
	
	protected function readRemoveObject2Tag(&$bytesAvailable) {
		$tag = new SWFRemoveObject2Tag;
		$tag->depth = $this->readUI16($bytesAvailable);
		return $tag;
	}
	
	protected function readScriptLimitsTag(&$bytesAvailable) {
		$tag = new SWFScriptLimitsTag;
		$tag->maxRecursionDepth = $this->readUI16($bytesAvailable);
		$tag->scriptTimeoutSeconds = $this->readUI16($bytesAvailable);
		return $tag;
	}
	
	protected function readSetBackgroundColorTag(&$bytesAvailable) {
		$tag = new SWFSetBackgroundColorTag;
		$tag->color = $this->readRGB($bytesAvailable);
		return $tag;
	}
	
	protected function readSetTabIndexTag(&$bytesAvailable) {
		$tag->depth = $this->readUI16($bytesAvailable);
		$tag->tabIndex = $this->readUI16($bytesAvailable);
		return $tag;
	}
	
	protected function readShowFrameTag(&$bytesAvailable) {
		$tag = new SWFShowFrameTag;
		return $tag;
	}
	
	protected function readSoundStreamBlockTag(&$bytesAvailable) {
		$tag = new SWFSoundStreamBlockTag;
		$tag->data = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readSoundStreamHeadTag(&$bytesAvailable) {
		$tag = new SWFSoundStreamHeadTag;
		$tag->flags = $this->readUI16($bytesAvailable);
		$tag->sampleCount = $this->readUI16($bytesAvailable);
		if($tag->flags & 0xF000 == 0x2000) {
			$tag->latencySeek = $this->readS16($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readSoundStreamHead2Tag(&$bytesAvailable) {
		$tag = new SWFSoundStreamHead2Tag;		
		$this->readUB(4, $bytesAvailable);
		$tag->playbackSampleRate = $this->readUB(2, $bytesAvailable);
		$tag->playbackSampleSize = $this->readUB(1, $bytesAvailable);
		$tag->playbackType = $this->readUB(1, $bytesAvailable);
		$tag->format = $this->readUB(4, $bytesAvailable);
		$tag->sampleRate = $this->readUB(2, $bytesAvailable);
		$tag->sampleSize = $this->readUB(1, $bytesAvailable);
		$tag->type = $this->readUB(1, $bytesAvailable);
		$tag->sampleCount = $this->readUI16($bytesAvailable);
		if($tag->format == SWFDefineSoundTag::FormatMP3) {
			$tag->latencySeek = $this->readS16($bytesAvailable);
		}
		return $tag;
	}
	
	protected function readStartSoundTag(&$bytesAvailable) {
		$tag = new SWFStartSoundTag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->info = $this->readSoundInfo($bytesAvailable);
		return $tag;
	}
	
	protected function readStartSound2Tag(&$bytesAvailable) {
		$tag = new SWFStartSound2Tag;
		$tag->className = $this->readString($bytesAvailable);
		$tag->info = $this->readSoundInfo($bytesAvailable);
		return $tag;
	}
	
	protected function readSymbolClassTag(&$bytesAvailable) {
		$tag = new SWFSymbolClassTag;
		$tag->names = $this->readStringTable($bytesAvailable);
		return $tag;
	}
	
	protected function readVideoFrameTag(&$bytesAvailable) {
		$tag = new SWFVideoFrameTag;
		$tag->streamId = $this->readUI16($bytesAvailable);
		$tag->frameNumber = $this->readUI16($bytesAvailable);
		$tag->data = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}
	
	protected function readZoneRecords(&$bytesAvailable) {
		$records = array();
		while($bytesAvailable) {
			$records[] = $this->readZoneRecord($bytesAvailable);
		}
		return $records;
	}
	
	protected function readZoneRecord(&$bytesAvailable) {
		$record = new SWFZoneRecord;
		$numZoneData = $this->readUI8($bytesAvailable);
		$record->zoneData1 = $this->readUI16($bytesAvailable);
		$record->zoneData2 = $this->readUI16($bytesAvailable);
		$record->flags = $this->readUI8($bytesAvailable);
		$record->alignmentCoordinate = $this->readUI16($bytesAvailable);
		$record->range = $this->readUI16($bytesAvailable);
		return $record;
	}
	
	protected function readKerningRecord(&$bytesAvailable) {
		$kern = new SWFKerningRecord;
		$kern->code1 = $this->readUI8($bytesAvailable);
		$kern->code2 = $this->readUI8($bytesAvailable);
		$kern->adjustment = $this->readUI16($bytesAvailable);
		return $kern;
	}
	
	protected function readWideKerningRecord(&$bytesAvailable) {
		$kern = new SWFKerningRecord;
		$kern->code1 = $this->readUI16($bytesAvailable);
		$kern->code2 = $this->readUI16($bytesAvailable);
		$kern->adjustment = $this->readUI16($bytesAvailable);
		return $kern;
	}
	
	protected function readClipActions(&$bytesAvailable) {
		$clipActions = array();
		while($clipAction = $this->readClipAction($bytesAvailable)) {
			$clipActions[] = $clipAction;
		}
		return $clipActions;
	}
	
	protected function readClipAction(&$bytesAvailable) {
		$eventFlags = ($this->swfVersion >= 6) ? $this->readUI32($bytesAvailable) : $this->readUI16($bytesAvailable);
		if($eventFlags) {
			$clipAction = new SWFClipAction;
			$clipAction->eventFlags = $eventFlags;
			$actionLength = $this->readUI32($bytesAvailable);
			if($clipAction->eventFlags & SWFClipAction::KeyPress) {
				$clipAction->keyCode = $this->readUI8($bytesAvailable);
			}
			$clipAction->actions = $this->readBytes($actionLength, $bytesAvailable);
			return $clipAction;
		}
	}
	
	protected function readFilters(&$bytesAvailable) {
		$filters = array();
		$count = $this->readUI8(&$bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$filters[] = $this->readFilter($bytesAvailable);
		}
		return $filters;
	}
	
	protected function readFilter(&$bytesAvailable) {
		$id = $this->readUI8($bytesAvailable);
		if($id == 0) {
			$filter = new SWFDropShadowFilter;
			$filter->shadowColor = $this->readRGBA($bytesAvailable);
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->angle = $this->readSI32($bytesAvailable);
			$filter->distance = $this->readSI32($bytesAvailable);
			$filter->strength = $this->readSI16($bytesAvailable);
			$filter->flags = $this->readUB(3, $bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 1) {
			$filter = new SWFBlurFilter;
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 2) {
			$filter = new SWFGlowFilter;
			$filter->color = $this->readRGBA($bytesAvailable);
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->strength = $this->readSI16($bytesAvailable);
			$filter->flags = $this->readUB(3, $bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 3) {
			$filter = new SWFBevelFilter;
			// the spec incorrectly states that shadowColor comes first
			$filter->highlightColor = $this->readRGBA($bytesAvailable);
			$filter->shadowColor = $this->readRGBA($bytesAvailable);
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->angle = $this->readSI32($bytesAvailable);
			$filter->distance = $this->readSI32($bytesAvailable);
			$filter->strength = $this->readSI16($bytesAvailable);
			$filter->flags = $this->readUB(4, $bytesAvailable);
			$filter->passes = $this->readUB(4, $bytesAvailable);
		} else if($id == 4) {
			$filter = new SWFGradientGlowFilter;
			$colorCount = $this->readUI8($bytesAvailable);
			for($i = 0; $i < $colorCount; $i++) {
				$filter->colors[] = $this->readRGBA($bytesAvailable);
			}
			for($i = 0; $i < $colorCount; $i++) {
				$filter->ratios[] = $this->readUI8($bytesAvailable);
			}
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->angle = $this->readSI32($bytesAvailable);
			$filter->distance = $this->readSI32($bytesAvailable);
			$filter->strength = $this->readSI16($bytesAvailable);
			$filter->flags = $this->readUB(4, $bytesAvailable);
			$filter->passes = $this->readUB(4, $bytesAvailable);
		} else if($id == 5) {
			$filter = new SWFConvolutionFilter;
			$filter->matrixX = $this->readUI8($bytesAvailable);
			$filter->matrixY = $this->readUI8($bytesAvailable);
			$filter->divisor = $this->readFloat($bytesAvailable);
			$filter->bias = $this->readFloat($bytesAvailable);
			$filter->matrix = array();
			$count = $filter->matrixX * $filter->matrixY;
			for($i = 0; $i < $count; $i++) {
				$filter->matrix[] = $this->readFloat($bytesAvailable);
			}
			$filter->defaultColor = $this->readRGBA($bytesAvailable);
			$filter->flags = $this->readUI8($bytesAvailable);
		} else if($id == 6) {
			$filter = new SWFColorMatrixFilter;
			$filter->matrix = array();
			for($i = 0; $i < 20; $i++) {
				$filter->matrix[] = $this->readFloat($bytesAvailable);
			}
		} else if($id == 7) {
			$filter = new SWFGradientBevelFilter;
			$colorCount = $this->readUI8($bytesAvailable);
			for($i = 0; $i < $colorCount; $i++) {
				$filter->colors[] = $this->readRGBA($bytesAvailable);
			}
			for($i = 0; $i < $colorCount; $i++) {
				$filter->ratios[] = $this->readUI8($bytesAvailable);
			}
			$filter->blurX = $this->readSI32($bytesAvailable);
			$filter->blurY = $this->readSI32($bytesAvailable);
			$filter->angle = $this->readSI32($bytesAvailable);
			$filter->distance = $this->readSI32($bytesAvailable);
			$filter->strength = $this->readSI16($bytesAvailable);
			$filter->flags = $this->readUB(4, $bytesAvailable);
			$filter->passes = $this->readUB(4, $bytesAvailable);
		}
		return $filter;
	}

	protected function readTextRecords($glyphBits, $advanceBits, $version, &$bytesAvailable) {
		$records = array();
		while($record = $this->readTextRecord($glyphBits, $advanceBits, $version, $bytesAvailable)) {
			$records[] = $record;
		}
		return $records;
	}
	
	protected function readTextRecord($glyphBits, $advanceBits, $version, &$bytesAvailable) {
		$flags = $this->readUI8($bytesAvailable);
		if($flags) {
			$record = new SWFTextRecord;
			$record->flags = $flags;
			if($record->flags & SWFTextRecord::HasFont) {
				$record->fontId = $this->readUI16($bytesAvailable);
			}
			if($record->flags & SWFTextRecord::HasColor) {
				$record->textColor = ($version >= 2) ? $this->readRGBA($bytesAvailable) : $this->readRGB($bytesAvailable);
			}
			if($record->flags & SWFTextRecord::HasXOffset) {
				$record->xOffset = $this->readSI16($bytesAvailable);
			}
			if($record->flags & SWFTextRecord::HasYOffset) {
				$record->yOffset = $this->readSI16($bytesAvailable);
			}
			if($record->flags & SWFTextRecord::HasFont) {
				$record->textHeight = $this->readUI16($bytesAvailable);
			}
			$record->glyphs = $this->readGlyphEntries($glyphBits, $advanceBits, $bytesAvailable);
			return $record;
		}
	}
	
	protected function readGlyphEntries($glyphBits, $advanceBits, &$bytesAvailable) {
		$glyphs = array();
		$count = $this->readUI8($bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$glyph = $this->readGlyphEntry($glyphBits, $advanceBits, $bytesAvailable);
			$glyph->bytesAvailable = $bytesAvailable;
			$glyph->count = $count;
			$glyphs[] = $glyph;
		}
		return $glyphs;
	}
	
	protected function readGlyphEntry($glyphBits, $advanceBits, &$bytesAvailable) {
		$glyph = new SWFGlyphEntry;
		$glyph->index = $this->readUB($glyphBits, $bytesAvailable);
		$glyph->advance = $this->readUB($advanceBits, $bytesAvailable);
		return $glyph;
	}
	
	protected function readSoundInfo(&$bytesAvailable) {
		$info = new SWFSoundInfo;
		$info->flags = $this->readUI8($bytesAvailable);
		if($info->flags & SWFSoundInfo::HasInPoint) {
			$info->inPoint = $this->readUI32($bytesAvailable);
		}
		if($info->flags & SWFSoundInfo::HasOutPoint) {
			$info->outPoint = $this->readUI32($bytesAvailable);
		}
		if($info->flags & SWFSoundInfo::HasLoops) {
			$info->loopCount = $this->readUI32($bytesAvailable);
		}
		if($info->flags & SWFSoundInfo::HasEnvelope) {
			$info->envelopes = $this->readSoundEnvelopes($bytesAvailable);
		}
		return $info;
	}
	
	protected function readSoundEnvelopes(&$bytesAvailable) {
		$envelopes = array();
		$count = $this->readUI8($bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$envelope = new SWFSoundEnvelope;
			$envelope->position44 = $this->readUI32($bytesAvailable);
			$envelope->leftLevel = $this->readUI16($bytesAvailable);
			$envelope->rightLevel = $this->readUI16($bytesAvailable);
		}
		$envelopes[] = $envelope;
		return $envelopes;
	}
	
	protected function readButtonRecords($version, &$bytesAvailable) {
		$records = array();
		while($record = $this->readButtonRecord($version, $bytesAvailable)) {
			$records[] = $record;
		}
		return $records;
	}
	
	protected function readButtonRecord($version, &$bytesAvailable) {
		$flags = $this->readUI8($bytesAvailable);
		if($flags) {
			$record = new SWFButtonRecord;
			$record->flags = $flags;
			$record->characterId = $this->readUI16($bytesAvailable);
			$record->placeDepth = $this->readUI16($bytesAvailable);
			$record->matrix = $this->readMatrix($bytesAvailable);
			if($version == 2) {
				$record->colorTransform = $this->readColorTransformAlpha($bytesAvailable);
			}
			if($version == 2 && $record->flags & SWFButtonRecord::HasFilterList) {
				$record->filters = $this->readFilters($bytesAvailable);
			}
			if($version == 2 && $record->flags & SWFButtonRecord::HasBlendMode) {
				$record->blendMode = $this->readUI8($bytesAvailable);
			}
			return $record;
		}
	}
	
	protected function readShape(&$bytesAvailable) {
		$shape = new SWFShape;
		$shape->numFillBits = $this->readUB(4, $bytesAvailable);
		$shape->numLineBits = $this->readUB(4, $bytesAvailable);
		$shape->edges = $this->readShapeRecords($shape->numFillBits, $shape->numLineBits, 1, &$bytesAvailable);
		return $shape;
	}
	
	protected function readShapeWithStyle($version, &$bytesAvailable) {
		$shape = new SWFShapeWithStyle;
		$shape->fillStyles = $this->readFillStyles($version, $bytesAvailable);
		$shape->lineStyles = $this->readLineStyles($version, $bytesAvailable);
		$shape->numFillBits = $this->readUB(4, $bytesAvailable);
		$shape->numLineBits = $this->readUB(4, $bytesAvailable);
		$shape->edges = $this->readShapeRecords($shape->numFillBits, $shape->numLineBits, $version, &$bytesAvailable);
		return $shape;
	}
	
	protected function readMorphShapeWithStyle($version, &$bytesAvailable) {
		$shape = new SWFMorphShapeWithStyle;
		$offset = $this->readUI32($bytesAvailable);
		$shape->fillStyles = $this->readMorphFillStyles($bytesAvailable);
		$shape->lineStyles = $this->readMorphLineStyles($version, $bytesAvailable);
		$shape->startNumFillBits = $this->readUB(4, $bytesAvailable);
		$shape->startNumLineBits = $this->readUB(4, $bytesAvailable);
		$shape->startEdges = $this->readShapeRecords($shape->startNumFillBits, $shape->startNumLineBits, $version, &$bytesAvailable);
		$shape->endNumFillBits = $this->readUB(4, $bytesAvailable);
		$shape->endNumLineBits = $this->readUB(4, $bytesAvailable);		
		$shape->endEdges = $this->readShapeRecords($shape->endNumFillBits, $shape->endNumLineBits, $version, &$bytesAvailable);
		return $shape;
	}
		
	protected function readShapeRecords($numFillBits, $numLineBits, $version, &$bytesAvailable) {
		$records = array();
		while($bytesAvailable) {
			if($this->readUB(1, $bytesAvailable)) {
				// edge
				if($this->readUB(1, $bytesAvailable)) {
					// straight
					$line = new SWFStraightEdge;
					$line->numBits = $this->readUB(4, $bytesAvailable) + 2;
					if($this->readUB(1, $bytesAvailable)) {
						// general line
						$line->deltaX = $this->readSB($line->numBits, $bytesAvailable);
						$line->deltaY = $this->readSB($line->numBits, $bytesAvailable);
					} else {
						if($this->readUB(1, $bytesAvailable)) {
							// vertical
							$line->deltaX = 0;
							$line->deltaY = $this->readSB($line->numBits, $bytesAvailable);
						} else {
							// horizontal 
							$line->deltaX = $this->readSB($line->numBits, $bytesAvailable);
							$line->deltaY = 0;
						}
					}
					$records[] = $line;
				} else {
					// curve
					$curve = new SWFQuadraticCurve;
					$curve->numBits = $this->readUB(4, $bytesAvailable) + 2;
					$curve->controlDeltaX = $this->readSB($curve->numBits, $bytesAvailable);
					$curve->controlDeltaY = $this->readSB($curve->numBits, $bytesAvailable);
					$curve->anchorDeltaX = $this->readSB($curve->numBits, $bytesAvailable);
					$curve->anchorDeltaY = $this->readSB($curve->numBits, $bytesAvailable);
					$records[] = $curve;
				}
			} else {
				$flags = $this->readUB(5, $bytesAvailable);
				if(!$flags) {
					break;
				} else {
					// style change
					$change = new SWFStyleChange;
					if($flags & 0x01) {
						$change->numMoveBits = $this->readSB(5, $bytesAvailable);
						$change->moveDeltaX = $this->readSB($change->numMoveBits, $bytesAvailable);
						$change->moveDeltaY = $this->readSB($change->numMoveBits, $bytesAvailable);
					}
					if($flags & 0x02) {
						$change->fillStyle0 = $this->readUB($numFillBits, $bytesAvailable);
					}
					if($flags & 0x04) {
						$change->fillStyle1 = $this->readUB($numFillBits, $bytesAvailable);
					}
					if($flags & 0x08) {
						$change->lineStyle = $this->readUB($numLineBits, $bytesAvailable);
					}
					if($flags & 0x10) {
						$change->newFillStyles = $this->readFillStyles($version, $bytesAvailable);
						$change->newLineStyles = $this->readLineStyles($version, $bytesAvailable);
						$change->numFillBits = $numFillBits = $this->readUB(4, $bytesAvailable);
						$change->numLineBits = $numLineBits = $this->readUB(4, $bytesAvailable);
					}
					$records[] = $change;
				}
			}
		}
		$this->alignToByte();
		return $records;
	}

	protected function readFillStyles($version, &$bytesAvailable) {
		$count = $this->readUI8($bytesAvailable);
		if($count == 0xFF && $version > 1) {
			$count = $this->readUI16($bytesAvailable);
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = $this->readFillStyle($version, $bytesAvailable);
		}
		return $styles;
	}

	protected function readFillStyle($version, &$bytesAvailable) {
		$style = new SWFFillStyle;
		$style->type = $this->readUI8($bytesAvailable);
		if($style->type == 0x00) {
			$style->color = ($version >= 3) ? $this->readRGBA($bytesAvailable) : $this->readRGB($bytesAvailable);
		} 
		if($style->type == 0x10 || $style->type == 0x12 || $style->type == 0x13) {
			$style->gradientMatrix = $this->readMatrix($bytesAvailable);
			if($style->type == 0x13) {
				$style->gradient = $this->readFocalGradient($version, $bytesAvailable);
			} else {
				$style->gradient = $this->readGradient($version, $bytesAvailable);
			}
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$style->bitmapId = $this->readUI16($bytesAvailable);
			$style->bitmapMatrix = $this->readMatrix($bytesAvailable);
		}
		return $style;
	}
	
	protected function readMorphFillStyles(&$bytesAvailable) {
		$count = $this->readUI8($bytesAvailable);
		if($count == 0xFF) {
			$count = $this->readUI16($bytesAvailable);
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = $this->readMorphFillStyle($bytesAvailable);
		}
		return $styles;
	}
	
	protected function readMorphFillStyle(&$bytesAvailable) {
		$style = new SWFMorphFillStyle;
		$style->type = $this->readUI8($bytesAvailable);
		if($style->type == 0x00) {
			$style->startColor = $this->readRGBA($bytesAvailable);
			$style->endColor = $this->readRGBA($bytesAvailable);
		} 
		if($style->type == 0x10 || $style->type == 0x12) {
			$style->startGradientMatrix = $this->readMatrix($bytesAvailable);
			$style->endGradientMatrix = $this->readMatrix($bytesAvailable);
			$style->gradient = $this->readMorphGradient($bytesAvailable);
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$style->bitmapId = $this->readUI16($bytesAvailable);
			$style->startBitmapMatrix = $this->readMatrix($bytesAvailable);
			$style->endBitmapMatrix = $this->readMatrix($bytesAvailable);
		}
		return $style;
	}
	
	protected function readLineStyles($version, &$bytesAvailable) {
		$count = $this->readUI8($bytesAvailable);
		if($count == 0xFF && $version > 1) {
			$count = $this->readUI16($bytesAvailable);
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = ($version >= 4) ? $this->readLineStyle2($version, $bytesAvailable) : $this->readLineStyle($version, $bytesAvailable);
		}
		return $styles;
	}
		
	protected function readLineStyle2($version, &$bytesAvailable) {
		$style = new SWFLineStyle2;
		$style->width = $this->readUI16($bytesAvailable);
		$style->startCapStyle = $this->readUB(2, $bytesAvailable);
		$style->joinStyle = $this->readUB(2, $bytesAvailable);		
		$style->flags = $this->readUB(10, $bytesAvailable);
		$style->endCapStyle = $this->readUB(2, $bytesAvailable);
		if($style->joinStyle == SWFLineStyle2::JoinStyleMiter) {
			$style->miterLimitFactor = $this->readUI16($bytesAvailable);
		}
		if($style->flags & SWFLineStyle2::HasFill) {
			$style->fillStyle = $this->readFillStyle($version, $bytesAvailable);
		} else {
			$style->color = $this->readRGBA($bytesAvailable);
		}
		return $style;		
	}
	
	protected function readLineStyle($version, &$bytesAvailable) {
		$style = new SWFLineStyle;
		$style->width = $this->readUI16($bytesAvailable);
		$style->color = ($version >= 3) ? $this->readRGBA($bytesAvailable) : $this->readRGB($bytesAvailable);
		return $style;
	}
	
	protected function readMorphLineStyles($version, &$bytesAvailable) {
		$count = $this->readUI8($bytesAvailable);
		if($count == 0xFF) {
			$count = $this->readUI16($bytesAvailable);
		}
		$styles = array();
		for($i = 0; $i < $count; $i++) {
			$styles[] = ($version >= 4) ? $this->readMorphLineStyle2($bytesAvailable) : $this->readMorphLineStyle($bytesAvailable);
		}
		return $styles;
	}
	
	protected function readMorphLineStyle2(&$bytesAvailable) {
		$style = new SWFLineStyle2;
		$style->startWidth = $this->readUI16($bytesAvailable);
		$style->endWidth = $this->readUI16($bytesAvailable);
		$style->startCapStyle = $this->readUB(2, $bytesAvailable);
		$style->joinStyle = $this->readUB(2, $bytesAvailable);		
		$style->flags = $this->readUB(10, $bytesAvailable);
		$style->endCapStyle = $this->readUB(2, $bytesAvailable);
		if($style->joinStyle == SWFLineStyle2::JoinStyleMiter) {
			$style->miterLimitFactor = $this->readUI16($bytesAvailable);
		}
		if($style->flags & SWFLineStyle2::HasFill) {
			$style->fillStyle = $this->readMorphFillStyle($bytesAvailable);
		} else {
			$style->startColor = $this->readRGBA($bytesAvailable);
			$style->endColor = $this->readRGBA($bytesAvailable);
		}
		return $style;		
	}
	
	protected function readMorphLineStyle(&$bytesAvailable) {
		$style = new SWFMorphLineStyle;
		$style->startWidth = $this->readUI16($bytesAvailable);
		$style->endWidth = $this->readUI16($bytesAvailable);
		$style->startColor = $this->readRGBA($bytesAvailable);
		$style->endColor = $this->readRGBA($bytesAvailable);
		return $style;
	}
	
	protected function readGradient($version, &$bytesAvailable) {
		$gradient = new SWFGradient;
		$gradient->spreadMode = $this->readUB(2, $bytesAvailable);
		$gradient->interpolationMode = $this->readUB(2, $bytesAvailable);
		$gradient->controlPoints = $this->readGradientControlPoints($version, $bytesAvailable);
		return $gradient;
	}
	
	protected function readFocalGradient($version, &$bytesAvailable) {
		$gradient = new SWFFocalGradient;
		$gradient->spreadMode = $this->readUB(2, $bytesAvailable);
		$gradient->interpolationMode = $this->readUB(2, $bytesAvailable);
		$gradient->controlPoints = $this->readGradientControlPoints($version, $bytesAvailable);
		$gradient->focalPoint = $this->readSI16($bytesAvailable);
		return $gradient;
	}
	
	protected function readGradientControlPoints($version, &$bytesAvailable) {
		$controlPoints = array();
		$count = $this->readUB(4, $bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$controlPoint = new SWFGradientControlPoint;
			$controlPoint->ratio = $this->readUI8($bytesAvailable);
			$controlPoint->color = ($version >= 3) ? $this->readRGBA($bytesAvailable) : $this->readRGB($bytesAvailable);
			$controlPoints[] = $controlPoint;
		}
		return $controlPoints;
	}
	
	protected function readMorphGradient(&$bytesAvailable) {
		$gradient = new SWFMorphGradient;
		$gradient->records = array();
		$count = $this->readUI8($bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$record = new SWFMorphGradientRecord;
			$record->startRatio = $this->readUI8($bytesAvailable);
			$record->startColor = $this->readRGBA($bytesAvailable);
			$record->endRatio = $this->readUI8($bytesAvailable);
			$record->endColor = $this->readRGBA($bytesAvailable);
			$gradient->records[] = $record;
		}
		return $gradient;
	}
	
	protected function readColorTransformAlpha(&$bytesAvailable) {
		$transform = new SWFColorTransformAlpha;
		$hasAddTerms = $this->readUB(1, $bytesAvailable);
		$hasMultTerms = $this->readUB(1, $bytesAvailable);
		$transform->numBits = $this->readUB(4, $bytesAvailable);
		if($hasMultTerms) {
			$transform->redMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->greenMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->blueMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->alphaMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
		}
		if($hasAddTerms) {
			$transform->redAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->greenAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->blueAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->alphaAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
		}
		$this->alignToByte();
		return $transform;
	}
	
	protected function readColorTransform(&$bytesAvailable) {
		$transform = new SWFColorTransform;
		$hasAddTerms = $this->readUB(1, $bytesAvailable);
		$hasMultTerms = $this->readUB(1, $bytesAvailable);
		$transform->numBits = $this->readUB(4, $bytesAvailable);
		if($hasMultTerms) {
			$transform->redMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->greenMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->blueMultTerm = $this->readSB($transform->numBits, $bytesAvailable);
		}
		if($hasAddTerms) {
			$transform->redAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->greenAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
			$transform->blueAddTerm = $this->readSB($transform->numBits, $bytesAvailable);
		}
		$this->alignToByte();
		return $transform;
	}
	
	protected function readMatrix(&$bytesAvailable) {
		$matrix = new SWFMatrix;		
		if($this->readUB(1, $bytesAvailable)) {
			$matrix->nScaleBits = $this->readUB(5, $bytesAvailable);
			$matrix->scaleX = $this->readSB($matrix->nScaleBits, $bytesAvailable);
			$matrix->scaleY = $this->readSB($matrix->nScaleBits, $bytesAvailable);
		}
		if($this->readUB(1, $bytesAvailable)) {
			$matrix->nRotateBits = $this->readUB(5, $bytesAvailable);
			$matrix->rotateSkew0 = $this->readSB($matrix->nRotateBits, $bytesAvailable);
			$matrix->rotateSkew1 = $this->readSB($matrix->nRotateBits, $bytesAvailable);
		}
		$matrix->nTraslateBits = $this->readUB(5, $bytesAvailable);
		$matrix->translateX = $this->readSB($matrix->nTraslateBits, $bytesAvailable);
		$matrix->translateY = $this->readSB($matrix->nTraslateBits, $bytesAvailable);
		$this->alignToByte();
		return $matrix;
	}
	
	protected function readRect(&$bytesAvailable) {
		$rect = new SWFRect;
		$rect->numBits = $this->readUB(5, $bytesAvailable);
		$rect->left = $this->readSB($rect->numBits, $bytesAvailable);
		$rect->right = $this->readSB($rect->numBits, $bytesAvailable);
		$rect->top = $this->readSB($rect->numBits, $bytesAvailable);
		$rect->bottom = $this->readSB($rect->numBits, $bytesAvailable);
		$this->alignToByte($bytesAvailable);
		return $rect;
	}
	
	protected function readARGB(&$bytesAvailable) {
		$rgb = new SWFRGBA;
		$rgb->alpha = $this->readUI8($bytesAvailable);
		$rgb->red = $this->readUI8($bytesAvailable);
		$rgb->green = $this->readUI8($bytesAvailable);
		$rgb->blue = $this->readUI8($bytesAvailable);
		return $rgb;
	}
	
	protected function readRGBA(&$bytesAvailable) {
		$rgb = new SWFRGBA;
		$rgb->red = $this->readUI8($bytesAvailable);
		$rgb->green = $this->readUI8($bytesAvailable);
		$rgb->blue = $this->readUI8($bytesAvailable);
		$rgb->alpha = $this->readUI8($bytesAvailable);
		return $rgb;
	}
		
	protected function readRGB(&$bytesAvailable) {
		$rgb = new SWFRGBA;
		$rgb->red = $this->readUI8($bytesAvailable);
		$rgb->green = $this->readUI8($bytesAvailable);
		$rgb->blue = $this->readUI8($bytesAvailable);
		$rgb->alpha = 255;
		return $rgb;
	}
		
	protected function readUI8(&$bytesAvailable) {
		$this->alignToByte();
		$byte = $this->readBytes(1, $bytesAvailable);
		if($byte !== null) {
			return ord($byte);
		}
	}

	protected function readUI16(&$bytesAvailable) {
		$this->alignToByte();
		$bytes = $this->readBytes(2, $bytesAvailable);
		if($bytes !== null) {
			$array = unpack('v', $bytes);
			return $array[1];
		}
	}

	protected function readSI16(&$bytesAvailable) {
		$value = $this->readUI16($bytesAvailable);
		if($value & 0x00008000) {
			$value |= -1 << 16;
		}
		return $value;
	}
	
	protected function readUI32(&$bytesAvailable) {
		$this->alignToByte();
		$bytes = $this->readBytes(4, $bytesAvailable);
		if($bytes !== null) {		
			$array = unpack('V', $bytes);
			return $array[1];
		}
	}

	protected function readEncodedUI32(&$bytesAvailable) {
		$this->alignToByte();
		$result = null;
		$shift = 0;
		do {
			$byte = $this->readUI8($bytesAvailable);
			if($byte !== null) {
				$result |= ($byte & 0x7F) << $shift;
				$shift += 7;
			}
		} while($byte & 0x80);
		return $result;
	}
	
	protected function readSI32(&$bytesAvailable) {
		$value = $this->readUI32($bytesAvailable);
		if($value & 0x80000000) {
			$value |= -1 << 32;
		}
		return $value;
	}
	
	protected function readFloat(&$bytesAvailable) {
		$this->alignToByte();
		$bytes = $this->readBytes(4, $bytesAvailable);
		if($bytes !== null) {		
			$array = unpack('f', $bytes);
			return $array[1];
		}
	}

	protected function readEncodedStringTable(&$bytesAvailable) {
		$strings = array();
		$count = $this->readEncodedUI32($bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$index = $this->readEncodedUI32($bytesAvailable);
			$strings[$index] = $this->readString($bytesAvailable);
		}
		return $strings;
	}
	
	protected function readStringTable(&$bytesAvailable) {
		$strings = array();
		$count = $this->readUI16($bytesAvailable);
		for($i = 0; $i < $count; $i++) {
			$index = $this->readUI16($bytesAvailable);
			$strings[$index] = $this->readString($bytesAvailable);
		}
		return $strings;
	}

	protected function readString(&$bytesAvailable) {
		$this->alignToByte();
		$bytes = '';
		while(($byte = $this->readBytes(1, $bytesAvailable)) !== null) {
			if($byte == "\0") {
				break;
			} else {
				$bytes .=  $byte;
			}
		}
		return $bytes;
	}
	
	protected function readSB($count, &$bytesAvailable) {
		$value = $this->readUB($count, $bytesAvailable);
		if($value & (1 << $count - 1)) {
			// negative
			$value |= -1 << $count;
		}
		return $value;
	}
	
	protected function readUB($count, &$bytesAvailable) {
		// the next available bit is always at the 31st bit of the buffer
		while($this->bitsRemaining < $count) {
			$byte = ord($this->readBytes(1, $bytesAvailable));
			$this->bitBuffer = $this->bitBuffer | ($byte << (24 - $this->bitsRemaining));
			$this->bitsRemaining += 8;
		}
		
		$value = ($this->bitBuffer >> (32 - $count)) & ~(-1 << $count);
		$this->bitsRemaining -= $count;
		$this->bitBuffer = (($this->bitBuffer << $count) & (-1 << (32 - $this->bitsRemaining))) & 0xFFFFFFFF;	// mask 32 bits in case of 64 bit system
		return $value;
	}
	
	protected function alignToByte() {
		$this->bitsRemaining = $this->bitBuffer = 0;
	}
	
	protected function readBytes($count, &$bytesAvailable) {
		$toRead = min($count, $bytesAvailable);
		if($toRead > 0) {
			$bytes = fread($this->input, $toRead);
			$read = strlen($bytes);
			
			while($read < $toRead) {
				$chunk = fread($this->input, min($toRead - $read, 32768));
				if($chunk != '') {
					$bytes .= $chunk;
					$read += strlen($chunk);
				} else {
					break;
				}			
			}
			$bytesAvailable -= $read;
		} else {
			$read = 0;
		}
		return ($read > 0) ? $bytes : null;
	}	
}

?>