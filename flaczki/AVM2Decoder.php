<?php

class AVM2Decoder {

	protected $intTable;
	protected $uintTable;
	protected $doubleTable;	
	protected $stringTable;
	protected $namespaceTable;
	protected $namespaceSetTable;
	protected $nameTable;
	protected $methodTable;
	protected $metadataTable;
	protected $instanceTable;
	protected $classTable;
	protected $scriptTable;
	protected $registers;
	protected $exceptions;
	
	public function decode($abcFile) {
		$this->intTable = $abcFile->intTable;
		$this->uintTable = $abcFile->uintTable;
		$this->doubleTable = $abcFile->doubleTable;
		$this->stringTable = $abcFile->stringTable;
		$this->namespaceTable = array();
		foreach($abcFile->namespaceTable as $namespaceRec) {
			$this->namespaceTable[] = $this->decodeNamespace($namespaceRec);
		}
		$this->namespaceSetTable = array();
		foreach($abcFile->namespaceSetTable as $namespaceSetRec) {
			$this->namespaceSetTable[] = $this->decodeNamespaceSet($namespaceSetRec);
		}
		$this->nameTable = array();
		foreach($abcFile->multinameTable as $multinameRec) {
			$this->nameTable[] = $this->decodeName($multinameRec);
		}
		$this->methodTable = array();
		foreach($abcFile->methodTable as $methodRec) {
			$this->methodTable[] = $this->decodeMethod($methodRec);
		}
		$this->metadataTable = array();
		foreach($abcFile->metadataTable as $metadataRec) {
			$this->metadataTable[] = $this->decodeMetadata($metadataRec);
		}
		$this->instanceTable = array();
		foreach($abcFile->instanceTable as $instanceRec) {
			$this->instanceTable[] = $this->decodeInstance($instanceRec);
		}
		$this->classTable = array();
		foreach($abcFile->classTable as $index => $classRec) {		
			$class = $this->decodeClass($classRec);
			$class->instance = $this->instanceTable[$index];
			$this->classTable[] = $class;
		}
		$this->scriptTable = array();
		foreach($abcFile->scriptTable as $scriptRec) {
			$this->scriptTable[] = $this->decodeScript($scriptRec);
		}
		foreach($abcFile->methodBodyTable as $methodBodyRec) {
			$method = $this->methodTable[$methodBodyRec->methodIndex];
			$method->body = $this->decodeMethodBody($methodBodyRec, $method->arguments);
		}
		$scripts = $this->scriptTable;
		return $scripts;
	}
	
	protected function decodeMethodBody($methodBodyRec, $arguments) {
		$registers = array();
		$reg0 = new AVM2ImplicitRegister;
		$reg0->index = 0;
		$reg0->name = "this";
		$registers[] = $reg0;
		foreach($arguments as $index => $argument) {
			$reg = new AVM2Register;
			$reg->index = $index + 1;
			$reg->name = $argument->name->string;
			$registers[] = $reg;
		}
		for($i = count($arguments) + 1; $i < $methodBodyRec->localCount; $i++) {
			$reg = new AVM2Register;
			$reg->index = $i + 1;
			$registers[] = $reg;
		}
		$function = new AVM2MethodBody($this, $methodBodyRec->byteCodes, $registers);
		return $function;
	}
	
	protected function decodeScript($scriptRec) {
		$script = new AVM2Script;
		$script->initializer = $this->methodTable[$scriptRec->initializerIndex];
		$script->members = $this->decodeTraits($scriptRec->traits);
		$script->slots = $this->decodeSlots($script->members);
		return $script;
	}
	
	protected function decodeClass($classRec) {
		$class = new AVM2Class;
		$class->constructor = $this->methodTable[$classRec->constructorIndex];
		$class->members = $this->decodeTraits($classRec->traits);
		$class->slots = $this->decodeSlots($class->members);
		return $class;
	}
	
	protected function decodeInstance($instanceRec) {
		$instance = new AVM2ClassInstance;
		$instance->name = $this->nameTable[$instanceRec->nameIndex];
		$instance->constructor = $this->methodTable[$instanceRec->constructorIndex];
		$instance->constructor->name = $instance->name;
		$instance->members = $this->decodeTraits($instanceRec->traits);
		$instance->slots = $this->decodeSlots($instance->members);
		$instance->flags = $instanceRec->flags;
		if($instanceRec->superNameIndex) {
			$instance->parentName = $this->nameTable[$instanceRec->superNameIndex];
		}
		$instance->flags = $instanceRec->flags;
		if($instanceRec->protectedNamespaceIndex !== null) {
			$instance->protectedNamespace = $this->namespaceTable[$instanceRec->protectedNamespaceIndex];
		}
		$interfaces = array();
		if($instanceRec->interfaceIndices) {
			foreach($instanceRec->interfaceIndices as $index) {
				$interfaces[] = $this->nameTable[$index];
			}
		}
		$instance->interfaces = $interfaces;
		return $instance;
	}
	
	protected function decodeTraits($traitRecs) {
		$members = array();
		foreach($traitRecs as $traitRec) {
			$member = new AVM2ClassMember;
			$member->name = $this->nameTable[$traitRec->nameIndex];
			$member->slotId = $traitRec->slotId;
			$member->type = $traitRec->type & 0x0F;
			$member->flags = $traitRec->type >> 4;
			switch($member->type) {
				case 0:	
					$object = new AVM2Variable;
					$object->type = $this->nameTable[$traitRec->typeNameIndex];
					$object->value = $this->decodeValue($traitRec->valueType, $traitRec->valueIndex);
					break;
				case 6: 
					$object = new AVM2Constant;
					$object->type = $this->nameTable[$traitRec->typeNameIndex];
					$object->value = $this->decodeValue($traitRec->valueType, $traitRec->valueIndex);
					break;
				case 1:
				case 2:
				case 3:	
				case 5: 
					$object = $this->methodTable[$traitRec->methodIndex];
					break;
				case 4: 
					$object = $this->classTable[$traitRec->classIndex];
					break;
			}
			$member->object = $object;
			if($traitRec->metadataIndices) {
				$metadata = array();
				foreach($traitRec->metadataIndices as $index) {
					$metadata[] = $this->metadataTable[$index];
				}
				$member->metadata = $metadata;
			}
			$members[] = $member;
		}
		return $members;
	}
	
	protected function decodeSlots($members) {
		$slots = array();
		foreach($members as $member) {
			if($member->slotId) {
				$slots[$member->slotId] = $member;
			}
		}
		return $slots;
	}
	
	protected function decodeMethod($methodRec) {
		$method = new AVM2Method;
		$method->returnType = $this->nameTable[$methodRec->returnType];
		$arguments = array();
		for($i = 0; $i < $methodRec->paramCount; $i++) {
			$argument = new AVM2Argument;
			$argument->type = $this->nameTable[$methodRec->paramTypes[$i]];
			$name = new AVM2QName;
			$name->namespace = $this->namespaceTable[0];
			if(isset($methodRec->paramNameIndices[$i])) {
				$name->string = $this->stringTable[$methodRec->paramNameIndices[$i]];
			} else {
				$name->string = "arg" . ($i + 1);
			}
			$argument->name = $name;
			$optionalParamIndex = count($methodRec->optionalParams) - $methodRec->paramCount + $i;
			if(isset($methodRec->optionalParams[$optionalParamIndex])) {
				$parameterRec = $methodRec->optionalParams[$optionalParamIndex];
				$argument->value = $this->decodeValue($parameterRec->type, $parameterRec->index);
			}			
			$arguments[] = $argument;
		}
		$method->arguments = $arguments;
		return $method;
	}

	protected function decodeValue($type, $index) {
		switch($type) {
			case 0x03: return $this->intTable[$index]; 
			case 0x04: return $this->uintTable[$index];
			case 0x06: return $this->doubleTable[$index];
			case 0x01: return $this->stringTable[$index];
			case 0x0B: return true;
			case 0x0A: return false; 
			case 0x0C: return null;
			case 0x00: return AVM2Undefined::$singleton;
			case 0x08: 
			case 0x16: 
			case 0x17: 
			case 0x18: 
			case 0x19: 
			case 0x1A: 
			case 0x05: return $this->nameTable[$index];
		}
	} 
	
	protected function decodeMetadata($metadataRec) {
		$metadata = new AVM2Metadata;
		$metadata->name = $this->stringTable[$metadataRec->nameIndex];
		$values = array();
		foreach($metadataRec->keyIndices as $index => $keyIndex) {
			$key = $this->stringTable[$keyIndex];
			$value = $this->stringTable[$metadataRec->valueIndices[$index]];
			$values[$key] = $value;
		}
		$metadata->values = $values;
		return $metadata;
	}
	
	protected function decodeNamespace($namespaceRec) {
		switch($namespaceRec->kind) {
			case 0x08: $namespace = new AVM2RegularNamespace; break;
			case 0x16: $namespace = new AVM2PackageNamespace; break;
			case 0x17: $namespace = new AVM2InternalNamespace; break;
			case 0x18: $namespace = new AVM2ProtectedNamespace; break;
			case 0x19: $namespace = new AVM2ExplicitNamespace; break;
			case 0x1A: $namespace = new AVM2StaticProtectedNamespace; break;
			case 0x05: $namespace = new AVM2PrivateNamespace; break;
		}
		$namespace->string = $this->stringTable[$namespaceRec->stringIndex];
		return $namespace;
	}
	
	protected function decodeNamespaceSet($namespaceSetRec) {
		$namespaceSet = new AVM2NamespaceSet;
		$namespaceSet->namespaces = array();
		foreach($namespaceSetRec->namespaceIndices as $index) {
			$namespaceSet->namespaces[] = $this->namespaceTable[$index];
		}
		return $namespaceSet;
	}
	
	protected function decodeName($multinameRec) {
		switch($multinameRec->type) {
			case 0x07:
				$name = new AVM2QName;
				$name->namespace = $this->namespaceTable[$multinameRec->namespaceIndex];
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x0D:
				$name = new AVM2QNameA;
				$name->namespace = $this->namespaceTable[$multinameRec->namespaceIndex];
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x0F:
				$name = new AVM2RTQName;
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x10:
				$name = new AVM2RTQNameA;
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x11:
				$name = new AVM2RTQNameL;
				break;
			case 0x12:
				$name = new AVM2RTQNameLA;
				break;
			case 0x09:
				$name = new AVM2Multiname;
				$name->namespaceSet = $this->namespaceSetTable[$multinameRec->namespaceSetIndex];
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x0E:
				$name = new AVM2MultinameA;
				$name->namespaceSet = $this->namespaceSetTable[$multinameRec->namespaceSetIndex];
				$name->string = $this->stringTable[$multinameRec->stringIndex];
				break;
			case 0x1B:
				$name = new AVM2MultinameL;
				$name->namespaceSet = $this->namespaceSetTable[$multinameRec->namespaceSetIndex];
				break;
			case 0x1C:
				$name = new AVM2MultinameLA;
				$name->namespaceSet = $this->namespaceSetTable[$multinameRec->namespaceSetIndex];
				break;
			case 0x1D:
				$name = new AVM2GenericName;
				$name->name = $this->nameTable[$multinameRec->nameIndex];
				$name->types = array();
				foreach($multinameRec->typeIndices as $index) {
					$name->types[] = $this->nameTable[$index];
				}
				break;
			default:
				echo sprintf("%X", $multinameRec->type);
		}
		return $name;
	}
	
	public function decodeInstructions($byteCodes, $registers) {
		static $opNames = array(
			0xa0 => "add",
			0xc5 => "add_i",
			0x53 => "applytype",
			0x86 => "astype",
			0x87 => "astypelate",
			0x01 => "bkpt",
			0xf2 => "bkptline",
			0xa8 => "bitand",
			0x97 => "bitnot",
			0xa9 => "bitor",
			0xaa => "bitxor",
			0x41 => "call",
			0x43 => "callmethod",
			0x46 => "callproperty",
			0x4c => "callproplex",
			0x4f => "callpropvoid",
			0x44 => "callstatic",
			0x45 => "callsuper",
			0x4e => "callsupervoid",
			0x78 => "checkfilter",
			0x80 => "coerce",
			0x82 => "coerce_a",
			0x85 => "coerce_s",
			0x42 => "construct",
			0x4a => "constructprop",
			0x49 => "constructsuper",
			0x76 => "convert_b",
			0x75 => "convert_d",
			0x73 => "convert_i",
			0x77 => "convert_o",
			0x70 => "convert_s",
			0x74 => "convert_u",
			0xef => "debug",
			0xf1 => "debugfile",
			0xf0 => "debugline",
			0x94 => "declocal",
			0xc3 => "declocal_i",
			0x93 => "decrement",
			0xc1 => "decrement_i",
			0x6a => "deleteproperty",
			0xa3 => "divide",
			0x2a => "dup",
			0x06 => "dxns",
			0x07 => "dxnslate",
			0xab => "equals",
			0x72 => "esc_xattr",
			0x71 => "esc_xelem",
			0x5e => "findproperty",
			0x5d => "findpropstrict",
			0x59 => "getdescendants",
			0x64 => "getglobalscope",
			0x6e => "getglobalslot",
			0x60 => "getlex",
			0x62 => "getlocal",
			0xd0 => "getlocal_0",
			0xd1 => "getlocal_1",
			0xd2 => "getlocal_2",
			0xd3 => "getlocal_3",
			0x66 => "getproperty",
			0x65 => "getscopeobject",
			0x6c => "getslot",
			0x04 => "getsuper",
			0xb0 => "greaterequals",
			0xaf => "greaterthan",
			0x1f => "hasnext",
			0x32 => "hasnext2",
			0x13 => "ifeq",
			0x12 => "iffalse",
			0x18 => "ifge",
			0x17 => "ifgt",
			0x16 => "ifle",
			0x15 => "iflt",
			0x14 => "ifne",
			0x0f => "ifnge",
			0x0e => "ifngt",
			0x0d => "ifnle",
			0x0c => "ifnlt",
			0x19 => "ifstricteq",
			0x1a => "ifstrictne",
			0x11 => "iftrue",
			0xb4 => "in",
			0x92 => "inclocal",
			0xc2 => "inclocal_i",
			0x91 => "increment",
			0xc0 => "increment_i",
			0x68 => "initproperty",
			0xb1 => "instanceof",
			0xb2 => "istype",
			0xb3 => "istypelate",
			0x10 => "jump",
			0x08 => "kill",
			0x09 => "label",
			0xae => "lessequals",
			0xad => "lessthan",
			0x38 => "lf32",
			0x35 => "lf64",
			0x35 => "li8",
			0x36 => "li16",
			0x37 => "li32",
			0x1b => "lookupswitch",
			0xa5 => "lshift",
			0xa4 => "modulo",
			0xa2 => "multiply",
			0xc7 => "multiply_i",
			0x90 => "negate",
			0xc4 => "negate_i",
			0x57 => "newactivation",
			0x56 => "newarray",
			0x5a => "newcatch",
			0x58 => "newclass",
			0x40 => "newfunction",
			0x55 => "newobject",
			0x1e => "nextname",
			0x23 => "nextvalue",
			0x02 => "nop",
			0x96 => "not",
			0x29 => "pop",
			0x1d => "popscope",
			0x24 => "pushbyte",
			0x2f => "pushdouble",
			0x27 => "pushfalse",
			0x2d => "pushint",
			0x31 => "pushnamespace",
			0x28 => "pushnan",
			0x20 => "pushnull",
			0x30 => "pushscope",
			0x25 => "pushshort",
			0x2c => "pushstring",
			0x26 => "pushtrue",
			0x2e => "pushuint",
			0x21 => "pushundefined",
			0x1c => "pushwith",
			0x48 => "returnvalue",
			0x47 => "returnvoid",
			0xa6 => "rshift",
			0x6f => "setglobalslot",
			0x63 => "setlocal",
			0xd4 => "setlocal_0",
			0xd5 => "setlocal_1",
			0xd6 => "setlocal_2",
			0xd7 => "setlocal_3",
			0x61 => "setproperty",
			0x6d => "setslot",
			0x05 => "setsuper",
			0x3d => "sf32",
			0x3d => "sf64",
			0x3a => "si8",
			0x3b => "si16",
			0x3c => "si32",
			0xac => "strictequals",
			0xa1 => "subtract",
			0xc6 => "subtract_i",
			0x2b => "swap",
			0x50 => "sxi_1",
			0x51 => "sxi_8",
			0x52 => "sxi_16",
			0x03 => "throw",
			0x95 => "typeof",
			0xa7 => "urshift",
		);

		static $operandHandlers = array(
			0x04 => 'decodeNameIndex',
			0x05 => 'decodeNameIndex',
			0x06 => 'decodetringIndex',
			0x08 => 'decodeRegisterIndex',
			0x0c => 'decodeS24',
			0x0d => 'decodeS24',
			0x0e => 'decodeS24',
			0x0f => 'decodeS24',
			0x10 => 'decodeS24',
			0x11 => 'decodeS24',
			0x12 => 'decodeS24',
			0x13 => 'decodeS24',
			0x14 => 'decodeS24',
			0x15 => 'decodeS24',
			0x16 => 'decodeS24',
			0x17 => 'decodeS24',
			0x18 => 'decodeS24',
			0x19 => 'decodeS24',
			0x1a => 'decodeS24',
			0x1b => 'decodeSwitchOperands',
			0x24 => 'decodeS8',
			0x25 => 'decodeS30',
			0x2c => 'decodeStringIndex',
			0x2d => 'decodeIntIndex',
			0x2e => 'decodeUIntIndex',
			0x2f => 'decodeDoubleIndex',
			0x31 => 'decodeNamespaceIndex',
			0x32 => 'decodeTwoRegisterIndices',
			0x40 => 'decodeMethodIndex',
			0x41 => 'decodeU30',
			0x42 => 'decodeU30',
			0x43 => 'decodeMethodIndexAndArgumentCount',
			0x44 => 'decodeMethodIndexAndArgumentCount',
			0x45 => 'decodeNameIndexAndArgumentCount',
			0x46 => 'decodeNameIndexAndArgumentCount',
			0x49 => 'decodeU30',
			0x4a => 'decodeNameIndexAndArgumentCount',
			0x4c => 'decodeNameIndexAndArgumentCount',
			0x4e => 'decodeNameIndexAndArgumentCount',
			0x4f => 'decodeNameIndexAndArgumentCount',
			0x53 => 'decodeU30',
			0x55 => 'decodeU30',
			0x56 => 'decodeU30',
			0x58 => 'decodeClassIndex',
			0x59 => 'decodeNameIndex',
			0x5a => 'decodeExceptionIndex',
			0x5d => 'decodeNameIndex',
			0x5e => 'decodeNameIndex',
			0x60 => 'decodeNameIndex',
			0x61 => 'decodeNameIndex',
			0x62 => 'decodeRegisterIndex',
			0x63 => 'decodeRegisterIndex',
			0x65 => 'decodeU30',
			0x66 => 'decodeNameIndex',
			0x68 => 'decodeNameIndex',
			0x6a => 'decodeNameIndex',
			0x6c => 'decodeU30',
			0x6d => 'decodeU30',
			0x6e => 'decodeU30',
			0x6f => 'decodeU30',
			0x80 => 'decodeNameIndex',
			0x86 => 'decodeNameIndex',
			0x92 => 'decodeRegisterIndex',
			0x94 => 'decodeRegisterIndex',
			0xb2 => 'decodeNameIndex',
			0xc2 => 'decodeRegisterIndex',
			0xc3 => 'decodeRegisterIndex',
			0xef => 'decodeDebugOperands',
			0xf0 => 'decodeU30',
			0xf1 => 'decodeStringIndex',
			0xf2 => 'decodeU30',
		);
		
		$this->registers = $registers;
		$this->byteCodes = $byteCodes;
		$this->position = 0;
		$endPosition = strlen($byteCodes);
	 	$opCache = array();
	 	$ops = array();
		while($this->position < $endPosition) {
			$opcodePosition = $this->position;
			$opcode = $this->readU8();
			if($opcode) {
				$name = $opNames[$opcode];
				$handler = isset($operandHandlers[$opcode]) ? $operandHandlers[$opcode] : null;
				if(!$handler) {
					// avoid creating the identical objects
					if(isset($opCache[$opcode])) {						
						$op = $opCache[$opcode];
					} else {
						$op = $opCache[$opcode] = new AVM2Op;
						// exapnd getlocal_# and setlocal_# 
						if($opcode >= 0xd0 && $opcode <= 0xd3) {
							$op->code = 0x62;
							$op->name = "getlocal";
							$op->op1 = $this->registers[$opcode - 0xd0];
						} else if($opcode >= 0xd4 && $opcode <= 0xd7) {
							$op->code = 0x63;
							$op->name = "setlocal";
							$op->op1 = $this->registers[$opcode - 0xd4];
						} else {
							$op->name = $name;
							$op->code = $opcode;
						}
					}
				} else {
					$op = new AVM2Op;
					$op->name = $name;
					$op->code = $opcode;
					$this->$handler($op);
				}
				$ops[$opcodePosition] = $op;
			}
		}
		return $ops;
	}
	
	protected function decodeSwitchOperands($op) {
		$op->op1 = $this->readS24();
		$op->op2 = $caseCount = $this->readU30();
		$offsets = array();
		for($i = 0; $i <= $caseCount; $i++) {
			$offsets[] = $this->readS24();
		}
		$op->op3 = $offsets;
	}
	
	protected function decodeDebugOperands($op) {
		$op->op1 = $this->readU8();
		$op->op2 = $this->stringTable[ $this->readU30() ];
		$op->op3 = $this->readU8();
		$op->op4 = $this->readU30();
	}
	
	protected function decodeExceptionIndex($op) {
		$op->op1 = $this->exceptions[ $this->readU30() ];
	}
	
	protected function decodeMethodIndexAndArgumentCount($op) {
		$op->op1 = $this->methodTable[ $this->readU30() ];
		$op->op2 = $this->readU30();
	}
	
	protected function decodeMethodIndex($op) {
		$op->op1 = $this->methodTable[ $this->readU30() ];
	}
	
	protected function decodeClassIndex($op) {
		$op->op1 = $this->classTable[ $this->readU30() ];
	}
	
	protected function decodeTwoRegisterIndices($op) {
		$op->op1 = $this->registers[ $this->readU30() ];
		$op->op2 = $this->registers[ $this->readU30() ];
	}
	
	protected function decodeRegisterIndex($op) {
		$op->op1 = $this->registers[ $this->readU30() ];
	}
	
	protected function decodeNamespaceIndex($op) {
		$op->op1 = $this->namespaceTable[ $this->readU30() ];
	}
	
	protected function decodeNameIndexAndArgumentCount($op) {
		$op->op1 = $this->nameTable[ $this->readU30() ];
		$op->op2 = $this->readU30();
	}
	
	protected function decodeNameIndex($op) {
		$op->op1 = $this->nameTable[ $this->readU30() ];
	}
	
	protected function decodeIntIndex($op) {
		$op->op1 = $this->intTable[ $this->readU30() ];
	}
	
	protected function decodeUIntIndex($op) {
		$op->op1 = $this->uintTable[ $this->readU30() ];
	}
	
	protected function decodeDoubleIndex($op) {
		$op->op1 = $this->doubleTable[ $this->readU30() ];
	}
	
	protected function decodeStringIndex($op) {
		$op->op1 = $this->stringTable[ $this->readU30() ];
	}
	
	protected function decodeS8($op) {
		$op->op1 = $this->readS8();
	}
	
	protected function decodeS24($op) {
		$op->op1 = $this->readS24();
	}
	
	protected function decodeS30($op) {
		$op->op1 = $this->readS30();
	}
	
	protected function decodeU30($op) {
		$op->op1 = $this->readU30();
	}

	protected function readS8() {
		$value = ord($this->byteCodes[$this->position++]);
		if($value & 0x80) {
			$value |= -1 << 8;		// fill in the remaining upper bits
		}
		return $value;
	}
	
	protected function readU8() {
		$value = ord($this->byteCodes[$this->position++]);
		return $value;
	}
	
	protected function readS24() {
		$b1 = ord($this->byteCodes[$this->position++]);
		$b2 = ord($this->byteCodes[$this->position++]);
		$b3 = ord($this->byteCodes[$this->position++]);
		$value = ($b3 << 16) | ($b2 << 8) | $b1;
		if($value & 0x00800000) {
			$value |= -1 << 24;
		}
		return $value;
	}

	protected function readS30() {
		$shift = 0;
		$value = 0;
		do { 
			$byte = ord($this->byteCodes[$this->position++]);
			$value |= ($byte & 0x7F) << $shift;
			$shift += 7;
		} while($byte & 0x80);
		if($shift > 7 && $byte & 0x40) {
			$value |= -1 << $shift;
		}
		return $value;
	}
	
	protected function readU30() {
		$shift = 0;
		$value = 0;
		do { 
			$byte = ord($this->byteCodes[$this->position++]);
			$value |= ($byte & 0x7F) << $shift;
			$shift += 7;
		} while($byte & 0x80);
		return $value;
	}
}

class AVM2Undefined {
	public static $singleton;
}
AVM2Undefined::$singleton = new AVM2Undefined;

class AVM2Namespace {
	public $string;
}

class AVM2NamespaceSet {
	public $namespaces;
}

class AVM2RegularNamespace extends AVM2Namespace {
}

class AVM2PackageNamespace extends AVM2Namespace {
}

class AVM2InternalNamespace extends AVM2Namespace {
}

class AVM2ProtectedNamespace extends AVM2Namespace {
}

class AVM2PrivateNamespace extends AVM2Namespace {
}

class AVM2ExplicitNamespace extends AVM2Namespace {
}

class AVM2StaticProtectedNamespace extends AVM2ProtectedNamespace {
}

class AVM2Name {
}

class AVM2QName extends AVM2Name {
	public $namespace;
	public $string;
}

class AVM2QNameA extends AVM2QName {
}

class AVM2RTQName extends AVM2Name {
	public $string;
}

class AVM2RTQNameA extends AVM2RTQName {
}

class AVM2RTQNameL extends AVM2Name {
}

class AVM2RTQNameLA extends AVM2RTQNameL {
}

class AVM2Multiname extends AVM2Name {
	public $namespaceSet;
	public $string;
}

class AVM2MultinameA extends AVM2Multiname {
}

class AVM2MultinameL extends AVM2Name {
	public $namespaceSet;
}

class AVM2MultinameLA extends AVM2MultinameL {
}

class AVM2GenericName extends AVM2NAme {
	public $name;
	public $types;
}

class AVM2Metadata {
	public $name;
	public $values;
}

class AVM2ClassMember {
	const TYPE_VARIABLE = 0;
	const TYPE_CONSTANT = 6;
	const TYPE_METHOD = 1;
	const TYPE_GETTER = 2;
	const TYPE_SETTER = 3;
	const TYPE_FUNCTION = 5;
	const TYPE_CLASS = 4;
	
	const ATTR_FINAL = 0x01;
	const ATTR_OVERRIDE = 0x02;

	public $name;
	public $type;
	public $flags;
	public $object;
	public $slotId;
	public $metadata;
}

class AVM2Variable {
	public $type;
	public $value;
}

class AVM2Argument extends AVM2Variable {
	public $name;
}

class AVM2Constant {
	public $type;
	public $value;
}

class AVM2Register {
	public $index;
	public $name;
}

class AVM2ImplicitRegister extends AVM2Register {
}

class AVM2ActivionObject {
	public $slots = array();
	public $members = array();
}

class AVM2GlobalScope {
	public $slots = array();
	public $members = array();
}

class AVM2ClassInstance {
	const ATTR_SEALED = 0x01;
	const ATTR_FINAL = 0x02;
	const ATTR_INTERFACE = 0x04;

	public $name;
	public $parentName;
	public $interfaces;
	public $constructor;
	public $members;
	public $slots;
}

class AVM2Class {
	public $constructor;
	public $members;
	public $instance;
	public $slots;
}

class AVM2Script {
	public $initializer;
	public $members;
	public $slots;
}

class AVM2Method {
	public $arguments;
	public $returnType;
	public $body;
}

class AVM2MethodBody {
	protected $decoder;
	protected $byteCodes;
	protected $registers;
	
	public function __construct($decoder, $byteCodes, $registers) {
		$this->decoder = $decoder;
		$this->byteCodes = $byteCodes;
		$this->registers = $registers;
	}
	
	public function __get($name) {
		if($name == 'operations') {
			return $this->decoder->decodeInstructions($this->byteCodes, $this->registers);
		}
	}
}

class AVM2Op {
	public $code;
	public $name;
}

?>