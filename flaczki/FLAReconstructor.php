<?php

class FLAReconstructor {

	protected $output;
	protected $lastModified;
	protected $fileID;
	protected $lastItemID;
	protected $timeline;
	protected $frameIndex;
	protected $frameLabels;
	protected $sceneNames;
	protected $jpegTables;
	protected $dictionary;
	protected $symbols;
	protected $media;
	protected $library;
	protected $metadata;
	
	public function getRequiredTags() {
		$methodNames = get_class_methods($this);
		$tags = array();
		foreach($methodNames as $methodName) {
			if(preg_match('/^process(\w+?)Tag$/', $methodName, $m)) {
				$tags[] = $m[1];
			}
		}
		return $tags;
	}
	
	public function reconstruct($swfFile, $lastModified = 0) {
		$this->lastModified = ($lastModified) ? $lastModified : gmtime();
		$this->fileID = rand(1, 0xFF);
		$this->lastItemID = 0x50000000;
		$this->symbols = array();
		$this->library = array();
		
		$flaFile = new FLAFile;
		$flaFile->document = new FLADOMDocument;
		$flaFile->document->width = ($swfFile->frameSize->right - $swfFile->frameSize->left) / 20;
		$flaFile->document->height = ($swfFile->frameSize->bottom - $swfFile->frameSize->top) / 20;
		$flaFile->document->currentTimeline = 1;
		$flaFile->document->xflVersion = 2.1;
		$flaFile->document->creatorInfo = "Adobe Flash Professional CS5.5";
		$flaFile->document->platform = "Windows";
		$flaFile->document->versionInfo = "Saved by Adobe Flash Windows 11.5 build 325";
		$flaFile->document->majorVersion = 11;
		$flaFile->document->minorVersion = 5;
		$flaFile->document->buildNumber = 325;
		$flaFile->document->playOptionsPlayLoop = 'false';
		$flaFile->document->playOptionsPlayPages = 'false';
		$flaFile->document->playOptionsPlayFrameActions = 'false';
		$flaFile->document->timelines = $this->createTimelines($swfFile->tags);
		$flaFile->document->nextSceneIdentifier = count($flaFile->document->timelines) + 1;
		$flaFile->document->media = $this->media;
		$flaFile->document->symbols = $this->symbols;
		
		$flaFile->library = $this->library;
		$flaFile->metadata = $this->metadata;

		return $flaFile;
	}
	
	protected function createTimelines($tags) {
		// save previous values
		$previousTimeline = $this->timeline;
		$previousFrameIndex = $this->frameIndex;
		$previousFrameLabels = $this->frameLabels;
		$previousSceneNames = $this->sceneNames;
		
		// add frames to timeline
		$this->timeline = new FLADOMTimeline;
		$this->frameIndex = 0;
		$this->frameLabels = null;
		$this->sceneNames = null;
		
		// process the tags
		$this->processTags($tags);

		// attach frame lables
		if($this->frameLabels) {
		}
		
		// reverse the order of the layers so the bottom layer comes last
		$this->timeline->layers = array_reverse($this->timeline->layers, true);

		// split the timeline into scenes if necessary
		$timelines = array();
		if(count($this->sceneNames) > 1) {
			$timelines[] = $this->timeline;
		} else {
			if(count($this->sceneNames) == 1) {
				$this->timeline->name = $this->sceneNames[0];
			}
			$timelines[] = $this->timeline;
		} 
		
		// restore previous values
		$this->timeline = $previousTimeline;
		$this->frameIndex = $previousFrameIndex;
		$this->frameLabels = $previousFrameLabels;
		$this->sceneNames = $previousSceneNames;
		return $timelines;
	}
	
	protected function getNextItemID() {
		return sprintf('%08x-%08x', ++$this->lastItemID, $this->fileID);
	}
	
	protected function getNextLibraryName($prefix) {
		$num = 0;
		do {	
			$num++;
			$name = "$prefix $num";
		} while(isset($this->library[$name]));
		return $name;
	}
	
	protected function getLayer($depth) {
		if(isset($this->timeline->layers[$depth])) {
			$layer = $this->timeline->layers[$depth];
		} else {
			$layer = new FLADOMLayer;
			$layer->name = "Layer $depth";
			$layer->color = "#4FFF4F";
			$layer->frames = array();
			$this->timeline->layers[$depth] = $layer;
		}
		return $layer;
	}
	
	protected function getCharacter($characterId) {
		return $this->dictionary[$characterId];
	}
	
	protected function addCharacter($characterId, $character) {
		$this->dictionary[$characterId] = $character;
	}
	
	protected function getFrame($depth) {
		$layer = $this->getLayer($depth);
		$frame = $layer->frames[count($layer->frames) - 1];
		return $frame;
	}
	
	protected function addFrame($depth) {
		$frame = new FLADOMFrame;
		$frame->index = $this->frameIndex;
		$frame->duration = 0;
		$frame->keyMode = 0;
		$frame->elements = array();
		$layer = $this->getLayer($depth);
		$layer->frames[] = $frame;
		return $frame;
	}
	
	protected function addSymbol($object) {
		$this->library[$object->name] = $object;
		
		$include = new FLAInclude;
		$include->href = "{$object->name}.xml";
		$include->loadImmediate = "false";
		$include->itemID = $object->itemID;
		$include->lastModified = $object->lastModified;
		if($object->symbolType == 'graphic') {
			$include->itemIcon = "1";
		}
		$this->symbols[] = $include;
	}
	
	protected function addMedia($object) {
		$this->library[$object->name] = $object;

		if($object instanceof FLABitmap) {
			$item = new FLADOMBitmapItem;
			$item->name = $object->name;
			$item->itemID = $object->itemID;
			$item->externalFileSize = strlen($object->data);
			$item->originalCompressionType = ($object->mimeType == 'image/jpeg') ? 'losssly' : 'lossless';
			$item->quality = 50;
			$item->href = $object->path;
			$item->frameRight = $object->width * 20;
			$item->frameBottom = $object->height * 20;
		}
		$this->media[] = $item;
	}
	
	protected function addMovieClip($characterId, $tags) {
		$movieClip = new FLADOMSymbolItem;
		$movieClip->lastModified = $this->lastModified;
		$movieClip->itemID = $this->getNextItemID();
		$movieClip->name = $this->getNextLibraryName('Symbol');
		$movieClip->timeline = $this->createTimelines($tags);
		$movieClip->timeline[0]->name = $movieClip->name;
		$this->addCharacter($characterId, $movieClip);
		$this->addSymbol($movieClip);
	}
	
	protected function addShape($characterId, $shapeRecord) {
		$shape = new FLADOMShape;
		$shape->fills = $this->convertFills($shapeRecord->fillStyles);
		$shape->strokes = $this->convertStrokes($shapeRecord->lineStyles);
		$shape->edges = $this->convertEdges($shapeRecord->edges);

		// put a wrapper around the shape		
		$frame = new FLADOMFrame;
		$frame->index = 0;
		$frame->keyMode = 9728;
		$frame->elements = array($shape);
		$layer = new FLADOMLayer;
		$layer->name = "Layer 1";
		$layer->color = "#4FFF4F";
		$layer->current = "true";
		$layer->isSelected = "true";
		$layer->frames = array($frame);
		$timeline = new FLADOMTimeline;
		$timeline->layers = array($layer);
		
		$graphic = new FLADOMSymbolItem;
		$graphic->symbolType = "graphic";
		$graphic->itemID = $this->getNextItemID();
		$graphic->name = $timeline->name = $this->getNextLibraryName('Symbol');
		$graphic->lastModified = $this->lastModified;
		$graphic->timeline = array($timeline);
		
		$this->addCharacter($characterId, $graphic);
		$this->addSymbol($graphic);
	}
		
	protected function addBitmap($characterId, $imageData, $deblocking = false) {
		$imagePath = StreamMemory::add($imageData);
		$imageInfo = getimagesize($imagePath);
		
		$bitmap = new FLABitmap;
		$bitmap->data = $imageData;		
		$bitmap->mimeType = $imageInfo['mime'];
		$bitmap->width = $imageInfo[0];
		$bitmap->height = $imageInfo[1];
		$bitmap->name = $this->getNextLibraryName('Bitmap');
		$bitmap->itemID = $this->getNextItemID();
		
		switch($bitmap->mimeType) {
			case 'image/jpeg': $extension = '.jpg'; break;
			case 'image/png': $extension = '.png'; break;
			case 'image/gif': $extension = '.gif'; break;
		}
		$bitmap->path = "{$bitmap->name}$extension";
		
		$this->addCharacter($characterId, $bitmap);
		$this->addMedia($bitmap);
	}
	
	protected function processTags($tags) {
		foreach($tags as $tag) {
			$tagName = substr(get_class($tag), 3, -3);
			$methodName = "process{$tagName}Tag";
			if(method_exists($this, $methodName)) {
				$this->$methodName($tag);
			}
		}
	}
	
	protected function processMetadataTag($tag) {
		$this->metadata = '<?xpacket begin="?" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 5.2-c003 61.141987, 2011/02/22-12:03:51 ">'
				. $tag->metadata
				. '</x:xmpmeta><?xpacket end="w"?>';
	}
		
	protected function processDefineBinaryDataTag($tag) {
		if($tag->swfFile) {
			$this->processTags($tag->swfFile->tags);
		}
	}
	
	protected function processDefineSpriteTag($tag) {
		$this->addMovieClip($tag->characterId, $tag->tags);
	}
	
	protected function processPlaceObjectTag($tag) {
	}

	protected function processPlaceObject2Tag($tag) {
		if($tag->characterId !== null) {
			$character = $this->getCharacter($tag->characterId);
			$instance = new FLADOMSymbolInstance;
			$instance->libraryItemName = $character->name;
			$frame = $this->addFrame($tag->depth);
			$frame->elements[] = $instance;
			
			$instance->centerPoint3DX;
			$instance->centerPoint3DY;
		} 
		if($tag->matrix !== null) {
			$instance->matrix = $this->convertMatrix($tag->matrix);
		}
		if($tag->colorTransform !== null) {
			$instance->color = $this->convertColorTransform($tag->colorTransform);
		}
		if($tag instanceof SWFPlaceObject3Tag) {			
			if($tag->blendMode !== null) {
				static $blendModes = array('normal', 'normal', 'layer', 'multiply', 'screen', 'lighten', 'darken', 'difference', 'add', 'subtract', 'invert', 'alpha', 'erase', 'overlay', 'hardlight');
				$instance->blendMode = $blendModes[$instance->blendMode];
			}
			if($tag->bitmapCache !== null) {
				$instance->cacheAsBitmap = ($tag->bitmapCache) ? 'true' : 'false';
			}
			if($tag->bitmapCacheBackgroundColor !== null) {
				$instance->matteColor = $this->convertMatteColor($tag->bitmapCacheBackgroundColor);
			}
			if($tag->visibility !== null) {
				$instance->bits32 = ($tag->visibility) ? 'true' : 'false';
			}
			if($tag->filters !== null) {
				$instance->filters = $this->convertFilters($tag->filters);
			}
		}		
	}
	
	protected function processPlaceObject3Tag($tag) {
		$this->processPlaceObject2Tag($tag);
	}
	
	protected function processRemoveObjectTag($tag) {
		$frame = $this->getFrame($tag->depth);
		$frame->duration = (string) $frame->duration;
	}
	
	protected function processRemoveObject2Tag($tag) {
		$this->processRemoveObjectTag($tag);
	}
	
	protected function processDefineShapeTag($tag) {
		$this->addShape($tag->characterId, $tag->shape);
	}

	protected function processDefineShape2Tag($tag) {
		$this->processDefineShapeTag($tag);
	}

	protected function processDefineShape3Tag($tag) {
		$this->processDefineShapeTag($tag);
	}
	
	protected function processDefineShape4Tag($tag) {
		$this->processDefineShapeTag($tag);
	}
	
	protected function processShowFrameTag($tag) {
		foreach($this->timeline->layers as $depth => $layer) {
			$frame = $layer->frames[count($layer->frames) - 1];
			if(is_int($frame->duration)) {
				$frame->duration++;
			}
		}
		$this->frameIndex++;
	}
	
	protected function processJPEGTablesTag($tag) {
		$this->jpegTables = $tag->jpegData;
	}
	
	protected function processDefineBitsTag($tag) {
		$this->addBitmap($tag->characterId, $this->jpegTables . $tag->imageData);
	}
	
	protected function processDefineBitsJPEG2Tag($tag) {
		$this->addBitmap($tag->characterId, $tag->imageData);
	}
	
	protected function processDefineBitsJPEG3Tag($tag) {
		$deblocking = ($tag instanceof SWFDefineBitsJPEG4Tag) ? $tag->deblockingParam != 0 : false;
		if($tag instanceof SWFDefineBitsJPEG3Tag && $tag->alphaData && TransparentJPEGConverter::isAvailable()) {
			$converter = new TransparentJPEGConverter;
			$this->addBitmap($tag->characterId, $converter->convertToPNG($tag), $deblocking);
		} else {
			$this->addBitmap($tag->characterId, $tag->imageData, $deblocking);
		}
	}
	
	protected function processDefineBitsJPEG4Tag($tag) {
		$this->processDefineBitsJPEG3Tag($tag);
	}
	
	protected function processDefineBitsLosslessTag($tag) {
		if($this->tag instanceof SWFDefineBitsLosslessTag && LosslessBitsConverter::isAvailable()) {
			$converter = new LosslessBitsConverter;
			$this->addBitmap($tag->characterId, $converter->convertToPNG($tag));
		}
	}
	
	protected function processDefineBitsLossless2Tag($tag) {
		$this->processDefineBitsLosslessTag($tag);
	}
	
	protected function processDefineFont4Tag($tag) {
	}
	
	protected function processDoABCTag($tag) {
	}
	
	protected function processSymbolClassTag($tag) {
		foreach($tag->names as $characterId => $className) {
		}
	}
	
	protected function processDefineSceneAndFrameLabelDataTag($tag) {
		$this->sceneNames = $tag->sceneNames;
		$this->frameLabels = $tag->frameLabels;
	}

	protected function convertMatrix($record) {
		if($record->nTraslateBits || $record->nScaleBits || $record->nRotateBits) {
			$list = array();
			$matrix = new FLAMatrix;
			if($record->nTraslateBits) {
				$matrix->tx = $record->translateX / 20;
				$matrix->ty = $record->translateY / 20;
			}
			if($record->nScaleBits) {
				if($record->scaleX != 0x00010000) {
					$matrix->a = ($record->scaleX >> 16) + ($record->scaleX & 0xFFFF) / 65536.0;
				}
				if($record->scaleY != 0x00010000) {
					$matrix->d = ($record->scaleY >> 16) + ($record->scaleY & 0xFFFF) / 65536.0;
				}
			}
			if($record->nRotateBits) {
				if($record->rotateSkew0 != 0) {
					$matrix->b = ($record->rotateSkew0 >> 16) + ($record->rotateSkew0 & 0xFFFF) / 65536.0;
				}
				if($record->rotateSkew1 != 0) {
					$matrix->c = ($record->rotateSkew1 >> 16) + ($record->rotateSkew1 & 0xFFFF) / 65536.0;
				}
			}
			$list[] = $matrix;
			return $list;
		}
	}
	
	protected function convertColorTransform($record) {
		$list = array();
		$transform = new FLAColor;
		
		// see if a specific transform is employed
		$generic = true;
		if($record->redMultTerm == $record->blueMultTerm && $record->blueMultTerm == $record->greenMultTerm) {
			if($record->redAddTerm == $record->blueAddTerm && $record->blueAddTerm == $record->greenAddTerm) {
				if($record->alphaMultTerm == 256 && $record->alphaAddTerm == 0) {
					// brightness
					if($record->redAddTerm !== null) {
						$brightness1 = round(1 - ($record->redMultTerm / 256.0), 2);
						$brightness2 = round($record->redAddTerm / 256.0, 2);
						// make sure both calculations give the same result
						if($brightness1 == $brightness2) {						
							$transform->brightness = $brightness1;
							$generic = false;
						}
					} else {
						$transform->brightness = - round(1 - ($record->redMultTerm / 256.0), 2);
						$generic = false;
					}
				} else if($record->redMultTerm == 256 && $record->redAddTerm == 0) {
					// alpha
					$transform->alphaMultiplier = round($record->alphaMultTerm / 256.0, 2);
					$generic = false;
				}
			} else {
				if($record->alphaMultTerm == 256 && $record->alphaAddTerm == 0) {
					// tint
					$tintMultiplier = round(1 - ($record->redMultTerm / 256.0), 2);
					$red = floor($record->redAddTerm / $tintMultiplier);
					$green = floor($record->greenAddTerm / $tintMultiplier);
					$blue = floor($record->blueAddTerm / $tintMultiplier);
					$transform->tintMultiplier = $tintMultiplier;
					$transform->tintColor = sprintf("#%02X%02X%02X", $red, $green, $blue);		
					$generic = false;
				}
			}
		}
		
		if($generic) {
			$transform->alphaMultiplier = round($record->alphaMultTerm / 256.0, 2);
			$transform->redMultiplier = round($record->redMultTerm / 256.0, 2);
			$transform->greenMultiplier = round($record->greenMultTerm / 256.0, 2);
			$transform->blueMultiplier = round($record->blueMultTerm / 256.0, 2);
			$transform->alphaOffset = $record->alphaAddTerm;
			$transform->redOffset = $record->redAddTerm;
			$transform->greenOffset = $record->greenAddTerm;
			$transform->blueOffset = $record->blueAddTerm;
		}
		
		$list[] = $transform;
		return $list;
	}
	
	protected function convertFilters($records) {
		$list = array();
		foreach($records as $record) {
			print_r($record);
			if($record instanceof SWFDropShadowFilter) {
				$filter = new FLADropShadowFilter;
				$filter->color = $this->convertRGB($record->shadowColor);
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->angle = $record->angle / 65536.0 * 57.2957795;
				$filter->distance = $record->distance / 65536.0;
				$filter->strength = ($record->strength != 256) ? $record->strength / 256.0 : null;
				if(!($record->flags & 0x01)) {
					$filter->hideObject = 'true';
				}
				if($record->flags & 0x02) {
					$filter->knockout = 'true';
				}
				if($record->flags & 0x04) {
					$filter->inner = 'true';
				}
				$filter->quality = $record->passes;
			} else if($record instanceof SWFBlurFilter) {
				$filter = new FLABlurFilter;
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->quality = $record->passes;
			} else if($record instanceof SWFGlowFilter) {
				$filter = new FLAGlowFilter;
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->color = $this->convertRGB($record->color);
				$filter->strength = ($record->strength != 256) ? $record->strength / 256.0 : null;
				if($record->flags & 0x02) {
					$filter->knockout = 'true';
				}
				if($record->flags & 0x04) {
					$filter->inner = 'true';
				}
				$filter->quality = $record->passes;
			} else if($record instanceof SWFBevelFilter) {
				$filter = new FLABevelFilter;
				$filter->shadowColor = $this->convertRGB($record->shadowColor);
				$filter->highlightColor = $this->convertRGB($record->highlightColor);
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->angle = $record->angle / 65536.0 * 57.2957795;
				$filter->distance = $record->distance / 65536.0;
				$filter->strength = ($record->strength != 256) ? $record->strength / 256.0 : null;
				if($record->flags & 0x04) {
					$filter->knockout = 'true';
				}
				switch($record->flags & ~0x04) {
					case 2: $filter->type = 'outer'; break;
					case 3: $filter->type = 'full'; break;
				}
				$filter->quality = $record->passes;
			} else if($record instanceof SWFGradientGlowFilter) {
				$filter = new FLAGradientGlowFilter;
				foreach($record->colors as $index => $color) {
					$ratio = $record->ratios[$index];
					$entry = new FLAGradientEntry;
					$entry->color = $this->convertRGB($color);
					$entry->alpha = $this->convertAlpha($color);
					$entry->ratio = $ratio / 255.0;
					$name = "entry$index";
					$filter->$name = $entry;
				}
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->angle = $record->angle / 65536.0 * 57.2957795;
				$filter->distance = $record->distance / 65536.0;
				$filter->strength = ($record->strength != 256) ? $record->strength / 256.0 : null;
				if($record->flags & 0x04) {
					$filter->knockout = 'true';
				}
				switch($record->flags & ~0x04) {
					case 2: $filter->type = 'outer'; break;
					case 3: $filter->type = 'full'; break;
				}
				$filter->quality = $record->passes;
			} else if($record instanceof SWFConvolutionFilter) {
			} else if($record instanceof SWFColorMatrixFilter) {
			} else if($record instanceof SWFGradientBevelFilter) {
				$filter = new FLAGradientBevelFilter;
				foreach($record->colors as $index => $color) {
					$ratio = $record->ratios[$index];
					$entry = new FLAGradientEntry;
					$entry->color = $this->convertRGB($color);
					$entry->alpha = $this->convertAlpha($color);
					$entry->ratio = $ratio / 255.0;
					$name = "entry$index";
					$filter->$name = $entry;
				}
				$filter->blurX = $record->blurX / 65536.0;
				$filter->blurY = $record->blurY / 65536.0;
				$filter->angle = $record->angle / 65536.0 * 57.2957795;
				$filter->distance = $record->distance / 65536.0;
				$filter->strength = ($record->strength != 256) ? $record->strength / 256.0 : null;
				if($record->flags & 0x04) {
					$filter->knockout = 'true';
				}
				switch($record->flags & ~0x04) {
					case 2: $filter->type = 'outer'; break;
					case 3: $filter->type = 'full'; break;
				}
				$filter->quality = $record->passes;
			}
			print_r($filter);
			$list[] = $filter;
		}
		return $list;
	}
				
	protected function convertFills($records) {
		$list = array();
		foreach($records as $index => $record) {
			$fillStyle = $list[] = new FLAFillStyle;
			$fillStyle->index = $index + 1;
			switch($record->type) {
				case 0x00:
					$fillStyle->solidColor = $this->convertSolidColor($record->color);
					break;
				case 0x10:
					$fillStyle->linearGradient = $this->convertLinearGradient($record->gradient, $record->gradientMatrix);
					break;
				case 0x12:
				case 0x13:
					$fillStyle->radialGradient = $this->convertRadialGradient($record->gradient, $record->gradientMatrix);
					break;
				case 0x40:
				case 0x41:
				case 0x42:
				case 0x43:
					$fillStyle->bitmapFill = $this->convertBitmapFill($record->type, $record->bitmapId, $record->bitmapMatrix);
					break;
					
			}
		}
		return $list;
	}
	
	protected function convertStrokes($records) {
		$list = array();
		foreach($records as $record) {
			$lineStyle = $list[] = new FLALineStyle;
			$lineStyle->index = $index + 1;
			if($lineStyle->width !== null) {
				$lineStyle->width = $record->width;
			}
			if($lineStyle instanceof SWFLineStyle2) {
			}
		}
		return $list;
	}
	
	protected function convertSolidColor($record) {
		$solid = new FLASolidColor;
		$solid->color = $this->convertRGB($record);
		return $solid;
	}
		
	protected function convertMatteColor($record) {
		$solid = new FLASMatteColor;
		$solid->color = $this->convertRGB($record);
		return $solid;
	}
	
	protected function convertLinearGradient($record, $matrixRecord) {
		$gradient = new FLALinearGradient;
		$gradient->matrix = $this->convertMatrix($matrixRecord);
		if($record->interpolationMode == 1) {
			$gradient->interpolationMethod = "linearRGB";
		}
		foreach($record->controlPoints as $index => $controlPoint) {
			$name = "entry$index";
			$gradient->$name = $this->convertGradientControlPoint($controlPoint);
		}
		return $gradient;
	}
	
	protected function convertRadialGradient($record, $matrixRecord) {
		$gradient = new FLARadialGradient;
		$gradient->matrix = $this->convertMatrix($matrixRecord);
		if($record->interpolationMode == 1) {
			$gradient->interpolationMethod = "linearRGB";
		}
		if($record instanceof SWFFocalGradient) {
			$gradient->focalPointRatio = ($record->focalPoint >> 8) + (($record->focalPoint & 0x00FF) / 256.0);
		}
		foreach($record->controlPoints as $index => $controlPoint) {
			$name = "entry$index";
			$gradient->$name = $this->convertGradientControlPoint($controlPoint);
		}
		return $gradient;
	}
	
	protected function convertGradientControlPoint($record) {
		$entry = new FLAGradientEntry;
		$entry->color = $this->convertRGB($record->color);
		$entry->alpha = $this->convertAlpha($record->color);
		$entry->ratio = $record->ratio / 255.0;
		return $entry;
	}
	
	protected function convertBitmapFill($type, $bitmapId, $matrixRecord) {
		if($bitmapId != 0xFFFF) {
			$bitmap = $this->getCharacter($bitmapId);
			$fill = new FLABitmapFill;
			$fill->matrix = $this->convertMatrix($matrixRecord);
			$fill->bitmapPath = $bitmap->name;
			$fill->bitmapIsClipped = ($type == 0x41 || $type == 0x43) ? 'true' : 'false';
			return $fill;
		}
	}
	
	protected function convertRGB($record) {
		if($record->red || $record->green || $record->blue) {
			return sprintf("#%02X%02X%02X", $record->red, $record->green, $record->blue);
		}
	}
	
	protected function convertAlpha($record) {
		if($record->alpha != 255) {
			return $record->alpha / 255.0;
		}
	}
	
	protected function convertEdges($records) {
		$list = array();
		$edge = $list[] = new FLAEdge;
		$tokens = array();
		$x = 0;
		$y = 0;
		$flags = 0;
		foreach($records as $record) {
			if($record instanceof SWFStyleChange) {
				if($record->fillStyle0 !== null || $record->fillStyle1 !== null || $record->lineStyle !== null) {
					if($tokens) {
						$edge->edges = implode($tokens);
						$edge = $list[] = new FLAEdge;
						$tokens = array();
					}
					
					if($record->fillStyle0 !== null) {
						$edge->fillStyle0 = $record->fillStyle0;
						$flags |= 0x01;
					}
					if($record->fillStyle1 !== null) {
						$edge->fillStyle1 = $record->fillStyle1;
						$flags |= 0x02;
					}
					if($record->lineStyle !== null) {
						$edge->lineStyle = $record->lineStyle;
						$flags |= 0x04;
					}
				}
				if($record->moveDeltaX !== null) {
					$x = $record->moveDeltaX;
					$y = $record->moveDeltaY;
				}
			} else if($record instanceof SWFStraightEdge) {
				$tokens[] = "!$x $y";
				if($flags) {
					$tokens[] = "S$flags";
					$flags = 0;
				}
				if($record->deltaX && $record->deltaY) {
					$x += $record->deltaX;
					$y += $record->deltaY;
					
				} else if($record->deltaX) {
					$x += $record->deltaX;
				} else {
					$y += $record->deltaY;
				}
				$tokens[] = "|$x $y";
			} else if($record instanceof SWFQuadraticCurve) {
				$tokens[] = "!$x $y";
				$cx = $x + $record->controlDeltaX;
				$cy = $y + $record->controlDeltaY;
				$x = $cx + $record->anchorDeltaX;
				$y = $cy + $record->anchorDeltaY;
				$tokens[] = "[$cx $cy $x $y";
			}
		}
		$edge->edges = implode($tokens);
		return $list;
	}
}

?>