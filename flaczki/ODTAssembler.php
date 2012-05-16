<?php

class ODTAssembler {
	
	protected $output;
	protected $written = 0;

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
			
		// add the mimetype file (not compressed)
		StreamZipArchive::setCompressionLevel($zipPath, 0);
		$this->output = fopen("$zipPath/mimetype", "wb");
		$this->writeBytes("application/vnd.oasis.opendocument.text");
		StreamZipArchive::setCompressionLevel($zipPath, -1);
		
		// add everything else
		$this->output = fopen("$zipPath/content.xml", "wb");
		$this->writeContentXML($document);
		
		$this->output = fopen("$zipPath/styles.xml", "wb");
		$this->writeStylesXML($document);
		
		$this->output = fopen("$zipPath/meta.xml", "wb");
		$this->writeMetaXML($document);
		
		$this->output = fopen("$zipPath/settings.xml", "wb");
		$this->writeSettingsXML($document);
		
		// add the manifest
		$fileList = array('/', 'content.xml', 'styles.xml', 'meta.xml', 'settings.xml');
		$this->output = fopen("$zipPath/META-INF/manifest.xml", "wb");
		$this->writeXMLDeclaration();
		$this->writeStartTag('manifest:manifest', array('xmlns:manifest' => "urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"));
		foreach($fileList as $path) {
			if($path == '/') {
				$mimeType = 'application/vnd.oasis.opendocument.text';
			} else {
				$ext = strtolower(strrchr($path, '.'));
				switch($ext) {
					case '.xml': $mimeType = 'text/xml'; break;
				}
			}
			$this->writeStartTag('manifest:file-entry', array('manifest:media-type' => $mimeType, 'manifest:full-path' => $path), true);
		}
		$this->writeEndTag('manifest:manifest');

		StreamZipArchive::close($zipPath);
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}

	protected static $documentAttributes = array(
		'office:version' => "1.1",
		'xmlns:office' => "urn:oasis:names:tc:opendocument:xmlns:office:1.0",
		'xmlns:xlink' => "http://www.w3.org/1999/xlink",
		'xmlns:ooo' => "http://openoffice.org/2004/office",
		'xmlns:dc' => "http://purl.org/dc/elements/1.1/",
		'xmlns:meta' => "urn:oasis:names:tc:opendocument:xmlns:meta:1.0",
		'xmlns:style' => "urn:oasis:names:tc:opendocument:xmlns:style:1.0",
		'xmlns:text' => "urn:oasis:names:tc:opendocument:xmlns:text:1.0",
		'xmlns:table' => "urn:oasis:names:tc:opendocument:xmlns:table:1.0",
		'xmlns:draw' => "urn:oasis:names:tc:opendocument:xmlns:drawing:1.0",
		'xmlns:fo' => "urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0",
		'xmlns:number' => "urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0", 
		'xmlns:svg' => "urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0",
		'xmlns:chart' => "urn:oasis:names:tc:opendocument:xmlns:chart:1.0",
		'xmlns:dr3d' => "urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0",
		'xmlns:math' => "http://www.w3.org/1998/Math/MathML",
		'xmlns:form' => "urn:oasis:names:tc:opendocument:xmlns:form:1.0",
		'xmlns:script' => "urn:oasis:names:tc:opendocument:xmlns:script:1.0",
		'xmlns:ooow' => "http://openoffice.org/2004/writer",
		'xmlns:oooc' => "http://openoffice.org/2004/calc",
		'xmlns:dom' => "http://www.w3.org/2001/xml-events"
	);
	
	protected static $documentMetaAttributes = array(				
		'office:version' => "1.1",
		'xmlns:office' => "urn:oasis:names:tc:opendocument:xmlns:office:1.0",
		'xmlns:xlink' => "http://www.w3.org/1999/xlink",
		'xmlns:ooo' => "http://openoffice.org/2004/office", 
		'xmlns:dc' => "http://purl.org/dc/elements/1.1/",
		'xmlns:meta' => "urn:oasis:names:tc:opendocument:xmlns:meta:1.0"
	);
	
	protected static $documentSettingsAttributes = array(
		'office:version' => "1.1",
		'xmlns:office' => "urn:oasis:names:tc:opendocument:xmlns:office:1.0",
		'xmlns:xlink' => "http://www.w3.org/1999/xlink",
		'xmlns:ooo' => "http://openoffice.org/2004/office",
		'xmlns:config' => "urn:oasis:names:tc:opendocument:xmlns:config:1.0"
	);
	
	protected function writeContentXML($document) {
		$this->writeXMLDeclaration();
		$this->writeStartTag('office:document-content', self::$documentAttributes);		

		// write the font declarations first
		$this->writeStartTag('office:font-face-decls');
		foreach($document->fonts as $font) {
			$this->writeFontTag($font);
		}
		$this->writeEndTag('office:font-face-decls');
		
		// write the automatic styles
		$this->writeStartTag('office:automatic-styles');
		foreach($document->automaticStyles as $style) {
			$this->writeStyleTag($style);
		}
		$this->writeEndTag('office:automatic-styles');
		
		// write the document body
		$this->writeStartTag('office:body');
		$this->writeStartTag('office:text');
		foreach($document->paragraphs as $paragraph) {
			$this->writeParagraphTag($paragraph);
		}
		$this->writeEndTag('office:text');
		$this->writeEndTag('office:body');
		
		$this->writeEndTag('office:document-content');
	}
	
	protected function writeStylesXML($document) {
		$this->writeXMLDeclaration();
		$this->writeStartTag('office:document-styles', self::$documentAttributes);		

		$this->writeStartTag('office:font-face-decls');
		foreach($document->fonts as $font) {
			$this->writeFontTag($font);
		}
		$this->writeEndTag('office:font-face-decls');
		
		$this->writeStartTag('office:styles');
		foreach($document->defaultStyles as $style) {
			$this->writeStyleTag($style, true);
		}
		foreach($document->commonStyles as $style) {
			$this->writeStyleTag($style);
		}
		$this->writeEndTag('office:styles');
		
		$this->writeEndTag('office:document-styles');
	}
	
	protected function writeMetaXML($document) {
		$this->writeXMLDeclaration();
		$this->writeStartTag('office:document-meta', self::$documentMetaAttributes);		
	
		$this->writeStartTag('office:meta');
		$this->writeTag('meta:generator', null, "Flaczki PHP Framework");
		$this->writeTag('dc:title', null, "");
		$this->writeTag('meta:initial-creator', null, "");
		$this->writeTag('dc:creator', null, "");
		$this->writeTag('meta:editing-cycles', null, "1");
		$attributes = array( 
			'meta:page-count' => "1",
			'meta:paragraph-count' => "1", 
			'meta:row-count' => "1",
			'meta:word-count' => "0",
			'meta:character-count' => "0",
			'meta:non-whitespace-character-count' => "0"
		);
		$this->writeTag('meta:document-statistic', $attributes);
		$this->writeEndTag('office:meta');
		
		$this->writeEndTag('office:document-meta');
	}
	
	protected function writeSettingsXML($document) {
		$this->writeXMLDeclaration();
		$this->writeStartTag('office:document-settings', self::$documentSettingsAttributes);		
		
		$this->writeStartTag('office:settings');
		
		$this->writeStartTag('config:config-item-set', array('config:name' => "ooo:view-settings"));		
		$this->writeTag('config:config-item', array(' config:name' => "ShowRedlineChanges", 'config:type' => "boolean"), "true");
		$this->writeStartTag('config:config-item-map-indexed', array('config:name' => "Views"));
		$this->writeStartTag('config:config-item-map-entry');
		$this->writeTag('config:config-item', array('config:name' => "VisibleLeft", 'config:type' => "int"), "0");
		$this->writeTag('config:config-item', array('config:name' => "VisibleRight", 'config:type' => "int"), "0");
		$this->writeTag('config:config-item', array('config:name' => "VisibleTop", 'config:type' => "int"), "0");
		$this->writeTag('config:config-item', array('config:name' => "VisibleBottom", 'config:type' => "int"), "0");
		$this->writeTag('config:config-item', array('config:name' => "ZoomType", 'config:type' => "short"), "0");
		$this->writeTag('config:config-item', array('config:name' => "ZoomFactor", 'config:type' => "short"), "100");
		$this->writeEndTag('config:config-item-map-entry');
		$this->writeEndTag('config:config-item-map-indexed');
		$this->writeEndTag('config:config-item-set');

		$this->writeStartTag('config:config-item-set', array('config:name' => "ooo:configuration-settings"));
		$this->writeTag('config:config-item', array('config:name' => "UseFormerObjectPositioning", 'config:type' => "boolean"), "false");
		$this->writeTag('config:config-item', array('config:name' => "UseFormerTextWrapping", 'config:type' => "boolean"), "false");
		$this->writeTag('config:config-item', array('config:name' => "PrinterIndependentLayout", 'config:type' => "string"), "high-resolution");
		$this->writeTag('config:config-item', array('config:name' => "DoNotJustifyLinesWithManualBreak", 'config:type' => "boolean"), "false");
		$this->writeTag('config:config-item', array('config:name' => "IgnoreTabsAndBlanksForLineCalculation", 'config:type' => "boolean"), "true");
		$this->writeTag('config:config-item', array('config:name' => "AddExternalLeading", 'config:type' => "boolean"), "true");
		$this->writeTag('config:config-item', array('config:name' => "PrinterIndependentLayout", 'config:type' => "string"), "high-resolution");
		$this->writeTag('config:config-item', array('config:name' => "IgnoreFirstLineIndentInNumbering", 'config:type' => "boolean"), "false");
		$this->writeTag('config:config-item', array('config:name' => "UseFormerLineSpacing", 'config:type' => "boolean"), "false");
		$this->writeTag('config:config-item', array('config:name' => "AddParaTableSpacing", 'config:type' => "boolean"), "false");		
		$this->writeEndTag('config:config-item-set');
		
		$this->writeEndTag('office:settings');
		
		$this->writeEndTag('office:document-settings');
	}
	
	protected function writeFontTag($font) {
		$attributes = array();
		$this->addAttribute($attributes, 'style:name', $font->name);
		$this->addAttribute($attributes, 'svg:font-family', $font->fontFamily);
		$this->addAttribute($attributes, 'style:font-family-generic', $font->fontFamilyGeneric);
		$this->addAttribute($attributes, 'svg:font-style', $font->fontStyle);
		$this->addAttribute($attributes, 'style:font-pitch', $font->fontPitch);
		$this->addAttribute($attributes, 'svg:font-variant', $font->fontVariant);
		$this->addAttribute($attributes, 'svg:font-weight', $font->fontWeight);
		$this->addAttribute($attributes, 'svg:panose-1', $font->panose1);
		$this->writeTag('style:font-face', $attributes);
	}
	
	protected function writeStyleTag($style, $default = false) {
		$attributes = array();
		$tagName = 'style:' . ($default ? 'default-style' : 'style');
		$this->addAttribute($attributes, 'style:name', $style->name);
		$this->addAttribute($attributes, 'style:display-name', $style->displayName);
		$this->addAttribute($attributes, 'style:family', $style->family);
		$this->addAttribute($attributes, 'style:parent-style-name', $style->parentStyleName);		
		if($style->textProperties || $style->paragraphProperties) {
			$this->writeStartTag($tagName, $attributes);
			if($style->paragraphProperties) {
				$attributes = array();
				$paragraphProperties = $style->paragraphProperties;
				$this->addAttribute($attributes, 'fo:break-before', $paragraphProperties->breakBefore);
				$this->addAttribute($attributes, 'fo:break-after', $paragraphProperties->breakAfter);
				$this->addAttribute($attributes, 'fo:margin-bottom', $paragraphProperties->marginBottom);
				$this->addAttribute($attributes, 'fo:margin-left', $paragraphProperties->marginLeft);
				$this->addAttribute($attributes, 'fo:margin-right', $paragraphProperties->marginRight);
				$this->addAttribute($attributes, 'fo:margin-top', $paragraphProperties->marginTop);
				$this->addAttribute($attributes, 'fo:line-height', $paragraphProperties->lineHeight);
				$this->addAttribute($attributes, 'style:tab-stop-distance', $paragraphProperties->tabStopDistance);
				$this->addAttribute($attributes, 'fo:text-indent', $paragraphProperties->textIndent);
				$this->addAttribute($attributes, 'fo:text-align', $paragraphProperties->textAlign);
				$this->addAttribute($attributes, 'fo:text-align-last', $paragraphProperties->textAlignLast);
				$this->addAttribute($attributes, 'style:writing-mode', $paragraphProperties->writingMode);
				if($paragraphProperties->tabStops) {
					$this->writeStartTag('style:paragraph-properties', $attributes);
					$this->writeStartTag('style:tab-stops');
					foreach($paragraphProperties->tabStops as $tabStop) {
						$attributes = array();
						$this->addAttribute($attributes, 'style:char', $tabStop->char);
						$this->addAttribute($attributes, 'style:position', $tabStop->position);
						$this->addAttribute($attributes, 'style:type', $tabStop->type);
						$this->writeTag('style:tab-stops', $attributes);
					}
					$this->writeEndTag('style:tab-stops');
					$this->writeEndTag('style:paragraph-properties');
				} else {
					$this->writeTag('style:paragraph-properties', $attributes);
				}
			}
			if($style->textProperties) {
				$attributes = array();
				$textProperties = $style->textProperties;
				$this->addAttribute($attributes, 'fo:background-color', $textProperties->backgroundColor);
				$this->addAttribute($attributes, 'fo:color', $textProperties->color);
				$this->addAttribute($attributes, 'fo:country', $textProperties->country);
				$this->addAttribute($attributes, 'style:font-name', $textProperties->fontName);
				$this->addAttribute($attributes, 'fo:font-family', $textProperties->fontFamily);
				$this->addAttribute($attributes, 'fo:font-size', $textProperties->fontSize);
				$this->addAttribute($attributes, 'style:font-style', $textProperties->fontStyle);
				$this->addAttribute($attributes, 'fo:font-variant', $textProperties->fontVariant);
				$this->addAttribute($attributes, 'fo:font-weight', $textProperties->fontWeight);
				$this->addAttribute($attributes, 'style:justify-single-word', $textProperties->justifySingleWord);
				$this->addAttribute($attributes, 'fo:language', $textProperties->language);
				$this->addAttribute($attributes, 'style:letter-kerning', $textProperties->letterKerning);
				$this->addAttribute($attributes, 'fo:letter-spacing', $textProperties->letterSpacing);
				$this->addAttribute($attributes, 'style:text-line-through-style', $textProperties->textLineThroughStyle);
				$this->addAttribute($attributes, 'style:text-line-through-type', $textProperties->textLineThroughType);
				$this->addAttribute($attributes, 'style:text-position', $textProperties->textPosition);
				$this->addAttribute($attributes, 'style:text-rotation-angle', $textProperties->textRotationAngle);
				$this->addAttribute($attributes, 'fo:text-transformation', $textProperties->textTransformations);
				$this->addAttribute($attributes, 'style:text-underline-style', $textProperties->textUnderlineStyle);
				$this->addAttribute($attributes, 'style:text-underline-type', $textProperties->textUnderlineType);
				$this->writeTag('style:text-properties', $attributes);	
			}
			$this->writeEndTag($tagName);
		} else {
			$this->writeStartTag($tagName, $attributes, true);
		}
	}
	
	protected function writeParagraphTag($paragraph) {
		$attributes = array();
		$this->addAttribute($attributes, 'text:style-name', $paragraph->styleName);
		$this->addAttribute($attributes, 'text:class-names', $paragraph->classNames);
		if($paragraph instanceof ODTHeading) {
			$this->addAttribute($attributes, 'text:outline-level', $paragraph->outlineLevel);
			$tagName = 'text:h';
		} else {
			$tagName = 'text:p';
		}
		if($paragraph->spans) {
			$hyperlink = null;
			$this->writeStartTag($tagName, $attributes);
			foreach($paragraph->spans as $span) {
				if($span->hyperlink !== $hyperlink) {
					if($hyperlink) {
						$this->writeEndTag('text:a');
					}
					$hyperlink = $span->hyperlink;
					if($hyperlink) {
						$attributes = array();
						$this->addAttribute($attributes, 'xlink:href', $hyperlink->href);
						$this->addAttribute($attributes, 'xlink:type', $hyperlink->type);
						$this->addAttribute($attributes, 'xlink:target-frame-name', $hyperlink->targetFrameName);
						$this->writeStartTag('text:a', $attributes);
					}
				}
				$this->writeSpanTag($span);
			}
			if($hyperlink) {
				$this->writeEndTag('text:a');
			}
			$this->writeEndTag($tagName);
		} else {
			$this->writeStartTag($tagName, $attributes, true);
		}
	}
	
	protected function writeSpanTag($span) {
		if($span->text) {
			$attributes = array();
			$this->addAttribute($attributes, 'text:style-name', $span->styleName);
			$this->addAttribute($attributes, 'text:class-names', $span->classNames);
			
			$this->writeStartTag('text:span', $attributes);
			$chunks = preg_split('/(\t|\n| {2,})/', $span->text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
			foreach($chunks as $chunk) {
				if(rtrim($chunk)) {
					// normal text string
					$this->writeText($chunk);
				} else {
					// special character
					switch($chunk) {
						case "\n":
							$this->writeTag('text:line-break');
							break;
						case "\t":
							$this->writeTag('text:tab');
							break;
						default:
							$this->writeTag('text:s', array( 'text:c' => strlen($chunk)));
							break;
						
					}
				}
			}			
			$this->writeEndTag('text:span');
		}
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
		$this->writeBytes('<?xml version="1.0" encoding="utf-8" standalone="yes"?>');
	}
	
	protected function writeText($text) {
		static $entities = array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		if($text != '') {
			$s = strtr($text, $entities);
			$this->writeBytes($s);
		}
	}
	
	public function writeBytes($bytes) {
		$this->written += fwrite($this->output, $bytes);
	}
	
	public function addAttribute(&$attributes, $name, $value) {
		if($value !== null) {
			$attributes[$name] = $value;
		}
	}
}

?>
