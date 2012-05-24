<?php

class ABCParser {

	protected $input;
	
	public function parse($input) {
		if(gettype($input) == 'string') {
			$path = StreamMemory::add($input);
			$this->input = fopen($path, "rb");
		} else if(gettype($input) == 'resource') {
			$this->input = $input;
		} else {
			throw new Exception("Invalid input");
		}
		$abcFile = new ABCFile;
		
		// AVM version info--should be 16.46
		$abcFile->majorVersion = $this->readU16();
		$abcFile->minorVersion = $this->readU16();
		
		// signed integer constants (zeroth item is default value)
		$intCount = $this->readU32();
		$abcFile->intTable[] = 0;
		for($i = 1; $i < $intCount; $i++) {
			$abcFile->intTable[] = $this->readS32();
		}
		
		// unsigned integer constants
		$uintCount = $this->readU32();
		$abcFile->uintTable[] = 0;
		for($i = 1; $i < $uintCount; $i++) {
			$abcFile->uintTable[] = $this->readU32();
		}
		
		// double constants
		$doubleCount = $this->readU32();
		$abcFile->doubleTable[] = 0.0;
		for($i = 1; $i < $doubleCount; $i++) {
			$abcFile->doubleTable[] = $this->readD64();
		}

		// string constants
		$stringCount = $this->readU32();
		$abcFile->stringTable[] = '';
		for($i = 1; $i < $stringCount; $i++) {
			$length = $this->readU32();
			$abcFile->stringTable[] = ($length > 0) ? $this->readBytes($length) : '';
		}
		
		// namespace constants
		$namespaceCount = $this->readU32();
		$abcFile->namespaceTable[] = new ABCNamespace;
		for($i = 1; $i < $namespaceCount; $i++) {
			$abcFile->namespaceTable[] = $this->readNamespace();
		}
		
		// namespace-set constants
		$namespaceSetCount = $this->readU32();
		$abcFile->namespaceSetTable[] = new ABCNamespaceSet;
		for($i = 1; $i < $namespaceSetCount; $i++) {
			$abcFile->namespaceSetTable[] = $this->readNamespaceSet();
		}
		
		// multiname (i.e. variable name) constants
		$multinameCount = $this->readU32();
		$abcFile->multinameTable[] = new ABCMultiname;
		for($i = 1; $i < $multinameCount; $i++) {
			$abcFile->multinameTable[] = $this->readMultiname();
		}

		// methods 
		$methodCount = $this->readU32();
		for($i = 0; $i < $methodCount; $i++) {
			$abcFile->methodTable[] = $this->readMethod();
		}
		
		// metadata
		$metadataCount = $this->readU32();
		for($i = 0; $i < $metadataCount; $i++) {
			$abcFile->metadataTable[] = $this->readMetadata();
		}

		// class instances
		$classCount = $this->readU32();
		for($i = 0; $i < $classCount; $i++) {
			$abcFile->instanceTable[] = $this->readInstance();
		}
		for($i = 0; $i < $classCount; $i++) {
			$abcFile->classTable[] = $this->readClass();
		}
		
		// scripts
		$scriptCount = $this->readU32();
		for($i = 0; $i < $scriptCount; $i++) {
			$abcFile->scriptTable[] = $this->readScript();
		}
		
		// method bodies
		$methodBodyCount = $this->readU32();
		for($i = 0; $i < $methodBodyCount; $i++) {
			$methodBody = $this->readMethodBody();
			$abcFile->methodBodyTable[] = $methodBody;
			
			// link it with the method object so we can quickly find the body with a method index
			$method = $abcFile->methodTable[$methodBody->methodIndex];
			$method->body = $methodBody;
		}
		
		$this->input = null;
		return $abcFile;
	}
	
	protected function readNamespace() {
		$namespace = new ABCNamespace;
		$namespace->kind = $this->readU8();
		$namespace->stringIndex = $this->readU32();
		return $namespace;
	}
	
	protected function readNamespaceSet() {
		$namespaceSet = new ABCNamespaceSet;
		$namespaceCount = $this->readU32();
		for($i = 0; $i < $namespaceCount; $i++) {
			$namespaceSet->namespaceIndices[] = $this->readU32();
		}
		return $namespaceSet;
	}
	
	protected function readMultiname() {
		$multiname = new ABCMultiname;
		$multiname->type = $this->readU8();
		switch($multiname->type) {
			case 0x07:	// CONSTANT_QName
			case 0x0D:	// CONSTANT_QNameA
				$multiname->namespaceIndex = $this->readU32();
				$multiname->stringIndex = $this->readU32();
				break;
			case 0x0F:	// CONSTANT_RTQName
			case 0x10:	// CONSTANT_RTQNameA
				$multiname->namespaceIndex = $this->readU32();
				break;
			case 0x11:	// CONSTANT_RTQNameL
			case 0x12:	// CONSTANT_RTQNameLA
				break;
			case 0x09:	// CONSTANT_Multiname
			case 0x0E:	// CONSTANT_MultinameA
				$multiname->stringIndex = $this->readU32();
				$multiname->namespaceSetIndex = $this->readU32();
				break;
			case 0x1B:	// CONSTANT_MultinameL
			case 0x1C:	// CONSTANT_MultinameLA
				$multiname->namespaceSetIndex = $this->readU32();
				break;
			case 0x1D:	// ???
				$multiname->nameIndex = $this->readU32();
				$count = $this->readU32();
				for($i = 0; $i < $count; $i++) {
					$multiname->typeIndices[] = $this->readU32();
				}
				break;
		}
		return $multiname;
	}
	
	protected function readMethod() {
		$method = new ABCMethod;
		$method->paramCount = $this->readU32();
		$method->returnType = $this->readU32();
		for($i = 0; $i < $method->paramCount; $i++) {
			$method->paramTypes[] = $this->readU32();
		}
		$method->nameIndex = $this->readU32();
		$method->flags = $this->readU8();;
		if($method->flags & 0x08) {	// HAS_OPTIONAL
			$optParamCount = $this->readU32();
			for($i = 0; $i < $optParamCount; $i++) {
				$parameter = new ABCMethodOptionalParameter;
				$parameter->index = $this->readU32();
				$parameter->type = $this->readU8();
				$method->optionalParams[] = $parameter;
			}
		}
		if($method->flags & 0x80) {	// HAS_PARAM_NAMES
			for($i = 0; $i < $method->paramCount; $i++) {
				$paramNameIndex = $this->readU32();
				$method->paramNameIndices[] = $paramNameIndex;
			}
		}
		return $method;
	}
	
	protected function readMetadata() {
		$metadata = new ABCMetadata;
		$metadata->nameIndex = $this->readU32();
		$pairCount = $this->readU32();
		for($i = 0; $i < $pairCount; $i++) {
			$metadata->keyIndices[] = $this->readU32();
			$metadata->valueIndices[] = $this->readU32();
		}
		return $metadata;
	}
	
	protected function readInstance() {
		$instance = new ABCInstance;
		$instance->nameIndex = $this->readU32();			
		$instance->superNameIndex = $this->readU32();
		$instance->flags = $this->readU8();
		if($instance->flags & 0x08) {	// CONSTANT_ClassProtectedNs
			$instance->protectedNamespaceIndex = $this->readU32();
		}
		$interfaceCount = $this->readU32();
		for($j = 0; $j < $interfaceCount; $j++) {
			$instance->interfaceIndices[] = $this->readU32();
		}
		$instance->initializerIndex = $this->readU32();
		$traitCount = $this->readU32();
		for($i = 0; $i < $traitCount; $i++) {
			$instance->traits[] = $this->readTrait();
		}
		return $instance;
	}
	
	protected function readClass() {
		$class = new ABCClass;
		$class->constructorIndex = $this->readU32();
		$traitCount = $this->readU32();
		for($i = 0; $i < $traitCount; $i++) {
			$class->traits[] = $this->readTrait();
		}
		return $class;
	}
	
	protected function readScript() {
		$script = new ABCScript;
		$script->initializerIndex = $this->readU32();
		$traitCount = $this->readU32();
		for($i = 0; $i < $traitCount; $i++) {
			$script->traits[] = $this->readTrait();
		}
		return $script;
	}
	
	protected function readMethodBody() {
		$methodBody = new ABCMethodBody;
		$methodBody->methodIndex = $this->readU32();
		$methodBody->maxStack = $this->readU32() ;
		$methodBody->localCount = $this->readU32();
		$methodBody->initScopeDepth = $this->readU32();
		$methodBody->maxScopeDepth = $this->readU32();
		$codeLength = $this->readU32();
		$methodBody->byteCodes = $this->readBytes($codeLength);
		$exceptionCount = $this->readU32();
		for($i = 0; $i < $exceptionCount; $i++) {
			$exception = new ABCException;
			$exception->from = $this->readU32();
			$exception->to = $this->readU32();
			$exception->target = $this->readU32();
			$exception->typeIndex = $this->readU32();
			$exception->variableIndex = $this->readU32();
			$methodBody->exceptions[] = $exception;
		}
		$traitCount = $this->readU32();
		for($i = 0; $i < $traitCount; $i++) {
			$methodBody->traits[] = $this->readTrait();
		}
		return $methodBody;
	}
	
	protected function readTrait() {
		$trait = new ABCTrait;
		$trait->nameIndex = $this->readU32();
		$trait->type = $this->readU8();
		switch($trait->type & 0x0F) {
			case 0:		// Trait_Slot
			case 6:		// Trait_Const
				$data = new ABCTraitSlot;
				$data->slotId = $this->readU32();
				$data->typeNameIndex = $this->readU32();
				$data->valueIndex = $this->readU32();
				if($data->valueIndex) {
					$data->valueType = $this->readU8();
				}
				$trait->data = $data;
				break;
			case 1: 	// Trait_Method
			case 2:		// Trait_Getter
			case 3:		// Trait_Setter
				$data = new ABCTraitMethod;
				$data->dispId = $this->readU32();
				$data->methodIndex = $this->readU32();
				$trait->data = $data;
				break;
			case 4:		// Trait_Class
				$data = new ABCTraitClass;
				$data->slotId = $this->readU32();
				$data->classIndex = $this->readU32();
				$trait->data = $data;
				break;
			case 5:		// Trait_Function
				$data = new ABCTraitFunction;
				$data->slotId = $this->readU32();
				$data->methodIndex = $this->readU32();
				$trait->data = $data;
				break;
		}
		if($trait->type & 0x40) {	// ATTR_Metadata
			$metadataCount = $this->readU32();
			for($k = 0; $k < $metadataCount; $k++) {				
				$trait->metadataIndices[] = $this->readU32();
			}
		}
		return $trait;
	}
	
	protected function readU8() {
		$byte = $this->readBytes(1);
		if($byte !== null) {
			return ord($byte);
		}
	}
	
	protected function readU16() {
		$bytes = $this->readBytes(2);
		if($bytes !== null) {		
			$array = unpack('v', $bytes);
			return $array[1];
		}
	}
	
	protected function readU32() {
		$result = null;
		$shift = 0;
		do {
			$byte = $this->readU8();
			if($byte !== null) {
				$result |= ($byte & 0x7F) << $shift;
				$shift += 7;
			}
		} while($byte & 0x80);
		return $result;
	}
	
	protected function readS32() {		
		return $this->readU32();
	}
	
	protected function readD64() {
		$bytes = $this->readBytes(8);
		if($bytes !== null) {		
			$array = unpack('d', $bytes);
			return $array[1];
		}
	}
	
	protected function readBytes($count) {
		$bytes = '';
		$read = 0;
		while($read < $count) {
			$chunk = fread($this->input, min($count - $read, 32768));
			if($chunk != '') {
				$bytes .= $chunk;
				$read += strlen($chunk);
			} else {
				break;
			}			
		}
		return ($bytes != '') ? $bytes : null;
	}	
}

class ABCFile {
	public $majorVersion;
	public $minorVersion;
	public $intTable = array();
	public $uintTable = array();
	public $doubleTable = array();
	public $stringTable = array();
	public $namespaceTable = array();
	public $namespaceSetTable = array();
	public $multinameTable = array();
	public $methodTable = array();
	public $metadataTable = array();
	public $instanceTable = array();
	public $classTable = array();
	public $scriptTable = array();
	public $methodBodyTable = array();
}

class ABCNamespace {
	public $kind;
	public $stringIndex;
}

class ABCNamespaceSet {
	public $namespaceIndices = array();
}

class ABCMultiname {
	public $type;
	public $stringIndex;
	public $namespaceIndex;
	public $namespaceSetIndex;
	public $nameIndex;
	public $typeIndices = array();
}

class ABCMethod {
	public $paramCount;
	public $returnType;
	public $paramTypes = array();
	public $nameIndex;
	public $flags;
	public $optionalParams = array();
	public $paramNameIndices = array();
	
	public $body;
}

class ABCMethodOptionalParameter {
	public $type;
	public $index;
}

class ABCMetadata {
	public $nameIndex;
	public $keyIndices = array();
	public $valueIndices = array();
}

class ABCInstance {
	public $nameIndex;
	public $superNameIndex;
	public $flags;
	public $protectedNamespaceIndex;
	public $interfaceIndices = array();
	public $initializerIndex;
	public $traits = array();
}

class ABCClass {
	public $constructorIndex;
	public $traits = array();
}

class ABCScript {
	public $initializerIndex;
	public $traits = array();
}

class ABCMethodBody {
	public $methodIndex;
	public $maxStack;
	public $localCount;
	public $initScopeDepth;
	public $maxScopeDepth;
	public $byteCodes;
	public $exceptions = array();
	public $traits = array();
}

class ABCTrait {
	public $nameIndex;
	public $type;
	public $data;
	public $metadataIndices = array();
}

class ABCTraitSlot {
	public $slotId;
	public $typeNameIndex;
	public $valueIndex;
	public $valueType;
}

class ABCTraitClass {
	public $slotId;
	public $classIndex;
}

class ABCTraitFunction {
	public $slotId;
	public $methodIndex;
}

class ABCTraitMethod {
	public $dispId;
	public $methodIndex;
}

class ABCException {
	public $from;
	public $to;
	public $target;
	public $typeIndex;
	public $variableIndex;
}

?>