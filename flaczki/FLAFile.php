<?php

class FLAFile {
	public $document;
	public $library;
	public $metadata;
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

class FLACharacter {
	public $referenced = array();
}

class FLADOMSymbolItem extends FLACharacter { 
	public $name;
	public $itemID;
	public $symbolType;
	public $lastModified;
	
	public $timeline;
}

class FLADOMSymbolInstance {
	public $libraryItemName;
	public $name;
	public $blendMode;
	public $cacheAsBitmap;
	public $bits32;
	public $centerPoint3DX;
	public $centerPoint3DY;
	
	public $matrix;
	public $transformationPoint;
	public $filters;
	public $matteColor;
	public $trackAsMenu;
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

class FLABitmap extends FLACharacter {
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
	public $layers;
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
	public $morphShape;
	
	public $elements;
}

class FLADOMShape {
	public $fills;
	public $strokes;
	public $edges;
}

class FLAMorphShape extends FLACharacter {
	public $morphSegments;
	
	public $startShape;
	public $endShape;
}

class FLAMorphSegment {
	public $startPointA;
	public $startPointB;
	public $strokeIndex1;
	public $strokeIndex2;
	public $fillIndex1;
	public $fillIndex2;
	
	public $morphCurves0;
}

class FLAMorphCurves {
	public $controlPointA;
	public $anchorPointA;
	public $controlPointB;
	public $anchorPointB;
	public $isLine;
}

class FLAStrokeStyle {
	public $index;
	public $solidStroke;
}

class FLASolidStroke {
	public $scaleMode;
	public $caps;
	public $weight;
	public $joints;
	public $miterLimit;
	public $pixelHinting;
	public $solidStyle;
	
	public $fill;
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

class FLADOMDynamicText extends FLACharacter {
	public $name;
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

class FLADOMVideoInstance {
	public $libraryItemName;
	public $frameRight;
	public $frameBottom;
}

class FLADOMVideoItem {
	public $name;
	public $itemID;
	public $sourceExternalFilepath;
	public $sourceLastImported;
	public $videoDataHRef;
	public $videoType;
	public $fps;
	public $rate;
	public $bits; 
	public $channels;
	public $width;
	public $height;
	public $length;
}

class FLAVideo extends FLACharacter {
	public $data;
	public $filename;
	public $frameCount;	
	public $width;
	public $height;
	public $deblockingLevel;
	public $smoothing;
	public $codecId;
	public $codec;
	public $path;
	public $referenceCount;
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

class FLADOMTLFText extends FLACharacter {
	public $name;
	public $left;
	public $top;
	public $right;
	public $bottom;
	public $blendMode;
	public $cacheAsBitmap;
	public $matrix;
	public $filters;
	public $tlfFonts;
	public $markup;
}

class FLAMarkup {
	public $data;
	
	public function write($stream, $name) {
		fwrite($stream, "<$name>");
		fwrite($stream, $this->data);
		fwrite($stream, "</$name>");
	}
}

class FLAFont {
	public $name;
	public $fullName;
	public $codeTable;
}

class FLATLFFont  {
	public $platformName;
	public $psName;
}

?>