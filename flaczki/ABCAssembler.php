<?php

class ABCAssembler {

	protected $output;
	protected $written;

	public function assemble(&$output, $abcFile) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$this->output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
			$this->output = $output;
		} else {
			throw new Exception("Invalid output");
		}
		$this->written = 0;
		
		$this->writeU16($abcFile->majorVersion);
		$this->writeU16($abcFile->minorVersion);
		
		$this->writeU32(count($abcFile->intTable) > 1 ? count($abcFile->intTable) : 0);
		foreach($abcFile->intTable as $index => $intValue)  {
			if($index != 0) {
				$this->writeS32($intValue);
			}
		}
		
		$this->writeU32(count($abcFile->uintTable) > 1 ? count($abcFile->uintTable) : 0);
		foreach($abcFile->uintTable as $index => $uintValue) {
			if($index != 0) {
				$this->writeU32($uintValue);
			}
		}
		
		$this->writeU32(count($abcFile->doubleTable) > 1 ? count($abcFile->doubleTable) : 0);
		foreach($abcFile->doubleTable as $index => $doubleValue) {
			if($index != 0) {
				$this->writeD64($doubleValue);
			}
		}

		$this->writeU32(count($abcFile->stringTable) > 1 ? count($abcFile->stringTable) : 0);
		foreach($abcFile->stringTable as $index => $stringValue) {
			if($index != 0) {
				$length = strlen($stringValue);
				$this->writeU32($length);
				if($length > 0) {
					$this->writeBytes($stringValue);
				}
			}
		}
		
		$this->writeU32(count($abcFile->namespaceTable) > 1 ? count($abcFile->namespaceTable) : 0);
		foreach($abcFile->namespaceTable as $index => $namespace) {
			if($index != 0) {
				$this->writeNamespace($namespace);
			}
		}
		
		$this->writeU32(count($abcFile->namespaceSetTable) > 1 ? count($abcFile->namespaceSetTable) : 0);
		foreach($abcFile->namespaceSetTable as $index => $namespaceSet) {
			if($index != 0) {
				$this->writeNamespaceSet($namespaceSet);
			}
		}
		
		$this->writeU32(count($abcFile->multinameTable) > 1 ? count($abcFile->multinameTable) : 0);
		foreach($abcFile->multinameTable as $index => $multiname) {
			if($index != 0) {
				$this->writeMultiname($multiname);
			}
		}

		$this->writeU32(count($abcFile->methodTable));
		foreach($abcFile->methodTable as $method) {
			$this->writeMethod($method);
		}
		
		$this->writeU32(count($abcFile->metadataTable));
		foreach($abcFile->metadataTable as $metadata) {
			$this->writeMetadata($metadata);
		}

		$this->writeU32(count($abcFile->instanceTable));
		foreach($abcFile->instanceTable as $instance) {
			$this->writeInstance($instance);
		}
		foreach($abcFile->classTable as $class) {
			$this->writeClass($class);
		}
		
		$this->writeU32(count($abcFile->scriptTable));
		foreach($abcFile->scriptTable as $script) {
			$this->writeScript($script);
		}
		
		$this->writeU32(count($abcFile->methodBodyTable));
		foreach($abcFile->methodBodyTable as $methodBody) {
			$this->writeMethodBody($methodBody);
		}
		
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}

	protected function writeNamespace($namespace) {
		$this->writeU8($namespace->kind);
		$this->writeU32($namespace->stringIndex);
	}
	
	protected function writeNamespaceSet($namespaceSet) {
		$this->writeU32(count($namespaceSet->namespaceIndices));
		foreach($namespaceSet->namespaceIndices as $namespaceIndex) {
			$this->writeU32($namespaceIndex);
		}
	}
	
	protected function writeMultiname($multiname) {
		$this->writeU8($multiname->type);
		switch($multiname->type) {
			case 0x07:	// CONSTANT_QName
			case 0x0D:	// CONSTANT_QNameA
				$this->writeU32($multiname->namespaceIndex);
				$this->writeU32($multiname->stringIndex);
				break;
			case 0x0F:	// CONSTANT_RTQName
			case 0x10:	// CONSTANT_RTQNameA
				$this->writeU32($multiname->namespaceIndex);
				break;
			case 0x11:	// CONSTANT_RTQNameL
			case 0x12:	// CONSTANT_RTQNameLA
				break;
			case 0x09:	// CONSTANT_Multiname
			case 0x0E:	// CONSTANT_MultinameA
				$this->writeU32($multiname->stringIndex);
				$this->writeU32($multiname->namespaceSetIndex);
				break;
			case 0x1B:	// CONSTANT_MultinameL
			case 0x1C:	// CONSTANT_MultinameLA
				$this->writeU32($multiname->namespaceSetIndex);
				break;
			case 0x1D:	// ???
				$this->writeU32($multiname->nameIndex);
				$this->writeU32(count($multiname->typeIndices));
				foreach($multiname->typeIndices as $typeIndex) {
					$this->writeU32($typeIndex);
				}
				break;
		}
	}
	
	protected function writeMethod($method) {
		$this->writeU32($method->paramCount);
		$this->writeU32($method->returnType);
		foreach($method->paramTypes as $paramType) {
			$this->writeU32($paramType);
		}
		$this->writeU32($method->nameIndex);
		$this->writeU8($method->flags);
		if($method->flags & 0x08) {	// HAS_OPTIONAL
			$this->writeU32(count($method->optionalParams));
			foreach($method->optionalParams as $parameter) {
				$this->writeU32($parameter->index);
				$this->writeU8($parameter->type);
			}
		}
		if($method->flags & 0x80) {	// HAS_PARAM_NAMES
			foreach($method->paramNameIndices as $paramNameIndex) {
				$this->writeU32($paramNameIndex);
			}
		}
	}
	
	protected function writeMetadata($metadata) {
		$this->writeU32($metadata->nameIndex);
		$this->writeU32(count($metadata->keyIndices));
		for($i = 0; $i < count($metadata->keyIndices); $i++) {
			$this->writeU32($metadata->keyIndices[$i]);
			$this->writeU32($metadata->valueIndices[$i]);
		}
	}
	
	protected function writeInstance($instance) {
		$this->writeU32($instance->nameIndex);
		$this->writeU32($instance->superNameIndex);
		$this->writeU8($instance->flags);
		if($instance->flags & 0x08) {	// CONSTANT_ClassProtectedNs
			$this->writeU32($instance->protectedNamespaceIndex);
		}
		$this->writeU32(count($instance->interfaceIndices));
		foreach($instance->interfaceIndices as $interfaceIndex) {
			$this->writeU32($interfaceIndex);
		}
		$this->writeU32($instance->constructorIndex);
		$this->writeU32(count($instance->traits));
		foreach($instance->traits as $trait) {
			$this->writeTrait($trait);
		}
	}
	
	protected function writeClass($class) {
		$this->writeU32($class->constructorIndex);
		$this->writeU32(count($class->traits));
		foreach($class->traits as $trait) {
			$this->writeTrait($trait);
		}
	}
	
	protected function writeScript($script) {
		$this->writeU32($script->initializerIndex);
		$this->writeU32(count($script->traits));
		foreach($script->traits as $trait) {
			$this->writeTrait($trait);
		}
	}
	
	protected function writeMethodBody($methodBody) {
		$this->writeU32($methodBody->methodIndex);
		$this->writeU32($methodBody->maxStack) ;
		$this->writeU32($methodBody->localCount);
		$this->writeU32($methodBody->initScopeDepth);
		$this->writeU32($methodBody->maxScopeDepth);
		$this->writeU32(strlen($methodBody->byteCodes));
		$this->writeBytes($methodBody->byteCodes);
		$this->writeU32(count($methodBody->exceptions));
		foreach($methodBody->exceptions as $exception) {
			$this->writeU32($exception->from);
			$this->writeU32($exception->to);
			$this->writeU32($exception->target);
			$this->writeU32($exception->typeIndex);
			$this->writeU32($exception->variableIndex);
		}
		$this->writeU32(count($methodBody->traits));
		foreach($methodBody->traits as $trait) {
			$this->writeTrait($trait);
		}
	}
	
	protected function writeTrait($trait) {
		$this->writeU32($trait->nameIndex);
		$this->writeU8($trait->type);
		$this->writeU32($trait->slotId);
		switch($trait->type & 0x0F) {
			case 0:		// Trait_Slot
			case 6:		// Trait_Const
				$this->writeU32($trait->data->typeNameIndex);
				$this->writeU32($trait->data->valueIndex);
				if($trait->data->valueIndex) {
					$this->writeU8($trait->data->valueType);
				}
				break;
			case 1: 	// Trait_Method
			case 2:		// Trait_Getter
			case 3:		// Trait_Setter
				$this->writeU32($trait->data->methodIndex);
				break;
			case 4:		// Trait_Class
				$this->writeU32($trait->data->classIndex);
				break;
			case 5:		// Trait_Function
				$this->writeU32($trait->data->methodIndex);
				break;
		}
		if($trait->type & 0x40) {	// ATTR_Metadata
			$this->writeU32(count($trait->metadataIndices));
			foreach($trait->metadataIndices as $metadataIndex) {
				$this->writeU32($metadataIndex);
			}
		}
	}
	
	protected function writeU8($value) {
		$this->writeBytes(chr($value));
	}
	
	protected function writeU16($value) {
		$bytes = pack('v', $value);
		$this->writeBytes($bytes);
	}
	
	protected function writeU32($value) {
		if(!($value & 0xFFFFFF80)) {
			$bytes = pack('C', $value);
		} else if(!($value & 0xFFFFC000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F);
		} else if(!($value & 0xFFE00000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F);
		} else if(!($value & 0xF0000000)) {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F | 0x80,  ($value >> 21) & 0x7F);
		} else {
			$bytes = pack('C*', $value & 0x7F | 0x80, ($value >> 7) & 0x7F | 0x80, ($value >> 14) & 0x7F | 0x80,  ($value >> 21) & 0x7F | 0x80, ($value >> 28) & 0x0F);	// the last byte can only have four bits
		}
		$this->writeBytes($bytes);
	}
	
	protected function writeS32($value) {
		$this->writeU32($value);
	}
	
	protected function writeD64($value) {
		$bytes = pack('d', $value);
		$this->writeBytes($bytes);
	}
	
	public function writeBytes($bytes) {
		$this->written += fwrite($this->output, $bytes);
	}
}

?>