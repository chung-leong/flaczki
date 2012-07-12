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
		$this->assets = null;
		return true;
	}
	
	public function updateTextObject($textObject) {
		if($textObject->tag instanceof SWFDoABCTag) {
			$tlfAssembler = new TLFAssembler;
			$abcTextObject = $textObject->abcObject;
			$abcFile = $textObject->tag->abcFile;
			$tlfAssembler->assemble($abcTextObject->xml, $textObject->tlfObject);
			$abcUpdater = new ABCTextObjectUpdater;
			$abcUpdater->update($abcFile, $abcTextObject);
		}
	}
	
	public function updateImage($image) {
		
	}
}


?>