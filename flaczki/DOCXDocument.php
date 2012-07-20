<?php

class DOCXDocument {
	public $documentProperties;
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

class DOCXDrawing {
	public $embed;
	
	public $drawingProperties;
}

class DOCXHyperlink {
	public $href;
	public $tgtFrame;
}

class DOCXDocumentProperties {
	public $pgSzW;
	public $pgSzH;
}

class DOCXParagraphProperties {
	public $bidi;
	public $indFirstLine;
	public $indLeft;
	public $indRight;
	public $jcVal;
	public $outlineLvlVal;
	public $pageBreakBefore;
	public $pStyleVal;
	public $spacingAfter;
	public $spacingBefore;
	public $spacingLine;
	public $spacingLineRule;
	public $tabStops;
	public $textAlignmentVal;
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

class DOCXDrawingProperties {
	public $extentCx;
	public $extentCy;
	public $srcRectL;
	public $srcRectT;
	public $srcRectR;
	public $srcRectB;
	public $positionHRelativeFrom;
	public $positionHPosOffset;
	public $positionVRelativeFrom;
	public $positionVPosOffset;
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