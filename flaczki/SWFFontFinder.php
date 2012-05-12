<?php

class SWFFontFinder {

	protected $cffParser;
	
	public function __construct() {
		$this->cffParser = new CFFParser;
	}

	public function find($swfFile) {
		$families = array();
		$this->scanTags($swfFile->tags, $families);
		
		return $families;
	}
	
	protected function scanTags($tags, &$families) {
		foreach($tags as $tag) {
			if($tag instanceof SWFDefineFont4Tag) {
				if(isset($families[$tag->fontName])) {
					$family = $families[$tag->fontName];
				} else {
					$family = $families[$tag->fontName] = new SWFFontFamily;
					$family->name = $tag->fontName;
				}
				
				if($tag->cffData) {
					// embedded font
					$font = $this->cffParser->parse($tag->cffData);
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
			} else if($tag instanceof SWFDefineSpriteTag) {
				$this->scanTags($tag->tags, $families);
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->scanTags($tag->swfFile->tags, $families);
				}
			}
		}
	}
}

class SWFFontFamily {
	public $name;
	public $normal;
	public $bold;
	public $italic;
	public $boldItalic;
}

?>