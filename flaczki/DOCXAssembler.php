<?php

class DOCXAssembler {
	
	protected $output;
	protected $written = 0;
	protected $defaultContentTypes;
	protected $contentTypeOverrides;

	public function assemble(&$output, $document) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
		} else {
			throw new Exception("Invalid output");
		}
		
		// create ZIP archive
		$zipPath = StreamZipArchive::create($output);
		
		$this->addContentType('rels', "application/vnd.openxmlformats-package.relationships+xml");
		$this->addContentType('xml', "application/xml");
				
		$this->output = fopen("$zipPath/word/document.xml", "wb");
		$this->writeDocumentXML($document);
		$this->addRelationship("word/document.xml", "", "officeDocument");
		$this->addContentTypeOverride("word/document.xml", "application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml");
		
		$this->output = fopen("$zipPath/word/styles.xml", "wb");
		$this->writeStylesXML($document);
		$this->addRelationship("word/styles.xml", "word/document.xml", "styles");
		$this->addContentTypeOverride("word/styles.xml", "application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml");
		
		$this->output = fopen("$zipPath/word/fontTable.xml", "wb");
		$this->writeFontTableXML($document);
		$this->addRelationship("word/fontTable.xml", "word/document.xml", "fontTable" );
		$this->addContentTypeOverride("word/fontTable.xml", "application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml");
		
		foreach($this->relationships as $referrerPath => $fileRelationships) {
			$relsPath = ($referrerPath) ? preg_replace('/(.*)\/(.*)/', '$1/_rels/$2.rels', $referrerPath) : '_rels/.rels';
			$this->output = fopen("$zipPath/$relsPath", "wb");
			$this->writeRels($referrerPath, $fileRelationships);
		}

		$this->output = fopen("$zipPath/[Content_Types].xml", "wb");
		$this->writeContentTypesXML();		
		
		StreamZipArchive::close($zipPath);
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}
	
	protected function writeContentTypesXML() {
		$typesAttributes = array(
			'xmlns' => "http://schemas.openxmlformats.org/package/2006/content-types"
		);
		
		$this->writeXMLDeclaration();
		$this->writeStartTag('Types', $typesAttributes);
		foreach($this->defaultContentTypes as $extension => $contentType) {
			$attributes = array('Extension' => $extension, 'ContentType' => $contentType);
			$this->writeTag('Default', $attributes);
		}
		foreach($this->contentTypeOverrides as $partName => $contentType) {
			$attributes = array('PartName' => "/$partName", 'ContentType' => $contentType);
			$this->writeTag('Override', $attributes);
		}
		$this->writeEndTag('Types');
	}

	protected function writeDocumentXML($document) {
		$documentAttributes = array(
			'xmlns:ve' => "http://schemas.openxmlformats.org/markup-compatibility/2006",
			'xmlns:o' => "urn:schemas-microsoft-com:office:office",
			'xmlns:r' => "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
			'xmlns:m' => "http://schemas.openxmlformats.org/officeDocument/2006/math",
			'xmlns:v' => "urn:schemas-microsoft-com:vml",
			'xmlns:wp' => "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing",
			'xmlns:w10' => "urn:schemas-microsoft-com:office:word",
			'xmlns:w' => "http://schemas.openxmlformats.org/wordprocessingml/2006/main" ,
			'xmlns:wne' => "http://schemas.microsoft.com/office/word/2006/wordml"
		);
	
		$this->writeXMLDeclaration();
		$this->writeStartTag('w:document', $documentAttributes);		

		// write the document body
		$this->writeStartTag('w:body');
		foreach($document->paragraphs as $paragraph) {
			$this->writeParagraphTag($paragraph);
		}
		$this->writeEndTag('w:body');
		
		$this->writeEndTag('w:document');
	}
	
	protected function writeStylesXML($document) {
		$stylesAttributes = array(
			'xmlns:r' => "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
			'xmlns:w' => "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
		);
		
		$this->writeXMLDeclaration();
		$this->writeStartTag('w:styles', $stylesAttributes);
		if($document->defaultParagraphProperties || $document->defaultTextProperties) {
			$this->writeStartTag('w:docDefaults');
			$this->writeStartTag('w:rPrDefault');
			$this->writeTextPropertiesTag($document->defaultTextProperties);
			$this->writeEndTag('w:rPrDefault');			
			$this->writeEndTag('w:docDefaults');
			$this->writeStartTag('w:pPrDefault');
			$this->writeParagraphPropertiesTag($document->defaultParagraphProperties);
			$this->writeEndTag('w:pPrDefault');
		}
		foreach($document->styles as $style) {
			$this->writeStyleTag($style);
		}
		$this->writeEndTag('w:styles');
	}
	
	protected function writeFontTableXML($document) {
		$fontsAttributes = array(
			'xmlns:r' => "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
			'xmlns:w' => "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
		);
		
		$this->writeXMLDeclaration();
		$this->writeStartTag('w:fonts', $fontsAttributes);
		foreach($document->fonts as $font) {
			$this->writeFontTag($font);
		}
		$this->writeEndTag('w:fonts');
	}
	
	protected function writeRels($referrerPath, $relationships) {
		$relationshipsAttributes = array(
			'xmlns' => "http://schemas.openxmlformats.org/package/2006/relationships"
		);
		
		$this->writeXMLDeclaration();
		$this->writeStartTag('Relationships', $relationshipsAttributes);
		foreach($relationships as $relationship) {
			$attributes = array();
			$this->addAttribute($attributes, 'Id', $relationship->id);
			$this->addAttribute($attributes, 'Type', "http://schemas.openxmlformats.org/officeDocument/2006/relationships/{$relationship->type}");
			$targetPath = ($relationship->targetMode == 'External') ? $relationship->target : $this->getRelativePath($referrerPath, $relationship->target);
			$this->addAttribute($attributes, 'Target', $targetPath);
			$this->addAttribute($attributes, 'TargetMode', $relationship->targetMode);
			$this->writeTag('Relationship', $attributes);
		}
		$this->writeEndTag('Relationships', $relationshipsAttributes);
	}
	
	protected function writeFontTag($font) {
		$attributes = array();
		$this->addAttribute($attributes, 'w:name', $font->name);		
		$this->writeStartTag('w:font', $attributes);
		$this->writeValueTag('w:panose1', $font->panose1Val);
		$this->writeValueTag('w:family', $font->familyVal);
		$this->writeValueTag('w:pitch', $font->pitchVal);
		$attributes = array();
		$this->writeEndTag('w:font');
	}
		
	protected function writeStyleTag($style) {
		$attributes = array();
		$this->addAttribute($attributes, 'w:type', $style->type);
		$this->addAttribute($attributes, 'w:default', $style->default);
		$this->addAttribute($attributes, 'w:styleId', $style->styleId);
		$this->writeStartTag('w:style', $attributes);
		$this->writeValueTag('w:name', $style->nameVal);
		$this->writeValueTag('w:link', $style->linkVal);
		$this->writeValueTag('w:uiPriorityVal', $style->uiPriorityVal);		
		$this->writeValueTag('w:qFormat', $style->qFormat);
		$this->writeValueTag('w:semiHidden', $style->semiHidden);
		$this->writeValueTag('w:unhideWhenUsed', $style->unhideWhenUsed);
		if($style->textProperties) {
			$this->writeTextPropertiesTag($style->textProperties);
		}
		if($style->paragraphProperties) {
			$this->writeParagraphPropertiesTag($style->paragraphProperties);
		}
		$this->writeEndTag('w:style');
	}
	
	protected function writeParagraphTag($paragraph) {
		$attributes = array();
		$this->writeStartTag('w:p', $attributes);
		if($paragraph->paragraphProperties) {
			$this->writeParagraphPropertiesTag($paragraph->paragraphProperties);
		}
		$hyperlink = null;
		foreach($paragraph->spans as $span) {
			if($span->hyperlink !== $hyperlink) {
				if($hyperlink) {
					$this->writeEndTag('w:hyperlink');
				}
				$hyperlink = $span->hyperlink;
				if($hyperlink) {
					$attributes = array();
					$id = $this->addRelationship($hyperlink->href, 'word/document.xml', 'hyperlink', 'External');
					$this->addAttribute($attributes, 'r:id', $id);
					$this->writeStartTag('w:hyperlink', $attributes);
				}
			}
			$this->writeSpanTag($span);
		}
		if($hyperlink) {
			$this->writeEndTag('w:hyperlink');
		}
		$this->writeEndTag('w:p');
	}
	
	protected function writeParagraphPropertiesTag($properties) {
		$this->writeStartTag('w:pPr');
		$this->writeValueTag('w:pStyle', $properties->pStyleVal);
		$this->writeValueTag('w:bidi', $properties->bidi);
		$attributes = array();
		$this->addAttribute($attributes, 'w:firstLine', $properties->indFirstLine);
		$this->addAttribute($attributes, 'w:left', $properties->indLeft);
		$this->addAttribute($attributes, 'w:right', $properties->indRight);		
		if($attributes) {
			$this->writeTag('w:ind', $attributes);
		}
		$this->writeValueTag('w:jc', $properties->jcVal);
		$this->writeValueTag('w:pageBreakBefore', $properties->pageBreakBefore);
		if($properties->textProperties) {
			$this->writeTextPropertiesTag($properties->textProperties);
		}
		$attributes = array();
		$this->addAttribute($attributes, 'w:after', $properties->spacingAfter);
		$this->addAttribute($attributes, 'w:before', $properties->spacingBefore);
		$this->addAttribute($attributes, 'w:line', $properties->spacingLine);
		$this->addAttribute($attributes, 'w:lineRule', $properties->spacingLineRule);
		if($attributes) {
			$this->writeTag('w:spacing', $attributes);
		}
		if($properties->tabStops) {
			$this->writeStartTag('w:tabs');
			foreach($properties->tabStops as $tabStop) {
				$attributes = array();
				$this->addAttribute($attributes, 'w:val', $tabStop->val);
				$this->addAttribute($attributes, 'w:pos', $tabStop->pos);
				$this->writeTag('w:tab', $attributes);
			}
			$this->writeEndTag('w:tabs');
		}
		$this->writeValueTag('w:textAlignment', $properties->textAlignmentVal);
		$this->writeEndTag('w:pPr');
	}
	
	protected function writeSpanTag($span) {
		if($span->text) {
			$attributes = array();
			$this->writeStartTag('w:r', $attributes);
			if($span->textProperties) {
				$this->writeTextPropertiesTag($span->textProperties);
			}
			$chunks = preg_split('/(\t|\n| {2,})/', $span->text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
			foreach($chunks as $chunk) {
				switch($chunk) {
					case "\n":
						$this->writeTag('w:br');
						break;
					case "\t":
						$this->writeTag('w:tab');
						break;
					default:
						$this->writeTag('w:t', null, $chunk);
				}
			}			
			$this->writeEndTag('w:r');
		}
	}
	
	protected function writeTextPropertiesTag($properties) {
		$this->writeStartTag('w:rPr');
		$this->writeValueTag('w:rStyle', $properties->rStyleVal);
		$this->writeValueTag('w:b', $properties->b);
		$this->writeValueTag('w:bCs', $properties->bCs);
		$this->writeValueTag('w:caps', $properties->caps);
		$attributes = array();
		$this->addAttribute($attributes, 'w:themeColor', $properties->colorThemeColor);
		$this->addAttribute($attributes, 'w:themeTint', $properties->colorThemeTint);
		$this->addAttribute($attributes, 'w:val', $properties->colorVal);
		if($attributes) {
			$this->writeTag('w:color', $attributes);
		}
		$this->writeValueTag('w:dstrike', $properties->dstrike);
		$this->writeValueTag('w:highlight', $properties->highlightVal);
		$this->writeValueTag('w:i', $properties->i);
		$this->writeValueTag('w:iCs', $properties->iCs);
		$this->writeValueTag('w:kern', $properties->kernVal);
		$attributes = array();
		$this->addAttribute($attributes, 'w:bidi', $properties->langBidi);
		$this->addAttribute($attributes, 'w:eastAsia', $properties->langEastAsia);
		$this->addAttribute($attributes, 'w:val', $properties->langVal);
		if($attributes) {
			$this->writeTag('w:lang', $attributes);
		}
		$this->writeValueTag('w:position', $properties->positionVal);
		$attributes = array();
		$this->addAttribute($attributes, 'w:ascii', $properties->rFontsAscii);
		$this->addAttribute($attributes, 'w:asciiTheme', $properties->rFontsAsciiTheme);
		$this->addAttribute($attributes, 'w:hAnsi', $properties->rFontsHAnsi);
		$this->addAttribute($attributes, 'w:hAnsiTheme', $properties->rFontsHAnsiTheme);
		$this->addAttribute($attributes, 'w:eastAsia', $properties->rFontsEastAsia);
		$this->addAttribute($attributes, 'w:eastAsiaTheme', $properties->rFontsEastAsiaTheme);
		$this->addAttribute($attributes, 'w:cs', $properties->rFontsCs);
		$this->addAttribute($attributes, 'w:csTheme', $properties->rFontsCs);
		if($attributes) {
			$this->writeTag('w:rFonts', $attributes);
		}
		$this->writeValueTag('w:rtl', $properties->rtl);
		$this->writeValueTag('w:strike', $properties->strike);
		$this->writeValueTag('w:smallCaps', $properties->smallCaps);
		$this->writeValueTag('w:spacing', $properties->spacingVal);
		$this->writeValueTag('w:sz', $properties->szVal);
		$this->writeValueTag('w:szCs', $properties->szCsVal);
		$this->writeValueTag('w:u', $properties->u);
		$this->writeValueTag('w:vertAlign', $properties->vertAlignVal);
		$this->writeValueTag('w:w', $properties->w);
		$this->writeEndTag('w:rPr');
	}
			
	protected function writeTag($name, $attributes = null, $text = '') {
		if($text != '') {
			$this->writeStartTag($name, $attributes);
			$this->writeText($text);
			$this->writeEndTag($name);
		} else {
			$this->writeStartTag($name, $attributes, true);
		}
	}
	
	protected function writeValueTag($name, $value) {
		if($value !== null) {
			if($value === true) {
				$this->writeStartTag($name, null, true);
			} else {
				$this->writeStartTag($name, array('w:val' => $value), true);
			}
		}
	}
	
	protected function writeStartTag($name, $attributes = null, $end = false) {
		static $entities = array('"' => '&quot;', "'" => '&apos;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		$s = "<$name";
		if($attributes) {
			foreach($attributes as $name => $value) {
				$s .= ' ' . $name . '="' . strtr($value, $entities) . '"';
			}
		}
		$s .= ($end) ? "/>" : ">";
		$this->writeBytes($s);
	}
	
	protected function writeEndTag($name) {
		$s = "</$name>";
		$this->writeBytes($s);
	}
	
	protected function writeXMLDeclaration() {
		$this->writeBytes('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
	}
	
	protected function writeText($text) {
		static $entities = array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		if($text != '') {
			$s = strtr($text, $entities);
			$this->writeBytes($s);
		}
	}
	
	protected function writeBytes($bytes) {
		$this->written += fwrite($this->output, $bytes);
	}
	
	protected function addAttribute(&$attributes, $name, $value) {
		if($value !== null) {
			$attributes[$name] = $value;
		}
	}
	
	protected function addContentType($extension, $type) {
		$this->defaultContentTypes[$extension] = $type;
	}
	
	protected function addContentTypeOverride($path, $type) {
		$this->contentTypeOverrides[$path] = $type;
	}
	
	protected function addRelationship($targetPath, $referrerPath, $relationshipType, $targetMode = null) {
		if(!isset($this->relationships[$referrerPath])) {
			$this->relationships[$referrerPath] = array();
		}
		$fileRelationships =& $this->relationships[$referrerPath];
		// see if there's one already
		foreach($fileRelationships as $relationship) {
			if($relationship->type == $relationshipType && $relationship->target == $targetPath && $relationship->targetMode == $targetMode) {
				return $relationship->id;
			}
		}
		$relationship = new DOCXRelationship;
		$relationship->id = "rId" . (count($fileRelationships) + 1);
		$relationship->type = $relationshipType;
		$relationship->target = $targetPath;
		$relationship->targetMode = $targetMode;
		$fileRelationships[] = $relationship;
		return $relationship->id;
	}
	
	protected function getRelativePath($from, $to) {
    		$from = explode('/', $from);
		$to = explode('/', $to);
		$relPath = $to;

		foreach($from as $depth => $dir) {
        		// find first non-matching dir
			if($dir === $to[$depth]) {
				// ignore this directory
				array_shift($relPath);
			} else {
				// get number of remaining dirs to $from
				$remaining = count($from) - $depth;
				if($remaining > 1) {
					// add traversals up to first matching dir
					$padLength = (count($relPath) + $remaining - 1) * -1;
					$relPath = array_pad($relPath, $padLength, '..');
					break;
				}
        		}
		}
		return implode('/', $relPath);
	}
}

class DOCXRelationship {
	public $id;
	public $type;
	public $target;
	public $targetMode;
}

?>
