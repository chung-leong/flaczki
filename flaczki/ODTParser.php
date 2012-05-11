<?php

class ODTParser {

	protected $document;
	protected $paragraph;
	protected $span;
	protected $previousSpan;
	protected $style;
	protected $styleName;
	protected $font;
	protected $fontName;
	protected $fileName;

	public function parse($input) {
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
					$parser = xml_parser_create_ns();
					xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
					xml_set_object($parser, $this);
					xml_set_element_handler($parser, 'processStartTag', 'processEndTag');
					xml_set_character_data_handler($parser, 'processCharacterData');
					while($data = fread($stream, 1024)) {
						xml_parse($parser, $data, !$data);
					}
					//xml_parser_free($parser);
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
		switch($name) {
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:s':
				$this->processCharacterData($parser, " ");
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:tab':
				$this->processCharacterData($parser, "\t");
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:span':
				$styleName = $attributes['urn:oasis:names:tc:opendocument:xmlns:text:1.0:style-name'];
				$style = $this->document->styleTable[$styleName];
				if($this->previousSpan && $this->previousSpan->style === $style) {
					// the style is the same--continue to add text to previous span
					$this->span = $this->previousSpan;
				} else {
					$this->span = new ODTSpan;
					$this->span->style = $style;
				}
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:p':
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:h':
				$styleName = $attributes['urn:oasis:names:tc:opendocument:xmlns:text:1.0:style-name'];
				$style = $this->document->styleTable[$styleName];
				$this->paragraph = new ODTParagraph;
				$this->paragraph->type = $this->stripNs($name);
				$this->paragraph->style = $style;
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:style':
				$this->style = new ODTStyle;
				$this->styleName = $attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:name'];
				unset($attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:name']);
				foreach($attributes as $name => $value) {
					$this->style->attributes[$this->stripNs($name)] = $value;
				}
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:default-style':
				$this->style = new ODTStyle;
				$this->styleName = $attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:family'];
				unset($attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:family']);
				foreach($attributes as $name => $value) {
					$this->style->attributes[$this->stripNs($name)] = $value;
				}
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:paragraph-properties':
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:text-properties':
				if($this->style) {
					foreach($attributes as $name => $value) {
						$this->style->attributes[$this->stripNs($name)] = $value;
					}
				}
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:tab-stop':
				if($this->style) {
					$tabStop = new ODTTabStop;
					foreach($attributes as $name => $value) {
						$tabStop->attributes[$this->stripNs($name)] = $value;
					}
					$this->style->tabStops[] = $tabStop;
				}
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:font-face':
				$this->font = new ODTFont;
				$this->fontName = $attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:name'];
				unset($attributes['urn:oasis:names:tc:opendocument:xmlns:style:1.0:name']);
				foreach($attributes as $name => $value) {
					$this->font->attributes[$this->stripNs($name)] = $value;
				}
				break;
		}
	}

	public function processEndTag($parser, $name) {
		switch($name) {
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:span':
				// don't add the span if it's been added already
				if($this->previousSpan !== $this->span) {
					if($this->paragraph) {
						$this->paragraph->spans[] = $this->span;
					}
					$this->previousSpan = $this->span;
				}
				$this->span = null;
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:p':
			case 'urn:oasis:names:tc:opendocument:xmlns:text:1.0:h':
				$this->document->paragraphs[] = $this->paragraph;
				$this->paragraph = null;
				$this->span = null;
				$this->previousSpan = null;
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:style':
				// see if there's already a style object matching this one
				if($this->fileName == 'content.xml') {
					$name = array_search($this->style, $this->document->styleTable);
					$this->document->styleTable[$this->styleName] = ($name) ? $this->document->styleTable[$name] : $this->style;
				} else {
					$this->document->commonStyleTable[$this->styleName] = $this->style;
				}
				$this->style = null;
				$this->styleName = null;
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:default-style':
				$this->document->defaultStyleTable[$this->styleName] = $this->style;
				$this->style = null;
				$this->styleName = null;
				break;
			case 'urn:oasis:names:tc:opendocument:xmlns:style:1.0:font-face':
				$this->document->fonts[$this->fontName] = $this->font;
				$this->font = null;
				$this->fontName = null;
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

	protected function stripNs($s) {
		$pos = strrpos($s, ':');
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
	public $styleTable = array();
	public $commonStyleTable = array();
	public $paragraphs = array();
	public $fonts = array();
}

class ODTParagraph {
	public $style;
	public $spans = array();
	public $type;
}

class ODTSpan {
	public $style;
	public $text;
}

class ODTStyle {
	public $attributes = array();
	public $tabStops = array();

	public function __toString() {
		return print_r($this->attributes, true);
	}
}

class ODTTabStop {
	public $attributes = array();
}

class ODTFont {
	public $attributes = array();
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
