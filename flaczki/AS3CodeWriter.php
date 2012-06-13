<?php

class AS3CodeWriter {

	protected $output;
	protected $scopeDepth = 0;
	protected $statementLength = 0;
	protected $currentLabel = '';
	protected $lastToken = '';

	public function write($output, $objects) {
		$this->output = $output;
		foreach($objects as $object) {
			if(isset($object->name)) {
				$namespace = $object->name->namespace;
				if($namespace->kind == AS3Namespace::NS_PACKAGE) {
					$this->writeToken("package");
					$this->writeToken($namespace->text);
					$this->startScope();
					$this->writeExpression($object);
					$this->endScope();
				} else {
					$this->writeExpression($object);
				}
			} else {
				$this->startStatement();
				$this->writeExpression($object);
				$this->endStatement();
			}
		}
	}
	
	protected function writeExpression($expr, $precedence = null) {
		if($expr) {
			if(is_scalar($expr)) {
				$this->writeToken($expr);
			} else {
				$className = get_class($expr);
				$methodName = "write" . substr($className, 3);
				if($precedence !== null && $expr instanceof AS3Operation && $precedence < $expr->precedence) {
					$this->writeToken("(");
					$this->$methodName($expr);
					$this->writeToken(")");
				} else {
					$this->$methodName($expr);
				}
			}
		}
	}
	
	protected function writeIfLoop($expr) {
		$this->writeToken("if");
		$this->writeToken("(");
		$this->writeExpression($expr->condition);
		$this->writeToken(")");
		$this->writeCodeBlock($expr->expressionsIfTrue);
		if($expr->expressionsIfFalse) {
			if(count($expr->expressionsIfFalse) == 1 && $expr->expressionsIfFalse[0] instanceof AS3IfLoop) {
				$this->writeToken("else");
				$this->writeExpression($expr->expressionsIfFalse[0]);
			} else {
				$this->writeToken("else");
				$this->writeCodeBlock($expr->expressionsIfFalse);
			}
		}
	}
	
	protected function writeSwitchLoop($loop) {
		$this->writeToken("switch");
		$this->writeToken("(");
		$this->writeExpression($loop->compareValue);
		$this->writeToken(")");
		$this->startScope();
		foreach($loop->cases as $case) {
			$this->startStatement();
			$this->writeToken("case");
			$this->writeExpression($case->constant);
			$this->writeToken(":");
			if($case->expressions) {
				$this->writeCodeBlock($case->expressions);
			}
			$this->endStatement();
		}
		if($loop->defaultCase) {
			$this->writeToken("default");
			$this->writeToken(":");
			if($loop->defaultCase->expressions) {
				$this->writeCodeBlock($loop->defaultCase->expressions);
			}
			$this->endStatement();
		}
		$this->endScope();		
	}

	protected function writeWhileLoop($expr) {
		$this->writeToken("while");
		$this->writeToken("(");
		$this->writeExpression($expr->condition);
		$this->writeToken(")");
		$this->writeCodeBlock($expr->expressions);
	}
	
	protected function writeDoWhileLoop($expr) {
		$this->writeToken("do");
		$this->writeCodeBlock($expr->expressions);
		$this->writeToken("while");
		$this->writeToken("(");
		$this->writeExpression($expr->condition);
		$this->writeToken(")");
	}
	
	protected function writeBreak($expr) {
		$this->writeToken("break");
	}
		
	protected function writeContinue($expr) {
		$this->writeToken("continue");
	}
		
	protected function writeUnaryOperation($expr) {
		if($expr->associativity == 'rtl') {
			$this->writeToken($expr->operator, $expr->precedence);
			$this->writeExpression($expr->operand, $expr->precedence);
		} else {
			$this->writeExpression($expr->operand, $expr->precedence);
			$this->writeToken($expr->operator, $expr->precedence);
		}
	}
	
	protected function writeBinaryOperation($expr) {
		$this->writeExpression($expr->operand1, $expr->precedence);
		$this->writeToken($expr->operator);
		$this->writeExpression($expr->operand2, $expr->precedence);
	}
	
	protected function writeMethodCall($call) {
		$this->writeExpression($call->function);
		$this->writeToken("(");
		foreach($call->arguments as $index => $arg) {
			if($index > 0) {
				$this->writeToken(", ");
			}
			$this->writeExpression($arg);
		}
		$this->writeToken(")");
	}		
	
	protected function writePropertyLookUp($op) {
		if(!($op->receiver instanceof AS3Variable) || $op->receiver->name->text != 'this') {
			$this->writeExpression($op->receiver);
			$this->writeToken(".");
		}
		$this->writeExpression($op->name);
	}
	
	protected function writeConstant($const) {
		$this->writeName($const->name);
	}
	
	protected function writeConstantDeclaration($const) {
		$this->writeToken("const");
		$this->writeConstant($const);
		$this->writeType($const->type);
		if($expr->value) {
			$this->writeExpression('=');
			$this->writeExpression($const->value);
		}
	}
	
	protected function writeVariable($var) {
		$this->writeName($var->name);
	}
	
	protected function writeVariableDeclaration($var) {
		$this->writeToken("var");
		$this->writeVariable($var);
		$this->writeType($var->type);
		if($var->value) {
			$this->writeExpression('=');
			$this->writeExpression($var->value);
		}
	}
	
	protected function writeArgumentDeclaration($var) {
		$this->writeVariable($var);
		$this->writeType($var->type);
		if($var->value) {
			$this->writeExpression('=');
			$this->writeExpression($var->value);
		}
	}
	
	protected function writeMethod($method) {
		$this->writeToken("function");
		$this->writeName($method->name);
		$this->writeToken("(");
		foreach($method->arguments as $index => $var) {
			if($index > 0) {
				$this->writeToken(",");
			}
			$this->writeArgumentDeclaration($var);
		}
		$this->writeToken(")");
		$this->writeType($method->type);
		if($method->expressions !== null) {
			$this->writeCodeBlock($method->expressions);
		}
	}

	protected function writeCodeBlock($expressions) {	
		$this->startScope();
		foreach($expressions as $expr) {
			$this->startStatement();
			$this->writeExpression($expr);
			$this->endStatement();
		}
		$this->endScope();
	}
	
	protected function writeTernaryConditional($expr) {
		$this->writeExpression($expr->condition, $expr->precedence);
		$this->writeToken("?");
		if($expr->valueIfTrue instanceof AS3TernaryConditional) {
			$this->writeToken("(");
			$this->writeExpression($expr->valueIfTrue, $expr->precedence);
			$this->writeToken(")");
		} else {
			$this->writeExpression($expr->valueIfTrue, $expr->precedence);
		}
		$this->writeToken(":");
		if($expr->valueIfFalse instanceof AS3TernaryConditional) {
			$this->writeToken("(");
			$this->writeExpression($expr->valueIfFalse, $expr->precedence);
			$this->writeToken(")");
		} else {
			$this->writeExpression($expr->valueIfFalse, $expr->precedence);
		}
	}
	
	protected function writeConditionalBranch($branch) {
		$this->writeToken("if");
		$this->writeToken("(");
		$this->writeExpression($branch->condition);
		$this->writeToken(")");
		$this->writeToken("goto");
		$this->writeToken("L{$branch->positionIfTrue}");
		$this->writeToken("else");
		$this->writeToken("goto");
		$this->writeToken("L{$branch->positionIfFalse}");
		
	}
	
	protected function writeLabel($label) {
		$this->currentLabel = "L{$label->position}:";
	}
	
	protected function writeUnconditionalBranch($branch) {
		$this->writeToken("goto");
		$this->writeToken("L{$branch->position}");
	}
	
	protected function writeReturn($return) {
		$this->writeToken("return");
		$this->writeExpression($return->value);
	}
		
	protected function writeClassInstance($class) {
		$this->writeClass($class);
	}
	
	protected function writeClass($class) {
		$names = array();
		$this->addImports($class->members, $names);
		$this->addImports($class->instance->members, $names);
		if($names) {
			sort($names);
			foreach($names as $name) {
				$this->startStatement();
				$this->writeToken("import");
				$this->writeFullName($name);
				$this->endStatement();
			}
		}
	
		$this->startStatement();
		if($class->instance->flags & AS3ClassInstance::CLASS_FINAL) {
			$this->writeToken("final");
		}
		if(!($class->instance->flags & AS3ClassInstance::CLASS_SEALED)) {
			$this->writeToken("dynamic");
		}
		if($class->instance->flags & AS3ClassInstance::CLASS_INTERFACE) {
			$this->writeToken("interface");
		} else {
			$this->writeToken("class");
		}
		$this->writeName($class->name);
		
		if($class->instance->parentName && $class->instance->parentName->text != 'Object') {
			$this->writeToken("extends");
			$this->writeName($class->instance->parentName);
		}
		if($class->instance->interfaces) {
			$this->writeToken("implements");			
			foreach($class->instance->interfaces as $index => $interface) {
				if($index > 0) {
					$this->writeToken(",");
				}
				$this->writeName($interface);
			}
		}
		
		$this->startScope();
		if($class->members) {
			foreach($class->members as $index => $member) {
				if($index > 0) {
					$this->startStatement();
					$this->writeAccessModifier($member->name->namespace);
					$this->writeToken("static");
					$this->writeExpression($member);
					$this->endStatement();
				}
			}
		}
		
		if($class->instance->members) {
			foreach($class->instance->members as $index => $member) {
				if($index > 0) {
					$this->startStatement();
					$this->writeAccessModifier($member->name->namespace);
					$this->writeExpression($member);
					$this->endStatement();
				}
			}
		}
		$this->endScope();
	}
	
	protected function writeAccessModifier($namespace) {
		switch($namespace->kind) {
			case AS3Namespace::NS_PACKAGE:
				$this->writeToken("public");
				break;
			case AS3Namespace::NS_INTERNAL:
				$this->writeToken("internal");
				break;
			case AS3Namespace::NS_PROTECTED:
				$this->writeToken("protected");
				break;
			case AS3Namespace::NS_STATIC_PROTECTED:
				$this->writeToken("protected");
				break;
			case AS3Namespace::NS_PRIVATE:
				$this->writeToken("private");
				break;
		}
	}
	
	protected function addImports($object, &$list) {
		foreach($object as $propName => $property) {
			if(is_object($property)) {
				if($propName == 'type' && $property instanceof AS3Name) {
					if($property->namespace->text) {
						if(!in_array($property, $list, true)) {
							$list[] = $property;
						}
					}
				} else {
					$this->addImports($property, $list);
				}
			}
		}
	}
	
	protected function writeLiteral($literal) {
		if($literal->value !== null) {
			$type = gettype($literal->value);
			switch($type) {
				case 'boolean':
					$this->writeToken($literal->value ? 'true' : 'false');
					break;
				case 'string':
					$this->writeToken('"' . addcslashes($literal->value, "\0..\37\177..\377\"\\") . '"');
					break;
				case 'double':
					if(is_nan($literal->value)) {	
						// PHP gives "NAN"
						$this->writeToken('NaN');
					} else {
						$this->writeToken((string) $literal->value);
					}
					break;
				case 'object':
					// TODO
					break;
				default:
					$this->writeToken((string) $literal->value);
					break;
			}
		} else {
			$this->writeToken('null');
		}
	}
	
	protected function writeType($name) {
		$this->writeExpression(":");
		if($name) {
			if($name->text) {
				$this->writeName($name);
			} else {
				$this->writeToken('*');
			}
		} else {
			$this->writeToken('void');
		}
	}
	
	protected function writeName($name) {
		if($name) {
			$this->writeToken($name->text);
		} else {
			$this->writeToken("[name]");
		}
	}
	
	protected function writeFullName($name) {
		if($name->namespace->text) {
			if($name) {
				$this->writeToken("{$name->namespace->text}.{$name->text}");
			} else {
				$this->writeToken("{$name->namespace->text}.[name]");
			}
		} else {
			$this->writeName($name);
		}
	}
	
	protected function startStatement() {
	}
	
	protected function endStatement() {
		if($this->statementLength > 0) {
			switch($this->lastToken) {
				case ':':
				case '{':
				case '}': break;
				default: $this->writeText(";");
			}
			$this->writeText("\n");
			$this->statementLength = 0;
		}
	}
	
	protected function startScope() {
		$this->startStatement();
		$this->writeToken("{");		
		$this->endStatement();
		$this->scopeDepth++;
	}
	
	protected function endScope() {
		$this->scopeDepth--;
		$this->startStatement();
		$this->writeToken("}");		
		$this->endStatement();
	}
	
	protected function writeToken($token) {
		if($this->statementLength > 0) {
			$this->writeText(" ");
		} else {
			if($this->currentLabel) {
				$this->writeText($this->currentLabel);
				$this->currentLabel = '';
			}
			$this->writeText(str_repeat("\t", $this->scopeDepth));
		}
		$this->writeText($token);
		$this->lastToken = $token;
		$this->statementLength++;
	}
	
	protected function writeText($text) {
		fwrite($this->output, $text);
	}

}

?>