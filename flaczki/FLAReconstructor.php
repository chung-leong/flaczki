<?php

class FLAReconstructor {

	protected $output;
	protected $lastModified;
	protected $timeline;
	protected $frameIndex;
	protected $frameLabels;
	protected $removedFrames;
	protected $sceneNames;
	protected $jpegTables;
	protected $dictionary;
	protected $metadata;
	protected $buttonFlags;
	protected $tlfTextObjects;
	protected $tlfTextFieldSymbol;
	protected $embeddedFLAFile;
	
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
		$this->lastModified = ($lastModified) ? $lastModified : time();
		$this->dictionary = array();
		$this->buttonFlags = array();
		$this->tlfTextObjects = array();
		$this->tlfTextFieldSymbol = null;
		$this->embeddedFLAFiles = array();
		
		$flaFile = new FLAFile;
		$flaFile->document = $this->dictionary[0] = new FLADOMDocument;
		$flaFile->document->timelines = $this->createTimelines($swfFile->tags);
		
		if($this->embeddedFLAFile) {
			return $this->embeddedFLAFile;
		}
		
		$this->optimizeSymbols($this->dictionary);
		
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
		$flaFile->document->nextSceneIdentifier = count($flaFile->document->timelines) + 1;
		$flaFile->library = $this->createLibrary($this->dictionary);
		$flaFile->document->symbols = $this->createSymbolIncludes($flaFile->library);
		$flaFile->document->media = $this->createMediaIncludes($flaFile->library);
		$flaFile->metadata = $this->metadata;
		return $flaFile;
	}
	
	protected function createLibrary($dictionary) {
		$library = array();
		$nameIndices = array();
		$itemId1 = 0x50100000;
		$itemId2 = 0x00000100;
		foreach($dictionary as $characterId => $character) {
			// add objects in the dictionary that need to be in the library
			if($character instanceof FLADOMSymbolItem) {
				if($character == $this->tlfTextFieldSymbol) {
					// don't include the TLFTextField symbol
					continue;
				}
				switch($character->symbolType) {
					case 'button': $prefix = 'Button'; break;
					case 'graphic': $prefix = 'Graphic'; break;
					default: $prefix = 'Movie Clip'; break;
				}
				$extension = 'xml';
			} else if($character instanceof FLABitmap) {
				$prefix = 'Bitmap';
				switch($character->mimeType) {
					case 'image/jpeg': $extension = 'jpg'; break;
					case 'image/png': $extension = 'png'; break;
					case 'image/gif': $extension = 'gif'; break;
					default: $extension = '';
				}
			} else if($character instanceof FLAVideo) {
				$prefix = 'Video';
				$extension = 'flv';
			} else {
				$prefix = null;
			}
			if($prefix) {
				// assign a name to the library item
				$number =& $nameIndices[$prefix];
				$number++;
				$character->name = "$prefix $number";
				
				// assign an itemID
				$character->itemID = sprintf("%08x-%08x", $itemId1, $itemId2++);
				
				// add item at specific path
				$href = "$character->name.$extension";
				$library[$href] = $character;
			}
		}
		return $library;
	}
	
	protected function createSymbolIncludes($library) {
		$list = array();
		foreach($library as $href => $character) {
			if($character instanceof FLADOMSymbolItem) {
				$include = new FLAInclude;
				$include->loadImmediate = "false";
				$include->itemID = $character->itemID;
				$include->href = $href;
				$include->lastModified = $character->lastModified;
				switch($character->symbolType) {
					case 'graphic': $include->itemIcon = "1"; break;
					case 'button': $include->itemIcon = "0"; break;
				}
				$list[] = $include;
			}
		}
		return $list;
	}
	
	protected function createMediaIncludes($library) {
		$list = array();
		foreach($library as $href => $character) {
			if($character instanceof FLABitmap) {
				$item = new FLADOMBitmapItem;
				$item->name = $character->name;
				$item->itemID = $character->itemID;
				$item->href = $href;
				$item->externalFileSize = strlen($character->data);
				$item->originalCompressionType = ($character->mimeType == 'image/jpeg') ? 'losssly' : 'lossless';
				$item->quality = 50;
				$item->frameRight = $character->width * 20;
				$item->frameBottom = $character->height * 20;
				$item->allowSmoothing = $character->allowSmoothing;
				$list[] = $item;
			} else if($character instanceof FLAVideo) {
				$item = new FLADOMVideoItem;
				$item->name = $character->name;
				$item->itemID = $character->itemID;
				$item->videoType = "$character->codec media";
				$item->width = $character->width;
				$item->height = $character->height;
				$list[] = $item;
			}
			
		}
		return $list;
	}
	
	protected function createTimelines($tags) {
		// save previous values
		$previousTimeline = $this->timeline;
		$previousFrameIndex = $this->frameIndex;
		$previousFrameLabels = $this->frameLabels;
		$previousSceneNames = $this->sceneNames;
		$previousRemovedFrames = $this->removedFrames;
		
		// add frames to timeline
		$this->timeline = new FLADOMTimeline;
		$this->timeline->layers = array();
		$this->frameIndex = 0;
		$this->frameLabels = null;
		$this->sceneNames = null;
		$this->removedFrames = array();
		
		// process the tags
		foreach($tags as $tag) {
			$tagName = substr(get_class($tag), 3, -3);
			//$memeoryUsage = memory_get_usage(); echo "$tagName ($memeoryUsage)\n";
			$methodName = "process{$tagName}Tag";
			if(method_exists($this, $methodName)) {
				$this->$methodName($tag);
			}
		}

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
		$this->removedFrames = $previousRemovedFrames;
		return $timelines;
	}
	
	protected function optimizeSymbols(&$dictionary) {
		foreach($dictionary as $characterId => $character) {
			if($characterId == 0) {
				continue;
			}
			if($character instanceof FLADOMSymbolItem) {
				if($character->symbolType == 'graphic') {
					if(count($character->references) == 1) {
						// the graphic is only referenced once
						$timeline = $character->references[0];
						if(count($timeline->layers) == 1) {
							// there's only one layer on the timeline
							$originalLayer = reset($timeline->layers);
							if(count($originalLayer->frames) == 1) {
								// it has only one frame
								$originalFrame = $originalLayer->frames[0];
	
								// put layers of the graphic object into the timeline
								$timeline->layers = $character->timeline[0]->layers;
								foreach($timeline->layers as $layer) {
									foreach($layer->frames as $frame) {
										$frame->index = $originalFrame->index;
										$frame->duration = $originalFrame->duration;
									}
								}
	
								// remove the graphic from the dictionary
								unset($dictionary[$characterId]);
							}
						}
					}
				}
			}
			$character->references = null;			
		}
	}
		
	protected function getLayerColor($depth) {
		static $colors = array(0x4FFF4F, 0x9933CC, 0xFF800A, 0xFF4FFF, 0x4FFFFF, 0x808080, 0x4F80FF, 0xFF4F4F);
		$index = (int) ($depth - 1) % 8;
		return sprintf("#%06X", $colors[$index]);
	}
	
	protected function getLayer($depth) {
		if(isset($this->timeline->layers[$depth])) {
			$layer = $this->timeline->layers[$depth];
		} else {
			$layer = new FLADOMLayer;
			$layer->name = "Layer $depth";
			$layer->color = $this->getLayerColor($depth);
			$layer->frames = array();
			$this->timeline->layers[$depth] = $layer;
		}
		return $layer;
	}
	
	protected function getCharacter($characterId) {
		return $this->dictionary[$characterId];
	}
	
	protected function addCharacter($characterId, $character) {
		if(isset($this->dictionary[$characterId])) {
			$original = $this->dictionary[$characterId];
			$original->references = array();
		}
		$this->dictionary[$characterId] = $character;
	}
	
	protected function getFrame($depth) {
		$layer = $this->getLayer($depth);
		if($layer->frames) {
			$frame = $layer->frames[count($layer->frames) - 1];
			return $frame;
		}
	}
	
	protected function getPreviousFrame($depth) {
		$frame = $this->getFrame($depth);
		if($frame) {
			if($frame->index + $frame->duration == $this->frameIndex) {
				return $frame;
			}
		}
	}
	
	protected function addFrame($depth) {
		$layer = $this->getLayer($depth);
		if($this->frameIndex > 0) {
			$lastFrameIndex = 0;
			if($layer->frames) {
				$previousFrame = $layer->frames[count($layer->frames) - 1];
				$lastFrameIndex = $previousFrame->index + $previousFrame->duration;
			}
			if($lastFrameIndex != $this->frameIndex) {
				// add some empty frames
				$emptyFrame = new FLADOMFrame;
				$emptyFrame->index = $lastFrameIndex;
				$emptyFrame->duration = $this->frameIndex - $lastFrameIndex;
				$emptyFrame->keyMode = 0;
				$emptyFrame->elements = array();
				$layer->frames[] = $emptyFrame;
			}
		}
		$frame = new FLADOMFrame;
		$frame->index = $this->frameIndex;
		$frame->duration = 0;
		$frame->keyMode = 0x2600;
		$frame->elements = array();
		$layer->frames[] = $frame;
		return $frame;
	}
	
	protected function addMovieClip($characterId, $tags) {
		$movieClip = new FLADOMSymbolItem;
		$movieClip->lastModified = $this->lastModified;
		$movieClip->timeline = $this->createTimelines($tags);
		$movieClip->timeline[0]->name =& $movieClip->name;
		$this->dictionary[$characterId] = $movieClip;
	}
	
	protected function addShape($characterId, $shapeRecord) {
		// see if the shape records add any new style
		// that indicates that the original graphic has multiple layers
		$layerCount = 1;
		foreach($shapeRecord->edges as $record) {
			if($record instanceof SWFStyleChange && ($record->newFillStyles || $record->newLineStyles)) {
				$layerCount++;
			}
		}
		if($layerCount > 1) {
			// create a graphic object
			$timeline = new FLADOMTimeline;
			$timeline->layers = array();
			$graphic = new FLADOMSymbolItem;
			$graphic->symbolType = "graphic";
			$timeline->name =& $graphic->name;
			$graphic->lastModified = $this->lastModified;
			$graphic->timeline = array($timeline);
			
			// split up the shape and put each part on each own layer
			$recordCount = count($shapeRecord->edges);
			$fillStyles = $shapeRecord->fillStyles;
			$lineStyles = $shapeRecord->lineStyles;
			$edges = array();
			for($i = 0; $i <= $recordCount; $i++) {
				$record = ($i < $recordCount) ? $shapeRecord->edges[$i] : null;
				if(($record instanceof SWFStyleChange && ($record->newFillStyles || $record->newLineStyles)) || !$record) {
					$shape = new FLADOMShape;
					$shape->fills = $this->convertFills($fillStyles);
					$shape->strokes = $this->convertStrokes($lineStyles);
					$shape->edges = $this->convertEdges($edges);
				
					$frame = new FLADOMFrame;
					$frame->index = 0;
					$frame->keyMode = 0x2600;
					$frame->elements = array($shape);
					$layer = new FLADOMLayer;
					$layer->color = $this->getLayerColor($layerCount);
					$layer->name = "Layer " . $layerCount--;
					$layer->frames = array($frame);
					$timeline->layers[] = $layer;
					
					if($record) {
						$fillStyles = $record->newFillStyles;
						$lineStyles = $record->newLineStyles;
						$edges = array();
					}
				} else {
					$edges[] = $record;
				}
			}
			
			$this->dictionary[$characterId] = $graphic;
		} else {
			$shape = new FLADOMShape;
			$shape->fills = $this->convertFills($shapeRecord->fillStyles);
			$shape->strokes = $this->convertStrokes($shapeRecord->lineStyles);
			$shape->edges = $this->convertEdges($shapeRecord->edges);
			$this->dictionary[$characterId] = $shape;
		}
	}
	
	protected function convertToGraphic($characterId, $character) {
		$timeline = new FLADOMTimeline;
		$timeline->layers = array();
		$graphic = new FLADOMSymbolItem;
		$graphic->symbolType = "graphic";
		$timeline->name =& $graphic->name;
		$graphic->lastModified = $this->lastModified;
		$graphic->timeline = array($timeline);
		
		$frame = new FLADOMFrame;
		$frame->index = 0;
		$frame->keyMode = 0x2600;
		$frame->elements = array($character);
		$layer = new FLADOMLayer;
		$layer->color = $this->getLayerColor(1);
		$layer->name = "Layer 1";
		$layer->frames = array($frame);
		$timeline->layers[] = $layer;
		
		// replace previous occurences
		foreach($character->references as $timeline) {
			foreach($timeline->layers as $layer) {
				foreach($layer->frames as $frame) {
					foreach($frame->elements as &$element) {
						if($element === $character) {
							$instance = new FLADOMSymbolInstance;
							$instance->libraryItemName =& $graphic->name;
							$element = $instance;
						}
					}
				}
			}
		}
		
		$this->dictionary[$characterId] = $graphic;
		$graphic->references = $character->references;
		$character->references = array();
		return $graphic;
	}
		
	protected function addMorphShape($characterId, $morphRecord) {
		$morph = new FLAMorphShape;
		$morph->startShape = new FLADOMShape;
		$morph->endShape = new FLADOMShape;
		$morph->morphSegments = $this->convertMorphSegments($morphRecord->startEdges, $morphRecord->endEdges);
		list($morph->startShape->fills, $morph->endShape->fills) = $this->convertMorphFills($morphRecord->fillStyles);
		list($morph->startShape->strokes, $morph->endShape->strokes) = $this->convertMorphStrokes($morphRecord->lineStyles);
		$morph->startShape->edges = $this->convertEdges($morphRecord->startEdges);
		$morph->endShape->edges = $this->convertEdges($morphRecord->endEdges);
		
		$this->dictionary[$characterId] = $morph;
	}
		
	protected function addBitmap($characterId, $imageData, $deblocking = false) {
		$imagePath = StreamMemory::add($imageData);
		$imageInfo = getimagesize($imagePath);
		
		$bitmap = new FLABitmap;
		$bitmap->data = $imageData;		
		$bitmap->mimeType = $imageInfo['mime'];
		$bitmap->width = $imageInfo[0];
		$bitmap->height = $imageInfo[1];
		
		$this->dictionary[$characterId] = $bitmap;
	}
	
	protected function addVideo($characterId, $record) {
		static $codes = array( 2 => 'h263', 3 => 'screen share', 4 => 'vp6', 5 => 'vp6 alpha' );
		$video = new FLAVideo;
		$video->data = "FLV\x01\x01\x00\x00\x00\x09\x00\x00\x00\x00";
		$video->width = $record->width;
		$video->height = $record->height;
		$video->deblockingLevel = ($record->flags >> 1) & 0x07;
		$video->smoothing = $record->flags & 0x01;
		$video->frameCount = $record->frameCount;
		$video->codec = $codes[$record->codecId];
		$video->codecId = $record->codecId;
		
		$this->dictionary[$characterId] = $video;
	}
	
	protected function addButton($characterId, $records) {
		$button = new FLADOMSymbolItem;
		$button->lastModified = $this->lastModified;
		$button->symbolType = 'button';
		$button->timeline = array();		
		
		$timeline = $button->timeline[] = new FLADOMTimeline;
		$timeline->name =& $button->name;
		$timeline->layers = array();
		foreach($records as $record) {
			$character = $this->dictionary[$record->characterId];
			if($character instanceof FLADOMSymbolItem) {
				$instance = new FLADOMSymbolInstance;
				$instance->libraryItemName =& $character->name;
			} else if($character instanceof FLAMorphShape || $character instanceof FLAVideo) {
				continue;
			} else {
				$instance = $character;
			}
			if($record->matrix !== null) {
				$instance->matrix = $this->convertMatrix($record->matrix);
			}
			if($record->colorTransform !== null) {
				if($record->colorTransform->redMultTerm !== null || $record->colorTransform->redAddTerm !== null) {
					$instance->color = $this->convertColorTransform($record->colorTransform);
				}
			}
			if($record->blendMode !== null) {
				static $blendModes = array('normal', 'normal', 'layer', 'multiply', 'screen', 'lighten', 'darken', 'difference', 'add', 'subtract', 'invert', 'alpha', 'erase', 'overlay', 'hardlight');
				$instance->blendMode = $blendModes[$record->blendMode];
			}
			if($record->filters !== null) {
				$instance->filters = $this->convertFilters($record->filters);
			}
		
			if(isset($timeline->layers[$record->placeDepth])) {
				$layer = $timeline->layers[$record->placeDepth];
			} else {
				$layer = new FLADOMLayer;
				$layer->name = "Layer $record->placeDepth";
				$layer->color = $this->getLayerColor($record->placeDepth);
				$layer->frames = array();
				$timeline->layers[$record->placeDepth] = $layer;
			}

			// add instance to appropriate frames			
			if($record->flags & 0x0001) {
				$frame = $layer->frames[] = new FLADOMFrame;
				$frame->index = 0;
				$frame->duration = 1;
				$frame->elements = array($instance);
				if($record->flags & 0x0002) {
					// extend to over state
					$frame->duration++;
					if($record->flags & 0x0004) {
						// extend to down state
						$frame->duration++;
						if($record->flags & 0x0008) {
							// extend to hit test
							$frame->duration++;
						}
					}
				}
			}
			if(($record->flags & 0x0003) == 0x0002) {
				$frame = $layer->frames[] = new FLADOMFrame;
				$frame->index = 1;
				$frame->duration = 1;
				$frame->elements = array($instance);
				if($record->flags & 0x0004) {
					$frame->duration++;
					if($record->flags & 0x0008) {
						$frame->duration++;
					}
				}
			}
			if(($record->flags & 0x0006) == 0x0004) {
				$frame = $layer->frames[] = new FLADOMFrame;
				$frame->index = 2;
				$frame->duration = 1;
				$frame->elements = array($instance);
				if($record->flags & 0x0008) {
					$frame->duration++;
				}
			}
			if(($record->flags & 0x000C) == 0x0008) {
				$frame = $layer->frames[] = new FLADOMFrame;
				$frame->index = 3;
				$frame->duration = 1;
				$frame->elements = array($instance);
			}
		}
		$timeline->layers = array_reverse($timeline->layers, true);
		$this->dictionary[$characterId] = $button;
	}
	
	protected function findFont($name) {
		foreach($this->dictionary as $object) {
			if($object instanceof FLAFont) {
				if($object->name == $name) {
					return $object;
				}
			}
		}
	}

	protected function processMetadataTag($tag) {
		$this->metadata = '<?xpacket begin="?" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 5.2-c003 61.141987, 2011/02/22-12:03:51 ">'
				. $tag->metadata
				. '</x:xmpmeta><?xpacket end="w"?>';
	}
		
	protected function processDefineBinaryDataTag($tag) {
		$this->dictionary[$tag->characterId] = $tag->swfFile ? $tag->swfFile : $tag->data;
	}
	
	protected function processDefineSpriteTag($tag) {
		$this->addMovieClip($tag->characterId, $tag->tags);
	}
	
	protected function processPlaceObjectTag($tag) {
	}

	protected function processPlaceObject2Tag($tag) {
		$previousFrame = $this->getPreviousFrame($tag->depth);
		$previousInstance = ($previousFrame) ? $previousFrame->elements[0] : null;
		$instance = null;
		$character = null;
		if($tag->characterId !== null) {		
			$characterId = $tag->characterId;
			$character = $this->dictionary[$characterId];
			if(!$character) {
				return;
			}
			if($character instanceof FLAMorphShape) {
				if($character->startShape) {
					$instance = $character->startShape;
					$character->startShape = null;
					$character->endShape = null;
				}
			} else if($character instanceof FLAVideo) {
				$instance = new FLADOMVideoInstance;
				$instance->libraryItemName =& $character->name;
				$instance->frameRight = $character->width;
				$instance->frameBottom = $character->height;
			} else if($character instanceof FLADOMShape || $character instanceof FLADOMDynamicText) {
				$convertToGraphic = false;
				if($character->references) {
					// character has been referenced previously--it was probably a graphic object
					$convertToGraphic = true;
				} else if(($tag->matrix || $tag->colorTransform) && $character instanceof FLADOMShape) {
					// there're transforms on the character--which cannot be done on a naked shape 
					$convertToGraphic = true;
				} else if($tag->colorTransform && $character instanceof FLADOMDynamicText) {
					// there's a color transform on the character--which cannot be set on a classic text field
					$convertToGraphic = true;
				}
				if($convertToGraphic) {
					$character = $this->convertToGraphic($characterId, $character);
					$instance = new FLADOMSymbolInstance;
					$instance->libraryItemName =& $character->name;
				} else {
					$instance = $character;
				}
			} else if($character instanceof FLADOMSymbolItem) {
				$instance = new FLADOMSymbolInstance;
				$instance->libraryItemName =& $character->name;
				if($character->symbolType == 'button') {
					// track as menu is set by the DefineButton2 tag, yet it's treated as an instance property in Flash Professional
					if(isset($this->buttonFlags[$characterId])) {
						$buttonFlags = $this->buttonFlags[$characterId];
						if($buttonFlags & SWFDefineButton2Tag::TrackAsMenu) {
							$instance->trackAsMenu = 'true';
						}
					}
				}
			}			
			if(!in_array($this->timeline, $character->references)) {
				$character->references[] = $this->timeline;
			}
		} else {
			if(!$previousFrame) { 
				return;
			}
			if($previousInstance instanceof FLADOMShape || $previousInstance instanceof FLADOMDynamicText) {
				$characterId = array_search($previousInstance, $this->dictionary, true);
				$character = $this->convertToGraphic($characterId, $previousInstance);
				$instance = new FLADOMSymbolInstance;
				$instance->libraryItemName =& $character->name;				
			} else if($previousInstance instanceof FLADOMSymbolInstance) {
				$instance = clone $previousInstance;
			}
		}
		if($instance) {
			$frame = $this->addFrame($tag->depth);
			$frame->elements[] = $instance;
			
			if($tag->matrix !== null) {
				$instance->matrix = $this->convertMatrix($tag->matrix);
			}
			if($tag->colorTransform !== null) {
				$instance->color = $this->convertColorTransform($tag->colorTransform);
			}
			if($tag->name !== null) {
				$instance->name = $tag->name;
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
		if($tag->ratio) {
		}
	}
	
	protected function processPlaceObject3Tag($tag) {
		$this->processPlaceObject2Tag($tag);
	}
	
	protected function processRemoveObjectTag($tag) {
		$frame = $this->getFrame($tag->depth);
		$this->removedFrame[] = $frame;
	}
	
	protected function processRemoveObject2Tag($tag) {
		$this->processRemoveObjectTag($tag);
	}
	
	protected function processDefineButtonTag($tag) {
		$this->addButton($tag->characterId, $tag->characters);
	}
	
	protected function processDefineButton2Tag($tag) {
		$this->addButton($tag->characterId, $tag->characters);
		$this->buttonFlags[$tag->characterId] = $tag->flags;
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
	
	protected function processDefineMorphShapeTag($tag) {
		$this->addMorphShape($tag->characterId, $tag->morphShape);
	}
	
	protected function processDefineMorphShape2Tag($tag) {
		$this->processDefineMorphShapeTag($tag);
	}
	
	protected function processShowFrameTag($tag) {
		foreach($this->timeline->layers as $depth => $layer) {
			$frame = $layer->frames[count($layer->frames) - 1];
			if($frame->index + $frame->duration == $this->frameIndex) {
				if(!in_array($frame, $this->removedFrames)) {
					$frame->duration++;
				}
			}
		}
		$this->removedFrames = array();
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
		if($tag instanceof SWFDefineBitsLosslessTag && LosslessBitsConverter::isAvailable()) {
			$converter = new LosslessBitsConverter;
			$this->addBitmap($tag->characterId, $converter->convertToPNG($tag));
		}
	}
	
	protected function processDefineBitsLossless2Tag($tag) {
		$this->processDefineBitsLosslessTag($tag);
	}
	
	protected function processDefineEditTextTag($tag) {
		$textAttrs = new FLADOMTextAttrs;
		static $alignments = array('left', 'right', 'center', 'justify');
		$textAttrs->alignment = $alignments[$tag->align];
		$textAttrs->fillColor = $this->convertRGB($tag->textColor);
		$textAttrs->alpha = $this->convertAlpha($tag->textColor);
		$textAttrs->indent = $tag->indent / 20;
		$textAttrs->leftMargin = $tag->leftMargin / 20;
		$textAttrs->rightMargin = $tag->rightMargin / 20;
		$textAttrs->size = $tag->fontHeight / 20;
		$textAttrs->lineSpacing = $tag->leading / 20;
		if($tag->fontId) {
			$font = $this->dictionary[$tag->fontId];
			$textAttrs->face = $font->fullName;
		}
		
		if($tag->flags & SWFDefineEditTextTag::WasStatic) {
			$text = new FLADOMStaticText;
		} else if($tag->flags & SWFDefineEditTextTag::ReadOnly) {
			$text = new FLADOMDynamicText;
		} else {
			$text = new FLADOMInputText;
		}
		$text->width = ($tag->bounds->right - $tag->bounds->left) / 20 - 4;
		$text->height = ($tag->bounds->bottom - $tag->bounds->top) / 20 - 4;
		$text->maxCharacters = $tag->maxLength;
		if($tag->flags & SWFDefineEditTextTag::NoSelect) {
			$text->isSelectable = 'false';
		}
		if($tag->flags & SWFDefineEditTextTag::AutoSize) {
			$text->autoExpand = 'true';
		}
		if($tag->flags & SWFDefineEditTextTag::Border) {
			$text->border = 'true';
		}
		if($tag->flags & SWFDefineEditTextTag::Multiline) {
			if($tag->flags & SWFDefineEditTextTag::WordWrap) {
				$text->lineType = 'multiline';
			} else {
				$text->lineType = 'multiline no wrap';
			}
		} else if($tag->flags & SWFDefineEditTextTag::Password) {
			$text->lineType = 'password';
		}
		if($tag->flags & SWFDefineEditTextTag::UseOutlines) {
			$text->fontRenderingMode = 'device';
		} else {
			$text->fontRenderingMode = 'standard';
		}
		if($tag->flags & SWFDefineEditTextTag::HTML) {
			$text->renderAsHTML = 'true';
			$html = $tag->initialText;
			
			$text->textRuns = array();
			$textAttrStack = array();
			$currentTextRun = null;
	
			$endIndex = -1;
			while(($startIndex = strpos($html, '<', $endIndex + 1)) !== false) {
				if($endIndex + 1 < $startIndex) {
			   		// body text
					$characters = html_entity_decode(substr($html, $endIndex + 1, $startIndex - $endIndex - 1), ENT_COMPAT, 'UTF-8');
					$currentTextRun = new FLADOMTextRun;
					$currentTextRun->characters = new FLACharacters;
					$currentTextRun->characters->data = $characters;
					$currentTextRun->textAttrs = array($textAttrs);
					$text->textRuns[] = $currentTextRun;
				}
				if(($endIndex = strpos($html, '>', $startIndex + 1)) !== false) {			
				   	if($html[$startIndex + 1] != '/') {
				   		// start tag
				   		$tagContents = substr($html, $startIndex + 1, $endIndex - $startIndex - 1);
				   		if(($spaceIndex = strpos($tagContents, ' ')) !== false) {
				   			$tagName = substr($tagContents, 0, $spaceIndex);
				   			$attributes = substr($tagContents, $spaceIndex + 1);
				   		} else {
				   			$tagName = $tagContents;
				   			$attributes = null;
				   		}
				   		switch($tagName) {
							case 'p':
								if($currentTextRun) {
									$currentTextRun->characters->data .= "\r";
								}
							case 'b':
								$textAttrs->face = preg_replace('/(?:-(Italic)|)$/i', '-Bold$1', $textAttrs->face, 1);
								break;
							case 'i':
								$textAttrs->face = preg_replace('/(?:-(Bold)|)$/i', '-$1Italic', $textAttrs->face, 1);
								break;
				   		}
				   		
						array_push($textAttrStack, $textAttrs);
						$textAttrs = clone $textAttrs;
				   		if($attributes && preg_match_all('/(\w+)="(.*?)"/', $attributes, $m, PREG_SET_ORDER)) {
					   		foreach($m as $match) {
					   			$name = $match[1];
					   			$value = html_entity_decode($match[2], ENT_COMPAT, 'UTF-8');
								switch($name) {
									case 'align':
										$textAttrs->alignment = $value;
										break;
									case 'face':
										$font = $this->findFont($value);
										$textAttrs->face = ($font) ? $font->fullName : $value;
										break;
									case 'size':
										$textAttrs->size = $value;
										break;
									case 'color':
										$textAttrs->fillColor = $value;
										break;
									case 'letterSpacing':
										$textAttrs->letterSpacing = $value;
										break;
									case 'letterSpacing':
										$textAttrs->letterSpacing = $value;
										break;
									case 'href':
										$textAttrs->url = $value;
										break;
									case 'target':
										$textAttrs->target = $value;
										break;
								}
					   		}
				   		}
				   	} else {
				   		// end tag
						$textAttrs = array_pop($textAttrStack);
					}
				} else {
					break;
				}
			}
		} else {
			$run = new FLADOMTextRun;
			$run->characters = $text;
			$run->textAttrs = array($textAttrs);
			$text->textRuns = array($run);
		}
		$this->dictionary[$tag->characterId] = $text;
	}
	
	protected function processDefineTextTag($tag) {
		$text = new FLADOMStaticText;
		$text->isSelectable = "false";
		
		$x = 0;
		$y = 0;
		$leftMost = 4096;
		$rightMost = 0;
		$textHeight = 0;
		$codeTable = null;
		$textAttrs = new FLADOMTextAttrs;
		$characters = '';
		$fragments = array();
		foreach($tag->textRecords as $record) {
			if($record->fontId !== null || $record->textColor !== null || $record->textHeight !== null) {
				$textAttrs = clone $textAttrs;
				if($record->fontId !== null) {
					$font = $this->dictionary[$record->fontId];
					$textAttrs->face = $font->fullName;
					$codeTable = $font->codeTable;
				}
				if($record->textColor !== null) {
					$textAttrs->fillColor = $this->convertRGB($record->textColor);
					$textAttrs->alpha = $this->convertAlpha($record->textColor);
				}
				if($record->textHeight !== null) {
					$textHeight = $record->textHeight;
					$textAttrs->size = $record->textHeight / 20;
				}
			}
			if($record->xOffset !== null) {
				$x = $record->xOffset;
			}
			if($record->yOffset !== null) {
				$y = $record->yOffset;
			}

			if($codeTable) {
				$fragment = new stdClass;			
				$fragment->top = $y - $textHeight;
				$fragment->left = $x;
				$fragment->bottom = $y;
				$fragment->right = 0;
				$fragment->characters = '';
				$fragment->textAttrs = $textAttrs;
				$leftMost = min($x, $leftMost);
				$firstWordWidth = null;
				
				foreach($record->glyphs as $glyph) {
					$code = $codeTable[$glyph->index];					
					$fragment->characters .= chr($code);
					if($code == 32) {
						if($firstWordWidth === null) {
							$firstWordWidth = $x;
						}
					}
					$x += $glyph->advance;
				}
				$rightMost = max($rightMost, $x);
				$fragment->right = $x;
				$fragment->width = $fragment->right - $fragment->left;
				$fragment->height = $textHeight;
				$fragment->firstWordWidth = ($firstWordWidth) ? $firstWordWidth : $x;
				$fragments[] = $fragment;
			}			
		}
		
		// group the fragments into lines and paragraphs
		$textWidth = $rightMost - $leftMost;
		$remainingWidth = $textWidth;
		$paragraphs = array();
		$paragraph = $paragraphs[] = new stdClass;
		$paragraph->leftMost = 4096;
		$paragraph->rightMost = 0;
		$paragraph->lineCount = 1;
		$paragraph->lines = array();
		$line = $paragraph->lines[] = new stdClass;
		$line->fragments = array();
		$baseline = $fragments[0]->bottom;
		$textHeight = 0;
		foreach($fragments as $fragment) {
			if($fragment->width > $remainingWidth || $fragment->top >= $baseline) {
				// a new line
				$gap = $fragment->top - $baseline;
				if($fragment->firstWordWidth < $remainingWidth || $gap > $textHeight) {
					// a hard return
					$paragraph = $paragraphs[] = new stdClass;
					$paragraph->leftMost = 4096;
					$paragraph->rightMost = 0;
					$paragraph->lineCount = 0;
					$paragraph->fragments = array();
					
					while($gap > $textHeight) {
						$paragraphs[] = new stdClass;
						$gap -= $textHeight;
					}
				}
				$line = $paragraph->lines[] = new stdClass;
				$line->fragments = array();
				$paragraph->lineCount++;
				$remainingWidth = $textWidth;
				$baseline = $fragment->bottom;
			}
			$line->fragments[] = $fragment;
			if(!isset($line->left)) {
				$line->left = $fragment->left;
			}
			$line->right = $fragment->right;
			$paragraph->leftMost = min($fragment->left, $paragraph->leftMost);
			$paragraph->rightMost = max($fragment->right, $paragraph->rightMost);
			$remainingWidth -= $fragment->width;
			$textHeight = $fragment->height;
			$baseline = $fragment->bottom;
		}
		
		// determine the alignment
		if(count($paragraphs) == 1 && $paragraphs[0]->lineCount == 1) {
			// point text			
			$point = true;
			$paragraphs[0]->align = null;
		} else {
			$point = false;
			
			$leftAlign = true;
			$rightAlign = true;
			foreach($paragraphs as $paragraph)  {
				if(isset($paragraph->lines)) {
					// check each line
					foreach($paragraph->lines as $index => $line) {
						if($line->left != $paragraph->leftMost) {
							if($index != 0) {
								$leftAlign = false;
							}
						}
						if($line->right != $paragraph->rightMost) {
							if($index + 1 != $paragraph->lineCount) {
								$rightAlign = false;
							}
						}
					}
					if($leftAlign) {
						if($rightAlign) {
							$paragraph->align = 'justify';
						} else {
							$paragraph->align = 'left';
						}
					} else if($rightAlign) {
						$paragraph->align = 'right';
					} else {
						$paragraph->align = 'center';
					}
				}
			}		
		}
		
		
		// TODO: this is not quite so accurate
		$text->width = ($rightMost + 40) / 20;
		$text->height = ($baseline + $tag->bounds->top * 2) / 20;
		
		// create the runs
		$text->textRuns = array();
		$currentTextRun = null;
		foreach($paragraphs as $paragraph) {
			if($currentTextRun) {
				$currentTextRun->characters->data .= "\r";
			}
			if(isset($paragraph->lines)) {
				foreach($paragraph->lines as $line) {
					foreach($line->fragments as $fragment) {
						if(!$currentTextRun || $currentTextRun->textAttrs !== $fragment->textAttrs) {
							$fragment->textAttrs->alignment = $paragraph->align;
							
							$currentTextRun = new FLADOMTextRun;
							$currentTextRun->characters = new FLACharacters;
							$currentTextRun->characters->data = $fragment->characters;
							$currentTextRun->textAttrs = array($fragment->textAttrs);
							$text->textRuns[] = $currentTextRun;
						} else {
							$currentTextRun->characters->data .= $fragment->characters;
						}
					}
				}
			} 
		}
		
		$this->dictionary[$tag->characterId] = $text;
	}
	
	protected function processDefineText2Tag($tag) {
		$this->processDefineTextTag($tag);
	}
	
	protected function processCSMTextSettingsTag($tag) {
		$text = $this->dictionary[$tag->characterId];
		if($tag->renderer == SWFCSMTextSettingsTag::RendererAdvanced && $tag->gridFit == SWFCSMTextSettingsTag::GridFitSubpixel) {
			$text->fontRenderingMode = null;
			if($tag->sharpness) {
				$text->antiAliasSharpness = $tag->sharpness;
			}
			if($tag->thickness) {
				$text->antiAliasThickness = $tag->thickness;
			}
		}
	}	
	
	protected function processDefineFontTag($tag) {
		$font = new FLAFont;
		$this->dictionary[$tag->characterId] = $font;
	}
	
	protected function processDefineFontInfoTag($tag) {
		$font = $this->dictionary[$tag->characterId];
		$font->name = $font->fullName = trim($tag->name);
		$font->codeTable = $tag->codeTable;
	}
	
	protected function processDefineFont2Tag($tag) {
		$font = new FLAFont;
		$font->name = $font->fullName = trim($tag->name);
		$font->codeTable = $tag->codeTable;
		$this->dictionary[$tag->characterId] = $font;
	}
	
	protected function processDefineFont3Tag($tag) {
		$this->processDefineFont2Tag($tag);
	}
	
	protected function processDefineFont4Tag($tag) {
	}
	
	protected function processDefineFontNameTag($tag) {
		$font = $this->dictionary[$tag->characterId];
		$font->fullName = $tag->name;
	}
	
	protected function processDoABCTag($tag) {
		if($tag->abcFile) {
			$finder = new ABCTextObjectFinder;
			$this->tlfTextObjects = array_merge($this->tlfTextObjects, $finder->find($tag->abcFile));
		}
	}
	
	protected function processDoActionTag($tag) {
		$decompiler = new AS2Decompiler;
		//$expressions = $decompiler->decompile($tag->actions);
	}
	
	protected function processDoInitActionTag($tag) {
		$decompiler = new AS2Decompiler;
		//$expressions = $decompiler->decompile($tag->actions);
	}
	
	protected function processDefineVideoStreamTag($tag) {
		$this->addVideo($tag->characterId, $tag);
	}
	
	protected function processVideoFrameTag($tag) {
		$video = $this->dictionary[$tag->streamId];
		$length = strlen($tag->data);
		
		$tagType = "\x09";
		$dataSize = substr(pack("N", $length + 2), 1);
		$timestamp = pack("N", $tag->frameNumber * 1000 / 24);
		$timestamp = substr($timestamp, 1) . $timestamp[0];
		$streamId = "\x00\x00\x00";
		$videoHeader = chr((($tag->frameNumber > 0) ? 0x20 : 0x10) | $video->codecId) . "\x00";
		$header = $tagType . $dataSize . $timestamp . $streamId . $videoHeader;
		$trailer = pack("N", $length + 16);
		
		$video->data .= $header . $tag->data . $trailer;
	}
	
	protected function processSymbolClassTag($tag) {
		foreach($tag->names as $characterId => $className) {
			$character = $this->dictionary[$characterId];
			if($character instanceof SWFFile) {
				if(!preg_match('/LoadingAnimation__$/', $className)) {
					$reconstructor = clone $this;
					$this->embeddedFLAFile = $reconstructor->reconstruct($character, $this->lastModified);
				}
			} else if($className == 'fl.text.TLFTextField') {
				$this->tlfTextFieldSymbol = $character;
				$this->tlfTextFieldSymbol->name = 'TLFTextField';
			}
		}
		
		foreach($this->tlfTextObjects as $textObjectIndex => $textObject) {
			foreach($tag->names as $characterId => $className) {
				if($textObject->containerClassName == $className) {
					// the text object is contained in this object
					$character = $this->dictionary[$characterId];
					
					// look for the instance on the timeline
					$timeline = ($object instanceof FLADOMDocument) ? $this->timeline : $object->timeline[0];
					foreach($timeline->layers as $layer) {
						foreach($layer->frames as $frame) {
							foreach($frame->elements as &$instance) {
								if($instance instanceof FLADOMSymbolInstance) {
									if($instance->name == $textObject->name && $instance->libraryItemName == $this->tlfTextFieldSymbol->name) {
										// replace it with TLF object
										$markup = new FLAMarkup;
										$markup->data = $textObject->xml;
										
										$text = new FLADOMTLFText;
										$text->name = $textObject->name;
										$text->right = $text->left + $textObject->width * 20;
										$text->bottom = $text->top + $textObject->height * 20;
										$text->tlfFonts = array();
										$text->markup = $markup;
										$text->blendMode = $element->blendMode;
										$text->cacheAsBitmap = $element->cacheAsBitmap;
										$text->matrix = $element->matrix;
										$text->filters = $element->filters;
										
										$element = $text;
										unset($this->tlfTextObjects[$textObjectIndex]);
									}
								}
							}
						}
					}
				}
			}
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
			if($record instanceof SWFDropShadowFilter) {
				$filter = new FLADropShadowFilter;
				$filter->color = $this->convertRGB($record->shadowColor);
				$filter->alpha = $this->convertAlpha($record->shadowColor);
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
				$filter->alpha = $this->convertAlpha($record->color);
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
				$filter->shadowAlpha = $this->convertAlpha($record->shadowColor);
				$filter->highlightColor = $this->convertRGB($record->highlightColor);
				$filter->highlightAlpha = $this->convertAlpha($record->highlightColor);
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
				// there doesn't seem to be a way to create convolution filter in Flash professional
			} else if($record instanceof SWFColorMatrixFilter) {
				$filter = new FLAAdjustColorFilter;
				$solver = new ColorMatrixSolver;
				$solver->solve($record->matrix);
				if($solver->brightness) {
					$filter->brightness = $solver->brightness;
				}
				if($solver->contrast) {
					$filter->contrast = $solver->contrast;
				}
				if($solver->saturation) {
					$filter->saturation = $solver->saturation;
				}
				if($solver->hue) {
					$filter->hue = $solver->hue;
				}
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
			if($filter) {
				$list[] = $filter;
			}
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
	
	protected function convertMorphFills($records) {
		$startList = array();
		$endList = array();
		foreach($records as $index => $record) {
			$startFillStyle = $startList[] = new FLAFillStyle;
			$endFillStyle = $endList[] = new FLAFillStyle;
			$startFillStyle->index = $endFillStyle->index = $index + 1;
			switch($record->type) {
				case 0x00:
					$startFillStyle->solidColor = $this->convertSolidColor($record->startColor);
					$endFillStyle->solidColor = $this->convertSolidColor($record->endColor);
					break;
				case 0x10:
					list($startFillStyle->linearGradient, $endFillStyle->linearGradient) = $this->convertLinearMorphGradient($record->gradient, $record->startGradientMatrix, $record->endGradientMatrix);
					break;
				case 0x12:
				case 0x13:
					list($startFillStyle->radialGradient, $endFillStyle->radialGradient) = $this->convertRadialMorphGradient($record->gradient, $record->startGradientMatrix, $record->endGradientMatrix);
					break;
				case 0x40:
				case 0x41:
				case 0x42:
				case 0x43:
					$fillStyle->bitmapFill = $this->convertBitmapFill($record->type, $record->bitmapId, $record->startBitmapMatrix);
					$fillStyle->bitmapFill = $this->convertBitmapFill($record->type, $record->bitmapId, $record->endBitmapMatrix);
					break;
					
			}
		}
		return array($startList, $endList);
	}
	
	protected function convertStrokes($records) {
		$list = array();
		foreach($records as $index => $record) {
			$strokeStyle = $list[] = new FLAStrokeStyle;
			$strokeStyle->index = $index + 1;
			
			if($record instanceof SWFLineStyle2) {
				static $capStyles = array("round", "none", "square");
				static $joinStyles = array("round", "bevel", "miter");				

				if($record->fillStyle) {
				} else {
					$stroke = new FLASolidStroke;
					$solidColor = $this->convertSolidColor($record->color);
					$stroke->fill = array($solidColor);
				}
				
				$stroke->weight = $record->width / 20.0;
				if($stroke->weight == 0.05 && $stroke instanceof FLASolidStroke) {
					$stroke->solidStyle = "hairline";
				}
				if($record->startCapStyle) {
					$stroke->caps = $capStyles[$record->startCapStyle];
				}
				if($record->joinStyle) {
					$stroke->joints = $joinStyles[$record->joinStyle];
				}
				if($record->flags & SWFLineStyle2::NoHScale) {
					if($record->flags & SWFLineStyle2::NoVScale) {
					//	$stroke->scaleMode = "none";
					} else {
						$stroke->scaleMode = "vertical";
					}
				} else {
					if($record->flags & SWFLineStyle2::NoVScale) {
						$stroke->scaleMode = "horizontal";
					} else {
						$stroke->scaleMode = "normal";
					}
				}
				if($record->flags & SWFLineStyle2::PixelHinting) {
					$stroke->pixelHinting = "true";
				}
				if($record->miterLimitFactor) {
					$stroke->miterLimit = $record->miterLimitFactor / 256.0;
				}
			} else {
				$solidColor = $this->convertSolidColor($record->color);
				$stroke = new FLASolidStroke;
				$stroke->fill = array($solidColor);
				$stroke->weight = $record->width / 20.0;
				$stroke->scaleMode = "normal";
			}
			$strokeStyle->solidStroke = $stroke;
		}
		return $list;
	}
	
	protected function convertMorphStrokes($records) {
		$startList = array();
		$endList = array();
		foreach($records as $index => $record) {
			$startLineStyle = $startList[] = new FLAStrokeStyle;
			$endLineStyle = $endList[] = new FLAStrokeStyle;
			$startLineStyle->index = $endLineStyle->index = $index + 1;			
			if($record->startWidth !== null) {
				$startLineStyle->width = $record->startWidth;
			}
			if($record->startColor !== null) {
				$startLineStyle->color = $this->convertRGB($record->startColor);
				$startLineStyle->width = $this->convertAlpha($record->startColor);
			}
			if($record->endWidth !== null) {
				$endLineStyle->width = $record->endWidth;
			}
			if($record->endColor !== null) {
				$endLineStyle->color = $this->convertRGB($record->endColor);
				$endLineStyle->width = $this->convertAlpha($record->endColor);
			}
			if($record instanceof SWFLineStyle2) {
			}
		}
		return array($startList, $endList);
	}
	
	protected function convertSolidColor($record) {
		$solid = new FLASolidColor;
		$solid->color = $this->convertRGB($record);
		$solid->alpha = $this->convertAlpha($record);
		return $solid;
	}
		
	protected function convertMatteColor($record) {
		$solid = new FLASMatteColor;
		$solid->color = $this->convertRGB($record);
		$solid->alpha = $this->convertAlpha($record);
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
			$bitmap = $this->dictionary[$bitmapId];
			$fill = new FLABitmapFill;
			$fill->matrix = $this->convertMatrix($matrixRecord);
			$fill->bitmapPath =& $bitmap->name;
			$fill->bitmapIsClipped = ($type == 0x41 || $type == 0x43) ? 'true' : 'false';
			if($type == 0x40 || $type == 0x41) {
				$bitmap->allowSmoothing = 'true';
			}
			return $fill;
		}
	}
	
	protected function convertRGB($record) {
		return sprintf("#%02X%02X%02X", $record->red, $record->green, $record->blue);
	}
	
	protected function convertAlpha($record) {
		return $record->alpha / 255.0;
	}
	
	protected function convertEdges($records) {
		$list = array();
		$edge = $list[] = new FLAEdge;
		$tokens = array();
		$x = 0;
		$y = 0;
		$fillStyle0 = null;
		$fillStyle1 = null;
		$strokeStyle = null;
		foreach($records as $record) {
			if($record instanceof SWFStyleChange) {
				if($record->fillStyle0 !== null || $record->fillStyle1 !== null || $record->lineStyle !== null) {
					if($tokens) {
						$edge->edges = implode($tokens);
						$edge = $list[] = new FLAEdge;
						$tokens = array();
					}
					
					if($record->fillStyle0 !== null) {
						if($record->fillStyle0 != 0) {
							$edge->fillStyle0 = $fillStyle0 = $record->fillStyle0;
						} else {
							$fillStyle0 = null;
						}
					} else {
						$edge->fillStyle0 = $fillStyle0;
					}
					if($record->fillStyle1 !== null) {
						if($record->fillStyle1 != 0) {
							$edge->fillStyle1 = $fillStyle1 = $record->fillStyle1;
						} else {
							$fillStyle1 = null;
						}
					} else {
						$edge->fillStyle1 = $fillStyle1;
					}
					if($record->lineStyle !== null) {
						if($record->lineStyle != 0) {
							$edge->strokeStyle = $strokeStyle = $record->lineStyle;
						} else {
							$strokeStyle = null;
						}
					} else {
						$edge->strokeStyle = $strokeStyle;
					}
				}
				if($record->moveDeltaX !== null) {				
					$x = $record->moveDeltaX;
				}
				if($record->moveDeltaY !== null) {
					$y = $record->moveDeltaY;
				}
			} else if($record instanceof SWFStraightEdge) {
				$tokens[] = "!$x $y";
				$x += $record->deltaX;
				$y += $record->deltaY;
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
	
	public function convertPoint($x, $y) {
		return sprintf('#%X.%02X, #%X.%02X', ($x >> 8) & 0xFFFFFF, $x & 0xFF, ($y >> 8) & 0xFFFFFF, $y & 0xFF);
	}
	
	public function convertMorphSegments($startRecords, $endRecords) {
		$list = array();
		$startX = 0;
		$startY = 0;
		$endX = 0;
		$endY = 0;
		foreach($startRecords as $index => $startRecord) {
			$endRecord = $endRecords[$index];
			if($startRecord instanceof SWFStyleChange) {
				if($startRecord->fillStyle0 !== null || $startRecord->fillStyle1 !== null || $startRecord->lineStyle !== null) {
					$segment = $list[] = new FLAMorphSegment;
					$curveCount = 0;
					
					if($startRecord->fillStyle1 !== null) {
						$segment->fillIndex1 = $startRecord->fillStyle1 * 2 - 1;
					}
					if($startRecord->lineStyle !== null) {
						$segment->strokeIndex1 = $startRecord->lineStyle * 2 - 1;
					}
					if($endRecord->fillStyle1 !== null) {
						$segment->fillIndex2 = $endRecord->fillStyle1 * 2 - 1;
					}
					if($endRecord->lineStyle !== null) {
						$segment->strokeIndex2 = $endRecord->lineStyle * 2 - 1;
					}
				}
				if($startRecord->moveDeltaX !== null) {
					$startX = $startRecord->moveDeltaX;
					$startY = $startRecord->moveDeltaY;
					$endX = $endRecord->moveDeltaX;
					$endY = $endRecord->moveDeltaY;
				}
				$segment->startPointA = $this->convertPoint($startX, $startY);
				$segment->startPointB = $this->convertPoint($endX, $endY);
			} else {
				$curves = new FLAMorphCurves;
				$isLine = true;
				if($startRecord instanceof SWFStraightEdge) {
					$ax = $startX;
					$ay = $startY;
					if($startRecord->deltaX && $startRecord->deltaY) {
						$ax += $startRecord->deltaX;
						$ay += $startRecord->deltaY;
					} else if($startRecord->deltaX) {
						$ax += $startRecord->deltaX;
					} else {
						$ay += $startRecord->deltaY;
					}
					$acx = $ax + ($ax - $startX) / 2; 
					$acy = $ay + ($ay - $startY) / 2; 
				} else if($startRecord instanceof SWFQuadraticCurve) {
					$acx = $startX + $startRecord->controlDeltaX;
					$acy = $startY + $startRecord->controlDeltaY;
					$ax = $acx + $startRecord->anchorDeltaX;
					$ay = $acy + $startRecord->anchorDeltaY;
					$isLine = false;
				}
				if($endRecord instanceof SWFStraightEdge) {
					$bx = $endX;
					$by = $endY;
					if($endRecord->deltaX && $endRecord->deltaY) {
						$bx += $endRecord->deltaX;
						$by += $endRecord->deltaY;
					} else if($endRecord->deltaX) {
						$bx += $endRecord->deltaX;
					} else {
						$by += $endRecord->deltaY;
					}
					$bcx = $bx + ($bx - $endX) / 2; 
					$bcy = $by + ($by - $endY) / 2;
					$curves->isLine = 'true';
				} else if($endRecord instanceof SWFQuadraticCurve) {
					$bcx = $endX + $endRecord->controlDeltaX;
					$bcy = $endY + $endRecord->controlDeltaY;
					$bx = $bcx + $endRecord->anchorDeltaX;
					$by = $bcy + $endRecord->anchorDeltaY;
					$isLine = false;
				}
				
				$curves = new FLAMorphCurves;
				$curves->controlPointA = $this->convertPoint($acx, $acy);
				$curves->controlPointB = $this->convertPoint($bcx, $bcy);
				$curves->anchorPointA = $this->convertPoint($ax, $ay);
				$curves->anchorPointB = $this->convertPoint($bx, $by);
				if($isLine) {
					$curves->isLine = 'true';
				}
				$name = "morphCurves$curveCount";
				$segment->$name = $curves;
				$startX = $ax;
				$startY = $ay;
				$endX = $bx;
				$endY = $by;
				$curveCount++;
			}
		}
		return $list;
	}
	
}

?>