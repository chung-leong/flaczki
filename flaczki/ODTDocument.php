<?php

class ODTDocument {
	public $documentProperties;
	public $automaticStyles = array();
	public $commonStyles = array();
	public $defaultStyles = array();
	public $paragraphs = array();
	public $fonts = array();
	public $embeddedFiles = array();
}

class ODTParagraph {
	public $styleName;
	public $classNames;
	
	public $spans = array();
}

class ODTHeading extends ODTParagraph {
	public $outlineLevel;
}

class ODTSpan {
	public $styleName;
	public $classNames;
	
	public $text;
	public $hyperlink;
}

class ODTHyperlink {
	public $href;
	public $targetFrameName;
	public $type;
}

class ODTDrawing {
	public $href;
	public $drawingProperties;
}

class ODTStyle {
	public $name;
	public $displayName;
	public $family;
	public $parentStyleName;
	
	public $textProperties;
	public $paragraphProperties;
}

class ODTDocumentProperties {
	public $pageWidth;
	public $pageHeight;
}

class ODTParagraphProperties {
	public $breakBefore;
	public $breakAfter;
	public $marginBottom;
	public $marginLeft;
	public $marginRight;
	public $marginTop;	
	public $lineHeight;
	public $tabStops;
	public $tabStopDistance;
	public $textIndent;
	public $textAlign;
	public $textAlignLast;
	public $verticalAlign;
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
	public $justifySingleWord;
	public $language;
	public $letterKerning;
	public $letterSpacing;
	public $textLineThroughStyle;
	public $textLineThroughType;
	public $textPosition;
	public $textRotationAngle;
	public $textTransform;
	public $textUnderlineStyle;	
	public $textUnderlineType;
}

class ODTDrawingProperties {
	public $x;
	public $y;
	public $width;
	public $height;
	public $anchorType;
	public $zIndex;
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

class ODTEmbeddedFile {
	public $data;
}

?>