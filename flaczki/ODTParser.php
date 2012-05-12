<?php

class ODTParser {

	protected $document;
	protected $paragraph;
	protected $span;
	protected $previousSpan;
	protected $style;
	protected $fileName;
	
	protected $styleNameRemap = array();

	public function parse($input) {
		if(gettype($input) == 'string') {
			$path = StreamMemory::add($input);
			$input = fopen($path, "rb");
		} else if(gettype($input) == 'resource') {
		} else {
			throw new Exception("Invalid input");
		}
		
		$document = $this->document = new ODTDocument;
		// since we expect the file to be from a remote source, we're going to parse it as data comes in to improve performance
		// instead of going to the ZIP file's central directory, we'll scan the individual file records
		while($header = $this->readZipHeader($input)) {
			if($header->compressedSize != 0) {
				// if the compressed size is known, then create a partial stream encompassing that byte range
				$path = StreamPartial::add($input, $header->compressedSize);
			} else if($header->flags | 0x08) {
				// compressed since is unknown, there should be a 16-byte data descriptor at the end of 
				// the file record; the substream ends there
				$path = StreamZipDDDelimited::add($input);
			} else {
				// file must be empty or something
				$path = null;
			}
			if($path) {
				$stream = fopen($path, "rb");
				if($header->name == 'content.xml' || $header->name == 'styles.xml') {
					if($header->method == 8) {
						// inflate the content with the inflate filter
						stream_filter_append($stream, "zlib.inflate");
					}
					$this->fileName = $header->name;

					// parse it with PHP's SAX event-based parser, which requires less memory than a tree-based 
					// parser and is perfect for on-the-fly processing
					$parser = xml_parser_create();
					xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
					xml_set_object($parser, $this);
					xml_set_element_handler($parser, 'processStartTag', 'processEndTag');
					xml_set_character_data_handler($parser, 'processCharacterData');
					while($data = fread($stream, 1024)) {
						xml_parse($parser, $data, strlen($data) != 1024);
					}
				} else {
					// just pull the data through so we can reach the next file record
					while($data = fread($stream, 1024)) {
					}
				}
			}
		}
		$this->document = $this->paragraph = $this->span = $this->previousSpan = $this->style = $this->font = null;
		return $document;
	}
	
	public function processStartTag($parser, $name, $attributes) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 's':
				if(isset($attributes['c'])) {
					$this->processCharacterData($parser, str_repeat(" ", $attributes['c']));
				} else {
					$this->processCharacterData($parser, " ");
				}
				break;
			case 'tab':
				$this->processCharacterData($parser, "\t");
				break;
			case 'span':
				$this->span = new ODTSpan;
				$this->copyProperties($this->span, $attributes);
				
				// see if the new span has the same attributes as the previous one
				if($this->previousSpan && ($this->previousSpan->styleName == $this->span->styleName && $this->previousSpan->classNames == $this->span->classNames)) {
					// continue to add text to previous span instead
					$this->span = $this->previousSpan;
				}
				break;
			case 'p':
				$this->paragraph = new ODTParagraph;
				$this->copyProperties($this->paragraph, $attributes);
				break;
			case 'h':
				$this->paragraph = new ODTHeader;
				$this->copyProperties($this->paragraph, $attributes);
				break;
			case 'style':
			case 'default-style':
				$this->style = new ODTStyle;
				$this->copyProperties($this->style, $attributes);
				break;
			case 'paragraph-properties':
				$this->style->paragraphProperties = new ODTParagraphProperties;
				$this->copyProperties($this->style->paragraphProperties, $attributes);
				break;
			case 'text-properties':
				$this->style->textProperties = new ODTTextProperties;
				$this->copyProperties($this->style->textProperties, $attributes);
				break;
			case 'tab-stop':
				$tabStop = new ODTTabStop;
				$this->copyProperties($tabStop, $attributes);
				$this->style->paragraphProperties->tabStops[] = $tabStop;
				break;
			case 'font-face':
				$font = new ODTFont;
				$this->copyProperties($font, $attributes);
				$this->document->fonts[$font->name] = $font;
				break;
		}
	}

	public function processEndTag($parser, $name) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'span':
				// don't add the span if it's been added already
				if($this->previousSpan !== $this->span) {
					if($this->paragraph) {
						$this->paragraph->spans[] = $this->span;
					}
					$this->previousSpan = $this->span;
				}
				$this->span = null;
				break;
			case 'p':
			case 'h':
				$this->document->paragraphs[] = $this->paragraph;
				$this->paragraph = null;
				$this->span = null;
				$this->previousSpan = null;
				break;
			case 'style':
				if($this->fileName == 'content.xml') {
					// ODT file from GoogleDocs contains a style for EVERY word and EVERY white-space
					// we want to merge identical ones together to reduce the number of spans
					
					// see if there's already a style object matching this one
					$name = $this->findIdenticalStyle($this->document->automaticStyles, $this->style);
					if($name) {
						// remap style name to existing style
						$this->styleNameRemap[$this->style->name] = $name;
					} else {
						$this->document->automaticStyles[$this->style->name] = $this->style;
					}
				} else {
					$this->document->commonStyles[$this->style->name] = $this->style;
				}
				$this->style = null;
				break;
			case 'default-style':
				$this->document->defaultStyles[$this->style->family] = $this->style;
				$this->style = null;
				break;
		}
	}

	public function processCharacterData($parser, $text) {
		if(!$this->span && $this->paragraph) {
			$this->span = new ODTSpan;
			$this->paragraph->spans[] = $this->span;
		}
		if($this->span) {
			$this->span->text .= $text;
		}
	}
	
	protected function copyProperties(&$object, $attributes) {
		foreach($attributes as $name => $value) {
			$name = $this->stripPrefix($name);
			
			if($name == 'style-name') {
				// see if this style name isn't remapped to some other name
				if(isset($this->styleNameRemap[$value])) {
					$value = $this->styleNameRemap[$value];
				}
			}
			
			if(strpos($name, '-')) {
				// convert the name to camel case
				$name = str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
				$name = strtolower($name[0]) . substr($name, 1);
			}
			if(property_exists($object, $name)) {
				$object->$name = $value;
			}
		}
	}
	
	protected function findIdenticalStyle($list, $style) {
		foreach($list as $key => $existingStyle) {
			if($existingStyle->family == $style->family
			&& $existingStyle->parentStyleName == $style->parentStyleName
			&& $existingStyle->textProperties == $style->textProperties
			&& $existingStyle->paragraphProperties == $style->paragraphProperties) {
				return $key;
			}
		}
	}

	protected function stripPrefix($s) {
		$pos = strpos($s, ':');
		return ($pos !== false) ? substr($s, $pos + 1) : $s;
	}

	protected function readZipHeader($input) {
		$header = fread($input, 30);
		while(strlen($header) == 30) {
			$array = unpack("Vsignature/vversion/vflags/vmethod/vlastModifiedTime/vlastModifiedDate/Vcrc32/VcompressedSize/VuncompressedSize/vnameLength/vextraLength", $header);
			if($array['signature'] == 0x04034b50) {
				$header = new ODTZipHeader;
				$header->flags = $array['flags'];
				$header->method = $array['method'];
				$header->lastModifiedTime = $array['lastModifiedTime'];
				$header->lastModifiedDate = $array['lastModifiedDate'];
				$header->crc32 = $array['crc32'];
				$header->compressedSize = $array['compressedSize'];
				$header->uncompressedSize = $array['uncompressedSize'];
				if($array['nameLength'] > 0) {
					$header->name = fread($input, $array['nameLength']);
				}
				if($array['extraLength'] > 0) {
					$header->extra = fread($input, $array['extraLength']);
				}
				return $header;
			} else if($array['signature'] == 0x02014b50) {
				// we've reached the central directory--just pull the data thru
				while($data = fread($input, 1024)) {
				}
				break;
			} else {
				// shift one byte forward and try again
				$header = substr($header, 1) . fread($input, 1);
			}
		}
		return null;
	}
}

class ODTDocument {
	public $automaticStyles = array();
	public $commonStyles = array();
	public $defaultStyles = array();
	public $paragraphs = array();
	public $fonts = array();
}

class ODTParagraph {
	public $styleName;
	public $classNames;
	
	public $spans = array();
}

class ODTHeader extends ODTParagraph {
}

class ODTSpan {
	public $styleName;
	public $classNames;
	
	public $text;
}

class ODTHyperlink extends ODTSpan {
	public $href;
}

class ODTStyle {
	public $name;
	public $family;
	public $parentStyleName;
	
	public $textProperties;
	public $paragraphProperties;
}

class ODTParagraphProperties {
	public $breakBefore;
	public $marginBottom;
	public $marginLeft;
	public $marginRight;
	public $marginTop;	
	public $lineDistance;
	public $lineHeight;
	public $tabStops;
	public $tabStopDistance;
	public $textIndent;
	public $textAlign;
	public $textAlignLast;
	public $writingMode;
}

class ODTTextProperties {
	public $backgroundColor;
	public $color;
	public $country;
	public $fontName;
	public $fontFamily;
	public $fontSize;
	public $fontStyle;
	public $fontVariant;
	public $fontWeight;
	public $language;
	public $letterSpacing;
	public $textLineThroughStyle;
	public $textPosition;
	public $textTransformations;
	public $textUnderlineType;
	public $textUnderlineStyle;	
}

class ODTTabStop {
	public $type;
	public $char;
	public $position;	
}

class ODTFont {
	public $name;
	public $fontFamily;
	public $fontFamilyGeneric;
	public $fontStyle;
	public $fontPitch;
	public $fontVariant;
	public $fontWeight;
	public $panose1;
}

class ODTZipHeader {
	public $flags;
	public $method;
	public $lastModifiedTime;
	public $lastModifiedDate;
	public $crc32;
	public $compressedSize;
	public $uncompressedSize;
	public $name;
	public $extra;
}

?>
