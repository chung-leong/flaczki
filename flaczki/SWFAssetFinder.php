<?php

class SWFAssetFinder {

	protected $swfFile;
	protected $assets;
	protected $dictionary;
	protected $jpegTables;
	
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
		$this->swfFile = $swfFile;
		$this->assets = new SWFAssets;
		$this->dictionary = array();
		$this->processTags($swfFile->tags);
		$assets = $this->assets;
		$this->assets = $this->dictionary = $this->swfFile = null;
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
		$image->swfFile = $this->swfFile;
		if($tag instanceof SWFDefineBitsJPEG2Tag || $tag instanceof SWFDefineBitsTag) {
			$imagePath = StreamMemory::add($tag->imageData);
			$imageInfo = getimagesize($imagePath);
			$image->originalMimeType = $imageInfo['mime'];
			$width = $imageInfo[0];
			$height = $imageInfo[1];
		} else if($tag instanceof SWFDefineBitsLosslessTag) {
			$width = $tag->width;
			$height = $tag->height;
		}
		$image->jpegTables = ($tag instanceof SWFDefineBitsTag) ? $this->jpegTables : null;
		$crc32 = crc32($tag->imageData);
		$image->name = sprintf("image.%04dx%04d.%010u", $width, $height, $crc32);
		
		$this->assets->images[] = $image;
		$this->dictionary[$tag->characterId] = $image;
	}
	
	protected function processJPEGTablesTag($tag) {
		$this->jpegTables = $tag->jpegData;
	}
	
	protected function processDefineBitsTag($tag) {
		$this->processBitmapTag($tag);
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
	
	protected function addFont($font) {
		if(isset($this->assets->fontFamilies[$font->name])) {
			$family = $this->assets->fontFamilies[$font->name];
		} else {
			$family = $this->assets->fontFamilies[$font->name] = new SWFFontFamily;
			$family->name = $font->name;
		}
		if($font->isBold) {
			if($font->isItalic) {
				$family->boldItalic = $font;
			} else {
				$family->bold = $font;
			}
		} else if($font->isItalic) {
			$family->italic = $font;
		} else {
			$family->normal = $font;
		}
		$this->dictionary[$font->tag->characterId] = $font;
	}
	
	protected function processDefineFont4Tag($tag) {
		$font = new SWFCFFFont;
		$font->tag = $tag;
		$font->swfFile = $this->swfFile;
		$font->name = $tag->name;
		if($tag->cffData) {
			$header = unpack('Nversion/nnumTables/nsearchRange/nentrySelector/nrangeShift', $tag->cffData);
			if($header['version'] == 0x4F54544f) {	// 'OTTO'
				for($i = 0, $n = $header['numTables'], $offset = 12; $i < $n; $i++) {
					$table = unpack('Ntag/NcheckSum/Noffset/Nlength', substr($tag->cffData, $offset, 16));
					if($table['tag'] == 0x4F532F32) {	// 'OS/2'
						$tableData = substr($tag->cffData, $table['offset'], $table['length']);
						$os2 = unpack('nversion/navgCharWidth/nweightClass/nwidthClass/nfsType/nsubscriptXSize/nsubscriptYSize/nsubscriptXOffset/nsubscriptYOffset/nsuperscriptXSize/nsuperscriptYSize/nsuperscriptXOffset/nsuperscriptYOffset/nstrikeoutSize/nstrikeoutPosition/nfamilyClass/C10panose/N4unicodeRange/a4vendId/nselection/nfirstCharIndex/nlastCharIndex/ntypoAscender/ntypoDescender/ntypeLineGap/nwinAscent/nwinDescent/NcodePageRange1/NcodePageRange2', $tableData);
						$font->weight = $os2['weightClass'];
						$font->width = $os2['widthClass'];
						$font->isItalic = ($os2['selection'] & 0x0001) != 0 || ($os2['selection'] & 0x0200) != 0;
						$font->isBold = ($os2['selection'] & 0x0020) != 0;
						for($j = 1; $j <= 10; $j++) {
							$font->panose[$j] = $os2["panose$j"];
						}
						break;
					}
					$offset += 16;
				}
			}
		}
		$this->addFont($font);
	}
	
	protected function processDoABCTag($tag) {
		if($tag->abcFile) {			
			$tlfParser = new TLFParser;
			$abcFinder = new ABCTextObjectFinder;
			$abcTextObjects = $abcFinder->find($tag->abcFile);
			foreach($abcTextObjects as $abcTextObject) {
				$textObject = new SWFTextObject;
				$textObject->tag = $tag;
				$textObject->swfFile = $this->swfFile;
				$textObject->name = $abcTextObject->name;
				$textObject->tlfObject = $tlfParser->parse($abcTextObject->xml, $abcTextObject->extraInfo);
				$textObject->abcObject = $abcTextObject;
				$this->assets->textObjects[] = $textObject;
			}
		}
	}
	
	protected function processDefineBinaryDataTag($tag) {
		if($tag->swfFile) {
			$container = $this->swfFile;
			$this->swfFile = $tag->swfFile;
			$this->processTags($tag->swfFile->tags);
			$this->swfFile = $container;
		}
	}
	
	protected function processDefineSpriteTag($tag) {
		$this->processTags($tag->tags);
	}
	
	protected function processSymbolClassTag($tag) {
		foreach($tag->names as $characterId => $className) {
			if(isset($this->dictionary[$characterId])) {
				$object = $this->dictionary[$characterId];
				$tag->exports[$className] = $object;
			}
		}
	}
}

class SWFAssets {
	public $textObjects = array();
	public $fontFamilies = array();
	public $images = array();
	public $exports = array();
}

class SWFCharacter {
	public $tag;
	public $swfFile;
}

class SWFTextObject extends SWFCharacter {
	public $name;
	public $changed;
	public $tlfObject;
	public $abcObject;
	public $embeddedImages = array();
}

class SWFImage extends SWFCharacter {
	public $name;
	public $changed;
	public $originalMimeType;
	public $jpegTables;
	
	private $_data;
	private $_mimeType;
	
	public function __get($name) {
		if($name == 'data') {
			if(!$this->_data) {
				$this->_data = $this->getImageData();
			}
			return $this->_data;
		}
		if($name == 'mimeType') {
			if(!$this->_mimeType) {
				$this->_mimeType = $this->getMimeType();
			}
			return $this->_mimeType;
		}
	}
	
	public function __set($name, $value) {
		if($name == 'data') {
			$this->_data = $value;
		}
	}
	
	protected function getImageData() {
		if($this->tag instanceof SWFDefineBitsJPEG3Tag && $this->tag->alphaData && TransparentJPEGConverter::isAvailable()) {
			$converter = new TransparentJPEGConverter;
			return $converter->convertToPNG($this->tag);
		} else if($this->tag instanceof SWFDefineBitsLosslessTag && LosslessBitsConverter::isAvailable()) {
			$converter = new LosslessBitsConverter;
			return $converter->convertToPNG($this->tag);
		} else if($this->tag instanceof SWFDefineBitsJPEG2Tag) {
			return $this->tag->imageData;
		} else if($this->tag instanceof SWFDefineBitsTag) {
			return $this->tag->jpegTables . $this->tag->imageData;
		}
	}
	
	protected function getMimeType() {
		if($this->tag instanceof SWFDefineBitsJPEG3Tag && $this->tag->alphaData && TransparentJPEGConverter::isAvailable()) {
			return 'image/png';
		} else if($this->tag instanceof SWFDefineBitsLosslessTag && LosslessBitsConverter::isAvailable()) {
			return 'image/png';
		} else if($this->tag instanceof SWFDefineBitsJPEG2Tag) {
			return $this->originalMimeType;
		} else if($this->tag instanceof SWFDefineBitsTag) {
			return 'image/jpeg';
		}
	}
}

class SWFFontFamily  {
	public $name;
	public $normal;
	public $bold;
	public $italic;
	public $boldItalic;
}

class SWFFont extends SWFCharacter {
	public $name;
	public $isBold;
	public $isItalic;
}

class SWFGlyphFont extends SWFFont {
	public $codeTable;
}

class SWFCFFFont extends SWFFont {
	public $weight;			// 100 = thin, 400 = normal, 900 = heavy
	public $width;			// 1 = ultra-condensed, 5 = normal, 9 = ultra-expanded
	public $panose = array();	// see http://www.monotypeimaging.com/ProductsServices/pan1.aspx
}

?>