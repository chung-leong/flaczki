<?php

class FLAReconstructor {

	protected $output;
	protected $lastModified;
	
	protected $dictionary;
	protected $jpegTables;
	protected $frameIndex;
	protected $sceneNames;
	protected $frameLabels;
	protected $symbols;
	protected $library;
	protected $embeddedFiles;
	protected $fileID;
	protected $lastItemID;

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
		$document = new FLADOMDocument;
		$document->currentTimeline = 1;
		$document->xflVersion = 2.1;
		$document->creatorInfo = "Adobe Flash Professional CS5.5";
		$document->platform = "Windows";
		$document->versionInfo = "Saved by Adobe Flash Windows 11.5 build 325";
		$document->majorVersion = 11;
		$document->minorVersion = 5;
		$document->buildNumber = 325;

		$this->fileID = rand(1, 0xFF);
		$this->lastItemID = 0x50000000;
		$this->embeddedFiles = array();
		$this->timeline = new FLADOMTimeline;
		$this->frameIndex = 0;
		$this->symbols = array();
		$this->library = array();
		$this->processTags($swfFile->tags);
		$this->attachFrameLabels($this->timeline, $this->frameLabels);
		$document->timelines = $this->splitScenes($this->timeline, $this->sceneNames);
		$document->symbols = $this->symbols;
		$document->nextSceneIdentifier = count($document->timelines) + 1;
		$document->playOptionsPlayLoop = 'false';
		$document->playOptionsPlayPages = 'false';
		$document->playOptionsPlayFrameActions = 'false';
		
		$flaFile = new FLAFile;
		$flaFile->document = $document;
		$flaFile->library = $this->library;
		$flaFile->embeddedFiles = $this->embeddedFiles;
		
		return $flaFile;
	}
	
	protected function splitScenes($timeline, $sceneNames) {
		$timelines = array();
		if(count($sceneNames) > 1) {
		} else {
			if(count($sceneNames) == 1) {
				$timeline->name = $sceneNames[0];
			}
			$timelines[] = $timeline;
		} 
		return $timeline;
	}
	
	protected function attachFrameLabels($timeline, $frameLabels) {
	}
	
	protected function processTags($tags) {
		foreach($tags as $tag) {
			$tagName = substr(get_class($tag), 3, -3);
			$methodName = "process{$tagName}Tag";
			if(method_exists($this, $methodName)) {
				//echo "$tagName\n";
				$this->$methodName($tag);
			}
		}
		$this->timeline->layers = array_reverse($this->timeline->layers, true);
	}
	
	protected function processDefineBinaryDataTag($tag) {
		if($tag->swfFile) {
			$this->processTags($tag->swfFile->tags);
		}
	}
	
	protected function processDefineSpriteTag($tag) {
		$movieClip = new FLADOMSymbolItem;
		$movieClip->timeline = new FLADOMTimeline;
		$movieClip->lastModified = $this->lastModified;
		$movieClip->itemID = $this->getNextItemID();
		$movieClip->name = $this->getNextLibraryName('Symbol');
		$this->addCharacter($tag->characterId, $movieClip);
		
		// save previous values
		$previousTimeline = $this->timeline;
		$previousFrameIndex = $this->frameIndex;
		$previousFrameLabels = $this->frameLabels;
		$previousSceneNames = $this->sceneNames;
		
		// add frames to MovieCip timeline
		$this->timeline = $movieClip->timeline;		
		$this->frameIndex = 0;
		$this->frameLabels = null;
		$this->sceneNames = null;
		$this->processTags($tag->tags);
		
		// restore previous values
		$this->timeline = $previousTimeline;
		$this->frameIndex = $previousFrameIndex;
		$this->frameLabels = $previousFrameLabels;
		$this->sceneNames = $previousSceneNames;
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
		$element = $this->dictionary[$characterId];
		return $element;
	}
	
	protected function addCharacter($characterId, $element) {
		$this->dictionary[$characterId] = $element;
		
		// put character in library
		$this->library[$element->name] = $element;
		$extension = ".xml";
		$include = new FLAInclude;
		$include->href = "{$element->name}{$extension}";
		$include->loadImmediate = "false";
		$include->itemID = $element->itemID;
		$include->lastModified = $element->lastModified;
		if($element->symbolType == 'graphic') {
			$include->itemIcon = "1";
		}
		$this->symbols[] = $include;
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
			$instance->matrix = $this->serializeMatrix($tag->matrix);
		}
		if($tag->colorTransform !== null) {
			$instance->color = $this->serializeColorTransform($tag->colorTransform);
		}
		if($instance instanceof processPlaceObject3Tag) {			
			if($tag->blendMode !== null) {
				static $blendModes = array('normal', 'normal', 'layer', 'multiply', 'screen', 'lighten', 'darken', 'difference', 'add', 'subtract', 'invert', 'alpha', 'erase', 'overlay', 'hardlight');
				$instance->blendMode = $blendModes[$instance->blendMode];
			}
			if($tag->bitmapCache !== null) {
				$instance->cacheAsBitmap = ($tag->bitmapCache) ? 'true' : 'false';
			}
			if($tag->bitmapCacheBackgroundColor !== null) {
				$instance->matteColor = $this->serializeMatteColor($tag->bitmapCacheBackgroundColor);
			}
			if($tag->visibility !== null) {
				$instance->bits32 = ($tag->visibility) ? 'true' : 'false';
			}
			if($tag->filters !== null) {
				$instance->filters = $this->serializeFilters($tag->filters);
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
		$shape = new FLADOMShape;
		$shape->fills = $this->serializeFills($tag->shape->fillStyles);
		$shape->strokes = $this->serializeStrokes($tag->shape->lineStyles);
		$shape->edges = $this->serializeEdges($tag->shape->edges);

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
		$graphic->name = $this->getNextLibraryName('Symbol');
		$graphic->lastModified = $this->lastModified;
		$graphic->timeline = array($timeline);
		$this->addCharacter($tag->characterId, $graphic);
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
	}
	
	protected function processDefineBitsJPEG2Tag($tag) {
	}
	
	protected function processDefineBitsJPEG3Tag($tag) {
	}
	
	protected function processDefineBitsJPEG4Tag($tag) {
	}
	
	protected function processDefineBitsLosslessTag($tag) {
	}
	
	protected function processDefineBitsLossless2Tag($tag) {
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

	protected function serializeMatrix($record) {
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
	
	protected function serializeColorTransform($record) {
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
	
	protected function serializeFilters($records) {
		
	}
				
	protected function serializeSolidColor($record) {
		$solid = new FLASolidColor;
		$solid->color = sprintf("#%02X%02X%02X", $record->red, $record->green, $record->blue);		
		return $solid;
	}
		
	protected function serializeMatteColor($record) {
		$solid = new FLASMatteColor;
		$solid->color = sprintf("#%02X%02X%02X", $record->red, $record->green, $record->blue);		
		return $solid;
	}
	
	protected function serializeFills($records) {
		$list = array();
		foreach($records as $record) {
			$fillStyle = $list[] = new FLAFillStyle;
			if($record->color !== null) {
				$fillStyle->solidColor = $this->serializeSolidColor($record->color);
			}
			if($record instanceof SWFFillStyle2) {
			}
		}
		return $list;
	}
	
	protected function serializeStrokes($records) {
		$list = array();
		foreach($records as $record) {
			$lineStyle = $list[] = new FLALineStyle;
			if($lineStyle->width !== null) {
				$lineStyle->width = $record->width;
			}
			if($lineStyle instanceof SWFLineStyle2) {
			}
		}
		return $list;
	}
	
	protected function serializeEdges($records) {
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

class FLAFile {
	public $document;
	public $library;
	public $embeddedFiles;
}

class FLADOMDocument {
	public $currentTimeline;
	public $xflVersion; 
	public $creatorInfo;
	public $platform;
	public $versionInfo;
	public $majorVersion; 
	public $minorVersion;
	public $buildNumber;
	public $nextSceneIdentifier;
	public $playOptionsPlayLoop;
	public $playOptionsPlayPages; 
	public $playOptionsPlayFrameActions;
	
	public $symbols;
	public $timelines;
}

class FLADOMSymbolItem { 
	public $name;
	public $itemID;
	public $symbolType;
	public $lastModified;
	
	public $timeline;
}

class FLADOMSymbolInstance {
	public $libraryItemName;
	public $selected;
	public $blendMode;
	public $cacheAsBitmap;
	public $bits32;
	public $centerPoint3DX;
	public $centerPoint3DY;
	
	public $matrix;
	public $transformationPoint;
	public $filters;
	public $matteColor;
}

class FLAInclude {
	public $href;
	public $loadImmediate;
	public $itemIcon;
	public $itemID;
	public $lastModified;
}

class FLADOMTimeline {
	public $name;
	public $currentFrame;
}

class FLADOMLayer {
	public $name;
	public $color;
	public $current;
	public $isSelected;

	public $frames;	
}

class FLADOMFrame {
	public $label;
	public $index;
	public $duration;
	public $keyMode;
	
	public $elements;
}

class FLADOMShape {
	public $fills;
	public $strokes;
	public $edges;
}

class FLALineStyle {
	public $width;
	public $color;
}

class FLAFillStyle {
	public $solidColor;
}

class FLASolidColor {
	public $color;
}

class FLAColor {
	public $brightness;
	public $tintMultiplier;
	public $tintColor;
	public $alphaMultiplier;
	public $redMultiplier;
	public $greenMultiplier;
	public $blueMultiplier;
	public $alphaOffset;
	public $redOffset;
	public $greenOffset;
	public $blueOffset;
}

class FLAMatteColor {
	public $color;
}

class FLAEdge {
	public $fillStyle0;
	public $fillStyle1;
	public $lineStyle;
	public $edges;
}

class FLAMatrix {
	public $a;
	public $b;
	public $c;
	public $d;
	public $tx;
	public $ty;
}

class FLAPoint {
	public $x;
	public $y;
}

class FLADropShadowFilter {
	public $angle;
	public $blurX;
	public $blurY;
	public $color;
	public $distance;
	public $hideObject;
	public $inner;
	public $knockout;
	public $quality;
	public $strength;
}

?>