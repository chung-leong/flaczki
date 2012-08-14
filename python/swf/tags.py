'''
Created on Jul 21, 2012

@author: Chung Leong
'''
  
class Generic(object):
    code = None
    header_length = None
    length = None
    data = None

class Character(object):
    character_id = None

class CSMTextSettings(object):
    character_id = None
    renderer = None
    grid_fit = None
    thickness = None
    sharpness = None
    reserved1 = None
    reserved2 = None

class End(object):
    pass

class DefineBinaryData(Character):
    reserved = None
    data = None
    swf_file = None

class DefineBits(Character):
    image_data = None

class DefineBitsLossless(Character):
    format = None
    width = None
    height = None
    color_table_size = None
    image_data = None

class DefineBitsLossless2(DefineBitsLossless):
    pass

class DefineBitsJPEG2(Character):
    image_data = None

class DefineBitsJPEG3(DefineBitsJPEG2):
    alpha_data = None

class DefineBitsJPEG4(DefineBitsJPEG3):
    deblocking_param = None

class DefineButton(Character):
    characters = None
    actions = None    

class DefineButton2(DefineButton):
    flags = None

class DefineButtonCxform(object):
    character_id = None
    color_transform = None

class DefineButtonSound(object):
    character_id = None
    over_up_to_idle_id = None
    over_up_to_idle_info = None
    idle_to_over_up_id = None
    idle_to_over_up_info = None
    over_up_to_over_down_id = None
    over_up_to_over_down_info = None
    over_down_to_over_up_id = None
    over_down_to_over_up_info = None

class DefineEditText(Character):
    bounds = None
    flags = None
    font_id = None
    font_height = None
    font_class = None
    text_color = None
    max_length = None
    align = None
    left_margin = None
    right_margin = None
    indent = None
    leading = None
    variable_name = None
    initial_text = None

class DefineFont(Character):
    glyph_table = None

class DefineFont2(DefineFont):
    flags = None
    name = None
    ascent = None
    descent = None
    leading = None
    language_code = None
    code_table = None
    advance_table = None
    bound_table = None
    kerning_table = None

class DefineFont3(DefineFont2):
    pass

class DefineFont4(Character):
    flags = None
    name = None
    cff_data = None

class DefineFontAlignZones(object):
    character_id = None
    table_hint = None
    zone_table = None

class DefineFontInfo(object):
    character_id = None
    name = None
    flags = None
    code_table = None

class DefineFontInfo2(DefineFontInfo):
    language_code = None

class DefineFontName(object):
    character_id = None
    name = None
    copyright = None

class DefineMorphShape(Character):
    start_bounds = None
    end_bounds = None
    fill_styles = None
    line_styles = None
    start_edges = None
    end_edges = None

class DefineMorphShape2(DefineMorphShape):
    flags = None
    start_edge_bounds = None
    end_edge_bounds = None

class DefineScalingGrid(object):
    character_id = None
    splitter = None

class DefineSceneAndFrameLabelData(object):
    scene_names = None
    frame_labels = None

class DefineShape(Character):
    shape_bounds = None
    shape = None

class DefineShape2(DefineShape):
    pass

class DefineShape3(DefineShape2):
    pass

class DefineShape4(DefineShape3):
    flags = None
    edge_bounds = None

class DefineSound(Character):
    format = None
    sample_size = None
    sample_rate = None
    type = None
    sample_count = None
    data = None

class DefineSprite(Character):
    frame_count = None
    tags = None

class DefineText(Character):
    bounds = None
    matrix = None
    glyph_bits = None
    advance_bits = None
    text_records = None

class DefineText2(DefineText):
    pass

class DefineVideoStream(Character):
    frame_count = None
    width = None
    height = None
    flags = None
    codec_id = None

class DoABC(object):
    flags = None
    byte_code_name = None
    byte_codes = None
    
    abc_file = None

class DoAction(object):
    actions = None

class DoInitAction(object):
    character_id = None
    actions = None

class EnableDebugger(object):
    password = None

class EnableDebugger2(EnableDebugger):
    reserved = None

class ExportAssets(object):
    names = None

class FileAttributes(object):
    flags = None

class FrameLabel(object):
    name = None
    anchor = None

class ImportAssets(object):
    names = None
    url = None

class ImportAssets2(ImportAssets):
    reserved1 = None
    reserved2 = None    

class JPEGTables(object):
    jpeg_data = None

class Metadata(object):
    metadata = None

class PlaceObject(object):
    character_id = None
    depth = None
    matrix = None
    color_transform = None

class PlaceObject2(PlaceObject):
    flags = None
    ratio = None
    name = None
    clip_depth = None
    clip_actions = None
    all_events_flags = None

class PlaceObject3(PlaceObject2):
    class_name = None
    filters = None
    blend_mode = None
    bitmap_cache = None
    bitmap_cache_background_color = None
    visibility = None

class Protect(object):
    password = None

class RemoveObject(object):
    character_id = None
    depth = None

class RemoveObject2(object):
    depth = None

class ScriptLimits(object):
    max_recursion_depth = None
    script_timeout_seconds = None

class SetBackgroundColor(object):
    color = None

class SetTabIndex(object):
    depth = None
    tab_index = None

class ShowFrame(object):
    pass

class SoundStreamBlock(object):
    data = None

class SoundStreamHead(object):
    playback_sample_size = None
    playback_sample_rate = None
    playback_type = None
    format = None
    sample_size = None
    sample_rate = None
    type = None
    sample_count = None
    latency_seek = None

class SoundStreamHead2(SoundStreamHead):
    pass

class StartSound(object):
    info = None

class StartSound2(object):
    class_name = None
    info = None

class SymbolClass(object):
    names = None

class VideoFrame(object):
    stream_id = None
    frame_number = None
    data = None

class ZoneRecord(object):
    zone_data1 = None
    zone_data2 = None
    flags = None
    alignment_coordinate = None
    range = None

class KerningRecord(object):
    code1 = None
    code2 = None
    adjustment = None

class DropShadowFilter(object):
    shadow_color = None
    highlight_color = None
    blur_x = None
    blur_y = None
    angle = None
    distance = None
    strength = None
    flags = None
    passes = None

class BlurFilter(object):
    blur_x = None
    blur_y = None
    passes = None

class GlowFilter(object):
    color = None
    blur_x = None
    blur_y = None
    strength = None
    flags = None
    passes = None

class BevelFilter(object):
    shadow_color = None
    highlight_color = None
    blur_x = None
    blur_y = None
    angle = None
    distance = None
    strength = None
    flags = None
    passes = None

class GradientGlowFilter(object):
    colors = None
    ratios = None
    blur_x = None
    blur_y = None
    angle = None
    distance = None
    strength = None
    flags = None
    passes = None

class ConvolutionFilter(object):
    matrix_x = None
    matrix_y = None
    divisor = None
    bias = None
    matrix = None
    default_color = None
    flags = None

class ColorMatrixFilter(object):
    matrix = None

class GradientBevelFilter(object):
    colors = None
    ratios = None
    blur_x = None
    blur_y = None
    angle = None
    distance = None
    strength = None
    flags = None
    passes = None
    
class SoundInfo(object):
    flags = None
    in_point = None
    out_point = None
    loop_count = None
    envelopes = None

class SoundEnvelope(object):
    position_44 = None
    left_level = None
    right_level = None

class ButtonRecord(Character):
    flags = None
    place_depth = None
    matrix = None
    color_transform = None
    filters = None
    blend_mode = None

class ClipAction(object):
    event_flags = None
    key_code = None
    actions = None

class GlyphEntry(object):
    index = None
    advance = None

class Shape(object):
    num_fill_bits = None
    num_line_bits = None
    edges = None

class ShapeWithStyle(Shape):
    line_styles = None
    fill_styles = None

class MorphShapeWithStyle(object):
    line_styles = None
    fill_styles = None
    start_num_fill_bits = None
    start_num_line_bits = None
    end_num_fill_bits = None
    end_num_line_bits = None
    start_edges = None
    end_edges = None

class StraightEdge(object):
    num_bits = None
    delta_x = None
    delta_y = None

class QuadraticCurve(object):
    num_bits = None
    control_delta_x = None
    control_delta_y = None
    anchor_delta_x = None
    anchor_delta_y = None

class StyleChange(object):
    num_move_bits = None
    move_delta_x = None
    move_delta_y = None
    fill_style0 = None
    fill_style1 = None
    line_style = None
    new_fill_styles = None
    new_line_styles = None
    num_fill_bits = None
    num_line_bits = None

class TextRecord(object):
    flags = None
    font_id = None
    text_color = None
    x_offset = None
    y_offset = None
    text_height = None
    glyphs = None

class FillStyle(object):
    type = None
    color = None
    gradient_matrix = None
    gradient = None
    bitmap_id = None
    bitmap_matrix = None

class MorphFillStyle(object):
    type = None
    start_color = None
    end_color = None
    start_gradient_matrix = None
    end_gradient_matrix = None
    gradient = None
    bitmap_id = None
    start_bitmap_matrix = None
    end_bitmap_matrix = None

class LineStyle(object):
    width = None
    color = None

class LineStyle2(object):
    width = None
    start_cap_style = None
    end_cap_style = None
    join_style = None
    flags = None
    miter_limit_factor = None
    fill_style = None
    style = None

class MorphLineStyle(object):
    start_width = None
    end_width = None
    start_color = None
    end_color = None

class Gradient(object):
    spread_mode = None
    interpolation_mode = None
    control_points = None
    
class FocalGradient(Gradient):
    focal_point = None

class GradientControlPoint(object):
    ratio = None
    color = None

class MorphGradient(object):
    records = None

class MorphGradientRecord(object):
    start_ratio = None
    start_color = None
    end_ratio = None
    end_color = None

class ColorTransform(object):
    num_bits = None
    red_mult_term = None
    green_mult_term = None
    blue_mult_term = None
    red_add_term = None
    green_add_term = None
    blue_add_term = None

class ColorTransformAlpha(ColorTransform):
    alpha_mult_term = None
    alpha_add_term = None

class Matrix(object):
    num_scale_bits = None
    scale_x = None
    scale_y = None
    num_rotate_bits = None
    rotate_skew0 = None
    rotate_skew1 = None
    num_traslate_bits = None
    translate_x = None
    translate_y = None

class Rect(object):
    num_bits = None
    left = None
    right = None
    top = None
    bottom = None

class RGBA(object):
    red = None
    green = None
    blue = None
    alpha = None

