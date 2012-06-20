<?php

class SWFParser {
	protected $input;	
	protected $bitBuffer = 0;
	protected $bitsRemaining = 0;
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
		
		while($tag = $this->readTag($bytesAvailable)) {
			$swfFile->tags[] = $tag;
		}
		
		$this->input = null;
		return $swfFile;
	}
	
	public function addTags( /* tag names ...*/ ) {
		$list = func_get_args();
		foreach($list as $tagName) {
			if($tagName == self::ALL_TAGS) {
				$this->tagMask = null;
			} else if($this->tagMask !== null) {
				$this->tagMask[$tagName] = true;
			}
		}
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
					echo "<h1>$methodName: $bytesAvailable</h1>";
					$extra = $this->readBytes($bytesRemaining, $bytesRemaining);
				}
			} else {
				//echo "<div style='color: red'>No $methodName</div>\n";
				$tag = new SWFGenericTag;
				$tag->data = $this->readBytes($bytesRemaining, $bytesRemaining);
				$tag->code = $tagCode;
				$tag->name = $tagName;
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
		$tag->thinkness = $this->readUI32($bytesAvailable);
		$tag->sharpness = $this->readUI32($bytesAvailable);
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
	
	protected function readDefineBitsJPEG2Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsJPEG2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->imageData = $this->readBytes($bytesAvailable, $bytesAvailable);
		return $tag;
	}

	protected function readDefineBitsJPEG3Tag(&$bytesAvailable) {
		$tag = new SWFDefineBitsJPEG3Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$alphaOffset = $this->readUI32($bytesAvailable);
		$tag->imageData = $this->readBytes($alphaOffset, $bytesAvailable);
		$tag->alphaData = $this->readBytes($bytesAvailable, $bytesAvailable);
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
	
	protected function readDefineFont2Tag(&$bytesAvailable) {
		$tag = new SWFDefineFont2Tag;
		$tag->characterId = $this->readUI16($bytesAvailable);
		$tag->flags = $this->readUI8($bytesAvailable);
		$tag->languageCode = $this->readUI8($bytesAvailable);
		$nameLength = $this->readUI8($bytesAvailable);
		$tag->name = $this->readBytes($nameLength, $bytesAvailable);
		$glyphCount = $this->readUI16($bytesAvailable);
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
		if($tag->flags & SWFDefineFont2Tag::HasLayout) {
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
		$tag->fontName = $this->readString($bytesAvailable);
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
		$tag->names = $this->readEncodedStringTable(&$bytesAvailable);
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
	
	protected function readDefineVideoStream(&$bytesAvailable) {
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
		$tag->reserved = $this->readU16($bytesAvailable);
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
		$tag->metadata = $this->readBytes($bytesAvailable, $bytesAvailable);
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
	
	protected function SWFScriptLimitsTag(&$bytesAvailable) {
		$tag = new SWFScriptLimitsTag;
		$tag->maxRecursionDepth = $this->readU16($bytesAvailable);
		$tag->scriptTimeoutSeconds = $this->readU16($bytesAvailable);
		return $tag;
	}
	
	protected function readSetBackgroundColorTag(&$bytesAvailable) {
		$tag = new SWFSetBackgroundColorTag;
		$tag->color = $this->readRGB($bytesAvailable);
		return $tag;
	}
	
	protected function readSetTabIndexTag(&$bytesAvailable) {
		$tag->depth = $this->readU16($bytesAvailable);
		$tag->tabIndex = $this->readU16($bytesAvailable);
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
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->angle = $this->readUI32($bytesAvailable);
			$filter->distance = $this->readUI32($bytesAvailable);
			$filter->strength = $this->readUI16($bytesAvailable);
			$filter->flags = $this->readUB(3, $bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 1) {
			$filter = new SWFBlurFilter;
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 2) {
			$filter = new SWFGlowFilter;
			$filter->color = $this->readRGBA($bytesAvailable);
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->strength = $this->readUI16($bytesAvailable);
			$filter->flags = $this->readUB(3, $bytesAvailable);
			$filter->passes = $this->readUB(5, $bytesAvailable);
		} else if($id == 3) {
			$filter = new SWFBevelFilter;
			$filter->shadowColor = $this->readRGBA($bytesAvailable);
			$filter->highlightColor = $this->readRGBA($bytesAvailable);
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->angle = $this->readUI32($bytesAvailable);
			$filter->distance = $this->readUI32($bytesAvailable);
			$filter->strength = $this->readUI16($bytesAvailable);
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
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->angle = $this->readUI32($bytesAvailable);
			$filter->distance = $this->readUI32($bytesAvailable);
			$filter->strength = $this->readUI16($bytesAvailable);
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
			$filter->blurX = $this->readUI32($bytesAvailable);
			$filter->blurY = $this->readUI32($bytesAvailable);
			$filter->angle = $this->readUI32($bytesAvailable);
			$filter->distance = $this->readUI32($bytesAvailable);
			$filter->strength = $this->readUI16($bytesAvailable);
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
		$gradient->focalPoint = $this->readUI16($bytesAvailable);
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
			if(strlen($bytes) != 4) {
				dump_backtrace();
			}
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
		if($value & (1 << $count)) {
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

class SWFFile {
	public $version;
	public $compressed;
	public $compressionInfo;
	public $frameSize;
	public $tags = array();
}

class SWFGenericTag {
	public $code;
	public $name;
	public $length;
	public $data;
}

class SWFCharacterTag {
	public $characterId;
}

class SWFCSMTextSettingsTag {
	const RendererNormal		= 0;
	const RendererAdvanced		= 1;
	
	const GridFitNone		= 0;
	const GridFitPixel		= 1;
	const GridFitSubpixel		= 2;

	public $characterId;
	public $renderer;
	public $gridFit;
	public $thinkness;
	public $sharpness;
	public $reserved1;
	public $reserved2;
}

class SWFEndTag {
}

class SWFDefineBitsTag {
	public $jpegData;
}

class SWFDefineBinaryDataTag extends SWFCharacterTag {
	public $reserved;
	public $data;
	public $swfFile;
}

class SWFDefineBits extends SWFCharacterTag {
	public $imageData;
}

class SWFDefineBitsLosslessTag extends SWFCharacterTag {
	public $format;
	public $width;
	public $height;
	public $colorTableSize;
	public $imageData;
}

class SWFDefineBitsLossless2Tag extends SWFDefineBitsLosslessTag {
}

class SWFDefineBitsJPEG2Tag extends SWFCharacterTag {
	public $imageData;
}

class SWFDefineBitsJPEG3Tag extends SWFDefineBitsJPEG2Tag {
	public $alphaData;
}

class SWFDefineBitsJPEG4Tag extends SWFDefineBitsJPEG3Tag {
	public $deblockingParam;
}

class SWFDefineButtonTag extends SWFCharacterTag {
	public $characters;
	public $actions;	
}

class SWFDefineButton2Tag extends SWFDefineButtonTag {
	const TrackAsMenu	= 0x01;

	public $flags;
}

class SWFDefineEditTextTag extends SWFCharacterTag {
	const HasFontClass	= 0x8000;
	const AutoSize		= 0x4000;
	const HasLayout		= 0x2000;
	const NoSelect		= 0x1000;
	const Border		= 0x0800;
	const WasStatic		= 0x0400;
	const HTML		= 0x0200;
	const UseOutlines	= 0x0100;	
	const HasText		= 0x0080;
	const WordWrap		= 0x0040;
	const Multiline		= 0x0020;
	const Password		= 0x0010;
	const ReadOnly		= 0x0008;
	const HasTextColor	= 0x0004;
	const HasMaxLength	= 0x0002;
	const HasFont		= 0x0001;

	public $bounds;
	public $flags;
	public $fontId;
	public $fontHeight;
	public $fontClass;
	public $textColor;
	public $maxLength;
	public $align;
	public $leftMargin;
	public $rightMargin;
	public $indent;
	public $leading;
	public $variableName;
	public $initialText;
}

class SWFDefineFontTag extends SWFCharacterTag {
	public $glyphTable;
}

class SWFDefineFont2Tag extends SWFDefineFontTag {
	const HasLayout		= 0x80;
	const ShiftJIS		= 0x40;
	const SmallText		= 0x20;
	const ANSI		= 0x10;
	const WideOffsets	= 0x08;
	const WideCodes		= 0x04;
	const Italic		= 0x02;
	const Bold		= 0x01;

	public $flags;
	public $fontName;
	public $ascent;
	public $descent;
	public $leading;
	public $languageCode;
	public $codeTable = array();
	public $advanceTable = array();
	public $boundTable = array();
	public $kerningTable = array();
}

class SWFDefineFont3Tag extends SWFDefineFont2Tag {
}

class SWFDefineFont4Tag extends SWFCharacterTag {
	public $flags;
	public $fontName;
	public $cffData;
}

class SWFDefineFontAlignZonesTag {
	public $characterId;
	public $tableHint;
	public $zoneTable;
}

class SWFDefineMorphShapeTag extends SWFCharacterTag {
	public $startBounds;
	public $endBounds;
	public $fillStyles;
	public $lineStyles;
	public $startEdges;
	public $endEdges;
}

class SWFDefineMorphShape2Tag extends SWFDefineMorphShapeTag {
	const UsesNonScalingStrokes	= 0x02;
	const UsesScalingStrokes	= 0x01;

	public $flags;
	public $startEdgeBounds;
	public $endEdgeBounds;
}

class SWFDefineScalingGridTag {
	public $characterId;
	public $splitter;
}

class SWFDefineSceneAndFrameLabelDataTag {
	public $sceneNames = array();
	public $frameLabels = array();
}

class SWFDefineShapeTag extends SWFCharacterTag {
	public $shapeBounds;
	public $shape;
}

class SWFDefineShape2Tag extends SWFDefineShapeTag {
}

class SWFDefineShape3Tag extends SWFDefineShape2Tag {
}

class SWFDefineShape4Tag extends SWFDefineShape3Tag {
	const UsesFillWindingRule	= 0x04;
	const UsesNonScalingStrokes	= 0x02;
	const UsesScalingStrokes	= 0x01;

	public $flags;
	public $edgeBounds;
}

class SWFDefineSoundTag extends SWFCharacterTag {
	const FormatUncompressed	= 0;
	const FormatADPCM		= 1;
	const FormatMP3			= 2;
	const FormatUncompressedLE	= 3;
	const FormatNellymoser16	= 4;
	const FormatNellymoser8		= 5;
	const FormatNellymoser		= 6;
	const FormatSpeex		= 11;

	const SampleRate55khz		= 0;
	const SampleRate11khz		= 1;
	const SampleRate22khz		= 2;
	const SampleRate44khz		= 3;
	
	const SampleSize8Bit		= 0;
	const SampleSize16Bit		= 1;
	
	const TypeMono			= 0;
	const TypeStereo		= 1;
	
	public $format;
	public $sampleSize;
	public $sampleRate;
	public $type;
	public $sampleCount;
	public $data;
}

class SWFDefineSpriteTag extends SWFCharacterTag {
	public $frameCount;
	public $tags = array();
}

class SWFDefineTextTag extends SWFCharacterTag {
	public $bounds;
	public $matrix;
	public $glyphBits;
	public $advanceBits;
	public $textRecords;
}

class SWFDefineText2Tag extends SWFDefineTextTag {
}

class SWFDefineVideoStreamTag extends SWFCharacterTag {
	public $characterId;
	public $frameCount;
	public $width;
	public $height;
	public $flags;
	public $codecId;
}

class SWFDoABCTag {
	public $flags;
	public $byteCodeName;
	public $byteCodes;
	
	public $abcFile;
}

class SWFDoActionTag {
	public $actions;
}

class SWFDoInitActionTag {
	public $actions;
}

class SWFEnableDebuggerTag {
	public $password;
}

class SWFEnableDebugger2Tag extends SWFEnableDebuggerTag {
	public $reserved;
}

class SWFExportAssetsTag {
	public $names = array();
}

class SWFFileAttributesTag {
	public $flags;
}

class SWFFrameLabelTag {
	public $name;
	public $anchor;
}

class SWFImportAssetsTag {
	public $names = array();
	public $url;
}

class SWFImportAssets2Tag extends SWFImportAssetsTag {
	public $reserved1;
	public $reserved2;	
}

class SWFJPEGTablesTag {
	public $jpegData;
}

class SWFMetadataTag {
	public $metadata;
}

class SWFPlaceObjectTag extends SWFCharacterTag {
	public $depth;
	public $matrix;
	public $colorTransform;
}

class SWFPlaceObject2Tag extends SWFPlaceObjectTag {
	const HasClipActions		= 0x80;
	const HasClipDepth		= 0x40;
	const HasName			= 0x20;
	const HasRatio			= 0x10;
	const HasColorTransform		= 0x08;
	const HasMatrix			= 0x04;
	const HasCharacter		= 0x02;
	const Move			= 0x01;

	public $flags;
	public $ratio;
	public $name;
	public $clipDepth;
	public $clipActions;
	public $allEventsFlags;
}

class SWFPlaceObject3Tag extends SWFPlaceObject2Tag {
	const HasBackgroundColor	= 0x4000;
	const HasVisibility		= 0x2000;
	const HasImage			= 0x1000;
	const HasClassName		= 0x0800;
	const HasCacheAsBitmap		= 0x0400;
	const HasBlendMode		= 0x0200;
	const HasFilterList		= 0x0100;
	
	public $className;
	public $filters;
	public $blendMode;
	public $bitmapCache;
	public $bitmapCacheBackgroundColor;
	public $visibility;
}

class SWFProtectTag {
	public $password;
}

class SWFRemoveObjectTag {
	public $characterId;
	public $depth;
}

class SWFRemoveObject2Tag {
	public $depth;
}

class SWFScriptLimitsTag {
	public $maxRecursionDepth;
	public $scriptTimeoutSeconds;
}

class SWFSetBackgroundColorTag {
	public $color;
}

class SWFSetTabIndexTag {
	public $depth;
	public $tabIndex;
}

class SWFShowFrameTag {
}

class SWFSoundStreamBlockTag {
	public $data;
}

class SWFSoundStreamHeadTag {
	public $playbackSampleSize;
	public $playbackSampleRate;
	public $playbackType;
	public $format;
	public $sampleSize;
	public $sampleRate;
	public $type;
	public $sampleCount;
	public $latencySeek;
}

class SWFSoundStreamHead2Tag extends SWFSoundStreamHeadTag {
}

class SWFStartSoundTag {
	public $info;
}

class SWFStartSound2Tag {
	public $className;
	public $info;
}

class SWFSymbolClassTag {
	public $names = array();
}

class SWFVideoFrameTag {
	public $streamId;
	public $frameNumber;
	public $data;
}

class SWFZoneRecord {
	public $zoneData1;
	public $zoneData2;
	public $flags;
	public $alignmentCoordinate;
	public $range;
}

class SWFKerningRecord {
	public $code1;
	public $code2;
	public $adjustment;
}

class SWFDropShadowFilter {
	public $shadowColor;
	public $highlightColor;
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFBlurFilter {
	public $blurX;
	public $blurY;
	public $passes;
} 

class SWFGlowFilter {
	public $color;
	public $blurX;
	public $blurY;
	public $strength;
	public $flags;
	public $passes;
} 

class SWFBevelFilter {
	public $shadowColor;
	public $highlightColor;
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
} 

class SWFGradientGlowFilter {
	public $colors = array();
	public $ratios = array();
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFConvolutionFilter {
	public $matrixX;
	public $matrixY;
	public $divisor;
	public $bias;
	public $matrix = array();
	public $defaultColor;
	public $flags;
} 

class SWFColorMatrixFilter {
	public $matrix = array();
}

class SWFGradientBevelFilter {
	public $colors = array();
	public $ratios = array();
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFSoundInfo {
	const SyncStop 		= 0x20;
	const SyncNoMultiple	= 0x10;
	const HasEnvelope	= 0x08;
	const HasLoops		= 0x04;
	const HasOutPoint	= 0x02;
	const HasInPoint	= 0x01;

	public $flags;
	public $inPoint;
	public $outPoint;
	public $loopCount;
	public $envelopes;
}

class SWFSoundEnvelope {
	public $position44;
	public $leftLevel;
	public $rightLevel;
}

class SWFButtonRecord {
	const HasBlendMode	= 0x20;
	const HasFilterList	= 0x10;
	const StateHitTest	= 0x08;
	const StateDown		= 0x04;
	const StateOver		= 0x02;
	const StateUp		= 0x01;

	public $flags;
	public $characterId;
	public $placeDepth;
	public $matrix;
	public $colorTransform;
	public $filters;
	public $blendMode;
}

class SWFClipAction {
	const Construct		= 0x00040000;
	const KeyPress		= 0x00020000;
	const DragOut		= 0x00010000;
	const DragOver		= 0x00008000;
	const RollOut		= 0x00004000;
	const RollOver		= 0x00002000;
	const ReleaseOutside	= 0x00001000;
	const Release		= 0x00000800;
	const Press		= 0x00000400;
	const Initialize	= 0x00000200;
	const Data		= 0x00000100;
	const KeyUp		= 0x00000080;
	const KeyDown		= 0x00000040;
	const MouseUp		= 0x00000020;
	const MouseDown		= 0x00000010;
	const MouseMove		= 0x00000008;
	const Unload		= 0x00000004;
	const EnterFrame	= 0x00000002;
	const Load		= 0x00000001;

	public $eventFlags;
	public $keyCode;
	public $actions;
}

class SWFGlyphEntry {
	public $index;
	public $advance;
}

class SWFShape {
	public $numFillBits;
	public $numLineBits;
	public $edges;
}

class SWFShapeWithStyle extends SWFShape {
	public $lineStyles;
	public $fillStyles;
}

class SWFMorphShapeWithStyle {
	public $lineStyles;
	public $fillStyles;
	public $startNumFillBits;
	public $startNumLineBits;
	public $endNumFillBits;
	public $endNumLineBits;
	public $startEdges;
	public $endEdges;
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

class SWFTextRecord {
	const HasFont			= 0x08;
	const HasColor			= 0x04;
	const HasYOffset		= 0x02;
	const HasXOffset		= 0x01;

	public $flags;
	public $fontId;
	public $textColor;
	public $xOffset;
	public $yOffset;
	public $textHeight;
	public $glyphs;
}

class SWFFillStyle {
	public $type;
	public $color;
	public $gradientMatrix;
	public $gradient;
	public $bitmapId;
	public $bitmapMatrix;
}

class SWFMorphFillStyle {
	public $type;
	public $startColor;
	public $endColor;
	public $startGradientMatrix;
	public $endGradientMatrix;
	public $gradient;
	public $bitmapId;
	public $startBitmapMatrix;
	public $endBitmapMatrix;
}

class SWFLineStyle {
	public $width;
	public $color;
}

class SWFLineStyle2 {
	const HasFill			= 0x0200;
	const NoHScale			= 0x0100;
	const NoVScale			= 0x0080;
	const PixelHinting		= 0x0040;
	const NoClose			= 0x0001;

	const CapStyleRound		= 0;
	const CapStyleNone		= 1;
	const CapStyleSquare		= 2;
	
	const JoinStyleRound		= 0;
	const JoinStyleBevel		= 1;
	const JoinStyleMiter		= 2;

	public $width;
	public $startCapStyle;
	public $endCapStyle;
	public $joinStyle;
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

class SWFMorphGradient {
	public $records;
}

class SWFMorphGraidentRecord {
	public $startRatio;
	public $startColor;
	public $endRatio;
	public $endColor;
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