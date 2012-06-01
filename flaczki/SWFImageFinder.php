<?php

class SWFImageFinder {

	public function __construct() {
	}

	public function find($swfFile) {
		$images = array();
		$this->scanTags($swfFile->tags, $images);
		return $images;
	}
	
	public function replace($images) {
		foreach($images as $image) {
			$tag->imageData = $image->imageData;
			$tag->alphaData = $image->alphaData;
		}
	}
	
	protected function scanTags($tags, &$images) {
		foreach($tags as $tag) {
			if($tag instanceof SWFDefineBitsJPEGTag) {
				$image = new SWFImage;
				$image->tag = $tag;
				$image->imageData = $tag->imageData;
				$image->alphaData = $tag->alphaData;
				$path = StreamMemory::add($image->imageData);
				if($imageInfo = getimagesize($path)) {
					$image->width = $imageInfo[0];
					$image->height = $imageInfo[1];
					$image->mimeType = $imageInfo['mime'];
				}
				$crc = crc32($image->imageData);
				if($crc < 0) {
					$crc = 4294967295.0 + $crc;
				}
				$image->name = sprintf("image.%dx%d.%010.0f", $image->width, $image->height, $crc);
				$images[] = $image;
			} else if($tag instanceof SWFDefineSpriteTag) {
				$this->scanTags($tag->tags, $images);
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->scanTags($tag->swfFile->tags, $images);
				}
			}
		}
	}
}

class SWFImage {
	public $name;
	public $imageData;
	public $alphaData;
	public $mimeType;
	public $width;
	public $height;
	public $tag;
}


?>