<?php

class SWFAssetUpdater {

	protected $assets;
	
	public function update($assets) {
		$changed = false;
		$this->assets = $assets;
		foreach($assets->textObjects as $textObject) {
			if($textObject->changed) {
				$this->updateTextObject($textObject);
				$changed = true;
			}
		}
		foreach($assets->images as $image) {
			if($image->changed) {
				$this->updateImage($image);
				$changed = true;
			}
		}
		
		// insert images referenced by text objects
		foreach($assets->textObjects as $textObject) {
			if($textObject->changed) {
				$extraInfo = $textObject->abcObject->extraInfo;
				if($extraInfo) {
					$symbolClassTag = new SWFSymbolClassTag;
					foreach($extraInfo as $className) {
						$swfFile = $textObject->swfFile;
						$tagIndex = array_search($textObject->tag, $swfFile->tags);
						if($tagIndex !== false) {
							// insert image tags after the doABC tag
							$insertIndex = $tagIndex + 1;
							$image = $assets->exports[$className];
							if(!$image->tag->characterId) {
								// assign a character id to the tag
								$image->tag->characterId = ++$swfFile->highestCharacterId;
								$symbolClassTag->names[$image->tag->characterId] = $className;
								array_splice($swfFile->tags, $insertIndex++, 0, array($image->tag));
							}
						}
					}
					if($symbolClassTag->names) {
						array_splice($swfFile->tags, $insertIndex++, 0, array($symbolClassTag));
					}
				}
			}
		}
		$this->assets = null;
		return $changed;
	}
	
	protected function updateTextObject($textObject) {
		if($textObject->tag instanceof SWFDoABCTag) {
			$tlfAssembler = new TLFAssembler;
			$abcTextObject = $textObject->abcObject;
			$abcFile = $textObject->tag->abcFile;
			$tlfAssembler->assemble($abcTextObject->xml, $abcTextObject->extraInfo, $textObject->tlfObject);
			$abcUpdater = new ABCTextObjectUpdater;
			$abcUpdater->update($abcFile, $abcTextObject);
		}
	}
	
	protected function updateImage($image) {
		$originalTag = $image->tag;
		if($originalTag instanceof SWFDefineBitsJPEG3Tag && TransparentJPEGConverter::isAvailable()) {
			// should be a JPEG image with alpha channel
			$converter = new TransparentJPEGConverter;
			$deblockingParam = ($originalTag instanceof SWFDefineBitsJPEG4) ? $originalTag->deblockingParam : 0; 
			$image->tag = $converter->convertFromPNG($image->data, $deblockingParam);
		} else {
			$image->tag = new SWFDefineBitsJPEG2Tag;
			$image->tag->imageData = $image->data;
		}
		if($originalTag) {
			$image->tag->characterId = $originalTag->characterId;
			
			// replace the tag
			$swfFile = $image->swfFile;
			$index = array_search($originalTag, $swfFile->tags, true);
			if($index !== false) {
				$swfFile->tags[$index] = $image->tag;
			}
		}
	}
}


?>