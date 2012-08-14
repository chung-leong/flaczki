'''
Created on Jul 21, 2012

@author: Chung Leong
'''
from zlib import compressobj
from struct import Struct
from flaczki.swf.tags import *

class SWFAssembler(object):
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
        
        self.tag_codes_and_handlers = {
            CSMTextSettings: (74, self.writeCSMTextSettingsTag),
            DefineScalingGrid: (78, self.writeDefineScalingGridTag),
            DefineBinaryData: (87, self.writeDefineBinaryDataTag),
            DefineBits: (6, self.writeDefineBitsTag),
            DefineBitsJPEG2: (21, self.writeDefineBitsJPEG2Tag), 
            DefineBitsJPEG3: (35, self.writeDefineBitsJPEG3Tag), 
            DefineBitsJPEG4: (90, self.writeDefineBitsJPEG4Tag),
            DefineBitsLossless: (20, self.writeDefineBitsLosslessTag), 
            DefineBitsLossless2: (36, self.writeDefineBitsLossless2Tag),
            DefineButton: (7, self.writeDefineButtonTag),
            DefineButton2: (34, self.writeDefineButton2Tag),
            DefineButtonCxform: (23, self.writeDefineButtonCxformTag),
            DefineButtonSound: (17, self.writeDefineButtonSoundTag),
            DefineEditText: (37, self.writeDefineEditTextTag),
            DefineFont: (10, self.writeDefineFontTag),
            DefineFont2: (48, self.writeDefineFont2Tag),
            DefineFont3: (75, self.writeDefineFont3Tag),
            DefineFont4: (91, self.writeDefineFont4Tag),
            DefineFontAlignZones: (73, self.writeDefineFontAlignZonesTag),
            DefineFontInfo: (13, self.writeDefineFontInfoTag),
            DefineFontInfo2: (62, self.writeDefineFontInfo2Tag),
            DefineFontName: (88, self.writeDefineFontNameTag),
            DefineMorphShape: (46, self.writeDefineMorphShapeTag),
            DefineMorphShape2: (84, self.writeDefineMorphShape2Tag), 
            DefineSceneAndFrameLabelData: (86, self.writeDefineSceneAndFrameLabelDataTag), 
            DefineShape: (2, self.writeDefineShapeTag),
            DefineShape2: (22, self.writeDefineShape2Tag),
            DefineShape3: (32, self.writeDefineShape3Tag),
            DefineShape4: (83, self.writeDefineShape4Tag),
            DefineSprite: (39, self.writeDefineSpriteTag),
            DefineText: (11, self.writeDefineTextTag),
            DefineText2: (33, self.writeDefineText2Tag),
            DefineSound: (14, self.writeDefineSoundTag),
            DefineVideoStream: (60, self.writeDefineVideoStreamTag),
            DoInitAction: (59, self.writeDoInitActionTag),
            DoABC: (82, self.writeDoABCTag),
            DoAction: (12, self.writeDoActionTag),
            EnableDebugger: (58, self.writeEnableDebuggerTag),
            EnableDebugger2: (64, self.writeEnableDebugger2Tag),
            End: (0, self.writeEndTag),
            ExportAssets: (56, self.writeExportAssetsTag),
            FileAttributes: (69, self.writeFileAttributesTag),
            FrameLabel: (43, self.writeFrameLabelTag),
            ImportAssets: (57, self.writeImportAssetsTag),
            ImportAssets2: (71, self.writeImportAssets2Tag),
            JPEGTables: (8, self.writeJPEGTablesTag),
            Metadata: (77, self.writeMetadataTag),
            Protect: (24, self.writeProtectTag),
            PlaceObject: (4, self.writePlaceObjectTag),
            PlaceObject2: (26, self.writePlaceObject2Tag),
            PlaceObject3: (70, self.writePlaceObject3Tag),
            RemoveObject: (5, self.writeRemoveObjectTag),
            RemoveObject2: (28, self.writeRemoveObject2Tag),
            ScriptLimits: (65, self.writeScriptLimitsTag),
            SetBackgroundColor: (9, self.writeSetBackgroundColorTag),
            SetTabIndex: (66, self.writeSetTabIndexTag),
            ShowFrame: (1, self.writeShowFrameTag),
            StartSound: (15, self.writeStartSoundTag),
            StartSound2: (89, self.writeStartSound2Tag),
            SoundStreamHead: (18, self.writeSoundStreamHeadTag),
            SoundStreamHead2: (45, self.writeSoundStreamHead2Tag),
            SoundStreamBlock: (19, self.writeSoundStreamBlockTag),
            SymbolClass: (76, self.writeSymbolClassTag),
            VideoFrame: (61, self.writeVideoFrameTag)
        }
        
    def assemble(self, destination, swf_file):
        self.destination = destination
        self.byte_buffer = None
        self.bytes_remaining = 8
        self.buffer_stack = []
        self.bit_buffer = 0
        self.bits_remaining = 0
        self.compressor = None

        # convert all tags to generic ones first so we know the total length
        # names = [tag.__class__.__name__ for tag in swf_file.tags]
        generic_tags = [tag if isinstance(tag, Generic) else self.createGenericTag(tag) for tag in swf_file.tags];
        
        # signature
        signature = swf_file.version << 24   
        signature |= 0x535743 if swf_file.compressed else 0x535746
        self.writeUI32(signature)
                
        # file length
        file_length = 8 + (((swf_file.frame_size.num_bits * 4 + 5) + 7) >> 3) + 4
        for tag in generic_tags:
            file_length += tag.header_length + tag.length
        self.writeUI32(file_length)
        
        if swf_file.compressed:
            # start compressing data
            self.startCompression()
            
        self.writeRect(swf_file.frame_size)

        # frame rate and count
        self.writeUI16(swf_file.frame_rate)
        self.writeUI16(swf_file.frame_count)
        
        for tag in generic_tags:
            self.writeTag(tag)
        
        if swf_file.compressed:
            self.stopCompression()    
            
        self.destination = None

    def createGenericTag(self, tag):
        tag_code, handler = self.tag_codes_and_handlers[tag.__class__]
        self.startBuffer()
        handler(tag)
        data = self.endBuffer()
        generic_tag = Generic()
        generic_tag.code = tag_code
        generic_tag.data = data        
        generic_tag.length = len(data)
        # use the short format only for tags with no data--just to be safe
        generic_tag.header_length = 6 if generic_tag.length > 0 else 2
        return generic_tag
    
    def writeTag(self, tag):
        if not isinstance(tag, Generic):
            tag = self.createGenericTag(tag)
        if tag.header_length == 2:
            tag_code_and_length = (tag.code << 6) | tag.length
            self.writeUI16(tag_code_and_length)
        else:
            tag_code_and_length = (tag.code << 6) | 0x003F
            self.writeUI16(tag_code_and_length)
            self.writeUI32(tag.length)
        self.writeBytes(tag.data)
        
    def writeCSMTextSettingsTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUB(tag.renderer, 2)
        self.writeUB(tag.gridFit, 3)
        self.writeUB(tag.reserved1, 3)
        self.writeF32(tag.thickness)
        self.writeF32(tag.sharpness)
        self.writeUI8(tag.reserved2)
    
    def writeDefineBinaryDataTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI32(tag.reserved)
        self.writeBytes(tag.data)
    
    def writeDefineBitsTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeBytes(tag.image_data)
    
    def writeDefineBitsLosslessTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(tag.format)
        self.writeUI16(tag.width)
        self.writeUI16(tag.height)
        if tag.format == 3:
            self.writeUI8(tag.color_table_size)
        self.writeBytes(tag.image_data)
    
    def writeDefineBitsLossless2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(tag.format)
        self.writeUI16(tag.width)
        self.writeUI16(tag.height)
        if tag.format == 3:
            self.writeUI8(tag.colorTableSize)
        self.writeBytes(tag.image_data)

    def writeDefineBitsJPEG2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeBytes(tag.image_data)

    def writeDefineBitsJPEG3Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI32(len(tag.image_data))
        self.writeBytes(tag.image_data)
        self.writeBytes(tag.alpha_data)

    def writeDefineBitsJPEG4Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI32(len(tag.image_data))
        self.writeUI16(tag.deblockingParam)
        self.writeBytes(tag.image_data)
        self.writeBytes(tag.alpha_data)
    
    def writeDefineButtonTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeButtonRecords(tag.characters, 1)        
        self.writeBytes(tag.actions)
    
    def writeDefineButton2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(tag.flags)
        if tag.actions != None:
            self.startBuffer()
            self.writeButtonRecords(tag.characters, 2)
            data = self.endBuffer()
            action_offset = len(data) + 2
            self.writeUI16(action_offset)
            self.writeBytes(data)
            self.writeBytes(tag.actions)
        else:
            self.writeUI16(0)
            self.writeButtonRecords(tag.characters, 2)
    
    def writeDefineButtonCxformTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeColorTransform(tag.color_transform)
    
    def writeDefineButtonSoundTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI16(tag.over_up_to_idle_id)
        if tag.over_up_to_idle_id != 0:
            self.writeSoundInfo(tag.over_up_to_idle_info)
        self.writeUI16(tag.idle_to_over_up_id)
        if tag.idle_to_over_up_id != 0:
            self.writeSoundInfo(tag.idle_to_over_up_info)
        self.writeUI16(tag.over_up_to_over_down_id)
        if tag.over_up_to_over_down_id != 0:
            self.writeSoundInfo(tag.over_up_to_over_down_info)
        self.writeUI16(tag.over_down_to_over_up_id)
        if tag.over_down_to_over_up_id != 0:
            self.writeSoundInfo(tag.over_down_to_over_up_info)
    
    def writeDefineEditTextTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.bounds)
        self.writeUI16(tag.flags)        
        if tag.flags & 0x0001:  # HasFont
            self.writeUI16(tag.font_id)
            self.writeUI16(tag.font_height)            
        if tag.flags & 0x8000:  # HasFontClass
            self.writeString(tag.font_class)
        if tag.flags & 0x0004:  # HasTextColor
            self.writeRGBA(tag.text_color)
        if tag.flags & 0x0002:  # HasMaxLength
            self.writeUI16(tag.maxLength)
        if tag.flags & 0x2000:  # HasLayout
            self.writeUI8(tag.align)
            self.writeUI16(tag.leftMargin)
            self.writeUI16(tag.rightMargin)
            self.writeUI16(tag.indent)
            self.writeUI16(tag.leading)
            self.writeString(tag.variable_name)
        if tag.flags & 0x0080:  # HasText
            self.writeString(tag.initialText)
    
    def writeDefineFontTag(self, tag):
        glyph_count = len(tag.glyph_table)
        offset = glyph_count << 1 
        self.writeUI16(tag.character_id)
        shape_table = []
        for glyph in tag.glyph_table:
            self.writeUI16(offset)
            self.startBuffer()
            self.writeShape(glyph)
            data = self.endBuffer()
            shape_table.append(data)
            offset += len(data)
        for data in shape_table:
            self.writeBytes(data)    
    
    def writeDefineFont2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(tag.flags)
        self.writeUI8(tag.language_code)
        self.writeUI8(len(tag.name))
        self.writeBytes(tag.name)
        glyph_count = len(tag.glyph_table)
        self.writeUI16(glyph_count)        
        
        shape_table = []
        if tag.flags & 0x08:    # WideOffsets
            offset = glyph_count * 4 + 4 
            for glyph in tag.glyph_table:
                self.writeUI32(offset)
                self.startBuffer()
                self.writeShape(glyph)
                data = self.endBuffer()
                shape_table.append(data)
                offset += len(data)
            self.writeUI32(offset)
        else:
            offset = glyph_count * 2 + 2 
            for glyph in tag.glyph_table:
                self.writeUI16(offset)
                self.startBuffer()
                self.writeShape(glyph)
                data = self.endBuffer()
                shape_table.append(data)
                offset += len(data)
            self.writeUI16(offset)
            
        for data in shape_table:
            self.writeBytes(data)
                
        if tag.flags & 0x04:    # WideCodes
            for code in tag.code_table:
                self.writeUI16(code)
        else:
            for code in tag.code_table:
                self.writeUI8(code)
                        
        if tag.flags & 0x80 or tag.ascent != None:    # HasLayout
            self.writeSI16(tag.ascent)
            self.writeSI16(tag.descent)
            self.writeSI16(tag.leading)
            for advance in tag.advance_table:
                self.writeUI16(advance)
            for bound in tag.bound_table:
                self.writeRect(bound)
            if tag.flags & 0x04:    # WideCodes
                self.writeWideKerningRecords(tag.kerning_table)
            else:
                self.writeKerningRecords(tag.kerning_table)
    
    def writeDefineFont3Tag(self, tag):
        self.writeDefineFont2Tag(tag)
    
    def writeDefineFont4Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(tag.flags)
        self.writeString(tag.name)
        self.writeBytes(tag.cff_data)
    
    def writeDefineFontAlignZonesTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUB(tag.table_hint, 2)
        self.writeZoneRecords(tag.zone_table)
    
    def writeDefineFontInfoTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(len(tag.name))
        self.writeBytes(tag.name)
        self.writeUI8(tag.flags)        
        if tag.flags & 0x01:    # WideCodes
            for code in tag.code_table:
                self.writeUI16(code) 
        else:
            for code in tag.code_table:
                self.writeUI8(code) 
    
    def writeDefineFontInfo2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI8(len(tag.name))
        self.writeBytes(tag.name)
        self.writeUI8(tag.flags)
        self.writeUI8(tag.languageCode)        
        if tag.flags & 0x01:    # WideCodes
            for code in tag.code_table:
                self.writeUI16(code) 
        else:
            for code in tag.code_table:
                self.writeUI8(code) 
    
    def writeDefineFontNameTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeString(tag.name)
        self.writeString(tag.copyright)
    
    def writeDefineMorphShapeTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.start_bounds)
        self.writeRect(tag.end_bounds)
        self.writeMorphShapeWithStyle(tag.morph_shape, 3)        # use structures of DefineShape3
    
    def writeDefineMorphShape2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.start_bounds)
        self.writeRect(tag.end_bounds)
        self.writeRect(tag.start_edge_bounds)
        self.writeRect(tag.end_edge_bounds)
        self.writeUI8(tag.flags)
        self.writeMorphShapeWithStyle(tag.morph_shape, 4)        # use structures of DefineShape4
    
    def writeDefineScalingGridTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.splitter)
    
    def writeDefineSceneAndFrameLabelDataTag(self, tag):
        self.writeEncUI32StringTable(tag.scene_names)
        self.writeEncUI32StringTable(tag.frame_labels)
    
    def writeDefineShapeTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.shape_bounds)
        self.writeShapeWithStyle(tag.shape, 1)
    
    def writeDefineShape2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.shape_bounds)
        self.writeShapeWithStyle(tag.shape, 2)
    
    def writeDefineShape3Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.shape_bounds)
        self.writeShapeWithStyle(tag.shape, 3)
    
    def writeDefineShape4Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.shape_bounds)
        self.writeRect(tag.edge_bounds)
        self.writeUI8(tag.flags)
        self.writeShapeWithStyle(tag.shape, 4)
    
    def writeDefineSoundTag(self, tag):
        self.writeUB(tag.format, 4)
        self.writeUB(tag.sampleRate, 2)
        self.writeUB(tag.sampleSize, 1)
        self.writeUB(tag.type, 1)
        self.writeUI32(tag.sample_count)
        self.writeBytes(tag.data)
    
    def writeDefineSpriteTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI16(tag.frame_count)
        for child in tag.tags:
            self.writeTag(child)
    
    def writeDefineTextTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.bounds)
        self.writeMatrix(tag.matrix)
        self.writeUI8(tag.glyph_bits)
        self.writeUI8(tag.advance_bits)
        self.writeTextRecords(tag.text_records, tag.glyph_bits, tag.advance_bits, 1)
    
    def writeDefineText2Tag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeRect(tag.bounds)
        self.writeMatrix(tag.matrix)
        self.writeUI8(tag.glyph_bits)
        self.writeUI8(tag.advance_bits)
        self.writeTextRecords(tag.text_records, tag.glyph_bits, tag.advance_bits, 2)
    
    def writeDefineVideoStreamTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI16(tag.frame_count)
        self.writeUI16(tag.width)
        self.writeUI16(tag.height)
        self.writeUI8(tag.flags)
        self.writeUI8(tag.codecId)
    
    def writeDoABCTag(self, tag):
        self.writeUI32(tag.flags)
        self.writeString(tag.byte_code_name)
        self.writeBytes(tag.byte_codes)
    
    def writeDoActionTag(self, tag):
        self.writeBytes(tag.actions)
    
    def writeDoInitActionTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeBytes(tag.actions)
    
    def writeEndTag(self, tag):
        pass
    
    def writeEnableDebuggerTag(self, tag):
        self.writeString(tag.password)
    
    def writeEnableDebugger2Tag(self, tag):
        self.writeUI16(tag.reserved)
        self.writeString(tag.password)
    
    def writeExportAssetsTag(self, tag):
        self.writeStringTable(tag.names)
    
    def writeFileAttributesTag(self, tag):
        self.writeUI32(tag.flags)
    
    def writeFrameLabelTag(self, tag):
        self.writeString(tag.name)
        if tag.anchor != None:
            tag.writeString(tag.anchor)
    
    def writeImportAssetsTag(self, tag):
        self.writeString(tag.url)
        self.writeStringTable(tag.names)
    
    def writeImportAssets2Tag(self, tag):
        self.writeString(tag.url)
        self.writeUI8(tag.reserved1)
        self.writeUI8(tag.reserved2)
        self.writeStringTable(table.names)
    
    def writeJPEGTablesTag(self, tag):
        self.writeBytes(tag.jpeg_data)
    
    def writeMetadataTag(self, tag):
        self.writeString(tag.metadata)
    
    def writePlaceObjectTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI16(tag.depth)
        self.writeMatrix(tag.matrix)
        if tag.color_transform != None:
            self.writeColorTransform(tag.color_transform)
    
    def writePlaceObject2Tag(self, tag):
        self.writeUI8(tag.flags)
        self.writeUI16(tag.depth)
        if tag.flags & 0x02:    # HasCharacter
            self.writeUI16(tag.character_id)
        if tag.flags & 0x04:    # HasMatrix
            self.writeMatrix(tag.matrix)
        if tag.flags & 0x08:    # HasColorTransform
            self.writeColorTransformAlpha(tag.color_transform)
        if tag.flags & 0x10:    # HasRatio
            self.writeUI16(tag.ratio)
        if tag.flags & 0x20:    # HasName
            self.writeString(tag.name)
        if tag.flags & 0x40:    # HasClipDepth
            self.writeUI16(tag.clipDepth)
        if tag.flags & 0x80:    # HasClipActions
            self.writeUI16(0)
            if self.swf_version >= 6:
                self.writeUI32(tag.allEventFlags)
            else:
                self.writeUI16(tag.allEventFlags)
            self.writeClipActions(tag.clip_actions)
    
    def writePlaceObject3Tag(self, tag):
        self.writeUI16(tag.flags)
        self.writeUI16(tag.depth)
        if tag.flags & 0x0800:  # HasClassName
            self.writeString(tag.class_name)
        if tag.flags & 0x0002:  # HasCharacter
            self.writeUI16(tag.character_id)
        if tag.flags & 0x0004:  # HasMatrix
            self.writeMatrix(tag.matrix)
        if tag.flags & 0x0008:  # HasColorTransform
            self.writeColorTransformAlpha(tag.color_transform)
        if tag.flags & 0x0010:  # HasRatio
            self.writeUI16(tag.ratio)
        if tag.flags & 0x0020:  # HasName
            self.writeString(tag.name)
        if tag.flags & 0x0040:  # HasClipDepth
            self.writeUI16(tag.clipDepth)
        if tag.flags & 0x0100:  # HasFilterList
            self.writeFilters(tag.filters)            
        if tag.flags & 0x0200:  # HasBlendMode
            self.writeUI8(tag.blend_mode)
        if tag.flags & 0x0400:  # HasCacheAsBitmap
            self.writeUI8(tag.bitmapCache)
        if tag.flags & 0x0080:  # HasClipActions
            self.writeClipActions(tag.clip_actions)
        if tag.flags & 0x2000:  # HasVisibility
            self.writeUI8(tag.visibility)
        if tag.flags & 0x4000:  # HasBackgroundColor
            self.writeRGBA(tag.bitmap_cache_background_color)
        if tag.flags & 0x0080:  # HasClipActions
            self.writeUI16(0)
            if self.swf_version >= 6:
                self.writeUI32(tag.allEventFlags)
            else:
                self.writeUI16(tag.allEventFlags)
            self.writeClipActions(tag.clip_actions)
    
    def writeProtectTag(self, tag):
        self.writeString(tag.password)
    
    def writeRemoveObjectTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeUI16(tag.depth)
    
    def writeRemoveObject2Tag(self, tag):
        self.writeUI16(tag.depth)
    
    def writeScriptLimitsTag(self, tag):
        self.writeUI16(tag.max_recursion_depth)
        self.writeUI16(tag.script_timeout_seconds)
    
    def writeSetBackgroundColorTag(self, tag):
        self.writeRGB(tag.color)
    
    def writeSetTabIndexTag(self, tag):
        self.writeUI16(tag.depth)
        self.writeUI16(tag.tab_index)
    
    def writeShowFrameTag(self, tag):
        pass
        
    def writeSoundStreamBlockTag(self, tag):
        self.writeBytes(tag.data)
    
    def writeSoundStreamHeadTag(self, tag):
        self.writeUI16(tag.flags)
        self.writeUI16(tag.sample_count)
        if tag.flags & 0xF000 == 0x2000:
            self.writeS16(tag.latency_seek)
    
    def writeSoundStreamHead2Tag(self, tag):
        self.writeUB(0, 4)
        self.writeUB(tag.playback_sample_rate, 2)
        self.writeUB(tag.playback_sample_size, 1)
        self.writeUB(tag.playback_type, 1)
        self.writeUB(tag.format, 4)
        self.writeUB(tag.sample_rate, 2)
        self.writeUB(tag.sample_size, 1)
        self.writeUB(tag.type, 1)
        self.writeUI16(tag.sample_count)
        if tag.format == 2:
            self.writeS16(tag.latency_seek)
    
    def writeStartSoundTag(self, tag):
        self.writeUI16(tag.character_id)
        self.writeSoundInfo(tag.info)
    
    def writeStartSound2Tag(self, tag):
        self.writeString(tag.class_name)
        self.writeSoundInfo(tag.info)
    
    def writeSymbolClassTag(self, tag):
        self.writeStringTable(tag.names)
    
    def writeVideoFrameTag(self, tag):
        self.writeUI16(tag.stream_id)
        self.writeUI16(tag.frame_number)
        self.writeBytes(tag.data)

    def writeZoneRecords(self, records):
        for record in records:
            self.writeUI8(1)    # number of zone data--always 1
            self.writeUI16(record.zone_data1)
            self.writeUI16(record.zone_data2)
            self.writeUI8(record.flags)
            self.writeUI16(record.alignment_coordinate)
            self.writeUI16(record.range)
    
    def writeKerningRecords(self, kerns):
        self.writeUI16(len(kerns))
        for kern in kerns:            
            self.writeUI8(kern.code1)
            self.writeUI8(kern.code2)
            self.writeUI16(kern.adjustment)
    
    def writeWideKerningRecords(self, kerns):
        self.writeUI16(len(kerns))
        for kern in kerns:            
            self.writeUI16(kern.code1)
            self.writeUI16(kern.code2)
            self.writeUI16(kern.adjustment)
    
    def writeClipActions(self, clip_actions):
        clip_actions = []
        for clip_action in clip_actions:
            if self.swf_version >= 6:
                self.writeUI32(clip_action.eventFlags)
            else:
                self.writeUI16(clip_action.eventFlags)
            self.writeUI32(len(clip_action.actions))
            if clip_action.eventFlags & 0x00020000:    # KeyPress
                self.writeUI8(clip_action.keyCode)
            self.writeBytes(clip_action.actions)
        if self.swf_version >= 6:
            self.writeUI32(0)
        else:
            self.writeUI16(0)
    
    def writeFilters(self, filters):
        self.writeUI8(len(filters))
        for f in range(filters):
            if isinstance(f, DropShadowFilter):
                drop_shadow = f
                self.writeUI8(0)
                self.writeRGBA(drop_shadow.shadowColor)
                self.writeSI32(drop_shadow.blur_x)
                self.writeSI32(drop_shadow.blur_y)
                self.writeSI32(drop_shadow.angle)
                self.writeSI32(drop_shadow.distance)
                self.writeSI16(drop_shadow.strength)
                self.writeUB(drop_shadow.flags, 3)
                self.writeUB(drop_shadow.passes, 5)
            elif isinstance(f, BlurFilter):
                blur = f
                self.writeUI8(1)
                self.writeSI32(blur.blur_x)
                self.writeSI32(blur.blur_y)
                self.writeUB(blur.passes, 5)
            elif isinstance(f, GlowFilter):
                glow = f
                self.writeUI8(2)
                self.writeRGBA(glow.color)
                self.writeSI32(glow.blur_x)
                self.writeSI32(glow.blur_y)
                self.writeSI16(glow.strength)
                self.writeUB(glow.flags, 3)
                self.writeUB(glow.passes, 5)
            elif isinstance(f, BevelFilter):
                bevel = f
                self.writeUI8(3)
                self.writeRGBA(bevel.highlight_color)
                self.writeRGBA(bevel.shadow_color)
                self.writeSI32(bevel.blur_x)
                self.writeSI32(bevel.blur_y)
                self.writeSI32(bevel.angle)
                self.writeSI32(bevel.distance)
                self.writeSI16(bevel.strength)
                self.writeUB(bevel.flags, 4)
                self.writeUB(bevel.passes, 4)
            elif isinstance(f, GradientGlowFilter):
                gradient_glow = f
                self.writeUI8(4)
                self.writeUI8(len(gradient_glow.colors))
                for rgb in gradient_glow.colors:
                    self.writeRGBA(rgb)
                for ratio in gradient_glow.ratios:
                    self.writeUI8(ratio)
                self.writeSI32(gradient_glow.blur_x)
                self.writeSI32(gradient_glow.blur_y)
                self.writeSI32(gradient_glow.angle)
                self.writeSI32(gradient_glow.distance)
                self.writeSI16(gradient_glow.strength)
                self.writeUB(gradient_glow.flags, 4)
                self.writeUB(gradient_glow.passes, 4)
            elif isinstance(f, ConvolutionFilter):
                convolution = f
                self.writeUI8(5)
                self.writeUI8(convolution.matrix_x)
                self.writeUI8(convolution.matrix_y)
                self.writeFloat(convolution.divisor)
                self.writeFloat(convolution.bias)
                for m in convolution.matrix:
                    self.writeFloat(m)
                self.writeRGBA(convolution.default_color)
                self.writeUI8(convolution.flags)
            elif isinstance(f, ColorMatrixFilter):
                color_matrix = f
                self.writeUI8(6)
                color_matrix.matrix = []
                for _ in range(20):
                    color_matrix.matrix.append(self.writeFloat())
                filters.append(color_matrix)
            elif isinstance(f, GradientBevelFilter):
                gradient_bevel = f
                self.writeUI8(7)
                self.writeUI8(len(gradient_bevel.colors))
                for rgb in gradient_bevel.colors:
                    self.writeRGBA(rgb)
                for ratio in gradient_bevel.ratios:
                    self.writeUI8(ratio)
                self.writeSI32(gradient_bevel.blur_x)
                self.writeSI32(gradient_bevel.blur_y)
                self.writeSI32(gradient_bevel.angle)
                self.writeSI32(gradient_bevel.distance)
                self.writeSI16(gradient_bevel.strength)
                self.writeUB(gradient_bevel.flags, 4)
                self.writeUB(gradient_bevel.passes, 4)

    def writeTextRecords(self, records, glyph_bits, advance_bits, version):
        for record in records:
            self.writeUI8(record.flags)
            if record.flags & 0x08:     # HasFont
                self.writeUI16(record.font_id)
            if record.flags & 0x04:     # HasColor
                if version >= 2:
                    self.writeRGBA(record.textColor)
                else:
                    self.writeRGB(record.textColor)
            if record.flags & 0x02:     # HasXOffset
                self.writeSI16(record.x_offset)
            if record.flags & 0x01:     # HasYOffset
                self.writeSI16(record.y_offset)
            if record.flags & 0x08:     # HasFont
                self.writeUI16(record.text_height)
            self.writeGlyphEntries(record.glyphs, glyph_bits, advance_bits)
        self.writeUI8(0)
    
    def writeGlyphEntries(self, glyphs, glyph_bits, advance_bits):
        self.writeUI8(len(glyphs))
        for glyph in glyphs:
            self.writeUB(glyph.index, glyph_bits)
            self.writeUB(glyph.advance, advance_bits)

    def writeSoundInfo(self, info):
        self.writeUI8(info.flags)
        if info.flags & 0x01:   # HasInPoint
            self.writeUI32(info.in_point)
        if info.flags & 0x02:   # HasOutPoint
            self.writeUI32(info.out_point)
        if info.flags & 0x04:   # HasLoops
            self.writeUI32(info.loop_count)
        if info.flags & 0x08:   # HasEnvelope
            self.writeSoundEnvelopes(info.envelopes)
    
    def writeSoundEnvelopes(self, envelopes):
        self.writeUI8(len(envelopes))
        for envelope in envelopes:
            self.writeUI32(envelope.position_44)
            self.writeUI16(envelope.left_level)
            self.writeUI16(envelope.right_level)
    
    def writeButtonRecords(self, records, version):
        for record in records:
            self.writeUI8(record.flags)
            self.writeUI16(record.character_id)
            self.writeUI16(record.place_depth)
            self.writeMatrix(record.matrix)
            if version == 2:
                self.writeColorTransformAlpha(record.color_transform)
            if version == 2 and record.flags & 0x10:    # HasFilterList
                self.writeFilters(record.filters)
            if version == 2 and record.flags & 0x20:    # HasBlendMode
                self.writeUI8(record.blend_mode)
        self.writeUI8(0)    
    
    def writeShape(self, shape):
        self.writeUB(shape.num_fill_bits, 4)
        self.writeUB(shape.num_line_bits, 4)
        self.writeShapeRecords(shape.edges, shape.num_fill_bits, shape.num_line_bits, 1)
    
    def writeShapeWithStyle(self, shape, version):
        self.writeFillStyles(shape.fill_styles, version)
        self.writeLineStyles(shape.line_styles, version)
        self.writeUB(shape.num_fill_bits, 4)
        self.writeUB(shape.num_line_bits, 4)
        self.writeShapeRecords(shape.edges, shape.num_fill_bits, shape.num_line_bits, version)
    
    def writeMorphShapeWithStyle(self, shape, version):
        self.startBuffer()
        self.writeMorphFillStyles(shape.fill_styles)
        self.writeMorphLineStyles(shape.line_styles, version)
        self.writeUB(shape.start_num_fill_bits, 4)
        self.writeUB(shape.start_num_line_bits, 4)
        self.writeShapeRecords(shape.startEdges, shape.start_num_fill_bits, shape.start_num_line_bits, version)
        data = self.endBuffer()
        end_edges_offset = len(data)
        self.writeUI32(end_edges_offset)
        self.writeUB(shape.end_num_fill_bits, 4)
        self.writeUB(shape.end_num_line_bits, 4)        
        self.writeShapeRecords(shape.endEdges, shape.end_num_fill_bits, shape.end_num_line_bits, version)
        
    def writeShapeRecords(self, records, num_fill_bits, num_line_bits, version):
        for record in records:
            if isinstance(record, StraightEdge):
                line = record
                self.writeUB(0x03, 2)     # straight edge
                self.writeUB(line.num_bits - 2, 4)
                if line.delta_x != 0 and line.delta_y != 0:
                    self.writeUB(0x01, 1)     # general line
                    self.writeSB(line.delta_x, line.num_bits)
                    self.writeSB(line.delta_y, line.num_bits)
                else:
                    if line.delta_x != 0:
                        self.writeUB(0x00, 2)    # horizontal
                        self.writeSB(line.delta_x, line.num_bits)
                    else:
                        self.writeUB(0x01, 2)    # vertical 
                        self.writeSB(line.delta_y, line.num_bits)
            elif isinstance(record, QuadraticCurve):
                curve = record
                self.writeUB(0x02, 2)     # curve
                self.writeUB(curve.num_bits - 2, 4)
                self.writeSB(curve.control_delta_x, curve.num_bits)
                self.writeSB(curve.control_delta_y, curve.num_bits)
                self.writeSB(curve.anchor_delta_x, curve.num_bits)
                self.writeSB(curve.anchor_delta_y, curve.num_bits)
            elif isinstance(record, StyleChange):
                self.writeUB(0x00, 1)   # change style    
                change = record
                flags = 0x00
                if change.num_move_bits != None:
                    flags |= 0x01    # HasMove
                if change.fill_style0 != None:
                    flags |= 0x02   # HasFillStyle0
                if change.fill_style1 != None:
                    flags |= 0x04   # HasFillStyle1
                if change.line_style != None:
                    flags |= 0x08   # HasLineStyle
                if change.num_fill_bits != None:
                    flags |= 0x10   # HasNewStyles
                self.writeUB(flags, 5)
                if flags & 0x01:    # HasMove
                    self.writeSB(change.num_move_bits, 5)
                    self.writeSB(change.move_delta_x, change.num_move_bits)
                    self.writeSB(change.move_delta_y, change.num_move_bits)
                if flags & 0x02:    # HasFillStyle0
                    self.writeUB(change.fill_style0, num_fill_bits)
                if flags & 0x04:    # HasFillStyle1
                    self.writeUB(change.fill_style1, num_fill_bits)
                if flags & 0x08:    # HasLineStyle
                    self.writeUB(change.line_style, num_line_bits)
                if flags & 0x10:    # HasNewStyles
                    self.writeFillStyles(change.new_fill_styles, version)
                    self.writeLineStyles(change.new_line_styles, version)
                    self.writeUB(change.num_fill_bits, 4)
                    self.writeUB(change.num_line_bits, 4)
                    num_fill_bits = change.num_fill_bits
                    num_line_bits = change.num_line_bits
        self.writeUB(0x00, 6)
        self.alignToByte()

    def writeFillStyles(self, styles, version):
        count = len(styles)
        if count < 255:
            self.writeUI8(count)
        else: 
            self.writeUI8(0xFF)
            self.writeUI16(count)
        for style in styles:
            self.writeFillStyle(style, version)

    def writeFillStyle(self, style, version):
        self.writeUI8(style.type)
        if style.type == 0x00:
            if version >= 3:
                self.writeRGBA(style.color)
            else:
                self.writeRGB(style.color)
        elif style.type in (0x10, 0x12, 0x13):
            self.writeMatrix(style.gradient_matrix)
            if style.type == 0x13:
                self.writeFocalGradient(style.gradient, version)
            else:
                self.writeGradient(style.gradient, version)
        elif style.type in (0x40, 0x41, 0x42, 0x43):
            self.writeUI16(style.bitmap_id)
            self.writeMatrix(style.bitmap_matrix)
    
    def writeMorphFillStyles(self, styles):
        count = len(styles)
        if count < 255:
            self.writeUI8(count)
        else: 
            self.writeUI8(0xFF)
            self.writeUI16(count)
        for style in styles:
            self.writeMorphFillStyle(style)
    
    def writeMorphFillStyle(self, style):
        style = MorphFillStyle()
        self.writeUI8(style.type)
        if style.type == 0x00:
            self.writeRGBA(style.start_color)
            self.writeRGBA(style.end_color)
        elif style.type in (0x10, 0x12):
            self.writeMatrix(style.start_gradient_matrix)
            self.writeMatrix(style.end_gradient_matrix)
            self.writeMorphGradient(style.gradient)
        elif style.type in (0x40, 0x41, 0x42, 0x43):
            self.writeUI16(style.bitmapId)
            self.writeMatrix(style.start_bitmap_matrix)
            self.writeMatrix(style.end_bitmap_matrix)
    
    def writeLineStyles(self, styles, version):
        count = len(styles)
        if count < 255:
            self.writeUI8(count)
        else: 
            self.writeUI8(0xFF)
            self.writeUI16(count)
        for style in styles:
            if version >= 4:
                self.writeLineStyle2(style, version)
            else:
                self.writeLineStyle(style, version)

    def writeLineStyle2(self, style, version):
        self.writeUI16(style.width)
        self.writeUB(style.start_cap_style, 2)
        self.writeUB(style.join_style, 2)        
        self.writeUB(style.flags, 10)
        self.writeUB(style.end_cap_style, 2)
        if style.join_style == 2:   # JoinStyleMiter
            self.writeUI16(style.miterLimitFactor)
        if style.flags & 0x0200:    # HasFill
            self.writeFillStyle(style.fill_style, version)
        else:
            self.writeRGBA(style.color)
    
    def writeLineStyle(self, style, version):
        self.writeUI16(style.width)
        if version >= 3:
            self.writeRGBA(style.color)
        else:
            self.writeRGB(style.color)
    
    def writeMorphLineStyles(self, styles, version):
        count = len(styles)
        if count < 255:
            self.writeUI8(count)
        else: 
            self.writeUI8(0xFF)
            self.writeUI16(count)
        for style in styles:
            if version >= 4:
                self.writeMorphLineStyle2(style)
            else:
                self.writeMorphLineStyle(style)
    
    def writeMorphLineStyle2(self, style):
        self.writeUI16(style.start_width)
        self.writeUI16(style.end_width)
        self.writeUB(style.start_cap_style, 2)
        self.writeUB(style.join_style, 2)        
        self.writeUB(style.flags, 10)
        self.writeUB(style.end_cap_style, 2)
        if style.join_style == 2:   # JoinStyleMiter
            self.writeUI16(style.miterLimitFactor)
        if style.flags & 0x0200:    # HasFill
            self.writeMorphFillStyle(style.fill_style)
        else:
            self.writeRGBA(style.start_color)
            self.writeRGBA(style.end_color)
    
    def writeMorphLineStyle(self, style):
        self.writeUI16(style.start_width)
        self.writeUI16(style.end_width)
        self.writeRGBA(style.start_color)
        self.writeRGBA(style.end_color)
    
    def writeGradient(self, gradient, version):
        self.writeUB(gradient.spread_mode, 2)
        self.writeUB(gradient.interpolation_mode, 2)
        self.writeGradientControlPoints(gradient.control_points, version)
    
    def writeFocalGradient(self, gradient, version):
        self.writeUB(gradient.spread_mode, 2)
        self.writeUB(gradient.interpolation_mode, 2)
        self.writeGradientControlPoints(gradient.control_points, version)
        self.writeSI16(gradient.focal_point)
    
    def writeGradientControlPoints(self, control_points, version):
        self.writeUB(len(control_points), 4)
        for control_point in control_points:
            self.writeUI8(control_point.ratio)
            if version >= 3:
                self.writeRGBA(control_point.color)
            else:
                self.writeRGB(control_point.color)
    
    def writeMorphGradient(self, gradient):
        self.writeUI8(len(gradient.records))
        for record in gradient.records:
            self.writeUI8(record.start_ratio)
            self.writeRGBA(record.start_color)
            self.writeUI8(record.end_ratio)
            self.writeRGBA(record.end_color)
    
    def writeColorTransformAlpha(self, transform):
        has_add_terms = transform.red_add_term != None
        has_mult_terms = transform.red_mult_term != None
        self.writeUB(has_add_terms, 1)
        self.writeUB(has_mult_terms, 1)
        self.writeUB(transform.num_bits, 4)
        if has_mult_terms:
            self.writeSB(transform.red_mult_term, transform.num_bits)
            self.writeSB(transform.green_mult_term, transform.num_bits)
            self.writeSB(transform.blue_mult_term, transform.num_bits)
            self.writeSB(transform.alpha_mult_term, transform.num_bits)
        if has_add_terms:
            self.writeSB(transform.red_add_term, transform.num_bits)
            self.writeSB(transform.green_add_term, transform.num_bits)
            self.writeSB(transform.blue_add_term, transform.num_bits)
            self.writeSB(transform.alpha_add_term, transform.num_bits)
        self.alignToByte()
    
    def writeColorTransform(self, transform):
        has_add_terms = transform.red_add_term != None
        has_mult_terms = transform.red_mult_term != None
        self.writeUB(has_add_terms, 1)
        self.writeUB(has_mult_terms, 1)
        self.writeUB(transform.num_bits, 4)
        if has_mult_terms:
            self.writeSB(transform.red_mult_term, transform.num_bits)
            self.writeSB(transform.green_mult_term, transform.num_bits)
            self.writeSB(transform.blue_mult_term, transform.num_bits)
        if has_add_terms:
            self.writeSB(transform.red_add_term, transform.num_bits)
            self.writeSB(transform.green_add_term, transform.num_bits)
            self.writeSB(transform.blue_add_term, transform.num_bits)
        self.alignToByte()
    
    def writeMatrix(self, matrix):
        if matrix.num_scale_bits != None:        
            self.writeUB(1, 1);
            self.writeUB(matrix.num_scale_bits, 5)
            self.writeSB(matrix.scale_x, matrix.num_scale_bits)
            self.writeSB(matrix.scale_y, matrix.num_scale_bits)
        else:
            self.writeUB(0, 1)
        if matrix.num_rotate_bits != None:
            self.writeUB(1, 1)
            self.writeUB(matrix.num_rotate_bits, 5)
            self.writeSB(matrix.rotate_skew0, matrix.num_rotate_bits)
            self.writeSB(matrix.rotate_skew1, matrix.num_rotate_bits)
        else :
            self.writeUB(0, 1)
        self.writeUB(matrix.num_translate_bits, 5)
        self.writeSB(matrix.translate_x, matrix.num_translate_bits)
        self.writeSB(matrix.translate_y, matrix.num_translate_bits)
        self.alignToByte()

    def writeRect(self, rect):
        self.writeUB(rect.num_bits, 5)
        self.writeSB(rect.left, rect.num_bits)
        self.writeSB(rect.right, rect.num_bits)
        self.writeSB(rect.top, rect.num_bits)
        self.writeSB(rect.bottom, rect.num_bits)
        self.alignToByte()
    
    def writeARGB(self, rgb):
        self.writeUI8(rgb.alpha)
        self.writeUI8(rgb.red)
        self.writeUI8(rgb.green)
        self.writeUI8(rgb.blue)
    
    def writeRGBA(self, rgb):
        self.writeUI8(rgb.red)
        self.writeUI8(rgb.green)
        self.writeUI8(rgb.blue)
        self.writeUI8(rgb.alpha)
        
    def writeRGB(self, rgb):
        self.writeUI8(rgb.red)
        self.writeUI8(rgb.green)
        self.writeUI8(rgb.blue)

    def writeEncUI32StringTable(self, table):    
        self.writeEncUI32(len(table))
        for index, string in table.items():
            self.writeEncUI32(index)
            self.writeString(string)
            
    def writeStringTable(self, table):    
        self.writeUI16(len(table))
        for index, string in table.items():
            self.writeUI16(index)
            self.writeString(string)
            
    def writeString(self, string):
        self.alignToByte()
        data = string.encode()
        self.writeBytes(data)
        self.writeUI8(0)
    
    def alignToByte(self):
        if self.bits_remaining > 0:
            data = self.ui8.pack((self.bit_buffer >> 24) & 0x000000FF)
            self.bits_remaining = 0
            self.bit_buffer = 0
            self.writeBytes(data)
    
    def writeSB(self, value, num_bits):
        if value < 0:
            # mask out the upper bits
            value &= ~(-1 << num_bits)
        self.writeUB(value, num_bits)
    
    def writeUB(self, value, num_bits):
        self.bit_buffer |= (value << (32 - num_bits - self.bits_remaining))
        self.bits_remaining += num_bits
        while self.bits_remaining > 8:
            data = self.ui8.pack((self.bit_buffer >> 24) & 0x000000FF)
            self.bits_remaining -= 8
            self.bit_buffer = ((self.bit_buffer << 8) & (-1 << (32 - self.bits_remaining))) & 0xFFFFFFFF
            self.writeBytes(data)
        
    def writeUI8(self, value):
        '''Write an unsigned 8 bit integer.        
        '''
        self.alignToByte()
        data = self.ui8.pack(value) 
        self.writeBytes(data)
    
    def writeSI16(self, value):
        '''Write an signed 16 bit integer.        
        '''
        self.alignToByte()
        data = self.si16.pack(value) 
        self.writeBytes(data)
    
    def writeUI16(self, value):
        '''Write an unsigned 16 bit integer.        
        '''
        self.alignToByte()
        data = self.ui16.pack(value) 
        self.writeBytes(data)
        
    def writeSI32(self, value):
        '''Write an signed 32 bit integer.        
        '''
        self.alignToByte()
        data = self.si32.pack(value) 
        self.writeBytes(data)
    
    def writeUI32(self, value):
        '''Write an unsigned 32 bit integer.        
        '''
        self.alignToByte()
        data = self.ui32.pack(value)
        self.writeBytes(data)
    
    def writeEncUI32(self, value):
        self.alignToByte()
        if value & 0xFFFFFF80 == 0:
            data = self.ui8.pack(value)
        elif value & 0xFFFFC000 == 0:
            data = self.ui8.pack(value & 0x7F | 0x80, (value >> 7) & 0x7F)
        elif value & 0xFFE00000 == 0:
            data = self.ui8.pack(value & 0x7F | 0x80, (value >> 7) & 0x7F | 0x80, (value >> 14) & 0x7F)
        elif value & 0xF0000000 == 0:
            data = self.ui8.pack(value & 0x7F | 0x80, (value >> 7) & 0x7F | 0x80, (value >> 14) & 0x7F | 0x80,  (value >> 21) & 0x7F)
        else:
            # the last byte can only have four bits
            data = self.ui8.pack(value & 0x7F | 0x80, (value >> 7) & 0x7F | 0x80, (value >> 14) & 0x7F | 0x80,  (value >> 21) & 0x7F | 0x80, (value >> 28) & 0x0F);    
        self.writeBytes(data)
    
    def writeF32(self, value):
        '''Write a 32-bit floating point.
        '''
        self.alignToByte()
        data = self.f32.pack(value) 
        self.writeBytes(data)
        
    def writeF64(self, value):
        '''Write a 64-bit floating point.
        '''
        self.alignToByte()
        data = self.f64.pack(value)
        self.writeBytes(data)
        
    def writeBytes(self, data):
        '''Write a certain number of bytes.
        '''
        if self.byte_buffer != None:
            self.byte_buffer.extend(data)
        else:
            if self.compressor != None:
                data = self.compressor.compress(data)
            self.destination.write(data)
    
    def startBuffer(self):
        '''Start capturing output contents.
        '''
        if self.byte_buffer != None:
            self.buffer_stack.append(self.byte_buffer)
        self.byte_buffer = bytearray()
        
    def endBuffer(self):
        '''Stop buffering output and return captured contents. 
        '''
        data = self.byte_buffer; 
        self.byte_buffer = self.buffer_stack.pop() if len(self.buffer_stack) > 0 else None;
        return data
    
    def startCompression(self):
        '''Begin compressing data using the zlib algorithm. 
        '''
        self.compressor = compressobj()
        
    def stopCompression(self):
        self.alignToByte()
        if self.compressor:
            data = self.compressor.flush()
            self.compressor = None
            self.destination.write(data)
