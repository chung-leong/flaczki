<?php

class SWFAssetFinder {

	protected $assets;
	protected $dictionary;
	
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
	
	public function find($swfFile) {
		$this->assets = new SWFAssets;
		$this->dictionary = array();
		$this->processTags($swfFile->tags);
		$assets = $this->assets;
		$this->assets = $this->dictionary = null;
		return $assets;
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
	
	protected function processBitmapTag($tag) {
		$image = new SWFImage;
		$image->tag = $tag;
		$this->assets->images[] = $image;
		$this->dictionary[$tag->characterId] = $image;
	}
	
	protected function processDefineBitsTag($tag) {
		// TODO--need sample file
	}
	
	protected function processDefineBitsJPEG2Tag($tag) {
		$this->processBitmapTag($tag);
	}
	
	protected function processDefineBitsJPEG3Tag($tag) {
		$this->processBitmapTag($tag);
	}
	
	protected function processDefineBitsJPEG4Tag($tag) {
		$this->processBitmapTag($tag);
	}
	
	protected function processDefineBitsLosslessTag($tag) {
		$this->processBitmapTag($tag);
	}
	
	protected function processDefineBitsLossless2Tag($tag) {
		$this->processBitmapTag($tag);
	}
	
	protected function processJPEGTablesTag($tag) {
	}
	
	protected function processDefineFontTag($tag) {
	}
	
	protected function processDefineFont2Tag($tag) {
	}
	
	protected function processDefineFont3Tag($tag) {
	}
	
	protected function processDefineFont4Tag($tag) {
		if(isset($this->assets->fontFamilies[$tag->name])) {
			$family = $this->assets->fontFamilies[$tag->name];
		} else {
			$family = $this->assets->fontFamilies[$tag->name] = new SWFFontFamily;
			$family->name = $tag->name;
		}
		
		if($tag->cffData) {
			// embedded font
			$cffParser = new CFFParser;
			$font = $cffParser->parse($tag->cffData);
			if($font) {
				if($font->bold) {
					if($font->italic || $font->oblique) {
						$family->boldItalic = $font;
					} else {
						$family->bold = $font;
					}
				} else if($font->italic || $font->oblique) {
					$family->italic = $font;
				} else {
					$family->normal = $font;
				}
			}
		}
	}
	
	protected function processDefineFontInfoTag($tag) {
	}
	
	protected function processDefineFontInfo2Tag($tag) {
	}
	
	protected function processDefineFontNameTag($tag) {
	}
	
	protected function processDefineEditTextTag($tag) {
	}
	
	protected function processDefineTextTag($tag) {
	}
	
	protected function processDefineText2Tag($tag) {
	}
	
	protected function processDoABCTag($tag) {
		if($tag->abcFile) {			
			$tlfParser = new TLFParser;
			$abcFinder = new ABCTextObjectFinder;
			$abcTextObjects = $abcFinder->find($tag->abcFile);
			foreach($abcTextObjects as $abcTextObject) {
				$textObject = new SWFTextObject;
				$textObject->tag = $tag;
				$textObject->name = $abcTextObject->name;
				$textObject->tlfObject = $tlfParser->parse($abcTextObject->xml);
				$textObject->abcObject = $abcTextObject;
				$this->assets->textObjects[] = $textObject;
			}
		}
	}
	
	protected function processDefineBinaryDataTag($tag) {
		if($tag->swfFile) {
			$this->processTags($tag->swfFile->tags);
		}
	}
	
	protected function processDefineSpriteTag($tag) {
		$this->processTags($tag->tags);
	}
	
	protected function processExportAssetsTag($tag) {
	}
	
	protected function processSymbolClassTag($tag) {
	}
	
	protected function processPlaceObjectTag($tag) {
	}
	
	protected function processPlaceObject2Tag($tag) {
	}
	
	protected function processPlaceObject3Tag($tag) {
	}
}

class SWFAssets {
	public $textObjects = array();
	public $fontFamilies = array();
	public $images = array();
	public $swfFile = array();
}

class SWFAsset {
	public $name;
	public $tag;
	public $changed;
}

class SWFTextObject extends SWFAsset {
	public $tlfObject;
	public $abcObject;
}

class SWFImage extends SWFAsset {
	private $_data;
	
	public function __get($name) {
		if($name == 'data') {
			if(!$this->_data) {
				$this->_data = $this->getImageData();
			}
			return $this->_data;
		}
	}
	
	public function __set($name, $value) {
		if($name == 'data') {
			$this->_data = $value;
		}
	}
	
	protected function getImageData() {
		if($this->tag instanceof SWFDefineBitsJPEG3Tag) {
			$converter = new TransparentJPEGConverter;
			return $converter->convertToPNG($this->tag);
		} else if($this->tag instanceof SWFDefineBitsLosslessTag) {
			$converter = new LosslessBitsConverter;
			return $converter->convertToPNG($this->tag);
		} 
		return $this->tag->imageData;
	}
}

class SWFFontFamily extends SWFAsset {
	public $normal;
	public $bold;
	public $italic;
	public $boldItalic;
}

?>