<?php

class SWFTextObjectFinder {

	protected $abcFinder;
	
	public function __construct() {
		$this->abcFinder = new ABCTextObjectFinder;
	}

	public function find($swfFile) {
		$textObjects = array();
		$this->scanTags($swfFile->tags, $textObjects);
		return $textObjects;
	}
	
	public function replace($textObjects) {
		if($textObjects) {
			// group the changes to the same tag together
			$groups = array();
			$group = array();
			$previousObject = null;
			foreach($textObjects as $textObject) {
				if($previousObject && $previousObject->tag !== $textObject->tag) {
					$groups[] = $group;
				}
				$group[] = $textObject;
				$previousObject = $textObject;
			}
			$groups[] = $group;
			
			// update the ABCTextObjectInfo objects and pass the list to ABCTextObjectFinder
			foreach($groups as $group) {
				$abcTextObjects = array();
				foreach($group as $textObject) {
					$abcTextObject = $textObject->abcInfo;
					$abcTextObject->name = $textObject->name;
					$abcTextObject->xml = $textObject->xml;
					$abcTextObjects[] = $abcTextObject;
				}
				$abcFile = $textObject->tag->abcFile;
				$this->abcFinder->replace($abcFile, $abcTextObjects);
			}
		}
	}
	
	protected function scanTags($tags, &$textObjects) {
		foreach($tags as $tag) {
			if($tag instanceof SWFDoABCTag) {
				if($tag->abcFile) {
					$abcTextObjects = $this->abcFinder->find($tag->abcFile);
					foreach($abcTextObjects as $abcTextObject) {
						$textObject = new SWFTextObjectInfo;
						$textObject->name = $abcTextObject->name;
						$textObject->xml = $abcTextObject->xml;
						$textObject->tag = $tag;
						$textObject->abcInfo = $abcTextObject;
						$textObjects[] = $textObject;
					}
				}
			} else if($tag instanceof SWFDefineSpriteTag) {
				$this->scanTags($tag->tags, $textObjects);
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->scanTags($tag->swfFile->tags, $textObjects);
				}
			}
		}
	}
}

class SWFTextObjectInfo {
	public $name;
	public $xml;
	public $tag;
	public $abcInfo;
}

?>