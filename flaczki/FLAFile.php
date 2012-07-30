<?php

class FLAFile {
	public $document;
	public $library;
}

class FLADOMDocument {
	public $width;
	public $height;
	public $currentTimeline;
	public $xflVersion; 
	public $creatorInfo;
	public $platform;
	public $versionInfo;
	public $majorVersion; 
	public $minorVersion;
	public $buildNumber;
	public $nextSceneIdentifier;
	public $playOptionsPlayLoop;
	public $playOptionsPlayPages; 
	public $playOptionsPlayFrameActions;
	
	public $media;
	public $symbols;
	public $timelines;
}

class FLADOMSymbolItem { 
	public $name;
	public $itemID;
	public $symbolType;
	public $lastModified;
	
	public $timeline;
}

class FLADOMSymbolInstance {
	public $libraryItemName;
	public $selected;
	public $blendMode;
	public $cacheAsBitmap;
	public $bits32;
	public $centerPoint3DX;
	public $centerPoint3DY;
	
	public $matrix;
	public $transformationPoint;
	public $filters;
	public $matteColor;
}

class FLADOMBitmapItem {
	public $name;
	public $itemID;
	public $sourceExternalFilepath;
	public $sourceLastImported;
	public $externalFileSize;
	public $allowSmoothing;
	public $originalCompressionType;
	public $quality;
	public $href;
	public $bitmapDataHRef;
	public $frameRight;
	public $frameBottom;
}

class FLABitmap {
	public $width;
	public $height;
	public $mimeType;
	public $data;
	public $name;
	public $filename;
	public $allowSmoothing;
}

class FLAInclude {
	public $href;
	public $itemIcon;
	public $loadImmediate;
	public $itemID;
	public $lastModified;
}

class FLADOMTimeline {
	public $name;
	public $currentFrame;
}

class FLADOMLayer {
	public $name;
	public $color;
	public $current;
	public $isSelected;

	public $frames;	
}

class FLADOMFrame {
	public $label;
	public $index;
	public $duration;
	public $keyMode;
	
	public $elements;
}

class FLADOMShape {
	public $fills;
	public $strokes;
	public $edges;
}

class FLALineStyle {
	public $index;
	public $width;
	public $color;
	public $alpha;
}

class FLAFillStyle {
	public $index;
	public $solidColor;
	public $linearGradient;
	public $radialGradient;
	public $bitmapFill;
}

class FLASolidColor {
	public $color;
	public $alpha;
}

class FLAColor {
	public $brightness;
	public $tintMultiplier;
	public $tintColor;
	public $alphaMultiplier;
	public $redMultiplier;
	public $greenMultiplier;
	public $blueMultiplier;
	public $alphaOffset;
	public $redOffset;
	public $greenOffset;
	public $blueOffset;
}

class FLALinearGradient {
	public $interpolationMethod;
	public $matrix;
	public $entry0;
}

class FLARadialGradient {
	public $interpolationMethod;
	public $focalPointRatio;
	public $matrix;
	public $entry0;
}

class FLAGradientEntry {
	public $color;
	public $alpha;
	public $ratio;
}

class FLABitmapFill {
	public $bitmapPath;
	public $bitmapIsClipped;
}

class FLAMatteColor {
	public $color;
	public $alpha;
}

class FLAEdge {
	public $fillStyle0;
	public $fillStyle1;
	public $strokeStyle;
	public $edges;
}

class FLAMatrix {
	public $a;
	public $b;
	public $c;
	public $d;
	public $tx;
	public $ty;
}

class FLAPoint {
	public $x;
	public $y;
}

class FLADropShadowFilter {
	public $alpha;
	public $angle;
	public $blurX;
	public $blurY;
	public $color;
	public $distance;
	public $hideObject;
	public $inner;
	public $knockout;
	public $quality;
	public $strength;
}

class FLABlurFilter {
	public $blurX;
	public $blurY;
	public $quality;
}

class FLAGlowFilter {
	public $alpha;
	public $blurX;
	public $blurY;
	public $color;
	public $inner;
	public $knockout;
	public $quality;
	public $strength;
}

class FLABevelFilter {
	public $blurX;
	public $blurY;
	public $quality;
	public $angle;
	public $distance;
	public $highlightAlpha;
	public $highlightColor;
	public $knockout;
	public $shadowAlpha;
	public $shadowColor;
	public $strength;
	public $type;
}

class FLAGradientGlowFilter {
	public $angle;
	public $blurX;
	public $blurY;
	public $quality;
	public $distance;
	public $strength;
	public $type;
	public $entry0;
}

class FLAGradientBevelFilter {
	public $angle;
	public $blurX;
	public $blurY;
	public $quality;
	public $distance;
	public $strength;
	public $type;
	public $entry0;
}

class FLAAdjustColorFilter {
	public $brightness;
	public $contrast;
	public $saturation;
	public $hue;
}

class FLADOMDynamicText {
	public $fontRenderingMode;
	public $width;
	public $height;
	public $antiAliasSharpness;
	public $antiAliasThickness;
	public $autoExpand;
	public $maxCharacters;
	public $isSelectable;
	public $renderAsHTML;
	public $border;
	public $lineType;
	public $matrix;
	public $textRuns;
	public $filters;
}

class FLADOMInputText extends FLADOMDynamicText {
}

class FLADOMStaticText extends FLADOMDynamicText {
}

class FLADOMTextRun {
	public $characters;
	public $textAttrs;
}

class FLACharacters {
	public $data;
	
	public function write($stream, $name) {
		static $entities = array('"' => '&quot;', "'" => '&apos;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\x0d" => '&#xD;');
		fwrite($stream, "<$name>");
		fwrite($stream, strtr($this->data, $entities));
		fwrite($stream, "</$name>");
	}
}

class FLADOMTextAttrs {
	public $alignment;
	public $aliasText;
	public $alpha;
	public $indent;
	public $leftMargin;
	public $letterSpacing;
	public $lineSpacing;
	public $rightMargin;
	public $size;
	public $bitmapSize;
	public $face;
	public $fillColor;
	public $target;
	public $url;
}

class FLAFont {
	public $name;
	public $codeTable;
}

?>