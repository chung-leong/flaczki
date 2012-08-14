'''
Created on Jul 21, 2012

@author: Chung Leong
'''
from zlib import decompressobj
from struct import Struct
from flaczki.swf.tags import *

class SWFFile(object):
    '''
    classdocs
    '''
    version = None
    compressed = False
    frame_size = None
    tags = []

class SWFParser(object):
    '''
    classdocs
    '''

    def __init__(self):
        '''
        Constructor
        '''
        self.ui8 = Struct('<B')
        self.si16 = Struct('<h')
        self.ui16 = Struct('<H')
        self.si32 = Struct('<i')
        self.ui32 = Struct('<I')
        self.f32 = Struct('f')
        self.f64 = Struct('d')
        
        self.tag_class_and_handlers = {
            74: (CSMTextSettings, self.readCSMTextSettingsTag),
            78: (DefineScalingGrid, self.readDefineScalingGridTag),
            87: (DefineBinaryData, self.readDefineBinaryDataTag),
             6: (DefineBits, self.readDefineBitsTag),
            21: (DefineBitsJPEG2, self.readDefineBitsJPEG2Tag), 
            35: (DefineBitsJPEG3, self.readDefineBitsJPEG3Tag), 
            90: (DefineBitsJPEG4, self.readDefineBitsJPEG4Tag),
            20: (DefineBitsLossless, self.readDefineBitsLosslessTag), 
            36: (DefineBitsLossless2, self.readDefineBitsLossless2Tag),
             7: (DefineButton, self.readDefineButtonTag),
            34: (DefineButton2, self.readDefineButton2Tag),
            23: (DefineButtonCxform, self.readDefineButtonCxformTag),
            17: (DefineButtonSound, self.readDefineButtonSoundTag),
            37: (DefineEditText, self.readDefineEditTextTag),
            10: (DefineFont, self.readDefineFontTag),
            48: (DefineFont2, self.readDefineFont2Tag),
            75: (DefineFont3, self.readDefineFont3Tag),
            91: (DefineFont4, self.readDefineFont4Tag),
            73: (DefineFontAlignZones, self.readDefineFontAlignZonesTag),
            13: (DefineFontInfo, self.readDefineFontInfoTag),
            62: (DefineFontInfo2, self.readDefineFontInfo2Tag),
            88: (DefineFontName, self.readDefineFontNameTag),
            46: (DefineMorphShape, self.readDefineMorphShapeTag),
            84: (DefineMorphShape2, self.readDefineMorphShape2Tag), 
            86: (DefineSceneAndFrameLabelData, self.readDefineSceneAndFrameLabelDataTag), 
             2: (DefineShape, self.readDefineShapeTag),
            22: (DefineShape2, self.readDefineShape2Tag),
            32: (DefineShape3, self.readDefineShape3Tag),
            83: (DefineShape4, self.readDefineShape4Tag),
            39: (DefineSprite, self.readDefineSpriteTag),
            11: (DefineText, self.readDefineTextTag),
            33: (DefineText2, self.readDefineText2Tag),
            14: (DefineSound, self.readDefineSoundTag),
            60: (DefineVideoStream, self.readDefineVideoStreamTag),
            59: (DoInitAction, self.readDoInitActionTag),
            82: (DoABC, self.readDoABCTag),
            12: (DoAction, self.readDoActionTag),
            58: (EnableDebugger, self.readEnableDebuggerTag),
            64: (EnableDebugger2, self.readEnableDebugger2Tag),
             0: (End, self.readEndTag),
            56: (ExportAssets, self.readExportAssetsTag),
            69: (FileAttributes, self.readFileAttributesTag),
            43: (FrameLabel, self.readFrameLabelTag),
            57: (ImportAssets, self.readImportAssetsTag),
            71: (ImportAssets2, self.readImportAssets2Tag),
             8: (JPEGTables, self.readJPEGTablesTag),
            77: (Metadata, self.readMetadataTag),
            24: (Protect, self.readProtectTag),
            4:  (PlaceObject, self.readPlaceObjectTag),
            26: (PlaceObject2, self.readPlaceObject2Tag),
            70: (PlaceObject3, self.readPlaceObject3Tag),
             5: (RemoveObject, self.readRemoveObjectTag),
            28: (RemoveObject2, self.readRemoveObject2Tag),
            65: (ScriptLimits, self.readScriptLimitsTag),
             9: (SetBackgroundColor, self.readSetBackgroundColorTag),
            66: (SetTabIndex, self.readSetTabIndexTag),
             1: (ShowFrame, self.readShowFrameTag),
            15: (StartSound, self.readStartSoundTag),
            89: (StartSound2, self.readStartSound2Tag),
            18: (SoundStreamHead, self.readSoundStreamHeadTag),
            45: (SoundStreamHead2, self.readSoundStreamHead2Tag),
            19: (SoundStreamBlock, self.readSoundStreamBlockTag),
            76: (SymbolClass, self.readSymbolClassTag),
            61: (VideoFrame, self.readVideoFrameTag)
        }

    def parse(self, source):
        swf_file = SWFFile()
        
        self.source = source
        self.byte_buffer = bytearray()
        self.bytes_remaining = 8
        self.bit_buffer = 0
        self.bits_remaining = 0
        self.highest_character_id = 0
        self.decompressor = None
        
        # signature
        signature = self.readUI32()
        swf_file.version = self.swf_version = (signature >> 24) & 0xFF
        signature = signature & 0xFFFFFF
                
        # should be SWF or SWC
        if signature != 0x535746 and signature != 0x535743:
            return False
        
        # file length
        file_length = self.readUI32()
        self.bytes_remaining = file_length
        
        if signature == 0x535743:
            # start decompressing incoming data
            swf_file.compressed = True
            self.startDecompression()
            
        swf_file.frame_size = self.readRect()

        # frame rate and count
        swf_file.frame_rate = self.readUI16()
        swf_file.frame_count = self.readUI16()
        
        while True:
            tag = self.readTag()
            swf_file.tags.append(tag)
            if isinstance(tag, End):
                break
        swf_file.highest_character_id = self.highest_character_id
        
        self.source = None
        return swf_file
    
    def readTag(self):
        self.bytes_remaining = 6
        tag_code_and_length = self.readUI16()
        tag_code = (tag_code_and_length & 0xFFC0) >> 6
        tag_length = tag_code_and_length & 0x003F
        if tag_length == 0x003F:
            # long format
            tag_length = self.readUI32()
            header_length = 6
        else:
            header_length = 2
        
        self.bytes_remaining = tag_length
        tag_class, handler = self.tag_class_and_handlers[tag_code]
        if handler:
            tag = handler()
            if self.bytes_remaining > 0:
                extra = self.readBytes(self.bytes_remaining)
                print(str(len(extra)) + ' bytes left after ' + str(tag))

            if tag is Character:
                if tag.character_id > self.highest_character_id:
                    self.highest_character_id = tag.character_id
        else:
            tag = Generic()
            tag.data = self.readBytes(tag_length)
            tag.code = tag_code
            tag.header_length = header_length
            tag.length = tag_length
            
            if tag_class is Character:
                character_id = self.ui16.unpack_from(tag.data)
                if character_id > self.highest_character_id:
                    self.highest_character_id = character_id
        return tag
        
    def readCSMTextSettingsTag(self):
        tag = CSMTextSettings()
        tag.character_id = self.readUI16()
        tag.renderer = self.readUB(2)
        tag.gridFit = self.readUB(3)
        tag.reserved1 = self.readUB(3)
        tag.thickness = self.readF32()
        tag.sharpness = self.readF32()
        tag.reserved2 = self.readUI8()
        return tag
    
    def readDefineBinaryDataTag(self):
        tag = DefineBinaryData()
        tag.character_id = self.readUI16()
        tag.reserved = self.readUI32()
        tag.data = self.readBytes(self.bytes_remaining)
        return tag
        
    
    def readDefineBitsTag(self):
        tag = DefineBits()
        tag.character_id = self.readUI16()
        tag.image_data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineBitsLosslessTag(self):
        tag = DefineBitsLossless()
        tag.character_id = self.readUI16()
        tag.format = self.readUI8()
        tag.width = self.readUI16()
        tag.height = self.readUI16()
        if tag.format == 3:
            tag.color_table_size = self.readUI8()
        tag.image_data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineBitsLossless2Tag(self):
        tag = DefineBitsLossless2()
        tag.character_id = self.readUI16()
        tag.format = self.readUI8()
        tag.width = self.readUI16()
        tag.height = self.readUI16()
        if tag.format == 3:
            tag.colorTableSize = self.readUI8()
        tag.image_data = self.readBytes(self.bytes_remaining)
        return tag

    def readDefineBitsJPEG2Tag(self):
        tag = DefineBitsJPEG2()
        tag.character_id = self.readUI16()
        tag.image_data = self.readBytes(self.bytes_remaining)
        return tag

    def readDefineBitsJPEG3Tag(self):
        tag = DefineBitsJPEG3()
        tag.character_id = self.readUI16()
        alpha_offset = self.readUI32()
        tag.image_data = self.readBytes(alpha_offset)
        tag.alpha_data = self.readBytes(self.bytes_remaining)
        return tag

    def readDefineBitsJPEG4Tag(self):
        tag = DefineBitsJPEG4()
        tag.character_id = self.readUI16()
        alpha_offset = self.readUI32()
        tag.deblockingParam = self.readUI16()
        tag.image_data = self.readBytes(alpha_offset)
        tag.alpha_data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineButtonTag(self):
        tag = DefineButton()
        tag.character_id = self.readUI16()
        tag.characters = self.readButtonRecords(1)        
        tag.actions = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineButton2Tag(self):
        tag = DefineButton2()
        tag.character_id = self.readUI16()
        tag.flags = self.readUI8()
        _action_offset = self.readUI16()
        tag.characters = self.readButtonRecords(2)
        tag.actions = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineButtonCxformTag(self):
        tag = DefineButtonCxform()
        tag.character_id = self.readUI16()
        tag.color_transform = self.readColorTransform()
        return tag
    
    def readDefineButtonSoundTag(self):
        tag = DefineButtonSound()
        tag.character_id = self.readUI16()
        tag.over_up_to_idle_id = self.readUI16()
        if tag.over_up_to_idle_id != 0:
            tag.over_up_to_idle_info = self.readSoundInfo()
        tag.idle_to_over_up_id = self.readUI16()
        if tag.idle_to_over_up_id != 0:
            tag.idle_to_over_up_info = self.readSoundInfo()
        tag.over_up_to_over_down_id = self.readUI16()
        if tag.over_up_to_over_down_id != 0:
            tag.over_up_to_over_down_info = self.readSoundInfo()
        tag.over_down_to_over_up_id = self.readUI16()
        if tag.over_down_to_over_up_id != 0:
            tag.over_down_to_over_up_info = self.readSoundInfo()
        return tag
    
    def readDefineEditTextTag(self):
        tag = DefineEditText()
        tag.character_id = self.readUI16()
        tag.bounds = self.readRect()
        tag.flags = self.readUI16()        
        if tag.flags & 0x0001:  # HasFont
            tag.font_id = self.readUI16()
            tag.font_height = self.readUI16()            
        if tag.flags & 0x8000:  # HasFontClass
            tag.font_class = self.readString()
        if tag.flags & 0x0004:  # HasTextColor
            tag.text_color = self.readRGBA()
        if tag.flags & 0x0002:  # HasMaxLength
            tag.maxLength = self.readUI16()
        if tag.flags & 0x2000:  # HasLayout
            tag.align = self.readUI8()
            tag.leftMargin = self.readUI16()
            tag.rightMargin = self.readUI16()
            tag.indent = self.readUI16()
            tag.leading = self.readUI16()
            tag.variable_name = self.readString()
        if tag.flags & 0x0080:  # HasText
            tag.initialText = self.readString()
        return tag
    
    def readDefineFontTag(self):
        tag = DefineFont()
        tag.character_id = self.readUI16()
        first_offset = self.readUI16()
        glyph_count = first_offset >> 1
        _offset_table = [first_offset] + [self.readUI16() for _ in range(1, glyph_count)]
        tag.glyph_table = [self.readShape() for _ in range(glyph_count)]
        return tag
    
    def readDefineFont2Tag(self):
        tag = DefineFont2()
        tag.character_id = self.readUI16()
        tag.flags = self.readUI8()
        tag.language_code = self.readUI8()
        name_length = self.readUI8()
        tag.name = self.readBytes(name_length)
        glyph_count = self.readUI16()        
        bytes_remaining_before = self.bytes_remaining
        glyph_range = range(0, glyph_count)
        
        if tag.flags & 0x08:    # WideOffsets
            _offset_table = [self.readUI32() for _ in glyph_range]
            _code_table_offset = self.readUI32()
        else:
            _offset_table = [self.readUI16() for _ in glyph_range]
            _code_table_offset = self.readUI16()
                
        tag.glyph_table = [self.readShape() for _ in glyph_range]
        _offset = bytes_remaining_before - self.bytes_remaining
        
        if tag.flags & 0x04:    # WideCodes
            tag.code_table = [self.readUI16() for _ in glyph_range]        
        else:
            tag.code_table = [self.readUI8() for _ in glyph_range]        
                        
        if tag.flags & 0x80 or self.bytes_remaining > 0:    # HasLayout
            tag.ascent = self.readSI16()
            tag.descent = self.readSI16()
            tag.leading = self.readSI16()
            tag.advance_table = [self.readUI16() for _ in glyph_range]
            tag.bound_table = [self.readRect() for _ in glyph_range]    
            if tag.flags & 0x04:    # WideCodes
                tag.kerning_table = self.readWideKerningRecords()
            else:
                tag.kerning_table = self.readKerningRecords()
        return tag
    
    def readDefineFont3Tag(self):
        tag = DefineFont3()
        tag2 = self.readDefineFont2Tag()
        tag.__dict__ = tag2.__dict__
        return tag
    
    def readDefineFont4Tag(self):
        tag = DefineFont4()
        tag.character_id = self.readUI16()
        tag.flags = self.readUI8()
        tag.name = self.readString()
        tag.cff_data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineFontAlignZonesTag(self):
        tag = DefineFontAlignZones()
        tag.character_id = self.readUI16()
        tag.table_hint = self.readUB(2)
        tag.zone_table = self.readZoneRecords()
        return tag
    
    def readDefineFontInfoTag(self):
        tag = DefineFontInfo()
        tag.character_id = self.readUI16()
        nameLength = self.readUI8()
        tag.name = self.readBytes(nameLength)
        tag.flags = self.readUI8()        
        if tag.flags & 0x01:    # WideCodes
            while self.bytes_remaining > 0:
                tag.code_table.append(self.readUI16())
        else:
            while self.bytes_remaining > 0:
                tag.code_table.append(self.readU8())
        return tag
    
    def readDefineFontInfo2Tag(self):
        tag = DefineFontInfo2()
        tag.character_id = self.readUI16()
        nameLength = self.readUI8()
        tag.name = self.readBytes(nameLength)
        tag.flags = self.readUI8()
        tag.languageCode = self.readUI8()
        tag.code_table = []
        if tag.flags & 0x01:    # WideCodes
            while self.bytes_remaining > 0:
                tag.code_table.append(self.readUI16())
        else:
            while self.bytes_remaining > 0:
                tag.code_table.append(self.readU8())
        return tag
    
    def readDefineFontNameTag(self):
        tag = DefineFontName()
        tag.character_id = self.readUI16()
        tag.name = self.readString()
        tag.copyright = self.readString()
        return tag
    
    def readDefineMorphShapeTag(self):
        tag = DefineMorphShape()
        tag.character_id = self.readUI16()
        tag.start_bounds = self.readRect()
        tag.end_bounds = self.readRect()
        tag.morph_shape = self.readMorphShapeWithStyle(3)        # use structures of DefineShape3
        return tag
    
    def readDefineMorphShape2Tag(self):
        tag = DefineMorphShape2()
        tag.character_id = self.readUI16()
        tag.start_bounds = self.readRect()
        tag.end_bounds = self.readRect()
        tag.start_edge_bounds = self.readRect()
        tag.end_edge_bounds = self.readRect()
        tag.flags = self.readUI8()
        tag.morph_shape = self.readMorphShapeWithStyle(4)        # use structures of DefineShape4
        return tag
    
    def readDefineScalingGridTag(self):
        tag = DefineScalingGrid()
        tag.character_id = self.readUI16()
        tag.splitter = self.readRect()
        return tag
    
    def readDefineSceneAndFrameLabelDataTag(self):
        tag = DefineSceneAndFrameLabelData()
        tag.scene_names = self.readEncUI32StringTable()
        tag.frame_labels = self.readEncUI32StringTable()
        return tag
    
    def readDefineShapeTag(self):
        tag = DefineShape()
        tag.character_id = self.readUI16()
        tag.shape_bounds = self.readRect()
        tag.shape = self.readShapeWithStyle(1)
        return tag
    
    def readDefineShape2Tag(self):
        tag = DefineShape2()
        tag.character_id = self.readUI16()
        tag.shape_bounds = self.readRect()
        tag.shape = self.readShapeWithStyle(2)
        return tag
    
    def readDefineShape3Tag(self):
        tag = DefineShape3()
        tag.character_id = self.readUI16()
        tag.shape_bounds = self.readRect()
        tag.shape = self.readShapeWithStyle(3)
        return tag
    
    def readDefineShape4Tag(self):
        tag = DefineShape4()
        tag.character_id = self.readUI16()
        tag.shape_bounds = self.readRect()
        tag.edge_bounds = self.readRect()
        tag.flags = self.readUI8()
        tag.shape = self.readShapeWithStyle(4)
        return tag
    
    def readDefineSoundTag(self):
        tag = DefineSound()
        tag.format = self.readUB(4)
        tag.sampleRate = self.readUB(2)
        tag.sampleSize = self.readUB(1)
        tag.type = self.readUB(1)
        tag.sample_count = self.readUI32()
        tag.data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDefineSpriteTag(self):
        tag = DefineSprite()
        tag.character_id = self.readUI16()
        tag.frame_count = self.readUI16()
        tag.tags = []
        while True: 
            child = self.readTag()
            tag.tags.append(child)
            if isinstance(child, End):
                break
        return tag
    
    def readDefineTextTag(self):
        tag = DefineText()
        tag.character_id = self.readUI16()
        tag.bounds = self.readRect()
        tag.matrix = self.readMatrix()
        tag.glyph_bits = self.readUI8()
        tag.advance_bits = self.readUI8()
        tag.text_records = self.readTextRecords(tag.glyph_bits, tag.advance_bits, 1)
        return tag
    
    def readDefineText2Tag(self):
        tag = DefineText2()
        tag.character_id = self.readUI16()
        tag.bounds = self.readRect()
        tag.matrix = self.readMatrix()
        tag.glyph_bits = self.readUI8()
        tag.advance_bits = self.readUI8()
        tag.text_records = self.readTextRecords(tag.glyph_bits, tag.advance_bits, 2)
        return tag
    
    def readDefineVideoStreamTag(self):
        tag = DefineVideoStream()
        tag.character_id = self.readUI16()
        tag.frame_count = self.readUI16()
        tag.width = self.readUI16()
        tag.height = self.readUI16()
        tag.flags = self.readUI8()
        tag.codecId = self.readUI8()
        return tag
    
    def readDoABCTag(self):
        tag = DoABC()
        tag.flags = self.readUI32()
        tag.byte_code_name = self.readString()
        tag.byte_codes = self.readBytes(self.bytes_remaining)
        return tag
    
    def readDoActionTag(self):
        tag = DoAction()
        tag.actions = self.readBytes()
        return tag
    
    def readDoInitActionTag(self):
        tag = DoInitAction()
        tag.character_id = self.readUI16()
        tag.actions = self.readBytes()
        return tag
    
    def readEndTag(self):
        tag = End()
        return tag
    
    def readEnableDebuggerTag(self):
        tag = EnableDebugger()
        tag.password = self.readString()
        return tag
    
    def readEnableDebugger2Tag(self):
        tag = EnableDebugger2()
        tag.reserved = self.readUI16()
        tag.password = self.readString()
        return tag
    
    def readExportAssetsTag(self):
        tag = ExportAssets()
        tag.names = self.readStringTable()
        return tag
    
    def readFileAttributesTag(self):
        tag = FileAttributes()
        tag.flags = self.readUI32()
        return tag
    
    def readFrameLabelTag(self):
        tag = FrameLabel()
        tag.name = self.readString()
        if self.bytes_remaining > 0:
            tag.anchor = tag.readString()
        return tag
    
    def readImportAssetsTag(self):
        tag = ImportAssets()
        tag.url = self.readString()
        tag.names = self.readStringTable()
        return tag
    
    def readImportAssets2Tag(self):
        tag = ImportAssets2()
        tag.url = self.readString()
        tag.reserved1 = self.readUI8()
        tag.reserved2 = self.readUI8()
        tag.names = self.readStringTable()
        return tag
    
    def readJPEGTablesTag(self):
        tag = JPEGTables()
        tag.jpeg_data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readMetadataTag(self):
        tag = Metadata()
        tag.metadata = self.readString()
        return tag
    
    def readPlaceObjectTag(self):
        tag = PlaceObject()
        tag.character_id = self.readUI16()
        tag.depth = self.readUI16()
        tag.matrix = self.readMatrix()
        if self.bytes_remaining > 0:
            tag.color_transform = self.readColorTransform()
        return tag
    
    def readPlaceObject2Tag(self):
        tag = PlaceObject2()
        tag.flags = self.readUI8()
        tag.depth = self.readUI16()
        if tag.flags & 0x02:    # HasCharacter
            tag.character_id = self.readUI16()
        if tag.flags & 0x04:    # HasMatrix
            tag.matrix = self.readMatrix()
        if tag.flags & 0x08:    # HasColorTransform
            tag.color_transform = self.readColorTransformAlpha()
        if tag.flags & 0x10:    # HasRatio
            tag.ratio = self.readUI16()
        if tag.flags & 0x20:    # HasName
            tag.name = self.readString()
        if tag.flags & 0x40:    # HasClipDepth
            tag.clipDepth = self.readUI16()
        if tag.flags & 0x80:    # HasClipActions
            _reserved = self.readUI16()
            tag.allEventFlags = self.readUI32() if self.swf_version >= 6 else self.readUI16()
            tag.clip_actions = self.readClipActions()
        return tag
    
    def readPlaceObject3Tag(self):
        tag = PlaceObject3()
        tag.flags = self.readUI16()
        tag.depth = self.readUI16()
        if tag.flags & 0x0800:  # HasClassName
            tag.class_name = self.readString()
        if tag.flags & 0x0002:  # HasCharacter
            tag.character_id = self.readUI16()
        if tag.flags & 0x0004:  # HasMatrix
            tag.matrix = self.readMatrix()
        if tag.flags & 0x0008:  # HasColorTransform
            tag.color_transform = self.readColorTransformAlpha()
        if tag.flags & 0x0010:  # HasRatio
            tag.ratio = self.readUI16()
        if tag.flags & 0x0020:  # HasName
            tag.name = self.readString()
        if tag.flags & 0x0040:  # HasClipDepth
            tag.clipDepth = self.readUI16()
        if tag.flags & 0x0100:  # HasFilterList
            tag.filters = self.readFilters()            
        if tag.flags & 0x0200:  # HasBlendMode
            tag.blend_mode = self.readUI8()
        if tag.flags & 0x0400:  # HasCacheAsBitmap
            tag.bitmapCache = self.readUI8()
        if tag.flags & 0x0080:  # HasClipActions
            tag.clip_actions = self.readClipActions()
        if tag.flags & 0x2000:  # HasVisibility
            tag.visibility = self.readUI8()
        if tag.flags & 0x4000:  # HasBackgroundColor
            tag.bitmap_cache_background_color = self.readRGBA()
        if tag.flags & 0x0080:  # HasClipActions
            _reserved = self.readUI16()
            tag.allEventFlags = self.readUI32() if self.swf_version >= 6 else self.readUI16()
            tag.clip_actions = self.readClipActions()
        return tag
    
    def readProtectTag(self):
        tag = Protect()
        tag.password = self.readString()
        return tag
    
    def readRemoveObjectTag(self):
        tag = RemoveObject()
        tag.character_id = self.readUI16()
        tag.depth = self.readUI16()
        return tag
    
    def readRemoveObject2Tag(self):
        tag = RemoveObject2()
        tag.depth = self.readUI16()
        return tag
    
    def readScriptLimitsTag(self):
        tag = ScriptLimits()
        tag.max_recursion_depth = self.readUI16()
        tag.script_timeout_seconds = self.readUI16()
        return tag
    
    def readSetBackgroundColorTag(self):
        tag = SetBackgroundColor()
        tag.color = self.readRGB()
        return tag
    
    def readSetTabIndexTag(self):
        tag = SetTabIndex()
        tag.depth = self.readUI16()
        tag.tab_index = self.readUI16()
        return tag
    
    def readShowFrameTag(self):
        tag = ShowFrame()
        return tag
    
    def readSoundStreamBlockTag(self):
        tag = SoundStreamBlock()
        tag.data = self.readBytes(self.bytes_remaining)
        return tag
    
    def readSoundStreamHeadTag(self):
        tag = SoundStreamHead()
        tag.flags = self.readUI16()
        tag.sample_count = self.readUI16()
        if tag.flags & 0xF000 == 0x2000:
            tag.latency_seek = self.readS16()
        return tag
    
    def readSoundStreamHead2Tag(self):
        tag = SoundStreamHead2()        
        _reserved = self.readUB(4)
        tag.playback_sample_rate = self.readUB(2)
        tag.playback_sample_size = self.readUB(1)
        tag.playback_type = self.readUB(1)
        tag.format = self.readUB(4)
        tag.sample_rate = self.readUB(2)
        tag.sample_size = self.readUB(1)
        tag.type = self.readUB(1)
        tag.sample_count = self.readUI16()
        if tag.format == 2:
            tag.latency_seek = self.readS16()
        return tag
    
    def readStartSoundTag(self):
        tag = StartSound()
        tag.character_id = self.readUI16()
        tag.info = self.readSoundInfo()
        return tag
    
    def readStartSound2Tag(self):
        tag = StartSound2()
        tag.class_name = self.readString()
        tag.info = self.readSoundInfo()
        return tag
    
    def readSymbolClassTag(self):
        tag = SymbolClass()
        tag.names = self.readStringTable()
        return tag
    
    def readVideoFrameTag(self):
        tag = VideoFrame()
        tag.stream_id = self.readUI16()
        tag.frame_number = self.readUI16()
        tag.data = self.readBytes(self.bytes_remaining)
        return tag

    def readZoneRecords(self):
        records = []
        while self.bytes_remaining > 0:
            record = ZoneRecord()
            _num_zone_data = self.readUI8()  # always 1
            record.zone_data1 = self.readUI16()
            record.zone_data2 = self.readUI16()
            record.flags = self.readUI8()
            record.alignment_coordinate = self.readUI16()
            record.range = self.readUI16()
            records.append(record)
        return records
    
    def readKerningRecords(self):
        kerns = []
        count = self.readUI16()
        for _ in range(count):            
            kern = KerningRecord()
            kern.code1 = self.readUI8()
            kern.code2 = self.readUI8()
            kern.adjustment = self.readUI16()
            kerns.append(kern)
        return kerns
    
    def readWideKerningRecords(self):
        kerns = []
        count = self.readUI16()
        for _ in range(count):            
            kern = KerningRecord()
            kern.code1 = self.readUI16()
            kern.code2 = self.readUI16()
            kern.adjustment = self.readUI16()
            kerns.append(kern)
        return kerns
    
    def readClipActions(self):
        clip_actions = []
        while True:
            eventFlags = self.readUI32() if self.swf_version >= 6 else self.readUI16()
            if eventFlags == 0:
                break
            else:
                clip_action = ClipAction()
                clip_action.eventFlags = eventFlags
                actionLength = self.readUI32()
                if clip_action.eventFlags & 0x00020000:    # KeyPress
                    clip_action.keyCode = self.readUI8()
                clip_action.actions = self.readBytes(actionLength)
                clip_actions.append(clip_action)
        return clip_actions
    
    def readFilters(self):
        filters = []
        count = self.readUI8()
        for _ in range(count):
            filter_id = self.readUI8()
            if filter_id == 0:
                drop_shadow = DropShadowFilter()
                drop_shadow.shadowColor = self.readRGBA()
                drop_shadow.blur_x = self.readSI32()
                drop_shadow.blur_y = self.readSI32()
                drop_shadow.angle = self.readSI32()
                drop_shadow.distance = self.readSI32()
                drop_shadow.strength = self.readSI16()
                drop_shadow.flags = self.readUB(3)
                drop_shadow.passes = self.readUB(5)
                filters.append(drop_shadow)
            elif filter_id == 1:
                blur = BlurFilter()
                blur.blur_x = self.readSI32()
                blur.blur_y = self.readSI32()
                blur.passes = self.readUB(5)
                filters.append(blur)
            elif filter_id == 2:
                glow = GlowFilter()
                glow.color = self.readRGBA()
                glow.blur_x = self.readSI32()
                glow.blur_y = self.readSI32()
                glow.strength = self.readSI16()
                glow.flags = self.readUB(3)
                glow.passes = self.readUB(5)
                filters.append(glow)
            elif filter_id == 3:
                bevel = BevelFilter()
                # the spec incorrectly states that shadowColor comes first
                bevel.highlight_color = self.readRGBA()
                bevel.shadow_color = self.readRGBA()
                bevel.blur_x = self.readSI32()
                bevel.blur_y = self.readSI32()
                bevel.angle = self.readSI32()
                bevel.distance = self.readSI32()
                bevel.strength = self.readSI16()
                bevel.flags = self.readUB(4)
                bevel.passes = self.readUB(4)
                filters.append(bevel)
            elif filter_id == 4:
                gradient_glow = GradientGlowFilter()
                color_count = self.readUI8()
                gradient_glow.colors = [self.readRGBA() for _ in range(color_count)]
                gradient_glow.ratios = [self.readUI8() for _ in range(color_count)]
                gradient_glow.blur_x = self.readSI32()
                gradient_glow.blur_y = self.readSI32()
                gradient_glow.angle = self.readSI32()
                gradient_glow.distance = self.readSI32()
                gradient_glow.strength = self.readSI16()
                gradient_glow.flags = self.readUB(4)
                gradient_glow.passes = self.readUB(4)
                filters.append(gradient_glow)
            elif filter_id == 5:
                convolution = ConvolutionFilter()
                convolution.matrix_x = self.readUI8()
                convolution.matrix_y = self.readUI8()
                convolution.divisor = self.readFloat()
                convolution.bias = self.readFloat()
                convolution.matrix = [self.readFloat() for _ in range(convolution.matrix_x * convolution.matrix_y)]
                convolution.default_color = self.readRGBA()
                convolution.flags = self.readUI8()
                filters.append(convolution)
            elif filter_id == 6:
                color_matrix = ColorMatrixFilter()
                color_matrix.matrix = [self.readFloat() for _ in range(20)]
                filters.append(color_matrix)
            elif filter_id == 7:
                gradient_bevel = GradientBevelFilter()
                color_count = self.readUI8()
                gradient_bevel.colors = [self.readRGBA() for _ in range(color_count)]
                gradient_bevel.ratios = [self.readUI8() for _ in range(color_count)]
                gradient_bevel.blur_x = self.readSI32()
                gradient_bevel.blur_y = self.readSI32()
                gradient_bevel.angle = self.readSI32()
                gradient_bevel.distance = self.readSI32()
                gradient_bevel.strength = self.readSI16()
                gradient_bevel.flags = self.readUB(4)
                gradient_bevel.passes = self.readUB(4)
                filters.append(gradient_bevel)
        return filters

    def readTextRecords(self, glyph_bits, advance_bits, version):
        records = []
        while True:
            flags = self.readUI8()
            if flags == 0:
                break
            else:
                record = TextRecord()
                record.flags = flags
                if record.flags & 0x08:     # HasFont
                    record.font_id = self.readUI16()
                if record.flags & 0x04:     # HasColor
                    record.textColor = self.readRGBA() if version >= 2 else self.readRGB()
                if record.flags & 0x02:     # HasXOffset
                    record.x_offset = self.readSI16()
                if record.flags & 0x01:     # HasYOffset
                    record.y_offset = self.readSI16()
                if record.flags & 0x08:     # HasFont
                    record.text_height = self.readUI16()
                record.glyphs = self.readGlyphEntries(glyph_bits, advance_bits)
                records.append(record)
        return records
    
    def readGlyphEntries(self, glyph_bits, advance_bits):
        glyphs = []
        count = self.readUI8()
        for _ in range(count):
            glyph = GlyphEntry()
            glyph.index = self.readUB(glyph_bits)
            glyph.advance = self.readUB(advance_bits)
            glyphs.append(glyph)
        return glyphs

    def readSoundInfo(self):
        info = SoundInfo()
        info.flags = self.readUI8()
        if info.flags & 0x01:   # HasInPoint
            info.in_point = self.readUI32()
        if info.flags & 0x02:   # HasOutPoint
            info.out_point = self.readUI32()
        if info.flags & 0x04:   # HasLoops
            info.loop_count = self.readUI32()
        if info.flags & 0x08:   # HasEnvelope
            info.envelopes = self.readSoundEnvelopes()
        return info
    
    def readSoundEnvelopes(self):
        envelopes = []
        count = self.readUI8()
        for _ in range(count):
            envelope = SoundEnvelope()
            envelope.position_44 = self.readUI32()
            envelope.left_level = self.readUI16()
            envelope.right_level = self.readUI16()
            envelopes.append(envelope)
        return envelopes
    
    def readButtonRecords(self, version):
        records = []
        while True:
            flags = self.readUI8()
            if flags == 0:
                break
            else:
                record = ButtonRecord()
                record.flags = flags
                record.character_id = self.readUI16()
                record.place_depth = self.readUI16()
                record.matrix = self.readMatrix()
                if version == 2:
                    record.color_transform = self.readColorTransformAlpha()
                if version == 2 and record.flags & 0x10:    # HasFilterList
                    record.filters = self.readFilters()
                if version == 2 and record.flags & 0x20:    # HasBlendMode
                    record.blend_mode = self.readUI8()
            records.append(record)
        return records
    
    def readShape(self):
        shape = Shape()
        shape.num_fill_bits = self.readUB(4)
        shape.num_line_bits = self.readUB(4)
        shape.edges = self.readShapeRecords(shape.num_fill_bits, shape.num_line_bits, 1)
        return shape
    
    def readShapeWithStyle(self, version):
        shape = ShapeWithStyle()
        shape.fill_styles = self.readFillStyles(version)
        shape.line_styles = self.readLineStyles(version)
        shape.num_fill_bits = self.readUB(4)
        shape.num_line_bits = self.readUB(4)
        shape.edges = self.readShapeRecords(shape.num_fill_bits, shape.num_line_bits, version)
        return shape
    
    def readMorphShapeWithStyle(self, version):
        shape = MorphShapeWithStyle()
        _offset = self.readUI32()
        shape.fill_styles = self.readMorphFillStyles()
        shape.line_styles = self.readMorphLineStyles(version)
        shape.start_num_fill_bits = self.readUB(4)
        shape.start_num_line_bits = self.readUB(4)
        shape.startEdges = self.readShapeRecords(shape.start_num_fill_bits, shape.start_num_line_bits, version)
        shape.end_num_fill_bits = self.readUB(4)
        shape.end_num_line_bits = self.readUB(4)        
        shape.endEdges = self.readShapeRecords(shape.end_num_fill_bits, shape.end_num_line_bits, version)
        return shape
        
    def readShapeRecords(self, num_fill_bits, num_line_bits, version):
        records = []
        while self.bytes_remaining > 0:
            if self.readUB(1):
                # edge
                if self.readUB(1):
                    # straight
                    line = StraightEdge()
                    line.num_bits = self.readUB(4) + 2
                    if self.readUB(1):
                        # general line
                        line.delta_x = self.readSB(line.num_bits)
                        line.delta_y = self.readSB(line.num_bits)
                    else:
                        if self.readUB(1):
                            # vertical
                            line.delta_x = 0
                            line.delta_y = self.readSB(line.num_bits)
                        else:
                            # horizontal 
                            line.delta_x = self.readSB(line.num_bits)
                            line.delta_y = 0
                    records.append(line)
                else:
                    # curve
                    curve = QuadraticCurve()
                    curve.num_bits = self.readUB(4) + 2
                    curve.control_delta_x = self.readSB(curve.num_bits)
                    curve.control_delta_y = self.readSB(curve.num_bits)
                    curve.anchor_delta_x = self.readSB(curve.num_bits)
                    curve.anchor_delta_y = self.readSB(curve.num_bits)
                    records.append(curve)
            else:  
                flags = self.readUB(5)
                if flags == 0:
                    break
                else:
                    # style change
                    change = StyleChange()
                    if flags & 0x01:    # HasMove
                        change.num_move_bits = self.readSB(5)
                        change.move_delta_x = self.readSB(change.num_move_bits)
                        change.move_delta_y = self.readSB(change.num_move_bits)
                    if flags & 0x02:    # HasFillStyle0
                        change.fill_style0 = self.readUB(num_fill_bits)
                    if flags & 0x04:    # HasFillStyle1
                        change.fill_style1 = self.readUB(num_fill_bits)
                    if flags & 0x08:    # HasLineStyle
                        change.line_style = self.readUB(num_line_bits)
                    if flags & 0x10:    # HasNewStyles
                        change.new_fill_styles = self.readFillStyles(version)
                        change.new_line_styles = self.readLineStyles(version)
                        change.num_fill_bits = num_fill_bits = self.readUB(4)
                        change.num_line_bits = num_line_bits = self.readUB(4)
                    records.append(change)
        self.alignToByte()
        return records


    def readFillStyles(self, version):
        count = self.readUI8()
        if count == 0xFF and version > 1:
            count = self.readUI16()
        return [self.readFillStyle(version) for _ in range(count)]

    def readFillStyle(self, version):
        style = FillStyle()
        style.type = self.readUI8()
        if style.type == 0x00:
            style.color = self.readRGBA() if version >= 3 else self.readRGB()
        elif style.type in (0x10, 0x12, 0x13):
            style.gradient_matrix = self.readMatrix()
            if style.type == 0x13:
                style.gradient = self.readFocalGradient(version)
            else:
                style.gradient = self.readGradient(version)
        elif style.type in (0x40, 0x41, 0x42, 0x43):
            style.bitmap_id = self.readUI16()
            style.bitmap_matrix = self.readMatrix()
        return style
    
    def readMorphFillStyles(self):
        count = self.readUI8()
        if count == 0xFF:
            count = self.readUI16()
        styles = [self.readMorphFillStyle() for _ in range(count)]
        return styles
    
    def readMorphFillStyle(self):
        style = MorphFillStyle()
        style.type = self.readUI8()
        if style.type == 0x00:
            style.start_color = self.readRGBA()
            style.end_color = self.readRGBA()
        elif style.type in (0x10, 0x12):
            style.start_gradient_matrix = self.readMatrix()
            style.end_gradient_matrix = self.readMatrix()
            style.gradient = self.readMorphGradient()
        elif style.type in (0x40, 0x41, 0x42, 0x43):
            style.bitmapId = self.readUI16()
            style.start_bitmap_matrix = self.readMatrix()
            style.end_bitmap_matrix = self.readMatrix()
        return style
    
    def readLineStyles(self, version):
        count = self.readUI8()
        if count == 0xFF and version > 1:
            count = self.readUI16()
        if version >= 4:
            return [self.readLineStyle2(version) for _ in range(count)]
        else: 
            return [self.readLineStyle(version) for _ in range(count)]

    def readLineStyle2(self, version):
        style = LineStyle2()
        style.width = self.readUI16()
        style.start_cap_style = self.readUB(2)
        style.join_style = self.readUB(2)        
        style.flags = self.readUB(10)
        style.end_cap_style = self.readUB(2)
        if style.join_style == 2:   # JoinStyleMiter
            style.miterLimitFactor = self.readUI16()
        if style.flags & 0x0200:    # HasFill
            style.fill_style = self.readFillStyle(version)
        else:
            style.color = self.readRGBA()
        return style        
    
    def readLineStyle(self, version):
        style = LineStyle()
        style.width = self.readUI16()
        style.color = self.readRGBA() if version >= 3 else self.readRGB()
        return style
    
    def readMorphLineStyles(self, version):
        count = self.readUI8()
        if count == 0xFF:
            count = self.readUI16()
        if version >= 4:
            return [self.readMorphLineStyle2(version) for _ in range(count)]
        else: 
            return [self.readMorphLineStyle(version) for _ in range(count)]
    
    def readMorphLineStyle2(self):
        style = LineStyle2()
        style.start_width = self.readUI16()
        style.end_width = self.readUI16()
        style.start_cap_style = self.readUB(2)
        style.join_style = self.readUB(2)        
        style.flags = self.readUB(10)
        style.end_cap_style = self.readUB(2)
        if style.join_style == 2:   # JoinStyleMiter
            style.miterLimitFactor = self.readUI16()
        if style.flags & 0x0200:    # HasFill
            style.fill_style = self.readMorphFillStyle()
        else:
            style.start_color = self.readRGBA()
            style.end_color = self.readRGBA()
        return style        
    
    def readMorphLineStyle(self):
        style = MorphLineStyle()
        style.start_width = self.readUI16()
        style.end_width = self.readUI16()
        style.start_color = self.readRGBA()
        style.end_color = self.readRGBA()
        return style
    
    def readGradient(self, version):
        gradient = Gradient()
        gradient.spread_mode = self.readUB(2)
        gradient.interpolation_mode = self.readUB(2)
        gradient.control_points = self.readGradientControlPoints(version)
        return gradient
    
    def readFocalGradient(self, version):
        gradient = FocalGradient()
        gradient.spread_mode = self.readUB(2)
        gradient.interpolation_mode = self.readUB(2)
        gradient.control_points = self.readGradientControlPoints(version)
        gradient.focal_point = self.readSI16()
        return gradient
    
    def readGradientControlPoints(self, version):
        control_points = []
        count = self.readUB(4)
        for _ in range(count):
            control_point = GradientControlPoint()
            control_point.ratio = self.readUI8()
            control_point.color = self.readRGBA() if version >= 3 else self.readRGB()
            control_points.append(control_point)
        return control_points
    
    def readMorphGradient(self):
        gradient = MorphGradient()
        gradient.records = []
        count = self.readUI8()
        for _ in range(count):
            record = MorphGradientRecord()
            record.start_ratio = self.readUI8()
            record.start_color = self.readRGBA()
            record.end_ratio = self.readUI8()
            record.end_color = self.readRGBA()
            gradient.records.append(record)
        return gradient
    
    def readColorTransformAlpha(self):
        transform = ColorTransformAlpha()
        has_add_terms = self.readUB(1)
        has_mult_terms = self.readUB(1)
        transform.num_bits = self.readUB(4)
        if has_mult_terms:
            transform.red_mult_term = self.readSB(transform.num_bits)
            transform.green_mult_term = self.readSB(transform.num_bits)
            transform.blue_mult_term = self.readSB(transform.num_bits)
            transform.alpha_mult_term = self.readSB(transform.num_bits)
        if has_add_terms:
            transform.red_add_term = self.readSB(transform.num_bits)
            transform.green_add_term = self.readSB(transform.num_bits)
            transform.blue_add_term = self.readSB(transform.num_bits)
            transform.alpha_add_term = self.readSB(transform.num_bits)
        self.alignToByte()
        return transform
    
    def readColorTransform(self):
        transform = ColorTransform()
        has_add_terms = self.readUB(1)
        has_mult_terms = self.readUB(1)
        transform.num_bits = self.readUB(4)
        if has_mult_terms:
            transform.red_mult_term = self.readSB(transform.num_bits)
            transform.green_mult_term = self.readSB(transform.num_bits)
            transform.blue_mult_term = self.readSB(transform.num_bits)
        if has_add_terms:
            transform.red_add_term = self.readSB(transform.num_bits)
            transform.green_add_term = self.readSB(transform.num_bits)
            transform.blue_add_term = self.readSB(transform.num_bits)
        self.alignToByte()
        return transform
    
    def readMatrix(self):
        matrix = Matrix()        
        if(self.readUB(1)):
            matrix.num_scale_bits = self.readUB(5)
            matrix.scale_x = self.readSB(matrix.num_scale_bits)
            matrix.scale_y = self.readSB(matrix.num_scale_bits)
        if(self.readUB(1)):
            matrix.num_rotate_bits = self.readUB(5)
            matrix.rotate_skew0 = self.readSB(matrix.num_rotate_bits)
            matrix.rotate_skew1 = self.readSB(matrix.num_rotate_bits)
        matrix.num_translate_bits = self.readUB(5)
        matrix.translate_x = self.readSB(matrix.num_translate_bits)
        matrix.translate_y = self.readSB(matrix.num_translate_bits)
        self.alignToByte()
        return matrix

    def readRect(self):
        rect = Rect()
        rect.num_bits = num_bits = self.readUB(5)
        rect.left = self.readSB(num_bits)
        rect.right = self.readSB(num_bits)
        rect.top = self.readSB(num_bits)
        rect.bottom = self.readSB(num_bits)
        self.alignToByte()
        return rect    
    
    def readARGB(self):
        rgb = RGBA()
        rgb.alpha = self.readUI8()
        rgb.red = self.readUI8()
        rgb.green = self.readUI8()
        rgb.blue = self.readUI8()
        return rgb
    
    def readRGBA(self):
        rgb = RGBA()
        rgb.red = self.readUI8()
        rgb.green = self.readUI8()
        rgb.blue = self.readUI8()
        rgb.alpha = self.readUI8()
        return rgb
        
    def readRGB(self):
        rgb = RGBA()
        rgb.red = self.readUI8()
        rgb.green = self.readUI8()
        rgb.blue = self.readUI8()
        rgb.alpha = 255
        return rgb
    
    def readEncUI32StringTable(self):
        table = {}
        count = self.readEncUI32()
        for _ in range(count):
            index = self.readEncUI32()
            string = self.readString()
            table[index] = string
        return table
            
    def readStringTable(self):
        table = {}
        count = self.readUI16()
        for _ in range(count):
            index = self.readUI16()
            string = self.readString()
            table[index] = string
        return table
        
    def readString(self):
        length = self.byte_buffer.find(b"\x00")
        while length == -1:
            if not self.fillBuffer():
                break 
        data = self.readBytes(length)
        string = data.decode()
        self.readUI8()
        return string
    
    def alignToByte(self):
        self.bits_remaining = 0
    
    def readSB(self, count):
        if count > 0:
            value = self.readUB(count)
            if value & (1 << count - 1) != 0:
                # negative
                value |= -1 << count
            return value
        else:
            return 0
    
    def readUB(self, count):
        if count > 0:
            # the next available bit is always at the 31st bit of the buffer
            while self.bits_remaining < count:
                data = self.readBytes(1)
                if len(data) == 1:
                    result = self.ui8.unpack(data)
                    ui8 = result[0]
                else:
                    ui8 = 0
                self.bit_buffer = self.bit_buffer | (ui8 << (24 - self.bits_remaining))
                self.bits_remaining += 8   
            
            value = (self.bit_buffer >> (32 - count)) & ~(-1 << count)        
            self.bits_remaining -= count
            # mask 32 bits in case of 64 bit system
            self.bit_buffer = ((self.bit_buffer << count) & (-1 << (32 - self.bits_remaining))) & 0xFFFFFFFF    
            return value
        else:
            return 0
        
    def readUI8(self):
        '''Read an unsigned 8 bit integer.        
        '''
        self.alignToByte()
        data = self.readBytes(1)
        if len(data) == 1:
            result = self.ui8.unpack(data)
            return result[0]
        else:
            return 0
    
    def readSI16(self):
        '''Read an signed 16 bit integer.        
        '''
        self.alignToByte()
        data = self.readBytes(2)
        if len(data) == 2:
            result = self.si16.unpack(data)
            return result[0]
        else:
            return 0
    
    def readUI16(self):
        '''Read an unsigned 16 bit integer.        
        '''
        self.alignToByte()
        data = self.readBytes(2)
        if len(data) == 2:
            result = self.ui16.unpack(data)
            return result[0]
        else:
            return 0
        
    def readSI32(self):
        '''Read an signed 32 bit integer.        
        '''
        self.alignToByte()
        data = self.readBytes(4)
        if len(data) == 4:
            result = self.si32.unpack(data)
            return result[0]
        else:
            return 0
    
    def readUI32(self):
        '''Read an unsigned 32 bit integer.        
        '''
        self.alignToByte()
        data = self.readBytes(4)
        if len(data) == 4:
            result = self.ui32.unpack(data)
            return result[0]
        else:
            return 0
    
    def readEncUI32(self):
        self.alignToByte()
        result = 0
        shift = 0
        while shift < 32:
            ui8 = self.readUI8()
            result |= (ui8 & 0x7F) << shift
            shift += 7
            if not (ui8 & 0x80):
                break
        return result
    
    def readF32(self):
        '''Read a 32-bit floating point
        '''
        self.alignToByte()
        data = self.readBytes(4)
        if len(data) == 4:
            result = self.f32.unpack(data) 
            return result[0]
        else:
            return NaN
        
    def readF64(self):
        '''Read a 32-bit floating point
        '''
        self.alignToByte()
        data = self.readBytes(8)
        if len(data) == 8:
            result = self.f64.unpack(data) 
            return result[0]
        else:
            return NaN            
        
    def readBytes(self, count):
        '''Read a certain number of bytes
        '''        
        while len(self.byte_buffer) < count:
            if not self.fillBuffer():
                break
        
        if count > self.bytes_remaining:
            count = self.bytes_remaining
        result = self.byte_buffer[0:count]
        del self.byte_buffer[0:count]
        self.bytes_remaining -= count
        return result
    
    def fillBuffer(self):
        '''Fill the buffer with more data
        '''
        chunk = self.source.read(4096)
        if len(chunk) == 0:
            return False
        if self.decompressor:
            chunk = self.decompressor.decompress(chunk)
        self.byte_buffer.extend(chunk)
        return True
         
    def startDecompression(self):
        '''Begin decompressing data using the zlib algorithm 
        '''
        self.decompressor = decompressobj()
        decompressed_buffer = self.decompressor.decompress(self.byte_buffer) 
        del self.byte_buffer[:]
        self.byte_buffer.extend(decompressed_buffer) 
        
