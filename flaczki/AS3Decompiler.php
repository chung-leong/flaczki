<?php

class AS3Decompiler {

	protected $abcFile;
	
	protected $names = array();
	protected $namespaces = array();
	protected $defaultNamespace;
	protected $nameThis;
	protected $nameSuper;
	
	protected $global;
	protected $globalClass;
	
	protected $literalNaN;
	protected $literalTrue;
	protected $literalFalse;
	protected $literalNull;
	protected $literalUndefined;
	protected $literalOne;
	
	protected $typeAny;
	protected $typeBoolean;
	protected $typeClass;
	protected $typeUInt;
	protected $typeInt;
	protected $typeString;
	protected $typeNumber;
	protected $typeObject;
	protected $typeArray;
	protected $typeXML;
	protected $typeUndefined;
	
	public function decompile($abcFile) {
		$this->abcFile = $abcFile;
		
		// set up literals and other fixed objects
		$this->defaultNamespace = $this->getNamespace(0);
		
		$this->nameThis = $this->obtainQName('this');
		$this->nameSuper = $this->obtainQName('super');
		
		$this->typeAny = $this->getName(0);
		$this->typeBoolean = $this->obtainQName('Boolean');
		$this->typeClass = $this->obtainQName('Class');
		$this->typeUInt = $this->obtainQName('uint');
		$this->typeInt = $this->obtainQName('int');
		$this->typeString = $this->obtainQName('String');
		$this->typeNumber = $this->obtainQName('Number');
		$this->typeObject = $this->obtainQName('Object');
		$this->typeArray = $this->obtainQName('Array');
		$this->typeXML = $this->obtainQName('XML');
		$this->typeUndefined = $this->obtainQName('undefined');
		
		$this->literalNaN = new AS3Literal;
		$this->literalNaN->value = NAN;
		$this->literalNaN->type = $this->typeNumber;
		$this->literalTrue = new AS3Literal;
		$this->literalTrue->value = true;
		$this->literalTrue->type = $this->typeBoolean;		
		$this->literalFalse = new AS3Literal;
		$this->literalFalse->value = false;
		$this->literalFalse->type = $this->typeBoolean;		
		$this->literalNull = new AS3Literal;
		$this->literalNull->type = $this->typeObject;
		$this->literalUndefined = new AS3Literal;
		$this->literalUndefined->type = $this->typeAny;
		$this->literalOne = new AS3Literal;
		$this->literalOne->type = $this->typeInt;
		$this->literalOne->value = 1;
		
		// attach traits of scripts to global class
		$globalClass = new AS3Class;
		$globalClass->name = $this->addQName("GLOBAL");
		$globalClass->type = $this->typeClass;
		$globalClass->this = new AS3Variable;
		$globalClass->this->name = $this->nameThis;
		$globalClass->this->type = $this->typeClass;
		$globalClass->members = array();
		$globalClass->instance = new AS3ClassInstance;
		$globalClass->instance->name = $globalClass->name;
		$globalClass->instance->members = array();
		foreach($abcFile->scriptTable as $script) {
			$globalClass->instance->members += $this->decodeTraits($script->traits);
		}
		$globalClass->instance->members[$globalClass->name->index] = $globalClass;
		
		// we use object types to infer what properties an object would have during runtime
		// class objects (the object to which static methods are attached to) themselves 
		// don't belong to a specific class so this interference isn't possible
		// to simplify the logic, we'll assigned special classes to them
		foreach($globalClass->instance->members as $object) {
			if($object instanceof AS3Class) {
				$class = $object;
				$classClass = new AS3Class;
				$classClass->name = $this->addQName("CLASS:{$class->name->text}");
				$classClass->type = $this->typeClass;
				$classClass->members = array();
				$classClass->instance = $class;
				$class->type = $classClass->name;
				$class->this->type = $classClass->name;
				$globalClass->instance->members[$classClass->name->index] = $classClass;
			}
		}
		
		$this->global = new AS3Variable;
		$this->global->type = $globalClass->name;
		$this->global->name = $this->nameThis;
		$this->globalClass = $globalClass;
		
		$this->decompileMembers($globalClass->instance);

		$this->removeTemporaryMembers($globalClass->instance);	
		
		return $globalClass->instance->members;		
	}
	
	protected function removeTemporaryMembers($class) {
		$maxNameId = count($this->abcFile->multinameTable) - 1;
		foreach($class->members as $nameId => $member) {
			if($nameId > $maxNameId || $nameId < 0) {
				unset($class->members[$nameId]);
			} else {
				if($member instanceof AS3Class && $member != $this->globalClass) {
					$this->removeTemporaryMembers($member);
					$this->removeTemporaryMembers($member->instance);
				}
			}
		}
	}
	
	protected function getString($index) {
		$string = $this->abcFile->stringTable[(int) $index];
		return $string;
	}
	
	protected function getDouble($index) {
		$string = $this->abcFile->doubleTable[(int) $index];
		return $string;
	}
	
	protected function getInt($index) {
		$string = $this->abcFile->intTable[(int) $index];
		return $string;
	}
	
	protected function getUInt($index) {
		$string = $this->abcFile->uintTable[(int) $index];
		return $string;
	}
	
	protected function getNamespace($index) {
		$index = (int) $index;
		$namespace =& $this->namespaces[$index];
		if(!$namespace) {
			$nsRec = $this->abcFile->namespaceTable[$index];
			$namespace = new AS3Namespace;
			$namespace->kind = $nsRec->kind;
			$namespace->text = $this->getString($nsRec->stringIndex);
		}
		return $namespace;
	}
	
	protected function getNamespaceSet($index) {
		$index = (int) $index;
		$nssRec = $this->abcFile->namespaceSetTable[$index];
		$namespaces = array();
		foreach($nssRec->namespaceIndices as $nsIndex) {
			$namespaces[] = $this->getNamespace($nsIndex);
		}
		return $namespaces;
	}
	
	protected function getName($index) {
		$index = (int) $index;
		$name =& $this->names[$index];
		if(!$name) {
			$mnRec = $this->abcFile->multinameTable[(int) $index];
			$name = new AS3Name;
			switch($mnRec->type) {
				case 0x0D:
				case 0x10:
				case 0x12:
				case 0x0E:
				case 0x1C: $name->isAttribute = true; break;
			}
			if($mnRec->stringIndex !== null) {
				$name->text = $this->getString($mnRec->stringIndex);
			}
			if($mnRec->namespaceIndex !== null) {
				$name->namespace = $this->getNamespace($mnRec->namespaceIndex);
			}
			if($mnRec->namespaceSetIndex !== null) {
				$name->namespace = $this->getNamespaceSet($mnRec->namespaceSetIndex);
			}
			if($mnRec->typeIndices !== null) {
				$baseName = $this->getName($mnRec->nameIndex);
				$types = array();
				foreach($mnRec->typeIndices as $typeIndex) {
					$typeName = $this->getName($typeIndex);
					$types[] = $typeName->text;
				}
				$types = implode(', ', $types);
				$name->text = "{$baseName->text}.<$types>";
				$name->namespace = $baseName->namespace;
			}
			$name->index = $index;
		}
		return $name;
	}
	
	protected function findQName($text, $namespace = null) {
		$nameIndex = array_search($text, $this->abcFile->stringTable, true);
		foreach($this->abcFile->multinameTable as $mnIndex => $mnRec) {
			if($mnRec->stringIndex === $nameIndex) {
				if($namespace) {
					if($mnRec->namespaceIndex !== null) {
						$nsRec = $this->abcFile->namespaceTable[$mnRec->namespaceIndex];
						$nsText = $this->abcFile->stringTable[(int) $nsRec->stringIndex];
						if(is_array($namespace)) {
							if(!in_array($nsText, $namespace)) {
								continue;
							}
						} else {
							if($nsText != $namespace) {
								continue;
							}
						}
					} else {
						continue;
					}
				}
				return $this->getName($mnIndex);
			}
		}
		return false;
	}
	
	protected function obtainQName($text) {
		if(!($name = $this->findQName($text))) {
			$name = $this->addQName($text);
		}
		return $name;
	}
	
	protected function addQName($text, $namespace = null) {
		$name = new AS3Name;
		$name->namespace = ($namespace) ? $namespace : $this->defaultNamespace;
		$name->text = $text;
		$name->index = max(count($this->names), count($this->abcFile->multinameTable));
		$names[$name->index] = $name;
		return $name;
	}
	
	protected function findGName($baseType, $types) {
		// find the qname of the base type first if it's a multiname
		if(is_array($baseType->namespace)) {
			$namespaces = array();
			foreach($baseType->namespace as $namespace) {
				$namespaces[] = $namespace->text;
			}
			$baseType = $this->findQName($baseType->text, $namespaces);
		}
		// find the generic name
		$typeIndices = array();
		foreach($types as $type) {
			$typeIndices[] = $type->index;
		}
		foreach($this->abcFile->multinameTable as $mnIndex => $mnRec) {
			if($mnRec->nameIndex == $baseType->index) {
				if($mnRec->typeIndices == $typeIndices) {
					return $this->getName($mnIndex);
				}
			}
		}
	}
	
	protected function getClassInstance($expr) {
		// each expression should have a type associated with it
		// type could be AS3Name
		if($expr) {		
			$name = $expr->type;
			$globals = $this->globalClass->instance->members;
			if(isset($globals[$name->index])) {
				$class = $globals[$name->index];
				return $class->instance;
			}
		}
	}

	protected function getParent($expr) {
		$instance = $this->getClassInstance($expr);
		if($instance) {
			return $instance->super;
		}
	}
	
	protected function getPropertyType($expr, $propName) {
		if($propName instanceof AS3RuntimeName) {
			return $this->typeAny;
		} else {
			$instance = $this->getClassInstance($expr);
			if($instance) {
				if(isset($instance->members[$propName->index])) {
					$member = $instance->members[$propName->index];
					return $member->type;
				} else {
					return $this->typeUndefined;
				}
			} else {
				return $this->typeAny;
			}
		}
	}
	
	protected function searchScopeStack($cxt, $propName, $strict) {
		// we can't resolve runtime names
		if(!($propName instanceof AS3RuntimeName)) {
			for($i = count($cxt->scopeStack) - 1; $i >= 0; $i--) {
				$expr = $cxt->scopeStack[$i];
				// scope objects shouldn't have property
				if(!($expr instanceof AS3Scope)) {
					$instance = $this->getClassInstance($expr);
					if(isset($instance->members[$propName->index])) {
						return $expr;
					}
				}
			}
		}
		if(!$strict) {
			return $this->global;
		} else {
			// cannot resolve the property
			// assume the intention is to access the object at the top of the stack
			for($i = count($cxt->scopeStack) - 1; $i >= 0; $i--) {
				$expr = $cxt->scopeStack[$i];
				if(!($expr instanceof AS3Scope)) {
					return $expr;
				}
			}
		}
	}
	
	protected function getSlotName($expr, $slotId) {
		$instance = $this->getClassInstance($expr);
		if($instance) {
			$member = $instance->members[-$slotId];
			return $member->name;
		}
	}
	
	protected function decodeTraits($traitRecs) {
		$members = array();
		foreach($traitRecs as $traitRec) {
			$member = $this->decodeTrait($traitRec);
			if($member) {
				$members[$member->name->index] = $member;
				if($traitRec->slotId) {
					// store member at negative slot id as well so we can find it
					$members[ - $traitRec->slotId] = $member;
				}
			}
		}
		return $members;
	}
	
	protected function decodeTrait($traitRec) {
		switch($traitRec->type & 0x0F) {
			case 0:	return $this->decodeVariable($traitRec);
			case 6: return $this->decodeConstant($traitRec);
			case 1:
			case 2:
			case 3:	return $this->decodeMethod($traitRec);
			case 5: return $this->decodeFunction($traitRec);
			case 4: return $this->decodeClass($traitRec);
		}
	}
	
	protected function decodeClass($traitRec) {
		$classRec = $this->abcFile->classTable[$traitRec->data->classIndex];
		$class = new AS3Class;
		$class->name = $this->getName($traitRec->nameIndex);
		$class->members = $this->decodeTraits($classRec->traits);
		$class->constructor = $this->decodeConstructor($classRec->constructorIndex);
		$class->constructor->name = $class->name;
		$class->instance = $this->decodeClassInstance($traitRec->nameIndex);
		$class->type = $this->typeClass;
		$class->this = new AS3Variable;
		$class->this->name = $this->nameThis;
		$class->this->type = $class->name;
		return $class;
	}
	
	protected function decodeClassInstance($nameIndex) {
		foreach($this->abcFile->instanceTable as $instanceRec) {
			if($instanceRec->nameIndex == $nameIndex) {
				$instance = new AS3ClassInstance;
				$instance->name = $this->getName($instanceRec->nameIndex);
				$instance->members = $this->decodeTraits($instanceRec->traits);
				$instance->constructor = $this->decodeConstructor($instanceRec->constructorIndex);
				$instance->constructor->name = $instance->name;
				$instance->parentName = ($instanceRec->superNameIndex) ? $this->getName($instanceRec->superNameIndex) : null;
				$instance->flags = $instanceRec->flags;
				$instance->protectedNamespace = ($instanceRec->flags & 0x08) ? $this->getNamespace($instanceRec->protectedNamespaceIndex) : null;
				$instance->interfaces = ($instanceRec->interfaceIndices) ? array_map(array($this, 'getName'), $instanceRec->interfaceIndices) : array();
				$instance->type = $instance->name;
				$instance->this = new AS3Variable;
				$instance->this->name = $this->nameThis;
				$instance->this->type = $instance->name;
				$instance->super = new AS3Variable;
				$instance->super->name = $this->nameSuper;
				$instance->super->type = $instance->parentName;
				return $instance;
			}
		}
	}
	
	protected function decodeVariable($traitRec) {
		$var = new AS3Variable;
		$var->type = $this->decodeType($traitRec->data->typeNameIndex);
		$var->name = $this->getName($traitRec->nameIndex);
		$var->defaultValue = $this->decodeValue($traitRec->data->valueType, $traitRec->data->valueIndex);
		return $var;
	}
	
	protected function decodeConstant($traitRec) {
		$const = new AS3Constant;
		$const->type = $this->decodeType($traitRec->data->typeNameIndex);
		$const->name = $this->getName($traitRec->nameIndex);
		$const->defaultValue = $this->decodeValue($traitRec->data->valueType, $traitRec->data->valueIndex);
		return $const;
	}

	protected function decodeMethod($traitRec) {
		$methodRec = $this->abcFile->methodTable[$traitRec->data->methodIndex];
		$method = new AS3Method;
		$name = $this->getName($traitRec->nameIndex);
		if(($traitRec->type & 0x0F) == 2) {
			$method->name = $this->addQName("get $name->text", $name->namespace);
		} else if(($traitRec->type & 0x0F) == 3) {
			$method->name = $this->addQName("set $name->text", $name->namespace);
		} else {
			$method->name = $name;
		}
		$method->arguments = $this->decodeMethodArguments($methodRec);
		$method->type = $this->decodeType($methodRec->returnType);
		$method->byteCodes = ($methodRec->body) ? $methodRec->body->byteCodes : '';		
		$method->exceptions = $this->decodeExceptions($methodRec);
		$method->expressions = array();
		$method->localVariables = array();
		return $method;
	}

	protected function decodeExceptions($methodRec) {
		$exceptions = array();
		if($methodRec->body) {
			foreach($methodRec->body->exceptions as $exceptionRec) {
				$exception =& $exceptions[$exceptionRec->from];
				if(!$exception) {
					$exception = new AS3Exception;
					$exception->from = $exceptionRec->from;
					$exception->to = $exceptionRec->to;
				}
				$exception->targets[] = $exceptionRec->target;
				$errorObject = new AS3Variable;
				$errorObject->type = $this->getName($exceptionRec->typeIndex);
				$errorObject->name = $this->getName($exceptionRec->variableIndex);
				$exception->errorObjects[] = $errorObject;
			}
		}
		return $exceptions;
	}

	protected function decodeFunction($traitRec) {
		$methodRec = $this->abcFile->methodTable[$traitRec->data->methodIndex];
		$function = new AS3Function;
		$function->name = $this->getName($traitRec->nameIndex);
		$function->arguments = $this->decodeMethodArguments($methodRec);
		$function->type = $this->decodeType($methodRec->returnType);
		$function->byteCodes = ($methodRec->body) ? $methodRec->body->byteCodes : '';		
		$function->exceptions = $this->decodeExceptions($methodRec);
		$function->expressions = array();
		$function->localVariables = array();
		return $function;
	}
	
	protected function decodeConstructor($index) {
		$methodRec = $this->abcFile->methodTable[$index];
		$constructor = new AS3Constructor;
		$constructor->arguments = $this->decodeMethodArguments($methodRec);
		$constructor->record = $methodRec;
		$constructor->byteCodes = ($methodRec->body) ? $methodRec->body->byteCodes : '';		
		$constructor->exceptions = $this->decodeExceptions($methodRec);
		$constructor->expressions = array();
		$constructor->localVariables = array();
		return $constructor;
	}
	
	protected function decodeMethodArguments($methodRec) {
		$arguments = array();
		for($i = 0; $i < $methodRec->paramCount; $i++) {
			$arg = new AS3Variable;
			$arg->type = $this->decodeType($methodRec->paramTypes[$i]);
			if(isset($methodRec->paramNameIndices[$i])) {
				$text = $this->getString($methodRec->paramNameIndices[$i]);
				$arg->name = $this->addQName($text);
			} else {
				$arg->name = $this->addQName("arg$i");
			}
			$optionalParamIndex = count($methodRec->optionalParams) - count($methodRec->paramNameIndices) + $i;
			if(isset($methodRec->optionalParams[$optionalParamIndex])) {
				$parameter = $methodRec->optionalParams[$optionalParamIndex];
				$arg->defaultValue = $this->decodeValue($parameter->type, $parameter->index);
			}			
			$arguments[] = $arg;
		}
		return $arguments;
	}
		
	protected function decodeValue($type, $index) {
		if($type) {
			switch($type) {
				case 0x0B: return $this->literalTrue;
				case 0x0A: return $this->literalFalse; 
				case 0x0C: return $this->literalNull;
				case 0x00: return $this->literalUndefined;
				case 0x08: 
				case 0x16: 
				case 0x17: 
				case 0x18: 
				case 0x19: 
				case 0x1A: 
				case 0x05: return $this->getName($index);
			}
			
			$literal = new AS3Literal;
			switch($type) {
				case 0x03: $literal->value = $this->getInt($index); break;
				case 0x04: $literal->value = $this->getUInt($index); break;
				case 0x06: $literal->value = $this->getDouble($index); break;
				case 0x01: $literal->value = $this->getString($index); break;
			}
			return $literal;
		}
	} 
	
	protected function decodeType($index) {
		return ($index) ? $this->getName($index) : $this->typeAny;
	}
	
	protected function decompileMembers($class) {
		foreach($class->members as $index => $member) {
			if($index > 0) {
				if($member instanceof AS3Function) {
					$this->decompileMethod(null, $member);
				} else if($member instanceof AS3Method) {
					$this->decompileMethod($class->this, $member);
				} else if($member instanceof AS3Class && $member != $this->globalClass) {
					$this->decompileMembers($member);
					$this->decompileMembers($member->instance);
				}
			}
		}
		if($class->constructor) {
			//$this->decompileMethod($class->this, $member);
		}
		if($class->constructor) {
			//$this->decompileMethod($class->this, $class->constructor);
		}
	}
	
	protected function decompileMethod($receiver, $method) {
		if(isset($method->byteCodes)) {
			// decode the bytecodes 
			$decoder = new ABCDecoder;
			$ops = $decoder->decode($method->byteCodes);
			unset($method->byteCodes);
			
			foreach($ops as $ip => $op) {
				if(!preg_match('/debug/', $op->name)) {
					$operands = array();
					$i = 1;
					while(1) {
						$name = "op" . $i++;
						if(isset($op->$name)) {
							$operands[] = $op->$name;
						} else {
							break;
						}
					}
					$operands = implode(' ', $operands);
					echo "$ip $op->name $operands\n";
				}
			}
			
			// set up execution environment
			$cxt = new AS3DecompilerContext;
			$cxt->stack = array();
			$cxt->scopeStack = array();
			$cxt->registers = array();
			$cxt->registers[0] = $receiver;
			foreach($method->arguments as $index => $arg) {
				$arg->values = ($arg->defaultValue !== null) ? array($arg->defaultValue) : array();
				$cxt->registers[$index + 1] = $arg;
			}
			// TODO: arguments array
			$cxt->method = $method;
			$cxt->ops = $ops;
			$cxt->currentPosition = 0;
			
			// decompile the ops into expressions
			$expressions = $this->decompileInstructions($cxt);
		
			// divide ops into basic blocks
			$blocks = $this->createBasicBlocks($expressions, $method->exceptions);
			unset($expressions);
			
			// recreate loops and conditional statements
			$this->structureLoops($blocks, $method);
			
			// give local variables names
			$this->assignVariableNames($method->localVariables);
		}
	}
	
	protected function assignVariableNames($variables) {
		$prefixOccurences = array();
		
		foreach($variables as $var) {
			if(!$var->name) {
				if($var->type && $var->type->text) {
					$prefix = preg_replace("/\.<.*>/", "", $var->type->text);
				} else {
					$prefix = "var";
				}
				$prefix = ucfirst($prefix);				
				$count =& $prefixOccurences[$prefix];
				$name = new AS3Name;
				$name->namespace = $this->defaultNamespace;
				$name->text = "loc$prefix" . (++$count);
				$var->name = $name;
			}
		}
	}
	
	protected function decompileInstructions($cxt) {
		$expressions = array();	
		$contexts = array($cxt);
		$exceptions = $cxt->method->exceptions;
		while($contexts) {
			$cxt = array_shift($contexts);
			while(($expr = $this->decompileInstruction($cxt)) !== false) {
				if($expr) {
					if($expr instanceof AS3FlowControl) {
						if($expr instanceof AS3ConditionalBranch) {
							// look for ternary conditional operator
							if($this->decompileTernaryOperator($cxt, $expr)) {
								// the result is on the stack
								continue;
							}
						
							// look ahead find any logical statements that should be part of 
							// the branch's condition
							$this->decompileBranch($cxt, $expr);
						
							// clone the context and add it to the list
							$cxtT = clone $cxt;
							$cxtT->currentPosition = $expr->positionIfTrue;
							$contexts[] = $cxtT;
							$cxt->currentPosition = $expr->positionIfFalse;
							
						} else if($expr instanceof AS3UnconditionalBranch) {
							// jump to the location and continue
							$cxt->currentPosition = $expr->position;
						} else if($expr instanceof AS3SwitchLookup) {
							foreach($expr->casePositions as $casePosition) {
								$cxtC = clone $cxt;
								$cxtC->currentPosition = $casePosition;
								$contexts[] = $cxtC;
							}
							$cxt->currentPosition = $expr->defaultPosition;
						}
					}
					
					if(!isset($expressions[$cxt->lastPosition])) {
						$expressions[$cxt->lastPosition] = $expr;
					} else {
						// we've been here already
						break;
					}
				}
				if(isset($exceptions[$cxt->currentPosition])) {
					// branch to the catch blocks
					$exception = $exceptions[$cxt->currentPosition];
					foreach($exception->targets as $index => $catchPosition) {
						$cxtC = clone $cxt;
						$cxtC->currentPosition = $catchPosition;
						$cxtC->stack = array($exception->errorObjects[$index]);
						$cxtC->scopeStack = array();
						$contexts[] = $cxtC;
					}
				}
			}
		}
		return $expressions;
	}
	
	protected function decompileBranch($cxt, $branch) {
		if($branch->position > 0) {
			// find all other conditional branches immediately following this one
			$cxtT = clone $cxt;
			$cxtT->currentPosition = $branch->positionIfTrue;
			$cxtT->relatedBranch = $branch;
			$cxtT->branchOnTrue = true;
			$cxtF = clone $cxt;
			$cxtF->currentPosition = $branch->positionIfFalse;		
			$cxtF->relatedBranch = $branch;
			$cxtF->branchOnTrue = false;
			$contexts = array($cxtT, $cxtF);
			$count = 0;
			while($contexts) {
				$cxt = array_shift($contexts);
				while(($expr = $this->decompileInstruction($cxt)) !== false) {
					if($expr) {
						if($expr instanceof AS3ConditionalBranch) {
							if($cxt->branchOnTrue) {
								$cxt->relatedBranch->branchIfTrue = $expr;
							} else {
								$cxt->relatedBranch->branchIfFalse = $expr;
							}
							$cxtT = clone $cxt;
							$cxtT->currentPosition = $expr->positionIfTrue;
							$cxtT->relatedBranch = $expr;
							$cxtT->branchOnTrue = true;
							$contexts[] = $cxtT;
							$cxt->currentPosition = $expr->positionIfFalse;
							$cxt->relatedBranch = $expr;
							$cxt->branchOnTrue = false;
						} else {
							break;
						}
					}
				}
			}
			
			// collapsed branches into AND/OR statements
			// first, get rid of duplicates to simplify the logic
			$this->collapseDuplicateBranches($branch);
			
			// keep reducing until there are no more changes
			do {
				$changed = $this->collapseBranches($branch);
			} while($changed);
			unset($branch->branchIfTrue, $branch->branchIfFalse);
		}
	}
	
	protected function collapseDuplicateBranches($branch) {
		if(isset($branch->branchIfTrue)) {
			$this->collapseDuplicateBranches($branch->branchIfTrue);
			if($branch->condition === $branch->branchIfTrue->condition) {
				$branch->positionIfTrue = $branch->branchIfTrue->positionIfTrue;
				if(isset($branch->branchIfTrue->branchIfTrue)) {
					$branch->branchIfTrue = $branch->branchIfTrue->branchIfTrue;
				} else {
					unset($branch->branchIfTrue);
				}
			}
		}
		if(isset($branch->branchIfFalse)) {
			$this->collapseDuplicateBranches($branch->branchIfFalse);
			if($branch->condition === $branch->branchIfFalse->condition) {
				$branch->positionIfFalse = $branch->branchIfFalse->positionIfFalse;
				if(isset($branch->branchIfFalse->branchIfFalse)) {
					$branch->branchIfFalse = $branch->branchIfFalse->branchIfFalse;
				} else {
					unset($branch->branchIfFalse);
				}
			}
		}
	}
	
	protected function collapseBranches($branch) {
		$changed = false;
		if(isset($branch->branchIfTrue)) {
			if($branch->branchIfTrue->positionIfFalse == $branch->positionIfFalse) {
				$logicalExpr = new AS3BinaryOperation;
				$logicalExpr->operator = '&&';
				$logicalExpr->operand1 = $branch->condition;
				$logicalExpr->operand2 = $branch->branchIfTrue->condition;
				$logicalExpr->precedence = 12;
				$branch->condition = $logicalExpr;
				$branch->positionIfTrue = $branch->branchIfTrue->positionIfTrue;
				if(isset($branch->branchIfTrue->branchIfTrue)) {
					$branch->branchIfTrue = $branch->branchIfTrue->branchIfTrue;
				} else {
					unset($branch->branchIfTrue);
				}
				$changed = true;
			} else {
				$changed = $this->collapseBranches($branch->branchIfTrue) || $changed;
			}
		}
		if(isset($branch->branchIfFalse)) {
			if($branch->branchIfFalse->positionIfTrue == $branch->positionIfTrue) {
				$logicalExpr = new AS3BinaryOperation;
				$logicalExpr->operator = '||';
				$logicalExpr->operand1 = $branch->condition;
				$logicalExpr->operand2 = $branch->branchIfFalse->condition;
				$logicalExpr->precedence = 13;
				$branch->condition = $logicalExpr;
				$branch->positionIfFalse = $branch->branchIfFalse->positionIfFalse;
				if(isset($branch->branchIfFalse->branchIfFalse)) {
					$branch->branchIfFalse = $branch->branchIfFalse->branchIfFalse;
				} else {
					unset($branch->branchIfFalse);
				}
				$changed = true;
			} else {
				$changed = $this->collapseBranches($branch->branchIfFalse) || $changed;
			}
		}
		return $changed;
	}
	
	protected function decompileTernaryOperator($cxt, $branch) {
		$cxtF = clone $cxt;
		$cxtT = clone $cxt;
		$cxtT->currentPosition = $branch->position;
		$stackHeight = count($cxtF->stack);
		$uBranch = null;
		// keep decompiling until we hit an unconditional branch
		while(($expr = $this->decompileInstruction($cxtF)) !== false) {
			if($expr) {
				if($expr instanceof AS3UnconditionalBranch) {
					// something should have been pushed onto the stack
					if(count($cxtF->stack) == $stackHeight + 1) {
						$uBranch = $expr;
						break;
					} else {
						return false;
					}
				} else if($expr instanceof AS3ConditionalBranch) {
					// could be a ternary inside a ternary
					if(!$this->decompileTernaryOperator($cxtF, $expr)) {
						return false;
					}
				} else {
					return false;
				}
			}
		}
		if($uBranch) {
			// the value of the operator when the conditional expression evaluates to false
			$valueF = end($cxtF->stack);
			
			// see where the expression would end up
			while(($expr = $this->decompileInstruction($cxtF)) !== false) {
				if($expr) {
					if($expr instanceof AS3SwitchLookup) {
						// it's actually a jump to lookupswitch
						return false;
					} else {
						break;
					}
				}
			}
			
			// get the value for the branch by decompiling up to the destination of the unconditional jump 
			while(($expr = $this->decompileInstruction($cxtT)) !== false) {
				if($expr) {
					// no expression should be generated
					return false;
				}
				if($cxtT->currentPosition == $uBranch->position) {
					break;
				}
			}
			if(count($cxtT->stack) == $stackHeight + 1) {			
				$valueT = array_pop($cxtT->stack);
				// swap the values if we branch on false
				$branchOnTrue = ($branch->position == $branch->positionIfTrue);
				$expr = new AS3TernaryConditional;
				$expr->condition = $branch->condition;
				$expr->valueIfTrue = ($branchOnTrue) ? $valueT : $valueF;
				$expr->valueIfFalse = ($branchOnTrue) ? $valueF : $valueT;
				$expr->precedence = 14;
				$expr->type = $this->chooseSpecificType($valueT->type, $valueF->type);
				// push the expression on to the caller's context  and advance the instruction pointer 
				// to the jump destination
				array_push($cxt->stack, $expr);
				$cxt->currentPosition = $uBranch->position;
				return true;
			}
		}
		return false;
	}
	
	protected function chooseSpecificType($type1, $type2 /* ... */) {
		$types = func_get_args();
		$topScore = 0;
		$bestType = null;
		foreach($types as $index => $type) {
			if($type === $this->typeUndefined) {
				$score = 1;
			} else if($type === $this->typeAny) {
				$score = 2;
			} else if($type === $this->typeObject) {
				$score = 3;
			} else {
				$score = 4;
			}
			if($score > $topScore) {
				$bestType = $type;
			}
		}
		return $bestType;
	}
	
	protected function decompileInstruction($cxt) {
		if(isset($cxt->ops[$cxt->currentPosition])) {
			$op = $cxt->ops[$cxt->currentPosition];
			$cxt->nextPosition = $cxt->currentPosition + $op->width;
			$handler = "do_{$op->name}";
			$expr = $this->$handler($cxt, $op);
			$cxt->lastPosition = $cxt->currentPosition;
			$cxt->currentPosition = $cxt->nextPosition;
			if($expr instanceof AS3Expression && !$expr->type) {
				//die("Missing type: $handler {$cxt->lastPosition}");
			}
			return $expr;
		}
		return false;
	}
	
	protected function createBasicBlocks($expressions, $exceptions) {
		// find block entry positions
		$isEntry = array(0 => true);
		foreach($expressions as $ip => $expr) {
			if($expr instanceof AS3ConditionalBranch) {
				$isEntry[$expr->positionIfTrue] = true;
				$isEntry[$expr->positionIfFalse] = true;
			} else if($expr instanceof AS3UnconditionalBranch) {
				$isEntry[$expr->position] = true;
			} else if($expr instanceof AS3SwitchLookup) {
				foreach($expr->casePositions as $casePosition) {
					$isEntry[$casePosition] = true;
				}
				$isEntry[$expr->defaultPosition] = true;
			}
		}
		foreach($exceptions as $exception) {
			$isEntry[$exception->from] = true;
			$isEntry[$exception->to] = true;
			foreach($exception->targets as $target) {
				$isEntry[$target] = true;
			}
		}		
		
		// put nulls into place where there's no statement 
		foreach($isEntry as $ip => $state) {
			if(!isset($expressions[$ip])) {
				$expressions[$ip] = null;
			}
		}
		ksort($expressions);

		$blocks = array();
		$prev = null;
		foreach($expressions as $ip => $expr) {
			if(isset($isEntry[$ip])) {				
				if(isset($block)) {
					$block->next = $ip;
				}
				$block = new AS3BasicBlock;
				$blocks[$ip] = $block;
				$block->prev = $prev;
				$prev = $ip;
			}
			if($expr) {
				$block->expressions[$ip] = $expr;
				$block->lastExpression =& $block->expressions[$ip];
			}
		}

		foreach($blocks as $blockPosition => $block) {
			if($block->lastExpression instanceof AS3ConditionalBranch) {
				$block->to[] = $block->lastExpression->positionIfTrue;
				$block->to[] = $block->lastExpression->positionIfFalse;
			} else if($block->lastExpression instanceof AS3UnconditionalBranch) {
				$block->to[] = $block->lastExpression->position;
			} else if($block->lastExpression instanceof AS3SwitchLookup) {
				foreach($block->lastExpression->casePositions as $casePosition) {
					$block->to[] = $casePosition;
				}					
				$block->to[] = $block->lastExpression->defaultPosition;
			} else {
				if($block->next !== null) {
					$block->to[] = $block->next;
				}
			}
		}
		
		foreach($blocks as $blockPosition => $block) {
			sort($block->to);
			foreach($block->to as $to) {
				$toBlock = $blocks[$to];
				$toBlock->from[] = $blockPosition;
			}
		}
		return $blocks;
		
	}
	
	protected function structureLoops($blocks, $method) {			
		$loops = $this->createLoops($blocks);
		$exceptions = $method->exceptions;
		$exceptionBlocks = array();
		// convert the loops into either while or do-while
		foreach($loops as $loop) {
			if($loop->headerPosition !== null) {
				// header is the block containing the conditional statement
				// it is not the first block
				$headerBlock = $blocks[$loop->headerPosition];
				$headerBlock->structured = true;
				
				// there should be an unconditional branch into the loop
				// where it goes to determines if it's a do-while or a while				
				$entryBlock = $blocks[$loop->entrancePosition];
				if($entryBlock->lastExpression instanceof AS3UnconditionalBranch) {
					if($headerBlock->lastExpression->condition instanceof AS3HasNext) {
						// it's actually a for-in or for-each loop
						// first, look for the assignments to temporary variable in the block before
						$condition = $headerBlock->lastExpression->condition;
						$loopIndex = $loopObject = null;
						for($setExpr = end($entryBlock->expressions); $setExpr && !($loopIndex && $loopObject); $setExpr = prev($entryBlock->expressions)) {
							if($setExpr instanceof AS3BinaryOperation && $setExpr->operator == '=') {
								if($setExpr->operand1 === $condition->index) {
									$loopIndex = $setExpr->operand2;
									unset($entryBlock->expressions[key($entryBlock->expressions)]);
								} else if($setExpr->operand1 === $condition->object) {
									$loopObject = $setExpr->operand2;
									unset($entryBlock->expressions[key($entryBlock->expressions)]);
								}
							}
						}
						
						// look for assignment to named variable 
						$firstBlock = $blocks[$headerBlock->lastExpression->position];
						$loopVar = $loopValue = null;
						for($setExpr = reset($firstBlock->expressions); $setExpr && !$loopVar; $setExpr = next($firstBlock->expressions)) {
							if($setExpr instanceof AS3BinaryOperation && $setExpr->operator == '=') {
								$loopVar = $setExpr->operand1;
								$loopValue = $setExpr->operand2;
								unset($firstBlock->expressions[key($firstBlock->expressions)]);
							}
						}
						$condition = new AS3BinaryOperation;
						$condition->operator = 'in';
						$condition->operand1 = $loopVar;
						$condition->operand2 = $loopObject;
						$condition->precedence = 7;
						
						if($loopValue instanceof AS3NextValue) {
							$expr = new AS3ForEachLoop;
						} else {
							$expr = new AS3ForInLoop;
						}
						$expr->condition = $condition;
					} else {
						// it goes to the beginning of the loop (or just pass the label) so it must be a do-while
						if($entryBlock->lastExpression->position == $headerBlock->lastExpression->position
						|| $entryBlock->lastExpression->position == $headerBlock->lastExpression->position + 1) {
							$expr = new AS3DoWhileLoop;
						} else {			
							$expr = new AS3WhileLoop;
						}
						$expr->condition = $headerBlock->lastExpression->condition;
					}
					$expr->expressions = array();
					$entryBlock->lastExpression = $expr;
					$headerBlock->lastExpression = null;
				}
			} else {
				// no header--the "loop" is the method itself
				$method->expressions = array();
				$expr = $method;
			}

			// recreate switch statements first, so breaks in it don't get changed to continues 
			// when there's a switch inside a loop (the break in the switch would jump to the continue block of the loop)
			foreach($loop->contentPositions as $blockPosition) {
				$block = $blocks[$blockPosition];
				$this->structureSwitch($block, $blocks);
			}
			
			// convert jumps to breaks and continues
			foreach($loop->contentPositions as $blockPosition) {
				$block = $blocks[$blockPosition];
				$this->structureBreakContinue($block, $loop->continuePosition, $loop->breakPosition);
			}

			// recreate if statements
			foreach($loop->contentPositions as $blockPosition) {
				$block = $blocks[$blockPosition];
				$this->structureIf($block, $blocks);
			}

			// move stuff into the loop			
			$try = null;	
			$exception = null;
			foreach($loop->contentPositions as $blockPosition) {
				// don't take stuff from inner loops
				$block = $blocks[$blockPosition];
				if($block->destination === null) {
					// see if block is inside a try block
					if(isset($exceptions[$blockPosition])) {
						$exception = $exceptions[$blockPosition];
						$exceptionBlock = new AS3ExceptionBlock;
						$exceptionBlock->expressions = array();
						$exceptionBlock->destination =& $expr->expressions;
						$exceptionBlocks[$exception->from] = $exceptionBlock;
						
						$try = new AS3TryBlock;
						$try->expressions = array();
						$exceptionBlock->expressions[] = $try;
						
						// find blocks that belong in the catch block
						foreach($exception->targets as $index => $targetPosition) {
							$catch = new AS3CatchBlock;
							$catch->expressions = array();
							$catch->error = $exception->errorObjects[$index];
							$catchBlock = $blocks[$targetPosition];
							
							$prevBlock = $blocks[$catchBlock->prev];
							
							// there's always an unconditional branch before the catch to leap over it
							$endPosition = $prevBlock->lastExpression->position;
							$prevBlock->lastExpression = null;
							
							// move blocks into the catch block until we hit the end position
							$stack = array($targetPosition);
							while($stack) {
								$to = array_pop($stack);
								$toBlock = $blocks[$to];
								if($toBlock->destination === null) {								
									$toBlock->destination =& $catch->expressions;
									foreach($toBlock->to as $to) {
										if($to != $endPosition) {
											array_push($stack, $to);
										}
									}
								}
							}
							$exceptionBlock->expressions[] = $catch;
						}
					}
					if($exception) {
						if($blockPosition < $exception->to) {
							$block->destination =& $try->expressions;
							continue;
						} else {
							$exception = null;
							$try = null;
						}
					} 
					$block->destination =& $expr->expressions;
				}
			}
		}
		
		// copy expressions to where they belong
		foreach($blocks as $ip => $block) {
			if(is_array($block->destination)) {
				foreach($block->expressions as $expr) {
					$block->destination[] = $expr;
				}
				if(isset($exceptionBlocks[$ip])) {
					$exceptionBlock = $exceptionBlocks[$ip];
					foreach($exceptionBlock->expressions as $expr) {
						$exceptionBlock->destination[] = $expr;
					}
				}
			}
		}
	}
	
	protected function structureBreakContinue($block, $continuePosition, $breakPosition) {
		if($block->lastExpression instanceof AS3UnconditionalBranch && !$block->structured) {
			// if it's a jump to the break position (i.e. to the block right after the while loop) then it's a break 
			// if it's a jump to the continue position (i.e. the tail block containing the backward jump) then it's a continue 
			if($block->lastExpression->position === $breakPosition) {
				$block->lastExpression = new AS3Break;
				$block->structured;
			} else if($block->lastExpression->position === $continuePosition) {
				$block->lastExpression = new AS3Continue;
				$block->structured;
			}
		}
	}
		
	protected function structureIf($block, $blocks) {
		if($block->lastExpression instanceof AS3ConditionalBranch && !$block->structured) {
		
			$tBlock = $blocks[$block->lastExpression->positionIfTrue];
			$fBlock = $blocks[$block->lastExpression->positionIfFalse];
			
			$this->structureIf($tBlock, $blocks);
			$this->structureIf($fBlock, $blocks);
					
			$if = new AS3IfLoop;
			$if->condition = $block->lastExpression->condition;
			$if->expressionsIfTrue = array();
			$tBlock->destination =& $if->expressionsIfTrue;
			
			// if there's not other way to enter the block, then the statements must be in an else block
			if(count($fBlock->from) == 1) {
				$if->expressionsIfFalse = array();
				$fBlock->destination =& $if->expressionsIfFalse;
			}
			$block->lastExpression = $if;
			$block->structured = true;
		}
	}
	
	protected function structureSwitch($block, $blocks) {
		// look for an conditional branch into a switch lookup
		if($block->lastExpression instanceof AS3ConditionalBranch && !$block->structured) {
			$jumpBlock = $blocks[$block->lastExpression->positionIfTrue];
			if($jumpBlock->lastExpression instanceof AS3UnconditionalBranch) {
				$lookupBlock = $blocks[$jumpBlock->lastExpression->position];
				if($lookupBlock->lastExpression instanceof AS3SwitchLookup && !$lookupBlock->structured) {
					// find all the conditionals
					$conditions = array();
					$conditionBlock = $firstConditionBlock = $block;
					while($conditionBlock->lastExpression instanceof AS3ConditionalBranch) {
						$jumpBlock = $blocks[$conditionBlock->lastExpression->positionIfTrue];
						$conditions[] = $conditionBlock->lastExpression->condition;
						$conditionBlock->structured = $jumpBlock->structured = true;
						$conditionBlock->destination = $jumpBlock->destination = false;
						$conditionBlock = $blocks[$conditionBlock->lastExpression->positionIfFalse];
					} 
					$jumpBlock = $conditionBlock;
					$jumpBlock->structured = true;
					$jumpBlock->destination = false;
					
					$lookupBlock->structured = true;
					$lookupBlock->destination = false;
										
					// go through the cases in reverse, so a case that falls through to the next would not grab blocks belong to the next case
					$breakPosition = $lookupBlock->next;
					$casePositions = $lookupBlock->lastExpression->casePositions;
					$cases = array();
					for($i = count($casePositions) - 1; $i >= 0; $i--) {
						$case = new AS3SwitchCase;
						$case->expressions = array();
						$condition = ($i < count($conditions)) ? $conditions[$i] : null;
						$casePosition = $casePositions[$i];
						
						// the last instruction should be a strict comparison branch
						// except for the default case, which has a constant false as condition
						if($condition instanceof AS3BinaryOperation) {
							// the lookup object in $block->expressions is only valid to the first case
							// a different offset is put on the stack before every jump to the lookup instruction
							// instead generating different expressions from different paths, we'll just assume
							// the offsets are sequential
							$case->constant = $condition->operand1;
						}
						
						// move all blocks into the case until we are at the break
						$stack = array($casePosition);
						while($stack) {
							$to = array_pop($stack);
							$toBlock = $blocks[$to];
							if($toBlock->destination === null) {								
								$toBlock->destination =& $case->expressions;
		
								// change jumps to break statements
								$this->structureBreakContinue($toBlock, null, $breakPosition);
								
								// structure if statements
								$this->structureIf($toBlock, $blocks);
								
								foreach($toBlock->to as $to) {
									if($to != $breakPosition) {
										array_push($stack, $to);
									}
								}
							}
						}
						$cases[$i] = $case;
					}
					
					// the expression is set to a local variable before comparisons are make
					$assignment = reset($firstConditionBlock->expressions);
					$compareValue = $assignment->operand2;

					$switch = new AS3SwitchLoop;
					$switch->compareValue = $compareValue;
					$switch->defaultCase = array_shift($cases);
					$switch->cases = array_reverse($cases);
					
					// the block that jumps to the first conditional startment block
					// is where the switch loop should be placed
					$switchBlock = $blocks[$firstConditionBlock->from[0]];
					$switchBlock->lastExpression = $switch;
				}
			}
		}
	}
	
	protected function createLoops($blocks) {
		// see which blocks dominates
		$dominators = array();		
		$dominatedByAll = array();
		foreach($blocks as $blockPosition => $block) {
			$dominatedByAll[$blockPosition] = true;
		}
		foreach($blocks as $blockPosition => $block) {
			if($blockPosition == 0) {
				$dominators[$blockPosition] = array(0 => true);
			} else {
				$dominators[$blockPosition] = $dominatedByAll;
			}
		}
		do {
			$changed = false;
			foreach($blocks as $blockPosition => $block) {
				foreach($block->from as $from) {
					$dominatedByBefore = $dominators[$blockPosition];
					$dominatedBy =& $dominators[$blockPosition];
					$dominatedBy = array_intersect_key($dominatedBy, $dominators[$from]);
					$dominatedBy[$blockPosition] = true;
					if($dominatedBy != $dominatedByBefore) {
						$changed = true;
					}
				}
			}
		} while($changed);
		
		$loops = array();
		foreach($blocks as $blockPosition => $block) {
			if(!$block->structured && $blockPosition != 0) {
				foreach($block->to as $to) {
					// a block dominated by what it goes to is a the tail of the loop
					// that block is the loop header--it contains the conditional statement
					if(isset($dominators[$blockPosition][$to])) {
						$headerBlock = $blocks[$to];
						if($headerBlock->lastExpression instanceof AS3ConditionalBranch) {
							$loop = new AS3Loop;
							$loop->headerPosition = $to;
							$loop->continuePosition = $blockPosition;
							$loop->breakPosition = $headerBlock->lastExpression->positionIfFalse;
							$loop->contentPositions = array($loop->headerPosition, $loop->continuePosition);
							
							// all blocks that leads to the continue block are in the loop
							// we won't catch blocks whose last statement is return, but that's okay
							$stack = array($loop->continuePosition);
							while($stack) {
								$fromPosition = array_pop($stack);
								$fromBlock = $blocks[$fromPosition];
								foreach($fromBlock->from as $fromPosition) {
									if(!in_array($fromPosition, $loop->contentPositions)) {
										$loop->contentPositions[] = $fromPosition;
										array_push($stack, $fromPosition);
									}
								}
							}
							
							sort($loop->contentPositions);
							$loop->entrancePosition = $headerBlock->from[0];
							$loops[$blockPosition] = $loop;
							$block->structured = true;
						}
					}
				}
			}
		}
		
		// sort the loops, moving innermost ones to the beginning
		usort($loops, array($this, 'compareLoops'));
		
		// add outter loop encompassing the method body
		$loop = new AS3Loop;
		$loop->contentPositions = array_keys($blocks);
		$loops[] = $loop;
		
		return $loops;
	}
	
	protected function compareLoops($a, $b) {
		if(in_array($a->headerPosition, $b->contentPositions)) {
			return -1;
		} else if(in_array($b->headerPosition, $a->contentPositions)) {
			return 1;
		}
		return 0;
	}
	
	protected function resolveName($cxt, $index) {
		$name = $this->getName($index);
		if($name->text !== null && $name->namespace !== null) {
			return $name;
		}
		$rtName = new AS3RuntimeName;
		if($name->text !== null) {
			$rtName->text = $name->text;
		} else {
			$rtName->text = array_pop($cxt->stack);
		}
		$rtName->namespace = array_pop($cxt->stack);
		return $rtName;
	}
	
	protected function do_add($cxt, $op) {
		$this->do_binary_op($cxt, '+', 5, $this->typeNumber);
	}
	
	protected function do_add_i($cxt, $op) {
		$this->do_binary_op($cxt, '+', 5, $this->typeInt);
	}
	
	protected function do_applytype($cxt, $op) {
		$types = array();
		for($i = 0; $i < $op->op1; $i++) {
			$lookup = array_pop($cxt->stack);
			$types[] = $lookup->name;
		}
		$lookup = array_pop($cxt->stack);
		$baseType = $lookup->name;
		$genericType = $this->findGName($baseType, $types);
		$lookup->name = $genericType;
		$lookup->type = $genericType;
		array_push($cxt->stack, $lookup);
	}
	
	protected function do_astype($cxt, $op) {
		$name = $this->getName($op->op1);
		$expr = new AS3BinaryOperation;
		$expr->operator = 'as';
		$expr->operand2 = $name;
		$expr->operand1 = array_pop($cxt->stack);
		$expr->precedence = 7;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_astypelate($cxt, $op) {
		$this->do_binary_op($cxt, 'as', 7, $this->typeAny);
	}
	
	protected function do_binary_op($cxt, $operator, $precedence, $type) {
		$expr = new AS3BinaryOperation;
		$expr->operator = $operator;
		$expr->operand2 = array_pop($cxt->stack);
		$expr->operand1 = array_pop($cxt->stack);
		$expr->precedence = $precedence;
		$expr->type = $type;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_bitand($cxt, $op) {
		$this->do_binary_op($cxt, '&', 9, $this->typeInt);
	}
	
	protected function do_bitnot($cxt, $op) {
		$this->do_unary_op($cxt, '~', 3, $this->typeInt);
	}
	
	protected function do_bitor($cxt, $op) {
		$this->do_binary_op($cxt, '|', 11, $this->typeInt);
	}
	
	protected function do_bitxor($cxt, $op) {
		$this->do_binary_op($cxt, '^', 10, $this->typeInt);
	}
	
	protected function do_bkpt($cxt, $op) {
		// do nothing
	}
	
	protected function do_bkptline($cxt, $op) {
		// do nothing
	}
	
	protected function do_call($cxt, $op) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->function->name = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
			
	protected function do_callmethod($cxt, $op) {
		$expr = new AS3MethodCall;
		$expr->args = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->getSlotName($expr->receiver, $op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callproperty($cxt, $op) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->resolveName($cxt, $op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callproplex($cxt, $op) {
		$this->do_callproperty($cxt, $op);
	}
	
	protected function do_callpropvoid($cxt, $op) {
		$this->do_callproperty($cxt, $op);
		return array_pop($cxt->stack);
	}
	
	protected function do_callstatic($cxt, $op) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->getMethodName($op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callstaticvoid($cxt, $op) {
		$this->callstatic($cxt);
		return array_pop($cxt->stack);
	}
	
	protected function do_callsuper($cxt, $op) {		
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->resolveName($cxt, $op->op1);
		$object = array_pop($cxt->stack);
		$expr->function->receiver = $this->getParent($object);
		$expr->type = $this->getPropertyType($super, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callsupervoid($cxt, $op) {
		$this->do_callsuper($cxt, $op);
		return $this->do_pop($cxt, $op);
	}
	
	protected function do_coerce($cxt, $op) {
		$type = $this->resolveName($cxt, $op->op1);
		$this->do_convert_x($cxt, $type);
	}
	
	protected function do_checkfilter($cxt, $op) {
		// nothing happens
	}
	
	protected function do_coerce_a($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeAny);
	}
	
	protected function do_coerce_s($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeString);
	}
	
	protected function do_construct($cxt, $op) {
		$expr = new AS3ConstructorCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->receiver = array_pop($cxt->stack);
		$expr->name = $expr->receiver->name;
		$expr->type = $expr->receiver->type;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_constructprop($cxt, $op) {
		$expr = new AS3ConstructorCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->name = $this->resolveName($cxt, $op->op1);
		$expr->receiver = array_pop($cxt->stack);
		$expr->type = $expr->name;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_constructsuper($cxt, $op) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->function = $this->nameSuper;
		$expr->receiver = array_pop($cxt->stack);
		return $expr;
	}
	
	protected function do_convert_b($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeBoolean);
	}
	
	protected function do_convert_d($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeNumber);
	}
	
	protected function do_convert_i($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeInt);
	}
	
	protected function do_convert_o($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeObject);
	}
	
	protected function do_convert_s($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeString);
	}
	
	protected function do_convert_u($cxt, $op) {
		$this->do_convert_x($cxt, $this->typeUInt);
	}
	
	protected function do_convert_x($cxt, $type) {
		$val = array_pop($cxt->stack);
		if($val instanceof AS3Literal) {
			// just set the type
			$val->type = $type;
		} else if($val->type != $type) {
			$expr = new AS3UnaryOperation;
			$expr->operator = "($type->text)";
			$expr->operand = $val;
			$expr->precedence = 3;
			$expr->type = $type;
		}
		array_push($cxt->stack, $val);
	}
	
	protected function do_debug($cxt, $op) {
		// do nothing
	}
	
	protected function do_debugfile($cxt, $op) {
		// do nothing
	}
	
	protected function do_debugline($cxt, $op) {
		// do nothing
	}
	
	protected function do_declocal($cxt, $op) {
		return $this->do_unary_op_local($cxt, $op->op1, '--', 3, $this->typeNumber);
	}
	
	protected function do_declocal_i($cxt, $op) {
		return $this->do_unary_op_local($cxt, $op->op1, '--', 3, $this->typeNumber);
	}
	
	protected function do_decrement($cxt, $op) {
		array_push($cxt->stack, $this->literalOne);
		$this->do_binary_op($cxt, '-', 5, $this->typeNumber);
	}
	
	protected function do_decrement_i($cxt, $op) {
		array_push($cxt->stack, $this->literalOne);
		$this->do_binary_op($cxt, '-', 5, $this->typeInt);
	}
	
	protected function do_deleteproperty($cxt, $op) {
		$this->do_getproperty($cxt, $op);
		$this->do_unary_op($cxt, 'delete', 3, $this->typeBoolean);
	}
	
	protected function do_divide($cxt, $op) {
		$this->do_binary_op($cxt, '/', 4, $this->typeNumber);
	}
	
	protected function do_dup($cxt, $op) {
		array_push($cxt->stack, end($cxt->stack));
	}
	
	protected function do_dxns($cxt, $op) {
		$xmlNS = $this->getString($op->op1);
	}
			
	protected function do_dxnslate($cxt, $op) {
		$xmlNS = array_pop($cxt->stack);
	}
	
	protected function do_equals($cxt, $op) {
		$this->do_binary_op($cxt, '==', 8, $this->typeBoolean);
	}
	
	/* TODO
	protected function do_esc_xattr($cxt, $op) {
	}*/
	
	/* TODO
	protected function do_esc_xelem($cxt, $op) {
	}*/
	
	protected function do_findproperty($cxt, $op) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = $this->searchScopeStack($cxt, $name, false);	
		array_push($cxt->stack, $object);
	}
	
	protected function do_findpropstrict($cxt, $op) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = $this->searchScopeStack($cxt, $name, true);	
		array_push($cxt->stack, $object);
	}
	
	/* TODO
	protected function do_getdescendants($cxt, $op) {
	}*/
	
	protected function do_getglobalscope($cxt, $op) {
		array_push($cxt->stack, $this->global);
	}
	
	protected function do_getglobalslot($cxt, $op) {
		$expr = new AS3PropertyLookUp;
		$expr->receiver = $this->global;
		$expr->name = $this->getSlotName($this->global, $op->op1);
		$expr->type = $this->getPropertyType($this->global, $expr->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getproperty($cxt, $op) {
		$expr = new AS3PropertyLookUp;
		$expr->name = $this->resolveName($cxt, $op->op1);
		$expr->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->receiver, $expr->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getlex($cxt, $op) {
		$expr = new AS3PropertyLookUp;
		$expr->name = $this->resolveName($cxt, $op->op1);
		$expr->receiver = $this->searchScopeStack($cxt, $expr->name, true);	
		$expr->type = $this->getPropertyType($expr->receiver, $expr->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getlocal_0($cxt, $op) {
		$this->do_getlocal_x($cxt, 0);
	}
	
	protected function do_getlocal_1($cxt, $op) {
		$this->do_getlocal_x($cxt, 1);
	}
	
	protected function do_getlocal_2($cxt, $op) {
		$this->do_getlocal_x($cxt, 2);
	}
	
	protected function do_getlocal_3($cxt, $op) {
		$this->do_getlocal_x($cxt, 3);
	}
	
	protected function do_getlocal($cxt, $op) {
		$this->do_getlocal_x($cxt, $op->op1);
	}
	
	protected function do_getlocal_x($cxt, $reg) {
		$var = $cxt->registers[$reg];
		array_push($cxt->stack, $var);
	}
	
	protected function do_getslot($cxt, $op) {
		$object = array_pop($cxt->stack);
		if($object instanceof AS3Scope) {
			$expr = $object->members[- $op->op1];
		} else {
			$name = $this->getSlotName($object, $op->op1);
			$expr = new AS3PropertyLookUp;
			$expr->receiver = $object;
			$expr->name = $name;
			$expr->type = $this->getPropertyType($object, $name);
		}
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getscopeobject($cxt, $op) {
		$object = $cxt->scopeStack[$op->op1];
		array_push($cxt->stack, $object);
	}
	
	protected function do_getsuper($cxt, $op) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = array_pop($cxt->stack);
		$expr = new AS3PropertyLookup;
		$expr->name = $name;
		$expr->receiver = $this->getParent($object);
		$expr->type = $this->getPropertyType($object, $name);
		array_push($cxt->stack, $expr);
	}
		
	protected function do_greaterthan($cxt, $op) {
		$this->do_binary_op($cxt, '>', 7, $this->typeBoolean);
	}
	
	protected function do_greaterequals($cxt, $op) {
		$this->do_binary_op($cxt, '>=', 7, $this->typeBoolean);
	}
	
	protected function do_hasnext($cxt, $op) {
		$expr = new AS3HasNext;
		$expr->index = array_pop($cxt->stack);
		$expr->object = array_pop($cxt->stack);
		$expr->type = $this->typeBoolean;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_hasnext2($cxt, $op) {
		array_push($cxt->stack, $cxt->registers[$op->op1]);
		array_push($cxt->stack, $cxt->registers[$op->op2]);
		$this->do_hasnext($cxt, $op);
	}
	
	protected function do_ifeq($cxt, $op) {
		// invert the logic
		$this->do_binary_op($cxt, '!=', 8, $this->typeBoolean);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_iffalse($cxt, $op) {
		$ctrl = new AS3ConditionalBranch;
		$ctrl->condition = array_pop($cxt->stack);		
		$ctrl->positionIfFalse = $ctrl->position = $cxt->nextPosition + $op->op1;
		$ctrl->positionIfTrue = $cxt->nextPosition;
		return $ctrl;
	}
	
	protected function do_ifge($cxt, $op) {
		$this->do_lessthan($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_ifgt($cxt, $op) {
		$this->do_lessequals($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_ifle($cxt, $op) {
		$this->do_greater($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
		
	protected function do_iflt($cxt, $op) {
		$this->do_greaterequals($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_ifne($cxt, $op) {
		$this->do_equals($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_ifnge($cxt, $op) {
		$this->do_greaterequals($cxt, $op);
		return $this->do_iffalse($cxt, $op);		
	}
	
	protected function do_ifngt($cxt, $op) {
		$this->do_greaterthan($cxt, $op);
		return $this->do_iffalse($cxt, $op);		
	}
	
	protected function do_ifnle($cxt, $op) {
		$this->do_lessequals($cxt, $op);
		return $this->do_iffalse($cxt, $op);		
	}
	
	protected function do_ifnlt($cxt, $op) {
		$this->do_lessthan($cxt, $op);
		return $this->do_iffalse($cxt, $op);		
	}
	
	protected function do_ifstricteq($cxt, $op) {
		$this->do_binary_op($cxt, '!==', 8, $this->typeBoolean);
		return $this->do_iffalse($cxt, $op);
	}	
	
	protected function do_ifstrictne($cxt, $op) {
		$this->do_strictequals($cxt, $op);
		return $this->do_iffalse($cxt, $op);
	}
	
	protected function do_iftrue($cxt, $op) {
		$ctrl = new AS3ConditionalBranch;
		$ctrl->condition = array_pop($cxt->stack);
		$ctrl->positionIfTrue = $ctrl->position = $cxt->nextPosition + $op->op1;
		$ctrl->positionIfFalse = $cxt->nextPosition;
		return $ctrl;
	}
	
	protected function do_inclocal($cxt, $op) {
		return $this->do_unary_op_local($cxt, $op->op1, '++', 3, $this->typeNumber);
	}
	
	protected function do_inclocal_i($cxt, $op) {
		return $this->do_unary_op_local($cxt, $op->op1, '++', 3, $this->typeNumber);
	}
	
	protected function do_in($cxt, $op) {
		$this->do_binary_op($cxt, 'in', 7, $this->typeBoolean);
	}

	protected function do_increment($cxt, $op) {
		array_push($cxt->stack, $this->literalOne);
		$this->do_binary_op($cxt, '+', 5, $this->typeNumber);
	}
	
	protected function do_increment_i($cxt, $op) {
		array_push($cxt->stack, $this->literalOne);
		$this->do_binary_op($cxt, '+', 5, $this->typeInt);
	}
	
	protected function do_initproperty($cxt, $op) {
		return $this->do_setproperty($cxt, $op);
	}
	
	protected function do_instanceof($cxt, $op) {
		$this->do_binary_op($cxt, 'in', 7, $this->typeBoolean);
	}
	
	protected function do_istype($cxt, $op) {
		$name = $this->resolveName($cxt, $op->op1);
		$expr = new AS3BinaryOperation;
		$expr->operator = 'is';
		$expr->operand1 = array_pop($cxt->stack);
		$expr->operand2 = $name;
		$expr->precedence = 7;
		array_push($cxt->stack, $expr);
	}

	protected function do_istypelate($cxt, $op) {
		$this->do_binary_op($cxt, 'is', 7, $this->typeBoolean);
	}
	
	protected function do_jump($cxt, $op) {
		$ctrl = new AS3UnconditionalBranch;
		$ctrl->position = $cxt->nextPosition + $op->op1;
		return $ctrl;
	}
	
	protected function do_kill($cxt, $op) {
		unset($cxt->registers[$op->op1]);
	}
	
	protected function do_label($cxt, $op) {
		// do nothing
	}
	
	protected function do_lf32($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_lf64($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_li16($cxt, $op) {
		// ignore--Alchemy instruction
	}

	protected function do_li32($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_li8($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_lessequals($cxt, $op) {
		$this->do_binary_op($cxt, '<=', 7, $this->typeBoolean);
	}
	
	protected function do_lessthan($cxt, $op) {
		$this->do_binary_op($cxt, '<', 7, $this->typeBoolean);
	}
	
	protected function do_lookupswitch($cxt, $op) {
		$ctrl = new AS3SwitchLookup;
		$ctrl->defaultPosition = $cxt->currentPosition + $op->op1;
		$ctrl->casePositions = array();
		for($i = 0, $j = 3; $i <= $op->op2; $i++, $j++) {
			$name = "op" . $j;
			$ctrl->casePositions[] = $cxt->currentPosition + $op->$name;
		}
		$ctrl->index = array_pop($cxt->stack);
		return $ctrl;
	}
	
	protected function do_lshift($cxt, $op) {
		$this->do_binary_op($cxt, '<<', 6, $this->typeInt);
	}
			
	protected function do_modulo($cxt, $op) {
		$this->do_binary_op($cxt, '%', 4, $this->typeNumber);
	}
			
	protected function do_multiply($cxt, $op) {
		$this->do_binary_op($cxt, '*', 4, $this->typeNumber);
	}
			
	protected function do_multiply_i($cxt, $op) {
		$this->do_binary_op($cxt, '*', 4, $this->typeInt);
	}
	
	protected function do_negate($cxt, $op) {
		$this->do_unary_op($cxt, '-', 3, $this->typeNumber);
	}
	
	protected function do_negate_i($cxt, $op) {
		$this->do_unary_op($cxt, '-', 3, $this->typeInt);
	}
	
	protected function do_newarray($cxt, $op) {
		$expr = new AS3NewArray;
		$expr->values = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->type = $this->typeArray;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newactivation($cxt, $op) {
		$expr = new AS3Scope;
		$expr->type = $this->typeObject;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newcatch($cxt, $op) {
		$expr = new AS3Scope;
		$expr->type = $this->typeObject;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newclass($cxt, $op) {
		// TODO
	}
	
	protected function do_newfunction($cxt, $op) {
		$methodRec = $this->abcFile->methodTable[$op->op1];
		$method = new AS3Method;
		$method->arguments = $this->decodeMethodArguments($methodRec);
		$method->type = $this->decodeType($methodRec->returnType);
		$method->byteCodes = ($methodRec->body) ? $methodRec->body->byteCodes : '';		
		$method->exceptions = $this->decodeExceptions($methodRec);
		$method->expressions = array();
		$method->localVariables = array();
		$this->decompileMethod(null, $method);
		array_push($cxt->stack, $method);
	}
	
	protected function do_newobject($cxt, $op) {
		$expr = new AS3NewObject;
		$expr->names = array();
		$expr->values = array();
		$expr->type = $this->typeObject;
		$pairs = $op->op1;
		for($i = 0; $i < $pairs; $i++) {
			$expr->values[] = array_pop($cxt->stack);
			$expr->names[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $expr);
	}
	
	protected function do_nextname($cxt, $op) {
		array_pop($cxt->stack);
		array_pop($cxt->stack);
		$expr = new AS3NextName;
		$expr->type = $this->typeString;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_nextvalue($cxt, $op) {		
		array_pop($cxt->stack);
		array_pop($cxt->stack);
		$expr = new AS3NextValue;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_nop($cxt, $op) {
	}
	
	protected function do_not($cxt, $op) {
		$val = array_pop($cxt->stack);
		$changed = false;
		if($val instanceof AS3BinaryOperation) {
			switch($val->operator) {
				case '==': $newOperator = '!='; break;
				case '!=': $newOperator = '=='; break;
				case '===': $newOperator = '!=='; break;
				case '!==': $newOperator = '==='; break;
				case '!==': $newOperator = '==='; break;
				case '>': $newOperator = '<='; break;
				case '<': $newOperator = '>='; break;
				case '>=': $newOperator = '<'; break;
				case '<=': $newOperator = '>'; break;
				default: $newOperator = null;
			}
			if($newOperator) {
				$val->operator = $newOperator;
				$changed = true;
			}
		} else if($val instanceof AS3UnaryOperation) {
			if($val->operator == '!') {
				$val = $val->operand;
				$changed = true;
			}
		}
		array_push($cxt->stack, $val);
		if(!$changed) {
			$this->do_unary_op($cxt, '!', 3, $this->typeBoolean);
		}
	}
	
	protected function do_pop($cxt, $op) {
		$val = array_pop($cxt->stack);
		if($val instanceof AS3MethodCall) {
			return $val;
		}
	}
	
	protected function do_pushbyte($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $op->op1;
		$expr->type = $this->typeInt;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_popscope($cxt, $op) {
		array_pop($cxt->scopeStack);
	}
	
	protected function do_pushdouble($cxt, $op) {
		$float = $this->getDouble($op->op1);
		$expr = new AS3Literal;
		$expr->value = $float;
		$expr->type = $this->typeNumber;
		array_push($cxt->stack, $expr);
	}

	protected function do_pushfalse($cxt, $op) {
		array_push($cxt->stack, $this->literalFalse);
	}
	
	protected function do_pushint($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $this->getInt($op->op1);
		$expr->type = $this->typeInt;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_pushnamespace($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $this->getNamespace($op->op1);
		$expr->type = $this->typeString;
		array_push($cxt->stack, $namespace);
	}
			
	protected function do_pushnan($cxt, $op) {
		array_push($cxt->stack, $this->literalNaN);
	}
			
	protected function do_pushnull($cxt, $op) {
		array_push($cxt->stack, $this->literalNull);
	}
			
	protected function do_pushscope($cxt, $op) {
		$val = array_pop($cxt->stack);
		array_push($cxt->scopeStack, $val);
	}
	
	protected function do_pushshort($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $op->op1;
		$expr->type = $this->typeUInt;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_pushstring($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $this->getString($op->op1);
		$expr->type = $this->typeString;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_pushtrue($cxt, $op) {
		array_push($cxt->stack, $this->literalTrue);
	}
	
	protected function do_pushundefined($cxt, $op) {
		array_push($cxt->stack, $this->literalUndefined);
	}
	
	protected function do_pushuint($cxt, $op) {
		$expr = new AS3Literal;
		$expr->value = $this->getUInt($op->op1);
		$expr->type = $this->typeUInt;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_pushwith($cxt, $op) {
		$object = array_pop($cxt->stack);
		array_push($cxt->scopeStack, $object);
	}
	
	protected function do_returnvalue($cxt, $op) {
		$ctrl = new AS3Return;
		$ctrl->value = array_pop($cxt->stack);
		return $ctrl;
	}
	
	protected function do_returnvoid($cxt, $op) {
		$ctrl = new AS3Return;
		return $ctrl;
	}
	
	protected function do_rshift($cxt, $op) {
		$this->do_binary_op($cxt, '>>', 6, $this->typeInt);
	}
	
	protected function do_setglobalslot($cxt, $op) {
		// TODO		
	}
	
	protected function do_setlocal_0($cxt, $op) {
		return $this->do_setlocal_x($cxt, 0);
	}
	
	protected function do_setlocal_1($cxt, $op) {
		$val = array_pop($cxt->stack);
		return $this->do_setlocal_x($cxt, 1);
	}
	
	protected function do_setlocal_2($cxt, $op) {
		return $this->do_setlocal_x($cxt, 2);
	}
	
	protected function do_setlocal_3($cxt, $op) {
		return $this->do_setlocal_x($cxt, 3);
	}
	
	protected function do_setlocal($cxt, $op) {
		return $this->do_setlocal_x($cxt, $op->op1);
	}
	
	protected function do_setlocal_x($cxt, $reg) {
		$val = array_pop($cxt->stack);
		$var =& $cxt->registers[$reg];
		if($val instanceof AS3Scope) {
			$var = $val;
		} else {
			if(!$var) {
				$var = new AS3Variable;
				$var->type = $val->type;
				$var->values = array();
				$cxt->method->localVariables[] = $var;
			}
			$var->values[] = $val;
			return $this->do_set($cxt, $var, $val);
		}
	}
	
	protected function do_setproperty($cxt, $op) {
		$val = array_pop($cxt->stack);
		$this->do_getproperty($cxt, $op);
		$var = array_pop($cxt->stack);
		return $this->do_set($cxt, $var, $val);
	}
	
	protected function do_set($cxt, $var, $val) {
		// see if the operation can be shorten
		if($val instanceof AS3BinaryOperation && $val->operand1 == $var && strlen($val->operator) == 1) {
			if($val->operand2 == $this->literalOne) {
				$expr = new AS3UnaryOperation;
				$expr->operator = $val->operator . $val->operator;
				$expr->operand = $var;
				$expr->precedence = 2;
				$expr->postfix = true;
			} else {
				$expr = new AS3BinaryOperation;
				$expr->operator = $val->operator . '=';
				$expr->operand1 = $var;
				$expr->operand2 = $val->operand2;
				$expr->precedence = 15;
			}
		} else {		
			$expr = new AS3BinaryOperation;
			$expr->operator = '=';
			$expr->operand1 = $var;
			$expr->operand2 = $val;
			$expr->precedence = 15;
		}
		$expr->type = $val->type;
		return $expr;
	}

	protected function do_setslot($cxt, $op) {
		$val = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if($object instanceof AS3Scope) {
			$object->members[- $op->op1] = $val;
		} else {
			$this->do_getslot($cxt, $op);
			$var = array_pop($cxt->stack);
			return $this->do_set($cxt, $var, $val);
		}
	}

	protected function do_setsuper($cxt, $op) {
	}

	protected function do_sf_32($cxt, $op) {
		// ignore--Alchemy instruction
	}

	protected function do_sf_64($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_16($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_32($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_8($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_subtract($cxt, $op) {
		$this->do_binary_op($cxt, '-', 5, $this->typeNumber);
	}
	
	protected function do_subtract_i($cxt, $op) {
		$this->do_binary_op($cxt, '-', 5, $this->typeInt);
	}

	protected function do_strictequals($cxt, $op) {
		$this->do_binary_op($cxt, '===', 8, $this->typeBoolean);
	}
	
	protected function do_swap($cxt, $op) {
		$val1 = array_pop($cxt->stack);
		$val2 = array_pop($cxt->stack);
		array_push($cxt->stack, $val1);
		array_push($cxt->stack, $val2);
	}
	
	protected function do_sxi_1($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_sxi_16($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_sxi_8($cxt, $op) {
		// ignore--Alchemy instruction
	}
	
	protected function do_throw($cxt, $op) {
		$val = array_pop($cxt->stack);
		$expr = new AS3Throw;
		$expr->errorObject = $val;
		return $expr;
	}
	
	protected function do_typeof($cxt, $op) {
		$this->do_unary_op($cxt, 'typeof', 3, $this->typeString);
	}
	
	protected function do_unary_op($cxt, $operator, $precedence, $type, $postfix = false) {
		$expr = new AS3UnaryOperation;
		$expr->operator = $operator;
		$expr->precedence = $precedence;
		$expr->postfix = $postfix;
		$expr->operand = array_pop($cxt->stack);
		$expr->type = $type;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_unary_op_local($cxt, $reg, $operator, $precedence, $type, $postfix = false) {
		$expr = new AS3UnaryOperation;
		$expr->operator = $operator;
		$expr->precedence = $precedence;
		$expr->postfix = $postfix;
		$expr->operand = $cxt->registers[$reg];
		$expr->type = $type;
		return $expr;
	}
	
	protected function do_urshift($cxt, $op) {
		$this->do_binary_op($cxt, '>>>', 6, $this->typeUInt);
	}

}

class AS3DecompilerContext {
	public $stack;
	public $registers;
	public $scopeStack;
	public $currentPosition;
	public $nextPosition;
	public $ops;
	public $relatedBranch;
	public $branchOnTrue;
	public $method;
}

class AS3Namespace {
	const NS_REGULAR		= 0x08;
	const NS_PACKAGE		= 0x16;
	const NS_INTERNAL		= 0x17;
	const NS_PROTECTED		= 0x18;
	const NS_EXPLICIT		= 0x19;
	const NS_STATIC_PROTECTED	= 0x1A;
	const NS_PRIVATE		= 0x05;

	public $kind;
	public $text;
}

class AS3Name {
	public $namespace;
	public $text;
	public $isAttribute;
	public $index;
}

class AS3Expression  {
	public $type;
}

class AS3RuntimeName {
	public $namespace;
	public $text;
}

class AS3Literal extends AS3Expression {
	public $value;
}

class AS3Operation extends AS3Expression {
	public $operator;
	public $precedence;
}

class AS3Constant extends AS3Variable {
}

class AS3Variable extends AS3Expression {
	public $name;
	public $values;
	public $defaultValue;
}

class AS3Scope extends AS3Expression {
	public $members;
}

class AS3Class extends AS3Expression {
	public $name;
	public $constructor;
	public $members;
	public $instance;
	public $this;
}

class AS3ClassInstance extends AS3Expression {
	const CLASS_SEALED		= 0x01;
	const CLASS_FINAL		= 0x02;
	const CLASS_INTERFACE		= 0x04;
	const CLASS_PROTECTED_NS	= 0x08;

	public $name;
	public $parentName;
	public $constructor;
	public $interfaces;
	public $protectedNamespace;
	public $flags;
	public $this;
	public $super;
}

class AS3Method extends AS3Expression {
	public $name;
	public $arguments;
	public $type;
	public $expressions;
	public $exceptions;
	public $localVariables;
}

class AS3Function extends AS3Method {
}

class AS3Constructor extends AS3Method {
}

class AS3UnaryOperation extends AS3Operation {
	public $operator;
	public $operand;
	public $postfix;
}

class AS3BinaryOperation extends AS3Operation {
	public $operator;
	public $operand1;
	public $operand2;
}

class AS3TernaryConditional extends AS3Operation {
	public $condition;
	public $valueIfTrue;
	public $valueIfFalse;
}

class AS3HasNext extends AS3Expression {
	public $index;
	public $object;
}

class AS3NextName extends AS3Expression {
}

class AS3NextValue extends AS3Expression {
}

class AS3PropertyLookUp extends AS3Operation {
	public $receiver;
	public $name;
}

class AS3MethodCall extends AS3Operation {
	public $arguments;
	public $function;
}

class AS3ConstructorCall extends AS3MethodCall {
}

class AS3Throw {
	public $errorObject;
}

class AS3Return {
	public $value;
}

class AS3Break {
}

class AS3Continue {
}

class AS3NewObject extends AS3Operation {
	public $names;
	public $values;
}

class AS3NewArray extends AS3Operation {
	public $values;
}

class AS3Kill extends AS3Expression {
}

class AS3IfLoop extends AS3Expression {
	public $condition;
	public $expressionsIfTrue;
	public $expressionsIfFalse;
}

class AS3WhileLoop extends AS3Expression {
	public $condition;
	public $expressions;
}

class AS3ForEachLoop extends AS3Expression {
	public $condition;
	public $expressions;
}

class AS3ForInLoop extends AS3Expression {
	public $condition;
	public $expressions;
}

class AS3DoWhileLoop extends AS3WhileLoop {
}

class AS3SwitchLoop {
	public $compareValue;
	public $cases;
	public $defaultCase;
}

class AS3SwitchCase {
	public $constant;
	public $expressions;
}

class AS3TryBlock {
	public $expressions;
}

class AS3CatchBlock {
	public $error;
	public $expressions;
}

class AS3FinallyBlock {
	public $expressions;
}

class AS3FlowControl {
}

class AS3Label extends AS3FlowControl {
	public $position;
}

class AS3UnconditionalBranch extends AS3FlowControl {
	public $position;
}

class AS3ConditionalBranch extends AS3FlowControl {
	public $condition;
	public $position;
	public $positionIfTrue;
	public $positionIfFalse;
}

class AS3SwitchLookup extends AS3FlowControl {
	public $index;
	public $defaultPosition;
	public $casePositions;
}

class AS3BasicBlock {
	public $expressions = array();
	public $lastExpression;
	public $from = array();
	public $to = array();
	public $structured = false;
	public $destination;
	public $prev;
	public $next;
}

class AS3ExceptionBlock {
	public $expressions;
	public $destination;
}

class AS3Exception {
	public $from;
	public $to;
	public $targets = array();
	public $errorObjects = array();
}

class AS3Loop {
	public $contentPositions;
	public $headerPosition;
	public $continuePosition;
	public $breakPosition;
	public $entrancePosition;
}

?>