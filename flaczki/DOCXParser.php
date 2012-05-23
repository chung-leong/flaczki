<?php

class DOCXParser {

	protected $document;
	protected $paragraph;
	protected $span;
	protected $previousSpan;
	protected $hyperlink;
	protected $hyperlinks;
	protected $style;
	protected $font;
	protected $textProperties;
	protected $paragraphProperties;
	protected $fontList;
	protected $colorScheme;
	protected $fontScheme;
	protected $color;

	protected $currentDirectory;
	protected $currentFileName;
	protected $fileReferences;
	protected $embeddedFiles;

	public function parse($input) {
		if(gettype($input) == 'string') {
			$path = StreamMemory::add($input);
			$input = fopen($path, "rb");
		} else if(gettype($input) == 'resource') {
		} else {
			throw new Exception("Invalid input");
		}
		
		$document = $this->document = new DOCXDocument;
		$this->fileReferences = array();
		$this->embeddedFiles = array();
		$zipPath = StreamZipArchive::open($input);
		$processed = array('document.xml' => false, 'styles.xml' => false);
		$rootDir = opendir($zipPath);
		$dirStack = array($rootDir);
		$pathStack = array();
		do {
			// recursively scan the file structure in the zip file
			// because StreamZipArchive scans a file sequentially, an item must be open
			// immediately after it's returned
			$dir = array_pop($dirStack);
			while($file = readdir($dir)) {
				$directory = implode('/', $pathStack);
				$path = (($directory) ? "$directory/" : "") . $file;
				$fullPath = "$zipPath/$path";
				if(is_dir($fullPath)) {
					array_push($dirStack, $dir);
					array_push($pathStack, $file);
					$dir = opendir($fullPath);
				} else {
					// see what XML handlers to use
					if($file == 'document.xml') {
						$functions = array('processDocumentStartTag', 'processDocumentEndTag', 'processDocumentCharacterData');
					} else if($file == 'styles.xml') {
						$functions = array('processDocumentStartTag', 'processDocumentEndTag', 'ignoreCharacterData');
					} else if($file == 'fontTable.xml') {
						$functions = array('processFontTableStartTag', 'processFontTableEndTag', 'ignoreCharacterData');
					} else if(preg_match('/^theme\d*\.xml$/', $file)) {
						$functions = array('processThemeStartTag', 'processThemeEndTag', 'ignoreCharacterData');
					} else if(preg_match('/\.rels$/', $file)) {
						$functions = array('processRelsStartTag', 'processRelsEndTag', 'ignoreCharacterData');
					} else {
						$functions = null;
					}
					if($functions) {
						$stream = fopen($fullPath, "rb");
						$this->currentDirectory = $directory;
						$this->currentFileName = $file;
						
						$parser = xml_parser_create();
						xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
						xml_set_object($parser, $this);
						xml_set_element_handler($parser, $functions[0], $functions[1]);
						xml_set_character_data_handler($parser, $functions[2]);
						while($data = fread($stream, 1024)) {
							xml_parse($parser, $data, strlen($data) != 1024);
						}
						$processed[$file] = true;
					} else if(preg_match('/\.(jpeg|png)$/', $file)) {
						// save the image
						$size = filesize($fullPath);
						$stream = fopen($fullPath, "rb");
						$embeddedFile = new DOCXEmbeddedFile;
						$embeddedFile->fileName = $file;
						// use a loop to read the data just in case fread doesn't return everything in one call
						while($data = fread($stream, $size)) {
							if($embeddedFile->data) {
								$embeddedFile->data .= $data;
							} else {
								$embeddedFile->data = $data;
							}
						}
						$this->embeddedFiles[$path] = $embeddedFile;
					}
				}
			}
			$file = array_pop($pathStack);
		} while(count($dirStack) > 0);
		
		foreach($this->fileReferences as $reference => $path) {
			if(isset($this->embeddedFiles[$path])) {
				// attach embedded files to the document
				list($referrer, $id) = explode(':', $reference);
				if($referrer == 'word/document.xml') {
					$this->document->embeddedFiles[$id] = $this->embeddedFiles[$path];
				}
			} else if(isset($this->hyperlinks[$reference])) {
				// set the href of hyperlinks
				$hyperlink = $this->hyperlinks[$reference];
				$hyperlink->href = $path;
			}
		}
		if(array_sum($processed) == count($processed)) {
			return $document;
		}
		return false;
	}
	
	public function processRelsStartTag($parser, $name, $attributes) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'Relationship':
				$id = $attributes['Id'];
				$target = $attributes['Target'];
				$referrerFileName = preg_replace('/\.\w+$/', '', $this->currentFileName);
				$referrerDirectory = dirname($this->currentDirectory);
				$referrerPath = ($referrerDirectory == '.') ? $referrerFileName : "$referrerDirectory/$referrerFileName";
				if(isset($attributes['TargetMode']) && $attributes['TargetMode'] == 'External') {
					$targetPath = $target;					
				} else {
					$targetPath = ($referrerDirectory == '.') ? "$referrerDirectory/$target" : $target;
				}
				$this->fileReferences["$referrerPath:$id"] = $targetPath;
				break;
		}
	}
	
	public function processRelsEndTag($parser, $name) {
	}
	
	public function processDocumentStartTag($parser, $name, $attributes) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'noBreakHyphen':
				break;
			case 'tab':
				if($this->paragraphProperties) {
					$tabStop = new DOCXTabStop;
					$this->copyProperties($tabStop, $attributes);
					$this->paragraphProperties->tabStops[] = $tabStop;
				} else {				
					$this->processDocumentCharacterData($parser, "\t");
				}
				break;
			case 'br':
				$this->processDocumentCharacterData($parser, "\n");
				break;
			case 'r':
				$this->span = new DOCXSpan;
				$this->copyProperties($this->span, $attributes);
				$this->span->hyperlink = $this->hyperlink;
				break;
			case 'rPr':
				$this->textProperties = new DOCXTextProperties;
				break;
			case 'hyperlink':
				// the URL is stored in document.xml.rels 
				$this->hyperlink = new DOCXHyperlink;				
				$this->hyperlinks["{$this->currentDirectory}/{$this->currentFileName}:{$attributes['r:id']}"] = $this->hyperlink;
				break;
			case 'p':
				$this->paragraph = new DOCXParagraph;
				$this->copyProperties($this->paragraph, $attributes);
				break;
			case 'pPr':
				$this->paragraphProperties = new DOCXParagraphProperties;
				break;
			case 'style':
				$this->style = new DOCXStyle;
				$this->copyProperties($this->style, $attributes);
				break;
			case 'tabs':
				if($this->paragraphProperties) {
					$this->paragraphProperties->tabStops = array();
				}
				break;
			default:
				if($this->textProperties) {
					$this->copyProperties2($this->textProperties, $name, $attributes);
				} else if($this->paragraphProperties) {
					$this->copyProperties2($this->paragraphProperties, $name, $attributes);
				} else if($this->style) {
					$this->copyProperties2($this->style, $name, $attributes);
				}
		}
	}
	
	public function processDocumentEndTag($parser, $name) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'r':
				if($this->span) {
					// see if the new span has the same properties as the previous one
					if($this->previousSpan && ($this->previousSpan->textProperties == $this->span->textProperties && $this->previousSpan->hyperlink == $this->span->hyperlink)) {
						// add the text to the previous span
						$this->previousSpan->text .= $this->span->text;
					} else {
						$this->paragraph->spans[] = $this->span;
						$this->previousSpan = $this->span;
					}
					$this->span = null;
				}
				break;
			case 'rPr':
				// make sure it isn't empty
				if($this->textProperties != new DOCXTextProperties) {
					if($this->span) {
						$this->span->textProperties = $this->textProperties;
					} else if($this->paragraphProperties) {
						$this->paragraphProperties->textProperties = $this->textProperties;
					} else if($this->style) {
						$this->style->textProperties = $this->textProperties;
					} else {
						$this->document->defaultTextProperties = $this->textProperties;
					}
				}
				$this->textProperties = null;
				break;
			case 'hyperlink':
				$this->hyperlink = null;
				break;
			case 'p':
				$this->document->paragraphs[] = $this->paragraph;
				$this->paragraph = null;
				$this->span = null;
				$this->previousSpan = null;
				break;
			case 'pPr':
				if($this->paragraphProperties != new DOCXParagraphProperties) {
					if($this->paragraph) {
						$this->paragraph->paragraphProperties = $this->paragraphProperties;
					} else if($this->style) {
						$this->style->paragraphProperties = $this->paragraphProperties;
					} else {
						$this->document->defaultParagraphProperties = $this->paragraphProperties;
					}
				}
				$this->paragraphProperties = null;
				break;
			case 'style':
				$this->document->styles[$this->style->styleId] = $this->style;
				$this->style = null;
				break;
		}
	}

	public function processDocumentCharacterData($parser, $text) {
		if($this->span) {
			$this->span->text .= $text;
		}
	}
	
	public function processFontTableStartTag($parser, $name, $attributes) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'font':
				$this->font = new DOCXFont;
				$this->copyProperties($this->font, $attributes);
				break;
			default:
				if($this->font) {
					$this->copyProperties2($this->font, $name, $attributes);
				}
		}
	}

	public function processFontTableEndTag($parser, $name) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'font':
				$this->document->fonts[$this->font->name] = $this->font;
				$this->font = null;
				break;
		}
	}
	
	public function processThemeStartTag($parser, $name, $attributes) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'clrScheme': 
				$this->colorScheme = new DOCXColorScheme;
				$this->copyProperties($this->colorScheme, $attributes);
				break;
			case 'sysClr':
				$this->color = $attributes['lastClr'];
				break;
			case 'srgbClr':
				$this->color = $attributes['val'];
				break;
		}
	}

	public function processThemeEndTag($parser, $name) {
		$name = $this->stripPrefix($name);
		switch($name) {
			case 'clrScheme': 
				$this->document->colorScheme = $this->colorScheme;
				$this->colorScheme = null;
				break;
			case 'dk1':
			case 'dk2':
			case 'lt1':
			case 'lt2':
			case 'accent1':
			case 'accent2':
			case 'accent3':
			case 'accent4':
			case 'accent5':
			case 'accent6':
			case 'hlink':
			case 'folHlink':
				$this->colorScheme->$name = $this->color;
				break;
		}
	}
		
	public function ignoreCharacterData($parser, $text) {
	}
	
	protected function copyProperties($object, $attributes) {
		foreach($attributes as $name => $value) {
			$name = $this->stripPrefix($name);
			if(property_exists($object, $name)) {
				$object->$name = $value;
			}
		}
	}
	
	protected function copyProperties2($object, $tagName, $attributes) {
		foreach($attributes as $name => $value) {
			$name = $this->stripPrefix($name);
			$propName = $tagName . ucfirst($name);
			if(property_exists($object, $propName)) {
				$object->$propName = $value;
			}
		}
		if(property_exists($object, $tagName)) {
			$object->$tagName = true;
		}
	}
	
	protected function stripPrefix($s) {
		$pos = strpos($s, ':');
		return ($pos !== false) ? substr($s, $pos + 1) : $s;
	}
}

class DOCXDocument {
	public $defaultParagraphProperties;
	public $defaultTextProperties;
	public $paragraphs = array();
	public $fonts = array();
	public $styles = array();
	public $colorScheme;
	public $embeddedFiles = array();
}

class DOCXParagraph {
	public $paragraphProperties;
	
	public $spans = array();
}

class DOCXSpan {
	public $textProperties;
	
	public $text;
	public $hyperlink;
}

class DOCXHyperlink {
	public $href;
}

class DOCXParagraphProperties {
	public $bidi;
	public $indFirstLine;
	public $indLeft;
	public $indRight;
	public $jcVal;
	public $pageBreakBefore;
	public $pStyleVal;
	public $spacingAfter;
	public $spacingBefore;
	public $spacingLine;
	public $spacingLineRule;
	public $tabStops;
	public $textAlignmentVal;
	
	public $textProperties;
}

class DOCXTextProperties {
	public $b;
	public $bCs;
	public $caps;
	public $colorThemeColor;
	public $colorThemeTint;
	public $colorVal;
	public $dstrike;
	public $highlightVal;
	public $i;
	public $iCs;
	public $kernVal;
	public $langBidi;
	public $langEastAsia;
	public $langVal;
	public $positionVal;
	public $rFontsAscii;
	public $rFontsAsciiTheme;
	public $rFontsCs;
	public $rFontsCsTheme;
	public $rFontsHAnsi;
	public $rFontsHAnsiTheme;
	public $rFontsEastAsia;
	public $rFontsEastAsiaTheme;
	public $rStyleVal;
	public $rtl;
	public $spacingVal;
	public $strike;
	public $shdFill;
	public $shdThemeColor;
	public $shdThemeTint;
	public $smallCaps;
	public $szVal;
	public $szCsVal;
	public $u;
	public $vertAlignVal;
	public $w;
}

class DOCXTabStop {
	public $val;
	public $pos;	
}

class DOCXStyle {
	public $basedOnVal;
	public $default;
	public $linkVal;
	public $nameVal;
	public $semiHidden;
	public $styleId;
	public $type;
	public $uiPriorityVal;
	public $unhideWhenUsed;
	public $qFormat;
	
	public $textProperties;
	public $paragraphProperties;
}

class DOCXFont {
	public $name;
	public $panose1Val;
	public $familyVal;
	public $pitchVal;
}

class DOCXColorScheme {
	public $name;
	public $lt1;
	public $lt2;
	public $dk1;
	public $dk2;
	public $accent1;
	public $accent2;
	public $accent3;
	public $accent4;
	public $accent5;
	public $accent6;
	public $hlink;
	public $folHlink;
}

class DOCXEmbeddedFile {
	public $fileName;
	public $data;
}

?>