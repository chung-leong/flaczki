<?php

class SWFFile {
	public $version;
	public $compressed;
	public $frameSize;
	public $tags = array();
	public $highestCharacterId;
}

class SWFGenericTag {
	public $code;
	public $name;
	public $headerLength;
	public $length;
	public $data;
}

class SWFCharacterTag {
	public $characterId;
}

class SWFCSMTextSettingsTag {
	const RendererNormal		= 0;
	const RendererAdvanced		= 1;
	
	const GridFitNone		= 0;
	const GridFitPixel		= 1;
	const GridFitSubpixel		= 2;

	public $characterId;
	public $renderer;
	public $gridFit;
	public $thickness;
	public $sharpness;
	public $reserved1;
	public $reserved2;
}

class SWFEndTag {
}

class SWFDefineBinaryDataTag extends SWFCharacterTag {
	public $reserved;
	public $data;
	public $swfFile;
}

class SWFDefineBitsTag extends SWFCharacterTag {
	public $imageData;
}

class SWFDefineBitsLosslessTag extends SWFCharacterTag {
	public $format;
	public $width;
	public $height;
	public $colorTableSize;
	public $imageData;
}

class SWFDefineBitsLossless2Tag extends SWFDefineBitsLosslessTag {
}

class SWFDefineBitsJPEG2Tag extends SWFCharacterTag {
	public $imageData;
}

class SWFDefineBitsJPEG3Tag extends SWFDefineBitsJPEG2Tag {
	public $alphaData;
}

class SWFDefineBitsJPEG4Tag extends SWFDefineBitsJPEG3Tag {
	public $deblockingParam;
}

class SWFDefineButtonTag extends SWFCharacterTag {
	public $characters;
	public $actions;	
}

class SWFDefineButton2Tag extends SWFDefineButtonTag {
	const TrackAsMenu	= 0x01;

	public $flags;
}

class SWFDefineButtonCxformTag {
	public $characterId;
	public $colorTransform;
}

class SWFDefineButtonSoundTag {
	public $characterId;
	public $overUpToIdleId;
	public $overUpToIdleInfo;
	public $idleToOverUpId;
	public $idleToOverUpInfo;
	public $overUpToOverDownId;
	public $overUpToOverDownInfo;
	public $overDownToOverUpId;
	public $overDownToOverUpInfo;
}

class SWFDefineEditTextTag extends SWFCharacterTag {
	const HasFontClass	= 0x8000;
	const AutoSize		= 0x4000;
	const HasLayout		= 0x2000;
	const NoSelect		= 0x1000;
	const Border		= 0x0800;
	const WasStatic		= 0x0400;
	const HTML		= 0x0200;
	const UseOutlines	= 0x0100;	
	const HasText		= 0x0080;
	const WordWrap		= 0x0040;
	const Multiline		= 0x0020;
	const Password		= 0x0010;
	const ReadOnly		= 0x0008;
	const HasTextColor	= 0x0004;
	const HasMaxLength	= 0x0002;
	const HasFont		= 0x0001;

	public $bounds;
	public $flags;
	public $fontId;
	public $fontHeight;
	public $fontClass;
	public $textColor;
	public $maxLength;
	public $align;
	public $leftMargin;
	public $rightMargin;
	public $indent;
	public $leading;
	public $variableName;
	public $initialText;
}

class SWFDefineFontTag extends SWFCharacterTag {
	public $glyphTable;
}

class SWFDefineFont2Tag extends SWFDefineFontTag {
	const HasLayout		= 0x80;
	const ShiftJIS		= 0x40;
	const SmallText		= 0x20;
	const ANSI		= 0x10;
	const WideOffsets	= 0x08;
	const WideCodes		= 0x04;
	const Italic		= 0x02;
	const Bold		= 0x01;

	public $flags;
	public $name;
	public $ascent;
	public $descent;
	public $leading;
	public $languageCode;
	public $codeTable = array();
	public $advanceTable = array();
	public $boundTable = array();
	public $kerningTable = array();
}

class SWFDefineFont3Tag extends SWFDefineFont2Tag {
}

class SWFDefineFont4Tag extends SWFCharacterTag {
	public $flags;
	public $name;
	public $cffData;
}

class SWFDefineFontAlignZonesTag {
	public $characterId;
	public $tableHint;
	public $zoneTable;
}

class SWFDefineFontInfoTag {
	const SmallText		= 0x20;
	const ShiftJIS		= 0x10;
	const ANSI		= 0x08;
	const Italic		= 0x04;
	const Bold		= 0x02;
	const WideCodes		= 0x01;

	public $characterId;
	public $name;
	public $flags;
	public $codeTable = array();
}

class SWFDefineFontInfo2Tag extends SWFDefineFontInfoTag {
	public $languageCode;
}

class SWFDefineFontNameTag {
	public $characterId;
	public $name;
	public $copyright;
}

class SWFDefineMorphShapeTag extends SWFCharacterTag {
	public $startBounds;
	public $endBounds;
	public $fillStyles;
	public $lineStyles;
	public $startEdges;
	public $endEdges;
}

class SWFDefineMorphShape2Tag extends SWFDefineMorphShapeTag {
	const UsesNonScalingStrokes	= 0x02;
	const UsesScalingStrokes	= 0x01;

	public $flags;
	public $startEdgeBounds;
	public $endEdgeBounds;
}

class SWFDefineScalingGridTag {
	public $characterId;
	public $splitter;
}

class SWFDefineSceneAndFrameLabelDataTag {
	public $sceneNames = array();
	public $frameLabels = array();
}

class SWFDefineShapeTag extends SWFCharacterTag {
	public $shapeBounds;
	public $shape;
}

class SWFDefineShape2Tag extends SWFDefineShapeTag {
}

class SWFDefineShape3Tag extends SWFDefineShape2Tag {
}

class SWFDefineShape4Tag extends SWFDefineShape3Tag {
	const UsesFillWindingRule	= 0x04;
	const UsesNonScalingStrokes	= 0x02;
	const UsesScalingStrokes	= 0x01;

	public $flags;
	public $edgeBounds;
}

class SWFDefineSoundTag extends SWFCharacterTag {
	const FormatUncompressed	= 0;
	const FormatADPCM		= 1;
	const FormatMP3			= 2;
	const FormatUncompressedLE	= 3;
	const FormatNellymoser16	= 4;
	const FormatNellymoser8		= 5;
	const FormatNellymoser		= 6;
	const FormatSpeex		= 11;

	const SampleRate55khz		= 0;
	const SampleRate11khz		= 1;
	const SampleRate22khz		= 2;
	const SampleRate44khz		= 3;
	
	const SampleSize8Bit		= 0;
	const SampleSize16Bit		= 1;
	
	const TypeMono			= 0;
	const TypeStereo		= 1;
	
	public $format;
	public $sampleSize;
	public $sampleRate;
	public $type;
	public $sampleCount;
	public $data;
}

class SWFDefineSpriteTag extends SWFCharacterTag {
	public $frameCount;
	public $tags = array();
}

class SWFDefineTextTag extends SWFCharacterTag {
	public $bounds;
	public $matrix;
	public $glyphBits;
	public $advanceBits;
	public $textRecords;
}

class SWFDefineText2Tag extends SWFDefineTextTag {
}

class SWFDefineVideoStreamTag extends SWFCharacterTag {
	public $frameCount;
	public $width;
	public $height;
	public $flags;
	public $codecId;
}

class SWFDoABCTag {
	public $flags;
	public $byteCodeName;
	public $byteCodes;
	
	public $abcFile;
}

class SWFDoActionTag {
	public $actions;
}

class SWFDoInitActionTag {
	public $characterId;
	public $actions;
}

class SWFEnableDebuggerTag {
	public $password;
}

class SWFEnableDebugger2Tag extends SWFEnableDebuggerTag {
	public $reserved;
}

class SWFExportAssetsTag {
	public $names = array();
}

class SWFFileAttributesTag {
	public $flags;
}

class SWFFrameLabelTag {
	public $name;
	public $anchor;
}

class SWFImportAssetsTag {
	public $names = array();
	public $url;
}

class SWFImportAssets2Tag extends SWFImportAssetsTag {
	public $reserved1;
	public $reserved2;	
}

class SWFJPEGTablesTag {
	public $jpegData;
}

class SWFMetadataTag {
	public $metadata;
}

class SWFPlaceObjectTag {
	public $characterId;
	public $depth;
	public $matrix;
	public $colorTransform;
}

class SWFPlaceObject2Tag extends SWFPlaceObjectTag {
	const HasClipActions		= 0x80;
	const HasClipDepth		= 0x40;
	const HasName			= 0x20;
	const HasRatio			= 0x10;
	const HasColorTransform		= 0x08;
	const HasMatrix			= 0x04;
	const HasCharacter		= 0x02;
	const Move			= 0x01;

	public $flags;
	public $ratio;
	public $name;
	public $clipDepth;
	public $clipActions;
	public $allEventsFlags;
}

class SWFPlaceObject3Tag extends SWFPlaceObject2Tag {
	const HasBackgroundColor	= 0x4000;
	const HasVisibility		= 0x2000;
	const HasImage			= 0x1000;
	const HasClassName		= 0x0800;
	const HasCacheAsBitmap		= 0x0400;
	const HasBlendMode		= 0x0200;
	const HasFilterList		= 0x0100;
	
	public $className;
	public $filters;
	public $blendMode;
	public $bitmapCache;
	public $bitmapCacheBackgroundColor;
	public $visibility;
}

class SWFProtectTag {
	public $password;
}

class SWFRemoveObjectTag {
	public $characterId;
	public $depth;
}

class SWFRemoveObject2Tag {
	public $depth;
}

class SWFScriptLimitsTag {
	public $maxRecursionDepth;
	public $scriptTimeoutSeconds;
}

class SWFSetBackgroundColorTag {
	public $color;
}

class SWFSetTabIndexTag {
	public $depth;
	public $tabIndex;
}

class SWFShowFrameTag {
}

class SWFSoundStreamBlockTag {
	public $data;
}

class SWFSoundStreamHeadTag {
	public $playbackSampleSize;
	public $playbackSampleRate;
	public $playbackType;
	public $format;
	public $sampleSize;
	public $sampleRate;
	public $type;
	public $sampleCount;
	public $latencySeek;
}

class SWFSoundStreamHead2Tag extends SWFSoundStreamHeadTag {
}

class SWFStartSoundTag {
	public $info;
}

class SWFStartSound2Tag {
	public $className;
	public $info;
}

class SWFSymbolClassTag {
	public $names = array();
}

class SWFVideoFrameTag {
	public $streamId;
	public $frameNumber;
	public $data;
}

class SWFZoneRecord {
	public $zoneData1;
	public $zoneData2;
	public $flags;
	public $alignmentCoordinate;
	public $range;
}

class SWFKerningRecord {
	public $code1;
	public $code2;
	public $adjustment;
}

class SWFDropShadowFilter {
	public $shadowColor;
	public $highlightColor;
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFBlurFilter {
	public $blurX;
	public $blurY;
	public $passes;
} 

class SWFGlowFilter {
	public $color;
	public $blurX;
	public $blurY;
	public $strength;
	public $flags;
	public $passes;
} 

class SWFBevelFilter {
	public $shadowColor;
	public $highlightColor;
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
} 

class SWFGradientGlowFilter {
	public $colors = array();
	public $ratios = array();
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFConvolutionFilter {
	public $matrixX;
	public $matrixY;
	public $divisor;
	public $bias;
	public $matrix = array();
	public $defaultColor;
	public $flags;
} 

class SWFColorMatrixFilter {
	public $matrix = array();
}

class SWFGradientBevelFilter {
	public $colors = array();
	public $ratios = array();
	public $blurX;
	public $blurY;
	public $angle;
	public $distance;
	public $strength;
	public $flags;
	public $passes;
}

class SWFSoundInfo {
	const SyncStop 		= 0x20;
	const SyncNoMultiple	= 0x10;
	const HasEnvelope	= 0x08;
	const HasLoops		= 0x04;
	const HasOutPoint	= 0x02;
	const HasInPoint	= 0x01;

	public $flags;
	public $inPoint;
	public $outPoint;
	public $loopCount;
	public $envelopes;
}

class SWFSoundEnvelope {
	public $position44;
	public $leftLevel;
	public $rightLevel;
}

class SWFButtonRecord extends SWFCharacterTag {
	const HasBlendMode	= 0x20;
	const HasFilterList	= 0x10;
	const StateHitTest	= 0x08;
	const StateDown		= 0x04;
	const StateOver		= 0x02;
	const StateUp		= 0x01;

	public $flags;
	public $placeDepth;
	public $matrix;
	public $colorTransform;
	public $filters;
	public $blendMode;
}

class SWFClipAction {
	const Construct		= 0x00040000;
	const KeyPress		= 0x00020000;
	const DragOut		= 0x00010000;
	const DragOver		= 0x00008000;
	const RollOut		= 0x00004000;
	const RollOver		= 0x00002000;
	const ReleaseOutside	= 0x00001000;
	const Release		= 0x00000800;
	const Press		= 0x00000400;
	const Initialize	= 0x00000200;
	const Data		= 0x00000100;
	const KeyUp		= 0x00000080;
	const KeyDown		= 0x00000040;
	const MouseUp		= 0x00000020;
	const MouseDown		= 0x00000010;
	const MouseMove		= 0x00000008;
	const Unload		= 0x00000004;
	const EnterFrame	= 0x00000002;
	const Load		= 0x00000001;

	public $eventFlags;
	public $keyCode;
	public $actions;
}

class SWFGlyphEntry {
	public $index;
	public $advance;
}

class SWFShape {
	public $numFillBits;
	public $numLineBits;
	public $edges;
}

class SWFShapeWithStyle extends SWFShape {
	public $lineStyles;
	public $fillStyles;
}

class SWFMorphShapeWithStyle {
	public $lineStyles;
	public $fillStyles;
	public $startNumFillBits;
	public $startNumLineBits;
	public $endNumFillBits;
	public $endNumLineBits;
	public $startEdges;
	public $endEdges;
}

class SWFStraightEdge {
	public $numBits;
	public $deltaX;
	public $deltaY;
}

class SWFQuadraticCurve {
	public $numBits;
	public $controlDeltaX;
	public $controlDeltaY;
	public $anchorDeltaX;
	public $anchorDeltaY;
}

class SWFStyleChange {
	public $numMoveBits;
	public $moveDeltaX;
	public $moveDeltaY;
	public $fillStyle0;
	public $fillStyle1;
	public $lineStyle;
	public $newFillStyles;
	public $newLineStyles;
	public $numFillBits;
	public $numLineBits;
}

class SWFTextRecord {
	const HasFont			= 0x08;
	const HasColor			= 0x04;
	const HasYOffset		= 0x02;
	const HasXOffset		= 0x01;

	public $flags;
	public $fontId;
	public $textColor;
	public $xOffset;
	public $yOffset;
	public $textHeight;
	public $glyphs;
}

class SWFFillStyle {
	public $type;
	public $color;
	public $gradientMatrix;
	public $gradient;
	public $bitmapId;
	public $bitmapMatrix;
}

class SWFMorphFillStyle {
	public $type;
	public $startColor;
	public $endColor;
	public $startGradientMatrix;
	public $endGradientMatrix;
	public $gradient;
	public $bitmapId;
	public $startBitmapMatrix;
	public $endBitmapMatrix;
}

class SWFLineStyle {
	public $width;
	public $color;
}

class SWFLineStyle2 {
	const HasFill			= 0x0200;
	const NoHScale			= 0x0100;
	const NoVScale			= 0x0080;
	const PixelHinting		= 0x0040;
	const NoClose			= 0x0001;

	const CapStyleRound		= 0;
	const CapStyleNone		= 1;
	const CapStyleSquare		= 2;
	
	const JoinStyleRound		= 0;
	const JoinStyleBevel		= 1;
	const JoinStyleMiter		= 2;

	public $width;
	public $startCapStyle;
	public $endCapStyle;
	public $joinStyle;
	public $flags;
	public $miterLimitFactor;
	public $fillStyle;
	public $style;
}

class SWFMorphLineStyle {
	public $startWidth;
	public $endWidth;
	public $startColor;
	public $endColor;
}

class SWFFocalGradient extends SWFGradient {
	public $focalPoint;
}

class SWFGradient {
	public $spreadMode;
	public $interpolationMode;
	public $controlPoints;
}

class SWFGradientControlPoint {
	public $ratio;
	public $color;
}

class SWFMorphGradient {
	public $records;
}

class SWFMorphGradientRecord {
	public $startRatio;
	public $startColor;
	public $endRatio;
	public $endColor;
}

class SWFColorTransform {
	public $numBits;
	public $redMultTerm;
	public $greenMultTerm;
	public $blueMultTerm;
	public $redAddTerm;
	public $greenAddTerm;
	public $blueAddTerm;
}

class SWFColorTransformAlpha extends SWFColorTransform {
	public $alphaMultTerm;
	public $alphaAddTerm;
}

class SWFMatrix {
	public $nScaleBits;
	public $scaleX;
	public $scaleY;
	public $nRotateBits;
	public $rotateSkew0;
	public $rotateSkew1;
	public $nTraslateBits;
	public $translateX;
	public $translateY;
}

class SWFRect {
	public $numBits;
	public $left;
	public $right;
	public $top;
	public $bottom;
}

class SWFRGBA {
	public $red;
	public $green;
	public $blue;
	public $alpha;
}

?>