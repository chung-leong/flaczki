'''
Created on Aug 15, 2012

@author: Chung Leong
'''
from flaczki.abc.structures import *
from struct import Struct

class ABCParser(object):
	
	def __init__(self):
		self.u8 = Struct('<B')
		self.u16 = Struct('<H')
		self.f64 = Struct('d')

	def parse(self, byte_codes):		
		self.byte_codes = byte_codes
		self.byte_code_index = 0;
		abc_file = ABCFile()
		
		# AVM version info--should be 16.46
		abc_file.major_version = self.readU16()
		abc_file.minor_version = self.readU16()
		
		# signed integer constants (zeroth item is default value)
		int_count = self.readU32()
		abc_file.int_table = [0] + [self.readS32() for _ in range(int_count)]
				
		# unsigned integer constants
		uint_count = self.readU32()
		abc_file.uint_table = [0] + [self.readU32() for _ in range(uint_count)]
				
		# double constants
		double_count = self.readU32()
		abc_file.double_table = [0.0] + [self.readD64() for _ in range(double_count)]
		
		# string constants
		string_count = self.readU32()
		abc_file.string_table = [''] + [self.readString() for _ in range(string_count)]
				
		# namespace constants
		namespace_count = self.readU32()
		default_namespace = ABCNamespace()
		abc_file.namespace_table = [default_namespace] + [self.readNamespace() for _ in range(namespace_count)] 
				
		# namespace-set constants
		namespace_set_count = self.readU32()
		default_namespace_set = ABCNamespaceSet()
		abc_file.namespace_set_table = [default_namespace_set] + [self.readNamespaceSet() for _ in range(namespace_set_count)]
				
		# multiname (i.e. variable name) constants
		multiname_count = self.readU32()
		default_multiname = ABCMultiname()
		abc_file.multiname_table = [default_multiname] + [self.readMultiname() for _ in range(multiname_count)]
		
		# methods 
		method_count = self.readU32()
		abc_file.method_table = [self.readMethod() for _ in range(method_count)]
				
		# metadata
		metadata_count = self.readU32()
		abc_file.metadata_table = [self.readMetadata() for _ in range(metadata_count)]
		
		# class instances
		class_count = self.readU32()
		abc_file.instance_table = [self.readInstance() for _ in range(class_count)]
		abc_file.class_table = [self.readClass() for _ in range(class_count)]
				
		# scripts
		script_count = self.readU32()
		abc_file.script_table = [self.readScript() for _ in range(script_count)]
				
		# method bodies
		method_body_count = self.readU32()
		abc_file.method_body_table = [self.readMethodBody() for _ in range(method_body_count)]
			
		self.input = None
		return abc_file
		
	def readNamespace(self):
		namespace = ABCNamespace()
		namespace.kind = self.readU8()
		namespace.string_index = self.readU32()
		return namespace
		
	def readNamespaceSet(self):
		namespace_set = ABCNamespaceSet()
		namespace_count = self.readU32()
		namespace_set.namespace_indices = [self.readU32() for _ in range(namespace_count)]
		return namespace_set
		
	def readMultiname(self):
		multiname = ABCMultiname()
		multiname.name_type = name_type = self.readU8()		
		if name_type in (0x07, 0x0D):	# CONSTANT_QName, CONSTANT_QNameA
			multiname.namespace_index = self.readU32()
			multiname.string_index = self.readU32()
		elif name_type in (0x0F, 0x10):	# CONSTANT_RTQNamem, CONSTANT_RTQNameA
			multiname.namespace_index = self.readU32()
		elif name_type in (0x11, 0x12):	# CONSTANT_RTQNameL, CONSTANT_RTQNameLA
			pass
		elif name_type in (0x09, 0x0E):	# CONSTANT_Multiname, CONSTANT_MultinameA
			multiname.string_index = self.readU32()
			multiname.namespace_set_index = self.readU32()
		elif name_type in (0x1B, 0x1C): # CONSTANT_MultinameL, CONSTANT_MultinameLA
			multiname.namespace_set_index = self.readU32()
		elif name_type == 0x1D:			# CONSTANT_GENERIC
			multiname.name_index = self.readU32()
			type_count = self.readU32()
			multiname.type_indices = [self.readU32() for _ in range(type_count)]
		return multiname
		
	def readMethod(self):
		method = ABCMethod()
		method.param_count = self.readU32()
		method.return_type = self.readU32()
		method.param_types = [self.readU32() for _ in range(method.param_count)]
		method.name_index = self.readU32()
		method.flags = self.readU8()
		if method.flags & 0x08:		# HAS_OPTIONAL
			opt_param_count = self.readU32()
			method.optional_params = [self.readMethodOptionalParameter() for _ in range(opt_param_count)]
		if method.flags & 0x80:		# HAS_PARAM_NAMES
			method.paramName_indices = [self.readU32() for _ in range(method.param_count)]
		return method
						
	def readMethodOptionalParameter(self):
		parameter = ABCMethodOptionalParameter()
		parameter.index = self.readU32()
		parameter.flags = self.readU8()
		return parameter
		
	def readMetadata(self):
		metadata = ABCMetadata()
		metadata.name_index = self.readU32()
		pair_count = self.readU32()
		indices = [self.readU32() for _ in range(pair_count * 2)]
		metadata.key_indices = indices[0: :2]
		metadata.value_indices = indices[1: :2]
		return metadata
		
	def readInstance(self):
		instance = ABCInstance()
		instance.name_index = self.readU32()			
		instance.super_name_index = self.readU32()
		instance.flags = self.readU8()
		if instance.flags & 0x08:	# CONSTANT_ClassProtectedNs
			instance.protectedNamespace_index = self.readU32()
		interface_count = self.readU32()
		instance.interface_indices = [self.readU32() for _ in range(interface_count)]
		instance.constructor_index = self.readU32()
		trait_count = self.readU32()
		instance.traits = [self.readTrait() for _ in range(trait_count)]
		return instance
		
	def readClass(self):
		class_object = ABCClass()
		class_object.constructor_index = self.readU32()
		trait_count = self.readU32()
		class_object.traits = [self.readTrait() for _ in range(trait_count)] 
		return class_object
		
	def readScript(self):
		script = ABCScript()
		script.initializer_index = self.readU32()
		trait_count = self.readU32()
		script.traits = [self.readTrait() for _ in range(trait_count)]
		return script
		
	def readMethodBody(self):
		method_body = ABCMethodBody()
		method_body.method_index = self.readU32()
		method_body.max_stack = self.readU32() 
		method_body.local_count = self.readU32()
		method_body.init_scope_depth = self.readU32()
		method_body.max_scope_depth = self.readU32()
		codeLength = self.readU32()
		method_body.byte_codes = self.readBytes(codeLength)
		exception_count = self.readU32()
		method_body.exceptions = [self.readException for _ in range(exception_count)]
		trait_count = self.readU32()
		method_body.traits = [self.readTrait() for _ in range(trait_count)]
			
		# link it with the method object so we can quickly find the body with a method index
		# method = abc_file.method_table[method_body.method_index]
		# method.body = method_body
		return method_body
	
	def readException(self):
		exception = ABCException()
		exception.from_offset = self.readU32()
		exception.to_offset = self.readU32()
		exception.target_offset = self.readU32()
		exception.type_index = self.readU32()
		exception.variable_index = self.readU32()
		return exception
		
	def readTrait(self):
		trait = ABCTrait()
		trait.name_index = self.readU32()
		trait.flags = self.readU8()
		trait.slotId = self.readU32()
		trait_type = trait.flags & 0x0F 
		if trait_type in (0, 6):		# Trait_Slot, Trait_Constant
			data = ABCTraitSlot()
			data.typeName_index = self.readU32()
			data.value_index = self.readU32()
			if data.value_index:
				data.value_type = self.readU8()
			trait.data = data
		elif trait_type in (1, 2, 3):	# Trait_Method, Trait_Getter, Trait_Setter 
			data = ABCTraitMethod()
			data.method_index = self.readU32()
			trait.data = data
		elif trait_type == 4:			# Trait_Class	
			data = ABCTraitClass()
			data.class_index = self.readU32()
			trait.data = data
		elif trait_type == 5:			# Trait_Function
			data = ABCTraitFunction()
			data.method_index = self.readU32()
			trait.data = data
		if trait.flags & 0x40:			# ATTR_Metadata
			metadata_count = self.readU32()
			trait.metadata_indices = [self.readU32() for _ in range(metadata_count)]
		return trait
		
	def readU8(self):
		data = self.readBytes(1)		
		if len(data) == 1:
			result = self.ui8.unpack(data)
			return result[0]
		else:
			return 0
				
	def readU16(self):
		data = self.readBytes(2)		
		if len(data) == 2:
			result = self.u16.unpack(data)
			return result[0]
		else:
			return 0
				
	def readU32(self):
		result = 0
		shift = 0
		while shift < 32:
			u8 = self.readU8()
			result |= (u8 & 0x7F) << shift
			shift += 7
			if not (u8 & 0x80):
				break
		return result
		
	def readS32(self):
		result = 0
		shift = 0
		while shift < 32:
			u8 = self.readU8()
			result |= (u8 & 0x7F) << shift
			shift += 7
			if not (u8 & 0x80):
				break
		if u8 & 0x40:
			result |= -1 << shift;
		return result
		
	def readD64(self):
		data = self.readBytes(8)		
		if len(data) == 8:
			result = self.d64.unpack(data)
			return result[0]
		else:
			return 0.0
				
	def readBytes(self, count):
		start = self.byte_code_index;
		end = start + count;
		self.byte_code_index = end;
		return self.byte_codes[start:end]
