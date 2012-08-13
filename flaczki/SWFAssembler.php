<?php

class SWFAssembler {

	protected $output;
	protected $bitBuffer;
	protected $bitsRemaining;
	protected $outputBuffer;
	protected $outputBufferStack;
	protected $adler32Context;
	protected $written;
	protected $swfVersion;
	
	public function assemble(&$output, $swfFile, $tearDown = false) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$this->output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
			$this->output = $output;
		} else {
			throw new Exception("Invalid output");
		}
		$this->written = 0;
		$this->bitBuffer = $this->bitsRemaining = 0;
		$this->outputBuffer = null;
		$this->outputBufferStack = array();
		$this->swfVersion = $swfFile->version;
		
		// convert all tags to generic ones first so we know the total length
		$tags = array();
		$swfFile->generic = array();
		foreach($swfFile->tags as &$tag) {
			if($tag instanceof SWFGenericTag) {
				$tags[] = $tag;
			} else {
				$tags[] = $this->createGenericTag($tag);
				if($tearDown) {
					// free the tag to conserve memory
					$tag = null;
				}
			}
		}
		unset($tag);

		// signature
		$signature = (($swfFile->compressed) ? 0x535743 : 0x535746) | ($swfFile->version << 24);
		$signature = $this->writeUI32($signature);
		
		// file length (uncompressed)
		$fileLength = 8 + ((($swfFile->frameSize->numBits * 4 + 5) + 7) >> 3) + 4;
		foreach($tags as $tag) {
			$fileLength += $tag->headerLength;
			$fileLength += $tag->length;
		}		
		$this->writeUI32($fileLength);
		
		if($swfFile->compressed) {
			fwrite($this->output, "\x78\x9C");		// zlib header
			$filter = stream_filter_append($this->output, "zlib.deflate");
			
			// calculate the adler32 checksum if we can use the hash extension
			// the Flash Player doesn't seem to mind if it is missing 
			if(function_exists('hash_init')) {
				$this->adler32Context = hash_init('adler32');
			}
		}

		// frame size		
		$this->writeRect($swfFile->frameSize);
		
		// frame rate and count
		$this->writeUI16($swfFile->frameRate);
		$this->writeUI16($swfFile->frameCount);
		
		foreach($tags as $tag) {
			$this->writeTag($tag);
		}
		
		if($swfFile->compressed) {
			stream_filter_remove($filter);

			if($this->adler32Context) {
				$hash = hash_final($this->adler32Context, true);
				if(hash("adler32", "") == "\x01\x00\x00\x00") {
					// if the byte order is wrong, then reverses it
					$hash = strrev($hash);
				}			
				fwrite($this->output, $hash);
				$this->adler32Context = null;
			}
		}
		$this->output = null;
		return $this->written;
	}
	
	protected function createGenericTag($tag) {
		static $tagCodeTable = array(
			'CSMTextSettings' => 74,		'DefineSound' => 14,
			'DefineScalingGrid' => 78,		'DefineVideoStream' => 60,
			'DefineBinaryData' => 87,		'DoInitAction' => 59,
			'DefineBits' => 6,			'DoABC' => 82,
			'DefineBitsJPEG2' => 21,		'DoAction' => 12,
			'DefineBitsJPEG3' => 35,		'EnableDebugger' => 58,
			'DefineBitsJPEG4' => 90,		'EnableDebugger2' => 64,
			'DefineBitsLossless' => 20,		'End' => 0,
			'DefineBitsLossless2' => 36,		'ExportAssets' => 56,
			'DefineButton' => 7,			'FileAttributes' => 69,
			'DefineButton2' => 34,			'FrameLabel' => 43,
			'DefineButtonCxform' => 23,		'ImportAssets' => 57,
			'DefineButtonSound' => 17,		'ImportAssets2' => 71,
			'DefineEditText' => 37,			'JPEGTables' => 8,
			'DefineFont' => 10,			'Metadata' => 77,
			'DefineFont2' => 48,			'Protect' => 24,
			'DefineFont3' => 75,			'PlaceObject' => 4,
			'DefineFont4' => 91,			'PlaceObject2' => 26,
			'DefineFontAlignZones' => 73,		'PlaceObject3' => 70,
			'DefineFontInfo' => 13,			'RemoveObject' => 5,
			'DefineFontInfo2' => 62,		'RemoveObject2' => 28,
			'DefineFontName' => 88,			'ScriptLimits' => 65,
			'DefineMorphShape' => 46,		'SetBackgroundColor' => 9,
			'DefineMorphShape2' => 84,		'SetTabIndex' => 66,
			'DefineSceneAndFrameLabelData' => 86,	'ShowFrame' => 1,
			'DefineShape' => 2,			'StartSound' => 15,
			'DefineShape2' => 22,			'StartSound2' => 89,
			'DefineShape3' => 32,			'SoundStreamHead' => 18,
			'DefineShape4' => 83,			'SoundStreamHead2' => 45,
			'DefineSprite' => 39,			'SoundStreamBlock' => 19,
			'DefineText' => 11,			'SymbolClass' => 76,
			'DefineText2' => 33,			'VideoFrame' => 61,
		);
		
		$class = get_class($tag);
		$tagName = substr($class, 3, -3);
		$methodName = "write{$tagName}Tag";
		$this->startBuffer();
		$this->$methodName($tag);
		$generic = new SWFGenericTag;
		$generic->name = $tagName;
		$generic->code = $tagCodeTable[$tagName];
		$generic->data = $this->endBuffer();
		$generic->length = strlen($generic->data);
		// use the short format only for tags with no data--just to be safe
		$generic->headerLength = ($generic->length == 0) ? 2 : 6;
		return $generic;
	}
	
	protected function writeTag($tag) {
		if(!($tag instanceof SWFGenericTag)) {
			$tag = $this->createGenericTag($tag);
		}
		if($tag->headerLength == 2) {
			$tagCodeAndLength = ($tag->code << 6) | $tag->length;
			$this->writeUI16($tagCodeAndLength);
		} else {
			$tagCodeAndLength = ($tag->code << 6) | 0x003F;
			$this->writeUI16($tagCodeAndLength);
			$this->writeUI32($tag->length);
		}
		$this->writeBytes($tag->data);
	}
	
	protected function writeCSMTextSettingsTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUB($tag->renderer, 2);
		$this->writeUB($tag->gridFit, 3);
		$this->writeUB($tag->reserved1, 3);
		$this->writeFloat($tag->thickness);
		$this->writeFloat($tag->sharpness);
		$this->writeUI8($tag->reserved2);
	}
	
	protected function writeDefineBinaryDataTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI32($tag->reserved);
		
		if($tag->swfFile) {
			// the tag is an embedded SWF file
			// assemble the file using a clone of $this
			$data = '';
			$assembler = clone $this;
			$assembler->assemble($data, $tag->swfFile);
			$this->writeBytes($data);
		} else {
			$this->writeBytes($tag->data);
		}
	}
	
	protected function writeDefineBitsTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeBytes($tag->imageData);
	}
	
	protected function writeDefineBitsLosslessTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8($tag->format);
		$this->writeUI16($tag->width);
		$this->writeUI16($tag->height);
		if($tag->format == 3) {
			$this->writeUI8($tag->colorTableSize);
		}
		$this->writeBytes($tag->imageData);
	}
	
	protected function writeDefineBitsLossless2Tag($tag) {
		$this->writeDefineBitsLosslessTag($tag);
	}

	protected function writeDefineBitsJPEG2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeBytes($tag->imageData);
	}
	
	protected function writeDefineBitsJPEG3Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI32(strlen($tag->imageData));
		$this->writeBytes($tag->imageData);
		$this->writeBytes($tag->alphaData);
	}
	
	protected function writeDefineBitsJPEG4Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI32(strlen($tag->imageData));
		$this->writeUI16($tag->deblockingParam);
		$this->writeBytes($tag->imageData);
		$this->writeBytes($tag->alphaData);
	}
	
	protected function writeDefineButtonTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeButtonRecords($tag->characters, 1);		
		$this->writeBytes($tag->actions);
	}
	
	protected function writeDefineButton2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8($tag->flags);
		if($tag->actions) {
			$this->startBuffer();
			$this->writeButtonRecords($tag->characters, 2);
			$buttonRecordsData = $this->endBuffer();
			$actionOffset = strlen($buttonRecordsData) + 2;
			$this->writeUI16($actionOffset);
			$this->writeBytes($buttonRecordsData);
			$this->writeBytes($tag->actions);
		} else {
			$this->writeUI16(0);
			$this->writeButtonRecords($tag->characters, 2);
		}
	}
	
	protected function writeDefineButtonCxformTag(&$bytesAvailable) {
		$this->writeUI16($tag->characterId);
		$this->writeColorTransform($tag->colorTransform);
		return $tag;
	}
	
	protected function writeDefineButtonSoundTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->overUpToIdleId);
		if($tag->overUpToIdleId) {
			$this->writeSoundInfo($tag->overUpToIdleInfo);
		}
		$this->writeUI16($tag->idleToOverUpId);
		if($tag->idleToOverUpId) {
			$this->writeSoundInfo($tag->idleToOverUpInfo);
		}
		$this->writeUI16($tag->overUpToOverDownId);
		if($tag->overUpToOverDownId) {
			$this->writeSoundInfo($tag->overUpToOverDownInfo);
		}
		$this->writeUI16($tag->overDownToOverUpId);
		if($tag->overDownToOverUpId) {
			$this->writeSoundInfo($tag->overDownToOverUpInfo);
		}
	}
	
	protected function writeDefineEditTextTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->bounds);
		$this->writeUI16($tag->flags);
		if($tag->flags & SWFDefineEditTextTag::HasFont) {
			$this->writeUI16($tag->fontId);
			$this->writeUI16($tag->fontHeight);
		}
		if($tag->flags & SWFDefineEditTextTag::HasFontClass) {
			$this->writeString($tag->fontClass);
		}
		if($tag->flags & SWFDefineEditTextTag::HasTextColor) {
			$this->writeRGBA($tag->textColor);
		}
		if($tag->flags & SWFDefineEditTextTag::HasMaxLength) {
			$this->writeUI16($tag->maxLength);
		}
		if($tag->flags & SWFDefineEditTextTag::HasLayout) {
			$this->writeUI8($tag->align);
			$this->writeUI16($tag->leftMargin);
			$this->writeUI16($tag->rightMargin);
			$this->writeUI16($tag->indent);
			$this->writeUI16($tag->leading);
		}
		$this->writeString($tag->variableName);
		if($tag->flags & SWFDefineEditTextTag::HasText) {
			$this->writeString($tag->initialText);
		}
	}
	
	protected function writeDefineFontTag($tag) {
		$this->writeUI16($tag->characterId);
		$shapeTable = array();
		$glyphCount = count($tag->glyphTable);
		$offset = $glyphCount * 2;
		foreach($tag->glyphTable as $glyph) {
			$this->writeUI16($offset);
			$this->startBuffer();
			$this->writeShape($glyph, 1);
			$shapeData = $this->endBuffer();
			$shapeTable[] = $shapeData;
			$offset += strlen($shapeData);
		}
		foreach($shapeTable as $shapeData) {
			$this->writeBytes($shapeData);
		}
	}
	
	protected function writeDefineFont2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8($tag->flags);
		$this->writeUI8($tag->languageCode);
		$this->writeUI8(strlen($tag->name));
		$this->writeBytes($tag->name);
		$glyphCount = count($tag->glyphTable);
		$this->writeUI16($glyphCount);

		$shapeTable = array();
		if($tag->flags & SWFDefineFont2Tag::WideOffsets) {
			$offset = $glyphCount * 4 + 4;
			foreach($tag->glyphTable as $index => $glyph) {
				$this->writeUI32($offset);
				$this->startBuffer();
				$this->writeShape($glyph, 1);
				$shapeData = $this->endBuffer();
				$shapeTable[] = $shapeData;
				$offset += strlen($shapeData);
			}
			$this->writeUI32($offset);
		} else {
			$offset = $glyphCount * 2 + 2;
			foreach($tag->glyphTable as $glyph) {
				$this->writeUI16($offset);
				$this->startBuffer();
				$this->writeShape($glyph, 1);
				$shapeData = $this->endBuffer();
				$shapeTable[] = $shapeData;
				$offset += strlen($shapeData);
			}
			$this->writeUI16($offset);
		}
		foreach($shapeTable as $shapeData) {
			$this->writeBytes($shapeData);
		}
		if($tag->flags & SWFDefineFont2Tag::WideCodes) {
			foreach($tag->codeTable as $code) {
				$this->writeUI16($code);
			}
		} else {
			foreach($tag->codeTable as $code) {
				$this->writeUI8($code);
			}
		}
		if($tag->flags & SWFDefineFont2Tag::HasLayout || isset($tag->ascent)) {
			$this->writeSI16($tag->ascent);
			$this->writeSI16($tag->descent);
			$this->writeSI16($tag->leading);
			foreach($tag->advanceTable as $advance) {
				$this->writeUI16($advance);
			}
			foreach($tag->boundTable as $bound) {
				$this->writeRect($bound);
			}
			$this->writeUI16(count($tag->kerningTable));
			if($tag->flags & SWFDefineFont2Tag::WideCodes) {
				foreach($tag->kerningTable as $kerningRecord) {
					$this->writeWideKerningRecord($kerningRecord);
				}
			} else {
				foreach($tag->kerningTable as $kerningRecord) {
					$this->writeKerningRecord($kerningRecord);
				}
			}
		}
	}
	
	protected function writeDefineFont3Tag($tag) {
		$this->writeDefineFont2Tag($tag);
	}
	
	protected function writeDefineFont4Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8($tag->flags);
		$this->writeString($tag->name);
		$this->writeBytes($tag->cffData);
	}

	protected function writeDefineFontAlignZonesTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUB($tag->tableHint, 2);
		$this->writeZoneRecords($tag->zoneTable);
	}
	
	protected function writeDefineFontInfoTag(&$bytesAvailable) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8(strlen($tag->name));
		$this->writeBytes($tag->name);
		$this->writeUI8($tag->flags);
		if($tag->flags & SWFDefineFontInfo2Tag::WideCodes) {
			foreach($tag->codeTable as $code) {
				$this->writeU16($code);
			}
		} else {
			foreach($tag->codeTable as $code) {
				$this->writeU16($code);
			}
		}
	}
	
	protected function writeDefineFontInfo2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI8(strlen($tag->name));
		$this->writeBytes($tag->name);
		$this->writeUI8($tag->flags);
		$this->writeUI8($tag->languageCode);
		if($tag->flags & SWFDefineFontInfo2Tag::WideCodes) {
			foreach($tag->codeTable as $code) {
				$this->writeU16($code);
			}
		} else {
			foreach($tag->codeTable as $code) {
				$this->writeU16($code);
			}
		}
	}
	
	protected function writeDefineFontNameTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeString($tag->name);
		$this->writeString($tag->copyright);
	}
	
	protected function writeDefineMorphShapeTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->startBounds);
		$this->writeRect($tag->endBounds);
		$this->writeMorphShapeWithStyle($tag->morphShape, 1);
	}
	
	protected function writeDefineMorphShape2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->startBounds);
		$this->writeRect($tag->endBounds);
		$this->writeRect($tag->startEdgeBounds);
		$this->writeRect($tag->endEdgeBounds);
		$this->writeUI8($tag->flags);
		$this->writeMorphShapeWithStyle($tag->morphShape, 2);
	}
	
	protected function writeDefineScalingGridTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->splitter);
	}
	
	protected function writeDefineSceneAndFrameLabelDataTag($tag) {
		$this->writeEncodedStringTable($tag->names);
		$this->writeEncodedStringTable($tag->frameLabels);
	}
	
	protected function writeDefineShapeTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->shapeBounds);
		$this->writeShapeWithStyle($tag->shape, 1);
	}
	
	protected function writeDefineShape2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->shapeBounds);
		$this->writeShapeWithStyle($tag->shape, 2);
	}
	
	protected function writeDefineShape3Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->shapeBounds);
		$this->writeShapeWithStyle($tag->shape, 3);
	}
	
	protected function writeDefineShape4Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->shapeBounds);
		$this->writeRect($tag->edgeBounds);
		$this->writeUI8($tag->flags);
		$this->writeShapeWithStyle($tag->shape, 4);
	}
	
	protected function writeDefineSoundTag($tag) {
		$this->writeUB($tag->format, 4);
		$this->writeUB($tag->sampleRate, 2);
		$this->writeUB($tag->sampleSize, 1);
		$this->writeUB($tag->type, 1);
		$this->writeUI32($tag->sampleCount);
		$this->writeBytes($tag->data);
	}
	
	protected function writeDefineSpriteTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->frameCount);
		foreach($tag->tags as $child) {
			$this->writeTag($child);
		}
	}
	
	protected function writeDefineTextTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->bounds);
		$this->writeMatrix($tag->matrix);
		$this->writeUI8($tag->glyphBits);
		$this->writeUI8($tag->advanceBits);
		$this->writeTextRecords($tag->textRecords, $tag->glyphBits, $tag->advanceBits, 1);
	}
	
	protected function writeDefineText2Tag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->bounds);
		$this->writeMatrix($tag->matrix);
		$this->writeUI8($tag->glyphBits);
		$this->writeUI8($tag->advanceBits);
		$this->writeTextRecords($tag->textRecords, $tag->glyphBits, $tag->advanceBits, 2);
	}
	
	protected function writeDefineVideoStreamTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->frameCount);
		$this->writeUI16($tag->width);
		$this->writeUI16($tag->height);
		$this->writeUI8($tag->flags);
		$this->writeUI8($tag->codecId);
	}
	
	protected function writeDoABCTag($tag) {
		$this->writeUI32($tag->flags);
		$this->writeString($tag->byteCodeName);
		if($tag->abcFile) {
			// assemble the ABC file using ABCAssembler
			$byteCodes = '';
			$assembler = new ABCAssembler;
			$assembler->assemble($byteCodes, $tag->abcFile);
			$this->writeBytes($byteCodes);
		} else {
			$this->writeBytes($tag->byteCodes);
		}
	}
	
	protected function writeDoActionTag($tag) {
		$this->writeBytes($tag->actions);
	}
	
	protected function writeDoInitActionTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeBytes($tag->actions);
	}
	
	protected function writeEndTag($tag) {
	}
	
	protected function writeEnableDebuggerTag($tag) {
		$this->writeString($tag->password);
	}
	
	protected function writeEnableDebugger2Tag($tag) {
		$this->writeU16($tag->reserved);
		$this->writeString($tag->password);
	}
	
	protected function writeExportAssetsTag($tag) {
		$this->writeStringTable($tag->names);
	}
	
	protected function writeFileAttributesTag($tag) {
		$this->writeUI32($tag->flags);
	}
	
	protected function writeFrameLabelTag($tag) {
		$this->writeString($tag->name);
		if($tag->anchor) {
			$tag->writeString($tag->anchor);
		}
	}
	
	protected function writeImportAssetsTag($tag) {
		$this->writeString($tag->url);
		$this->writeStringTable($tag->names);
	}
	
	protected function writeImportAssets2Tag($tag) {
		$this->writeString($tag->url);
		$this->writeUI8($tag->reserved1);
		$this->writeUI8($tag->reserved2);
		$this->writeStringTable($tag->names);
	}
	
	protected function writeJPEGTablesTag($tag) {
		$this->writeBytes($tag->jpegData);
	}
	
	protected function writeMetadataTag($tag) {
		$this->writeString($tag->metadata);
	}
	
	protected function writePlaceObjectTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->depth);
		$this->writeMatrix($tag->matrix);
		if($tag->colorTransform) {
			$this->writeColorTransform($tag->colorTransform);
		}
	}
	
	protected function writePlaceObject2Tag($tag) {
		$this->writeUI8($tag->flags);
		$this->writeUI16($tag->depth);
		if($tag->flags & SWFPlaceObject2Tag::HasCharacter) {
			$this->writeUI16($tag->characterId);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasMatrix) {
			$this->writeMatrix($tag->matrix);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasColorTransform) {
			$this->writeColorTransformAlpha($tag->colorTransform);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasRatio) {
			$this->writeUI16($tag->ratio);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasName) {
			$this->writeString($tag->name);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasClipDepth) {
			$this->writeUI16($tag->clipDepth);
		}
		if($tag->flags & SWFPlaceObject2Tag::HasClipActions) {
			$this->writeUI16(0);
			if($this->swfVersion >= 6) {
				$this->writeUI32($tag->allEventFlags);
			} else {
				$this->writeUI16($tag->allEventFlags);
			}
			$this->writeClipActions($tag->clipActions);
		}
	}
	
	protected function writePlaceObject3Tag($tag) {
		$this->writeUI16($tag->flags);
		$this->writeUI16($tag->depth);
		if($tag->flags & SWFPlaceObject3Tag::HasClassName) {
			$this->writeString($tag->className);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasCharacter) {
			$this->writeUI16($tag->characterId);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasMatrix) {
			$this->writeMatrix($tag->matrix);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasColorTransform) {
			$this->writeColorTransformAlpha($tag->colorTransform);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasRatio) {
			$this->writeUI16($tag->ratio);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasName) {
			$this->writeString($tag->name);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasClipDepth) {
			$this->writeUI16($tag->clipDepth);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasFilterList) {
			$this->writeFilters($tag->filters);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasBlendMode) {
			$this->writeUI8($tag->blendMode);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasCacheAsBitmap) {
			$this->writeUI8($tag->bitmapCache);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasClipActions) {
			$this->writeClipActions($tag->clipActions);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasVisibility) {
			$this->writeUI8($tag->visibility);
		}
		if($tag->flags & SWFPlaceObject3Tag::HasBackgroundColor) {
			$this->writeRGBA($tag->bitmapCacheBackgroundColor);
		}
	}
	
	protected function writeProtectTag($tag) {
		$this->writeString($tag->password);
	}
	
	protected function writeRemoveObjectTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->depth);
	}
	
	protected function writeRemoveObject2Tag($tag) {
		$this->writeUI16($tag->depth);
	}
	
	protected function writeScriptLimitsTag($tag) {
		$this->writeU16($tag->maxRecursionDepth);
		$this->writeU16($tag->scriptTimeoutSeconds);
	}
	
	protected function writeSetBackgroundColorTag($tag) {
		$this->writeRGB($tag->color);
	}
	
	protected function writeSetTabIndexTag($tag) {
		$this->writeU16($tag->depth);
		$this->writeU16($tag->tabIndex);
	}
	
	protected function writeShowFrameTag($tag) {
	}
	
	protected function writeSoundStreamBlockTag($tag) {
		$this->writeBytes($tag->data);
	}
	
	protected function writeSoundStreamHeadTag($tag) {
		$this->writeUI16($tag->flags);
		$this->writeUI16($tag->sampleCount);
		if($tag->flags & 0xF000 == 0x2000) {
			$this->writeS16($tag->latencySeek);
		}
	}
	
	protected function writeSoundStreamHead2Tag($tag) {
		$this->writeUB(0, 4);
		$this->writeUB($tag->playbackSampleRate, 2);
		$this->writeUB($tag->playbackSampleSize, 1);
		$this->writeUB($tag->playbackType, 1);
		$this->writeUB($tag->format, 4);
		$this->writeUB($tag->sampleRate, 2);
		$this->writeUB($tag->sampleSize, 1);
		$this->writeUB($tag->type, 1);
		$this->writeUI16($tag->sampleCount);
		if($tag->format == SWFDefineSoundTag::FormatMP3) {
			$this->writeS16($tag->latencySeek);
		}
	}
	
	protected function writeStartSoundTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeSoundInfo($tag->info);
	}
	
	protected function writeStartSound2Tag($tag) {
		$this->writeString($tag->className);
		$this->writeSoundInfo($tag->info);
	}
	
	protected function writeSymbolClassTag($tag) {
		$this->writeStringTable($tag->names);
	}
	
	protected function writeVideoFrameTag($tag) {
		$this->writeUI16($tag->streamId);
		$this->writeUI16($tag->frameNumber);
		$this->writeBytes($tag->data);
	}
	
	protected function writeZoneRecords($records) {
		foreach($records as $record) {
			$this->writeZoneRecord($record);
		}
	}
	
	protected function writeZoneRecord($record) {
		$this->writeUI8(2);
		$this->writeUI16($record->zoneData1);
		$this->writeUI16($record->zoneData2);
		$this->writeUI8($record->flags);
		$this->writeUI16($record->alignmentCoordinate);
		$this->writeUI16($record->range);
	}
	
	protected function writeKerningRecord($kern) {
		$this->writeUI8($kern->code1);
		$this->writeUI8($kern->code2);
		$this->writeUI16($kern->adjustment);
	}
	
	protected function writeWideKerningRecord($kern) {
		$this->writeUI16($kern->code1);
		$this->writeUI16($kern->code2);
		$this->writeUI16($kern->adjustment);
	}
	
	protected function writeClipActions($clipActions) {
		foreach($clipActions as $clipAction) {
			$this->writeClipAction($clipAction);
		}
		if($this->swfVersion >= 6)  {
			$this->writeUI32(0);
		} else {
			$this->writeUI16(0);
		}
	}
	
	protected function writeClipAction($clipAction) {
		if($this->swfVersion >= 6) {
			$this->writeUI32($clipAction->eventFlags);
		} else {
			$this->writeUI16($clipAction->eventFlags);
		}
		$this->writeUI32(strlen($clipAction->actions));
		if($clipAction->eventFlags & SWFClipAction::KeyPress) {
			$this->writeUI8($clipAction->keyCode);
		}
		$this->writeBytes($clipAction->actions);
	}
	
	protected function writeFilters($filters) {
		$this->writeUI8(count($filters));
		foreach($filters as $filter) {
			$this->writeFilter($filter);
		}
	}
	
	protected function writeFilter($filter) {
		if($filter instanceof SWFDropShadowFilter) {
			$this->writeUI8(0);
			$this->writeRGBA($filter->shadowColor);
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUI32($filter->angle);
			$this->writeUI32($filter->distance);
			$this->writeUI16($filter->strength);
			$this->writeUB($filter->flags, 3);
			$this->writeUB($filter->passes, 5);
		} else if($filter instanceof SWFBlurFilter) {
			$this->writeUI8(1);
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUB($filter->passes, 5);			
		} else if($filter instanceof SWFGlowFilter) {
			$this->writeUI8(2);
			$this->writeRGBA($filter->color);
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUI16($filter->strength);
			$this->writeUB($filter->flags, 3);
			$this->writeUB($filter->passes, 5);
		} else if($filter instanceof SWFBevelFilter) {
			$this->writeUI8(3);
			$this->writeRGBA($filter->shadowColor);
			$this->writeRGBA($filter->highlightColor);
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUI32($filter->angle);
			$this->writeUI32($filter->distance);
			$this->writeUI16($filter->strength);
			$this->writeUB($filter->flags, 4);
			$this->writeUB($filter->passes, 4);
		} else if($filter instanceof SWFGradientGlowFilter) {
			$this->writeUI8(4);
			$this->writeUI8(count($filter->colors));
			foreach($filter->colors as $color) {
				$this->writeRGBA($color);
			}
			foreach($filter->ratios as $ratio) {
				$this->writeUI8($ratio);
			}
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUI32($filter->angle);
			$this->writeUI32($filter->distance);
			$this->writeUI16($filter->strength);
			$this->writeUB($filter->flags, 4);
			$this->writeUB($filter->passes, 4);
		} else if($filter instanceof SWFConvolutionFilter) {
			$this->writeUI8(5);
			$this->writeUI8($filter->matrixX);
			$this->writeUI8($filter->matrixY);
			$this->writeFloat($filter->divisor);
			$this->writeFloat($filter->bias);
			foreach($filter->matrix as $value) {
				 $this->writeFloat($value);
			}
			$this->writeRGBA($filter->defaultColor);
			$this->writeUI8($filter->flags);
		} else if($filter instanceof SWFColorMatrixFilter) {
			$this->writeUI8(6);
			foreach($filter->matrix as $value) {
				$this->writeFloat($value);
			}
		} else if($filter instanceof SWFGradientBevelFilter) {
			$this->writeUI8(7);
			$this->writeUI8(count($filter->colors));
			foreach($filter->colors as $color) {
				$this->writeRGBA($color);
			}
			foreach($filter->ratios as $ratio) {
				$this->writeUI8($ratio);
			}
			$this->writeUI32($filter->blurX);
			$this->writeUI32($filter->blurY);
			$this->writeUI32($filter->angle);
			$this->writeUI32($filter->distance);
			$this->writeUI16($filter->strength);
			$this->writeUB($filter->flags, 4);
			$this->writeUB($filter->passes, 4);
		}
		$this->alignToByte();
	}
	
	protected function writeTextRecords($records, $glyphBits, $advanceBits, $version) {
		foreach($records as $record) {
			$this->writeTextRecord($record, $glyphBits, $advanceBits, $version);
		}
		$this->writeUI8(0);
	}
	
	protected function writeTextRecord($record, $glyphBits, $advanceBits, $version) {
		$this->writeUI8($record->flags);
		if($record->flags & SWFTextRecord::HasFont) {
			$this->writeUI16($record->fontId);
		}
		if($record->flags & SWFTextRecord::HasColor) {
			if($version >= 2) {
				$this->writeRGBA($record->textColor);
			} else {
				$this->writeRGB($record->textColor);
			}
		}
		if($record->flags & SWFTextRecord::HasXOffset) {
			$this->writeSI16($record->xOffset);
		}
		if($record->flags & SWFTextRecord::HasYOffset) {
			$this->writeSI16($record->yOffset);
		}
		if($record->flags & SWFTextRecord::HasFont) {
			$this->writeUI16($record->textHeight);
		}
		$this->writeGlyphEntries($record->glyphs, $glyphBits, $advanceBits);
	}
	
	protected function writeGlyphEntries($glyphs, $glyphBits, $advanceBits) {
		$this->writeUI8(count($glyphs));
		foreach($glyphs as $glyph) {
			$this->writeGlyphEntry($glyph, $glyphBits, $advanceBits);
		}
	}
	
	protected function writeGlyphEntry($glyph, $glyphBits, $advanceBits) {
		$this->writeUB($glyph->index, $glyphBits);
		$this->writeUB($glyph->advance, $advanceBits);
	}
	
	protected function writeSoundInfo($info) {
		$this->writeUI8($info->flags);
		if($info->flags & SWFSoundInfo::HasInPoint) {
			$this->writeUI32($info->inPoint);
		}
		if($info->flags & SWFSoundInfo::HasOutPoint) {
			$this->writeUI32($info->outPoint);
		}
		if($info->flags & SWFSoundInfo::HasLoops) {
			$this->writeUI32($info->loopCount);
		}
		if($info->flags & SWFSoundInfo::HasEnvelope) {
			$this->writeSoundEnvelopes($info->envelopes);
		}
	}
	
	protected function writeSoundEnvelopes($envelopes) {
		$this->writeUI8(count($envelopes));
		foreach($envelopes as $envelope) {
			$this->writeUI32($envelope->position44);
			$this->writeUI16($envelope->leftLevel);
			$this->writeUI16($envelope->rightLevel);
		}
	}
	
	protected function writeButtonRecords($records, $version) {
		foreach($records as $record) {
			$this->writeButtonRecord($record, $version);
		}
		$this->writeUI8(0);
	}
	
	protected function writeButtonRecord($record, $version) {
		$this->writeUI8($record->flags);
		$this->writeUI16($record->characterId);
		$this->writeUI16($record->placeDepth);
		$this->writeMatrix($record->matrix);
		if($version == 2) {
			$this->writeColorTransformAlpha($record->colorTransform);
		}
		if($version == 2 && $record->flags & SWFButtonRecord::HasFilterList) {
			$this->writeFilters($record->filters);
		}
		if($version == 2 && $record->flags & SWFButtonRecord::HasBlendMode) {
			$this->writeUI8($record->blendMode);
		}
	}
	
	protected function writeShape($shape, $version) {
		$this->writeUB($shape->numFillBits, 4);
		$this->writeUB($shape->numLineBits, 4);
		$this->writeShapeRecords($shape->edges, $shape->numFillBits, $shape->numLineBits, $version);
	}
	
	protected function writeShapeWithStyle($shape, $version) {
		$this->writeFillStyles($shape->fillStyles, $version);
		$this->writeLineStyles($shape->lineStyles, $version);
		$this->writeUB($shape->numFillBits, 4);
		$this->writeUB($shape->numLineBits, 4);
		$this->writeShapeRecords($shape->edges, $shape->numFillBits, $shape->numLineBits, $version);
	}
	
	protected function writeMorphShapeWithStyle($shape, $version) {
		$this->startBuffer();
		$this->writeMorphFillStyles($shape->fillStyles);
		$this->writeMorphLineStyles($shape->lineStyles, $version);
		$this->writeUB($shape->startNumFillBits, 4);
		$this->writeUB($shape->startNumLineBits, 4);
		$this->writeShapeRecords($shape->startEdges, $shape->startNumFillBits, $shape->startNumLineBits, $version);
		$data = $this->endBuffer();		
		$endEdgesOffset = strlen($data);
		$this->writeUI32($endEdgesOffset);
		$this->writeBytes($data);
		$this->writeUB($shape->endNumFillBits, 4);
		$this->writeUB($shape->endNumLineBits, 4);
		$this->writeShapeRecords($shape->endEdges, $shape->endNumFillBits, $shape->endNumLineBits, $version);
	}
	
	protected function writeShapeRecords($records, $numFillBits, $numLineBits, $version) {
		foreach($records as $index => $record) {
			//if($index == 19) break;
			if($record instanceof SWFStraightEdge) {
				$this->writeUB(0x03, 2);
				$this->writeUB($record->numBits - 2, 4);
				if($record->deltaX != 0 && $record->deltaY != 0) {
					$this->writeUB(0x01, 1);
					$this->writeSB($record->deltaX, $record->numBits);
					$this->writeSB($record->deltaY, $record->numBits);
				} else {
					if($record->deltaX != 0) {
						$this->writeUB(0x00, 2);
						$this->writeSB($record->deltaX, $record->numBits);
					} else {
						$this->writeUB(0x01, 2);
						$this->writeSB($record->deltaY, $record->numBits);
					}
				}				
			} else if($record instanceof SWFQuadraticCurve) {
				$this->writeUB(0x02, 2);
				$this->writeUB($record->numBits - 2, 4);
				$this->writeSB($record->controlDeltaX, $record->numBits);
				$this->writeSB($record->controlDeltaY, $record->numBits);
				$this->writeSB($record->anchorDeltaX, $record->numBits);
				$this->writeSB($record->anchorDeltaY, $record->numBits);
			} else if($record instanceof SWFStyleChange) {
				$this->writeUB(0x00, 1);
				$flags = 0x00;
				if($record->numMoveBits !== null) {
					$flags |= 0x01;
				}
				if($record->fillStyle0 !== null) {
					$flags |= 0x02;
				}
				if($record->fillStyle1 !== null) {
					$flags |= 0x04;
				}
				if($record->lineStyle !== null) {
					$flags |= 0x08;
				}
				if($record->newFillStyles !== null || $record->newLineStyles !== null) {
					$flags |= 0x10;
				}
				$this->writeUB($flags, 5);
				if($flags & 0x01) {
					$this->writeSB($record->numMoveBits, 5);
					$this->writeSB($record->moveDeltaX, $record->numMoveBits);
					$this->writeSB($record->moveDeltaY, $record->numMoveBits);
				}
				if($flags & 0x02) {
					$this->writeUB($record->fillStyle0, $numFillBits);
				}
				if($flags & 0x04) {
					$this->writeUB($record->fillStyle1, $numFillBits);
				}
				if($flags & 0x08) {
					$this->writeUB($record->lineStyle, $numLineBits);
				}
				if($flags & 0x10) {
					$this->writeFillStyles($record->newFillStyles, $version);
					$this->writeLineStyles($record->newLineStyles, $version);
					$this->writeUB($record->numFillBits, 4);
					$this->writeUB($record->numLineBits, 4);
					$numFillBits = $record->numFillBits;
					$numLineBits = $record->numLineBits;
				}
			}
		}		
		$this->writeUB(0x00, 6);	// end record
		$this->alignToByte();
	}
	
	protected function writeFillStyles($styles, $version) {
		$count = count($styles);
		if($count >= 255) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			$this->writeFillStyle($style, $version);
		}
	}

	protected function writeFillStyle($style, $version) {
		$this->writeUI8($style->type);
		if($style->type == 0x00) {
			if($version >= 3) {
				$this->writeRGBA($style->color);
			} else {
				$this->writeRGB($style->color);
			}
		}
		if($style->type == 0x10 || $style->type == 0x12 || $style->type == 0x13) {
			$this->writeMatrix($style->gradientMatrix);
			if($style->type == 0x13) {
				$this->writeFocalGradient($style->gradient, $version);
			} else {
				$this->writeGradient($style->gradient, $version);
			}
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$this->writeUI16($style->bitmapId);
			$this->writeMatrix($style->bitmapMatrix);
		}
	}
	
	protected function writeMorphFillStyles($styles) {
		$count = count($styles);
		if($count >= 255) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			$this->writeMorphFillStyle($style);
		}
	}
	
	protected function writeMorphFillStyle($style) {
		$this->writeUI8($style->type);
		if($style->type == 0x00) {
			$this->writeRGBA($style->startColor);
			$this->writeRGBA($style->endColor);
		} 
		if($style->type == 0x10 || $style->type == 0x12) {
			$this->writeMatrix($style->startGradientMatrix);
			$this->writeMatrix($style->endGradientMatrix);
			$this->writeMorphGradient($style->gradient);
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$this->writeUI16($style->bitmapId);
			$this->writeMatrix($style->startBitmapMatrix);
			$this->writeMatrix($style->endBitmapMatrix);
		}
	}
	
	protected function writeLineStyles($styles, $version) {
		$count = count($styles);
		if($count >= 255) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			if($version == 4) {
				$this->writeLineStyle2($style, $version);
			} else {
				$this->writeLineStyle($style, $version);
			}
		}
	}
		
	protected function writeLineStyle2($style, $version) {
		$this->writeUI16($style->width);
		$this->writeUB($style->startCapStyle, 2);
		$this->writeUB($style->joinStyle, 2);		
		$this->writeUB($style->flags, 10);
		$this->writeUB($style->endCapStyle, 2);
		if($style->joinStyle == SWFLineStyle2::JoinStyleMiter) {
			$this->writeUI16($style->miterLimitFactor);
		}
		if($style->flags & SWFLineStyle2::HasFill) {
			$this->writeFillStyle($style->fillStyle, $version);
		} else {
			$this->writeRGBA($style->color);
		}
	}
	
	protected function writeLineStyle($style, $version) {
		$this->writeUI16($style->width);
		if($version >= 3) {
			$this->writeRGBA($style->color);
		} else {
			$this->writeRGB($style->color);
		}
	}
	
	protected function writeMorphLineStyles($styles, $version) {
		$count = count($styles);
		if($count >= 255) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			if($version == 2) {
				$this->writeMorphLineStyle2($style);
			} else {
				$this->writeMorphLineStyle($style);
			}
		}		
	}
	
	protected function writeMorphLineStyle2($style) {
		$this->writeUI16($style->startWidth);
		$this->writeUI16($style->endWidth);
		$this->writeUB($style->startCapStyle, 2);
		$this->writeUB($style->joinStyle, 2);
		$this->writeUB($style->flags, 10);
		$this->writeUB($style->endCapStyle, 2);
		if($style->joinStyle == SWFLineStyle2::JoinStyleMiter) {
			$this->writeUI16($style->miterLimitFactor);
		}
		if($style->flags & SWFLineStyle2::HasFill) {
			$this->writeMorphFillStyle($style->fillStyle);
		} else {
			$this->writeRGBA($style->startColor);
			$this->writeRGBA($style->endColor);
		}
	}
	
	protected function writeMorphLineStyle($style) {
		$this->writeUI16($style->startWidth);
		$this->writeUI16($style->endWidth);
		$this->writeRGBA($style->startColor);
		$this->writeRGBA($style->endColor);
	}
	
	protected function writeGradient($gradient, $version) {
		$this->writeUB($gradient->spreadMode, 2);
		$this->writeUB($gradient->interpolationMode, 2);
		$this->writeGradientControlPoints($gradient->controlPoints, $version);
	}
	
	protected function writeFocalGradient($gradient, $version) {
		$this->writeUB($gradient->spreadMode, 2);
		$this->writeUB($gradient->interpolationMode, 2);
		$this->writeGradientControlPoints($gradient->controlPoints, $version);
		$this->writeUI16($gradient->focalPoint);
	}
	
	protected function writeGradientControlPoints($controlPoints, $version) {
		$this->writeUB(count($controlPoints), 4);
		foreach($controlPoints as $controlPoint) {
			$this->writeUI8($controlPoint->ratio);
			if($version >= 3) {
				$this->writeRGBA($controlPoint->color);
			} else {
				$this->writeRGB($controlPoint->color);
			}
		}
	}
	
	protected function writeMorphGradient($gradient, $version) {
		$this->writeUI8(count($gradient->records));
		foreach($gradient->records as $record) {
			$this->writeUI8($record->startRatio);
			$this->writeRGBA($record->startColor);
			$this->writeUI8($record->endRatio);
			$this->writeRGBA($record->endColor);
		}
	}
	
	protected function writeColorTransformAlpha($transform) {
		$hasAddTerms = $transform->redAddTerm !== null || $transform->greenAddTerm !== null || $transform->blueAddTerm !== null || $transform->alphaAddTerm !== null;
		$hasMultTerms = $transform->redMultTerm !== null || $transform->greenMultTerm !== null || $transform->blueMultTerm !== null || $transform->alphaMultTerm !== null;
		$this->writeUB($hasAddTerms, 1);
		$this->writeUB($hasMultTerms, 1);
		$this->writeUB($transform->numBits, 4);
		if($hasMultTerms) {
			$this->writeSB($transform->redMultTerm, $transform->numBits);
			$this->writeSB($transform->greenMultTerm, $transform->numBits);
			$this->writeSB($transform->blueMultTerm, $transform->numBits);
			$this->writeSB($transform->alphaMultTerm, $transform->numBits);
		}
		if($hasAddTerms) {
			$this->writeSB($transform->redAddTerm, $transform->numBits);
			$this->writeSB($transform->greenAddTerm, $transform->numBits);
			$this->writeSB($transform->blueAddTerm, $transform->numBits);
			$this->writeSB($transform->alphaAddTerm, $transform->numBits);
		}
		$this->alignToByte();		
	}
	
	protected function writeColorTransform($transform) {
		$hasAddTerms = $transform->redAddTerm !== null || $transform->greenAddTerm !== null || $transform->blueAddTerm !== null;
		$hasMultTerms = $transform->redMultTerm !== null || $transform->greenMultTerm !== null || $transform->blueMultTerm !== null;
		$this->writeUB($hasAddTerms, 1);
		$this->writeUB($hasMultTerms, 1);
		$this->writeUB($transform->numBits, 4);
		if($hasMultTerms) {
			$this->writeSB($transform->redMultTerm, $transform->numBits);
			$this->writeSB($transform->greenMultTerm, $transform->numBits);
			$this->writeSB($transform->blueMultTerm, $transform->numBits);
		}
		if($hasAddTerms) {
			$this->writeSB($transform->redAddTerm, $transform->numBits);
			$this->writeSB($transform->greenAddTerm, $transform->numBits);
			$this->writeSB($transform->blueAddTerm, $transform->numBits);
		}
		$this->alignToByte();
	}

	protected function writeMatrix($matrix) {
		$this->writeUB($matrix->nScaleBits != null, 1);
		if($matrix->nScaleBits != null) {
			$this->writeUB($matrix->nScaleBits, 5);
			$this->writeSB($matrix->scaleX, $matrix->nScaleBits);
			$this->writeSB($matrix->scaleY, $matrix->nScaleBits);
		}
		$this->writeUB($matrix->nRotateBits != null, 1);
		if($matrix->nRotateBits != null) {
			$this->writeUB($matrix->nRotateBits, 5);
			$this->writeSB($matrix->rotateSkew0, $matrix->nRotateBits);
			$this->writeSB($matrix->rotateSkew1, $matrix->nRotateBits);
		}
		$this->writeUB($matrix->nTraslateBits, 5);
		$this->writeSB($matrix->translateX, $matrix->nTraslateBits);
		$this->writeSB($matrix->translateY, $matrix->nTraslateBits);
		$this->alignToByte();
	}
	
	protected function writeRect($rect) {
		$this->writeUB($rect->numBits, 5);
		$this->writeSB($rect->left, $rect->numBits);
		$this->writeSB($rect->right, $rect->numBits);
		$this->writeSB($rect->top, $rect->numBits);
		$this->writeSB($rect->bottom, $rect->numBits);
		$this->alignToByte();
	}
	
	protected function writeRGB($rgb) {
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
	}
		
	protected function writeRGBA($rgb) {
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
		$this->writeUI8($rgb->alpha);
	}
		
	protected function writeARGB($rgb) {
		$this->writeUI8($rgb->alpha);
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
	}
	
	protected function writeUI8($value) {
		$byte = chr($value);
		$this->alignToByte();
		$this->writeBytes($byte);
	}

	protected function writeSI16($value) {
		$this->writeUI16($value);
	}
	
	protected function writeUI16($value) {
		$bytes = pack('v', $value);
		$this->alignToByte();
		$this->writeBytes($bytes);
	}

	protected function writeUI32($value) {
		$bytes = pack('V', $value);
		$this->alignToByte();
		$this->writeBytes($bytes);
	}
	
	protected function writeEncodedUI32($value) {
		if(!($value & 0xFFFFFF80)) {
			$bytes = pack('C', $value);
		} else if(!($value & 0xFFFFC000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F);
		} else if(!($value & 0xFFE00000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F);
		} else if(!($value & 0xF0000000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F | 0x80,  ($value >> 21) & 0x7F);
		} else {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F | 0x80,  ($value >> 21) & 0x7F | 0x80, ($value >> 28) & 0x0F);	// the last byte can only have four bits
		}
		$this->writeBytes($bytes);
	}
	
	protected function writeFloat($value) {
		$bytes = pack('f', $value);
		$this->alignToByte();
		$this->writeBytes($bytes);
	}
	
	protected function writeEncodedStringTable($strings) {
		$this->writeEncodedUI32(count($strings));
		foreach($strings as $index => $string) {
			$this->writeEncodedUI32($index);
			$this->writeString($string);
		}
	}
	
	protected function writeStringTable($strings) {
		$this->writeUI16(count($strings));
		foreach($strings as $index => $string) {
			$this->writeUI16($index);
			$this->writeString($string);
		}
	}
	
	protected function writeString($value) {
		$this->alignToByte();
		$this->writeBytes($value);
		$this->writeBytes("\0");
	}
	
	protected function writeSB($value, $numBits) {
		if($value < 0) {
			// mask out the upper bits
			$value &= ~(-1 << $numBits);
		}
		$this->writeUB($value, $numBits);
	}
	
	protected function writeUB($value, $numBits) {
		$this->bitBuffer = $this->bitBuffer | ($value << (32 - $numBits - $this->bitsRemaining));
		$this->bitsRemaining += $numBits;
		while($this->bitsRemaining > 8) {
			$byte = chr(($this->bitBuffer >> 24) & 0x000000FF);
			$this->bitsRemaining -= 8;
			$this->bitBuffer = (($this->bitBuffer << 8) & (-1 << (32 - $this->bitsRemaining))) & 0xFFFFFFFF;
			$this->writeBytes($byte);
		}
	}
	
	protected function alignToByte() {
		if($this->bitsRemaining) {
			$byte = chr(($this->bitBuffer >> 24) & 0x000000FF);
			$this->writeBytes($byte);
			$this->bitBuffer = $this->bitsRemaining = 0;
		}		
	}
	
	protected function startBuffer() {
		if($this->outputBuffer !== null) {
			array_push($this->outputBufferStack, $this->outputBuffer);
		}
		$this->outputBuffer = '';
	}
	
	protected function endBuffer() {
		$this->alignToByte();
		$data = $this->outputBuffer;
		if($this->outputBufferStack) {
			$this->outputBuffer = array_pop($this->outputBufferStack);
		} else {
			$this->outputBuffer = null;
		}
		return $data;
	}
	
	protected function writeBytes($bytes) {
		if($this->outputBuffer !== null) {
			if($this->outputBuffer) {
				$this->outputBuffer .= $bytes;
			} else {
				$this->outputBuffer = $bytes;
			}
		} else {
			if($this->adler32Context) {
				hash_update($this->adler32Context, $bytes);
			}
			$this->written += fwrite($this->output, $bytes);
		}
	}	
}

?>