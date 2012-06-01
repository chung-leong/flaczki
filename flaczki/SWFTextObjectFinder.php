<?php

class SWFTextObjectFinder {

	protected $abcFinder;
	
	public function __construct() {
		$this->abcFinder = new ABCTextObjectFinder;
	}

	public function find($swfFile) {
		$textObjects = array();
		$this->scanTags($swfFile, $textObjects, new SWFImageExportInfo, $swfFile);
		return $textObjects;
	}
	
	public function replace($textObjects) {
		if($textObjects) {
			// group the changes to the same tag together
			$groups = array();
			$group = array();
			$previousObject = null;
			foreach($textObjects as $textObject) {
				if($previousObject && $previousObject->abcTag !== $textObject->abcTag) {
					$groups[] = $group;
				}
				$group[] = $textObject;
				$previousObject = $textObject;
			}
			$groups[] = $group;
			
			// update the ABCTextObjectInfo objects and pass the list to ABCTextObjectFinder
			$newImageClasses = array();
			foreach($groups as $group) {
				$abcTextObjects = array();
				foreach($group as $textObject) {
					$abcTextObject = $textObject->abcTextInfo;
					$abcTextObject->name = $textObject->name;
					$abcTextObject->xml = $textObject->xml;
					
					foreach($textObject->referencedImageClasses as $referenceName => $imageClass) {
						// copy image references over--ABCTextObjectFinder will notice that some of 
						// the classes don't have the code yet
						if(!isset($abcTextObject->referencedImageClasses[$referenceName])) {
							if(!$imageClass->abcClassInfo) {
								$imageClass->abcClassInfo = new ABCImageClassInfo;
								if($dotPos = strrpos($imageClass->name, '.')) {
									$imageClass->abcClassInfo->name = substr($imageClass->name, $dotPos + 1);
									$imageClass->abcClassInfo->namespace = substr($imageClass->name, 0, $dotPos);
								} else {
									$imageClass->abcClassInfo->name = $imageClass->name;
								}
							}
							$abcTextObject->referencedImageClasses[$referenceName] = $imageClass->abcClassInfo;
						}
						
						if(!$imageClass->imageTag && !in_array($imageClass, $newImageClasses)) {
							$newImageClasses[] = $imageClass;
						}
					}
					$abcTextObjects[] = $abcTextObject;
				}
				$abcFile = $textObject->abcTag->abcFile;
				$this->abcFinder->replace($abcFile, $abcTextObjects);
				
				if($newImageClasses) {
					// look for the best place to put the DefineSprite and SymbolClass tag, among other things
					$plan = $this->getExportPlan($textObject->swfFile, $textObject->abcTag);
					
					foreach($newImageClasses as $imageClass) {
						$imageTag = new SWFDefineBitsJPEGTag;
						$imageTag->characterId = $plan->nextCharacterId++;
						$imageTag->imageData = $imageClass->imageData;
						$imageClass->imageTag = $imageTag;
						
						array_splice($plan->imageTagDestination->tags, $plan->imageTagIndex++, 0, array($imageTag));
						if($plan->imageTagDestination === $plan->symbolClassTagDestination) {
							$plan->symbolClassTagIndex++;
						}
					}
					
					if(isset($plan->symbolClassTagDestination->tags[$plan->symbolClassTagIndex]) && $plan->symbolClassTagDestination->tags[$plan->symbolClassTagIndex] instanceof SWFSymbolClassTag) {
						// just add to the tag
						$symbolClassTag = $plan->symbolClassTagDestination->tags[$plan->symbolClassTagIndex];					
					} else {
						$symbolClassTag = new SWFSymbolClassTag;
						array_splice($plan->symbolClassTagDestination->tags, $plan->symbolClassTagIndex++, 0, array($symbolClassTag));
					}
					foreach($newImageClasses as $imageClass) {
						$symbolClassTag->characterIds[] = $imageClass->imageTag->characterId;
						$symbolClassTag->names[] = $imageClass->name;
					}
				}				
			}
		}
	}

	protected function getExportPlan($container, $abcTag) {
		$plan = new SWFImageExportPlan;
		$this->addExportInstructions($container, $abcTag, $plan);
		return $plan;
	}
	
	protected function addExportInstructions($container, $abcTag, $plan) {
		foreach($container->tags as $index => $tag) {
			// find the next available character id
			if(isset($tag->characterId) && $tag->characterId >= $plan->nextCharacterId) {
				$plan->nextCharacterId = $tag->characterId + 1;
			}
			
			// put images right in front of the doABC and SymbolClass after it
			if($tag === $abcTag) {
				$plan->imageTagIndex = $index;				
				$plan->imageTagDestination = $container;
				$plan->symbolClassTagIndex = $index + 1;
				$plan->symbolClassTagDestination = $container;
			} else if($tag instanceof SWFDefineSpriteTag) {
				$this->addExportInstructions($tag, $abcTag, $plan);
					
				// definition tags cannot actually be inside a sprite--put them outside
				if($plan->imageTagDestination instanceof SWFDefineSpriteTag) {
					$plan->imageTagIndex = $index;
					$plan->imageTagDestination = $container;
				}
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->addExportInstructions($tag->swfFile, $abcTag, $plan);
				}
			}
		}
	}
	
	protected function scanTags($container, &$textObjects, $imageExportInfo, $swfFile) {
		foreach($container->tags as $index => $tag) {
			if($tag instanceof SWFDoABCTag) {
				if($tag->abcFile) {			
					$abcTextObjects = $this->abcFinder->find($tag->abcFile);
					foreach($abcTextObjects as $abcTextObject) {
						$textObject = new SWFTextObjectInfo;
						$textObject->name = $abcTextObject->name;
						$textObject->xml = $abcTextObject->xml;
						$textObject->abcTag = $tag;
						$textObject->abcTextInfo = $abcTextObject;
						foreach($abcTextObject->referencedImageClasses as $referenceName => $abcImageClass) {
							$imageClass = new SWFImageClassInfo;
							$imageClass->name = "{$imageClass->namespace}.{$imageClass->name}";
							$textObject->referencedImageClasses[$referenceName] = $imageClass;
							$imageExportInfo->imageClasses[$imageClass->name] = $imageClass;
						}
						$textObject->imageExportInfo = $imageExportInfo;
						$textObject->swfFile = $swfFile;
						$textObjects[] = $textObject;
					}
				}
			} else if($tag instanceof SWFDefineSpriteTag) {
				$this->scanTags($tag, $textObjects, $imageExportInfo, $swfFile);
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->scanTags($tag->swfFile, $textObjects, $imageExportInfo, $swfFile);
				}
			} else if($tag instanceof SWFDefineBitsJPEGTag) {
				$imageExportInfo->imageTags[$tag->characterId] = $tag;
			} else if($tag instanceof SWFSymbolClassTag) {
				foreach($tag->characterIds as $index => $characterId) {
					$name = $tag->names[$index];
					if(isset($imageExportInfo->imageClasses[$name])) {
						$imageClass = $imageExportInfo->imageClasses[$name];
						$imageClass->imageTag = $imageExportInfo->imageTags[$characterId];
						$imageClass->imageData = $imageClass->imageTag->imageData;
					}
				}
			}
		}
	}
}

class SWFTextObjectInfo {
	public $name;
	public $xml;
	public $tag;
	public $swfFile;
	public $abcTextInfo;
	public $referencedImageClasses = array();
	public $imageExportInfo;
}

class SWFImageClassInfo {
	public $name;
	public $imageTag;
	public $imageData;
	public $abcClassInfo;
}

class SWFImageExportPlan {
	public $nextCharacterId;
	public $imageTagIndex;
	public $imageTagDestination;
	public $symbolClassTagIndex;
	public $symbolClassTagDestination;	
}

class SWFImageExportInfo {
	public $imageTags = array();
	public $imageClasses = array();
}

?>