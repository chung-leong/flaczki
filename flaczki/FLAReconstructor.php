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
	protected $tlfTextObjects;
	protected $tlfTextObjectSymbol;
	
	public function getRequiredTags() {
		$methodNames = get_class_methods($this);
		$tags = array('DefineBinaryData');
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
		$this->dictionary = array();
		$this->tlfTextObjects = array();
		$this->tlfTextObjectSymbol = null;
		
		// look for an embedded swf file		
		$embeddedSWFFiles = array();
		$hasPreloader = false;
		foreach($swfFile->tags as $tag) {
			if($tag instanceof SWFDefineBinaryDataTag) {
				$embeddedSWFFiles[$tag->characterId] = $tag->swfFile;
			} else if($tag instanceof SWFSymbolClassTag) {
				foreach($tag->names as $characterId => $name) {
					if(preg_match('/MainTimeline__Content__$/', $name)) {
						$swfFile = $embeddedSWFFiles[$characterId];
						break;
					}
				}
			}
		}
		
		$flaFile = new FLAFile;
		$flaFile->document = $this->dictionary[0] = new FLADOMDocument;
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
		$this->timeline->layers = array();
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
			$item->allowSmoothing =& $object->allowSmoothing;
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
	
	protected function findFont($name) {
		foreach($this->dictionary as $object) {
			if($object instanceof FLAFont) {
				if($object->name == $name) {
					return $object;
				}
			}
		}
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
		
	protected function processDefineSpriteTag($tag) {
		$this->addMovieClip($tag->characterId, $tag->tags);
	}
	
	protected function processPlaceObjectTag($tag) {
	}

	protected function processPlaceObject2Tag($tag) {
		if($tag->characterId !== null) {
			$character = $this->getCharacter($tag->characterId);
			if(!$character) {
				return;
			}
			if($character instanceof FLADOMSymbolItem) {
				$instance = new FLADOMSymbolInstance;
				$instance->libraryItemName = $character->name;
			} else {
				$instance = $character;
			}
			$frame = $this->addFrame($tag->depth);
			$frame->elements[] = $instance;
		} 
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
			$font = $this->getCharacter($tag->fontId);
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
		$this->addCharacter($tag->characterId, $text);
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
					$font = $this->getCharacter($record->fontId);
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
		
		$this->addCharacter($tag->characterId, $text);
	}
	
	protected function processDefineText2Tag($tag) {
		$this->processDefineTextTag($tag);
	}
	
	protected function processCSMTextSettingsTag($tag) {
		$text = $this->getCharacter($tag->characterId);
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
		$this->addCharacter($tag->characterId, $font);
	}
	
	protected function processDefineFontInfoTag($tag) {
		$font = $this->getCharacter($tag->characterId);
		$font->name = $font->fullName = trim($tag->name);
		$font->codeTable = $tag->codeTable;
	}
	
	protected function processDefineFont2Tag($tag) {
		$font = new FLAFont;
		$font->name = $font->fullName = trim($tag->name);
		$font->codeTable = $tag->codeTable;
		$this->addCharacter($tag->characterId, $font);
	}
	
	protected function processDefineFont3Tag($tag) {
		$this->processDefineFont2Tag($tag);
	}
	
	protected function processDefineFont4Tag($tag) {
	}
	
	protected function processDefineFontNameTag($tag) {
		$font = $this->getCharacter($tag->characterId);
		$font->fullName = $tag->name;
	}
	
	protected function processDoABCTag($tag) {
		if($tag->abcFile) {
			$finder = new ABCTextObjectFinder;
			$this->tlfTextObjects = array_merge($this->tlfTextObjects, $finder->find($tag->abcFile));
		}
	}
	
	protected function processSymbolClassTag($tag) {
		foreach($tag->names as $characterId => $className) {
			if($className == 'fl.text.TLFTextField') {
				$this->tlfTextObjectSymbol = $this->getCharacter($characterId);
			}
		}
		
		foreach($this->tlfTextObjects as $textObjectIndex => $textObject) {
			foreach($tag->names as $characterId => $className) {
				if($textObject->containerClassName == $className) {
					$object = $this->getCharacter($characterId);
					
					// look for the instance on the timeline
					$timeline = ($object instanceof FLADOMDocument) ? $this->timeline : $object->timeline[0];
					foreach($timeline->layers as $layer) {
						foreach($layer->frames as $frame) {
							foreach($frame->elements as &$element) {
								if($element instanceof FLADOMSymbolInstance) {
									if($element->name == $textObject->name && $element->libraryItemName == $this->tlfTextObjectSymbol->name) {
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
	
	protected function convertStrokes($records) {
		$list = array();
		foreach($records as $index => $record) {
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
			$bitmap = $this->getCharacter($bitmapId);
			$fill = new FLABitmapFill;
			$fill->matrix = $this->convertMatrix($matrixRecord);
			$fill->bitmapPath = $bitmap->name;
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
						$edge->strokeStyle = $record->lineStyle;
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