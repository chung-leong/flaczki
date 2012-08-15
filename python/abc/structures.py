'''
Created on Aug 15, 2012

@author: Chung Leong
'''

class ABCFile(object):
    major_version = None
    minor_version = None
    int_table = None
    uint_table = None
    double_table = None
    string_table = None
    namespace_table = None
    namespace_set_table = None
    multiname_table = None
    method_table = None
    metadata_table = None
    instance_table = None
    class_table = None
    script_table = None
    method_body_table = None

class ABCNamespace(object):
    kind = None
    string_index = None

class ABCNamespaceSet(object):
    namespace_indices = None

class ABCMultiname(object):
    name_type = None
    string_index = None
    namespace_index = None
    namespace_set_index = None
    name_index = None
    type_indices = None

class ABCMethod(object):
    param_count = None
    return_type = None
    param_types = None
    name_index = None
    flags = None
    optional_params = None
    param_name_indices = None
    
    body = None

class ABCMethodOptionalParameter(object):
    flags = None
    index = None

class ABCMetadata(object):
    name_index = None
    key_indices = None
    value_indices = None

class ABCInstance(object):
    name_index = None
    superName_index = None
    flags = None
    protectedNamespace_index = None
    interface_indices = None
    constructor_index = None
    traits = None

class ABCClass(object):
    constructor_index = None
    traits = None

class ABCScript(object):
    initializer_index = None
    traits = None

class ABCMethodBody(object):
    method_index = None
    max_stack = None
    local_count = None
    init_scope_depth = None
    max_scope_depth = None
    byte_codes = None
    exceptions = None
    traits = None

class ABCTrait(object):
    name_index = None
    flags = None
    data = None
    slotId = None
    metadata_indices = None

class ABCTraitSlot(object):
    type_name_index = None
    value_index = None
    value_type = None

class ABCTraitClass(object):
    class_index = None

class ABCTraitFunction(object):
    method_index = None

class ABCTraitMethod(object):
    method_index = None

class ABCException(object):
    from_offset = None
    to_offset = None
    target_offset = None
    type_index = None
    variable_index = None

