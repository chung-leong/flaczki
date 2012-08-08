<?php

class AS2SourceReconstructor {

	protected $lines;
	protected $line;
	protected $lastToken;
	protected $indent;

	public function reconstruct($expressions) {
		$this->lines = array();
		$this->line = null;
		$this->lastToken = null;
		$this->indent = 0;
		$this->addExpressions($expressions, false);
		return $this->lines;
	}
	
	protected function addExpressions($expressions, $enclosed = true) {
		if($enclosed) {
			$this->startBlock();
		}
		foreach($expressions as $expr) {
			$this->startLine();
			$this->addExpression($expr);
			$this->endLine();
		}
		if($enclosed) {
			$this->endBlock();
		}
	}
	
	protected function addExpression($expr, $precedence = null) {
		if($expr instanceof AS2Expression) {
			$name = substr(get_class($expr), 3);
			$method = "add$name";
			if($precedence !== null && $expr instanceof AS2Operation && $precedence < $expr->precedence) {
				$this->addToken("(");
				$this->$method($expr);
				$this->addToken(")");
			} else {
				$this->$method($expr);
			}
		} else {
			switch(gettype($expr)) {
				case 'boolean':
					$this->addToken($expr ? 'true' : 'false');
					break;
				case 'string':
					$this->addToken('"' . addcslashes($expr, "\0..\37\177..\377\"\\") . '"');
					break;
				case 'double':
					if(is_nan($expr)) {	
						// PHP gives "NAN"
						$this->addToken('NaN');
					} else {
						$this->addToken((string) $expr);
					}
					break;
				case 'integer':
					$this->addToken((string) $expr);
					break;
				case 'NULL':
					$this->addToken('null');
					break;
			}
		}
	}
	
	protected function addFunction($function) {
		$this->addToken('function');
		if($function->name) {
			$this->addExpression($function->name);
		}
		$this->addToken('(');
		foreach($function->arguments as $index => $argument) {
			if($index != 0) {
				$this->addToken(',');
			}
			$this->addExpression($argument);
		}
		$this->addToken(')');
		$this->addExpressions($function->expressions);
	}
	
	protected function addWhile($loop) {
		$this->addToken('while');
		$this->addToken('(');
		$this->addExpression($loop->condition);
		$this->addToken(')');
		$this->addExpressions($loop->expressions);
	}
	
	protected function addDoWhile($loop) {
		$this->addToken('do');
		$this->addExpressions($loop->expressions);
		$this->addToken('while');
		$this->addToken('(');
		$this->addExpression($loop->condition);
		$this->addToken(')');
	}

	protected function addForIn($loop) {
		$this->addToken('for');
		$this->addToken('(');
		$this->addExpression($loop->condition);
		$this->addToken(')');
		$this->addExpressions($loop->expressions);
	}
	
	protected function addIfElse($stmt) {
		$this->addToken('if');
		$this->addToken('(');
		$this->addExpression($stmt->condition);
		$this->addToken(')');
		$this->addExpressions($stmt->expressionsIfTrue);
		if($stmt->expressionsIfFalse !== null) {
			$this->addToken('else');
			$this->addExpressions($stmt->expressionsIfFalse);
		}
	}
	
	protected function addTry($try) {
		$this->addToken('try');
		$this->addExpressions($try->tryExpressions);
		if($try->catchExpressions !== null) {
			$this->addToken('catch');
			$this->addToken('(');
			$this->addExpression($try->catchObject);
			$this->addToken(')');
			$this->addExpressions($try->catchExpressions);
		}
		if($try->finallyExpressions !== null) {
			$this->addToken('finally');
			$this->addExpressions($try->finallyExpressions);
		}
	}
	
	protected function addReturn($expr) {
		$this->addToken('return');
		$this->addExpression($expr);
	}
	
	protected function addFunctionCall($call) {
		$this->addExpression($call->name);
		$this->addToken('(');
		foreach($call->arguments as $index => $argument) {
			if($index != 0) {
				$this->addToken(',');
			}
			$this->addExpression($argument);
		}
		$this->addToken(')');
	}
	
	protected function addUnaryOperation($op) {
		$this->addToken($op->operator);
		$this->addExpression($op->operand, $op->precedence);
	}
	
	protected function addBinaryOperation($op) {
		$this->addExpression($op->operand1, $op->precedence);
		$this->addToken($op->operator);
		$this->addExpression($op->operand2, $op->precedence);
	}
	
	protected function addTernaryConditional($op) {
		$this->addExpression($op->condition, $op->precedence);
		$this->addToken('?');
		$this->addExpression($op->valueIfTrue, $op->precedence);
		$this->addToken(':');
		$this->addExpression($op->valueIfFalse, $op->precedence);
	}
	
	protected function addArrayInitializer($array) {
		$this->addToken('[');
		foreach($array->items as $index => $item) {
			if($index != 0) {
				$this->addToken(',');
			}
			$this->addExpression($item);
		}
		$this->addToken(']');
	}
	
	protected function addArrayAssessor($assessor) {
		$this->addExpression($assessor->array);
		$this->addToken('[', false);
		$this->addExpression($assessor->index);
		$this->addToken(']', false);
	}
	
	protected function addObjectInitializer($array) {
		$this->addToken('{');
		$index = 0;
		foreach($array->items as $name => $item) {
			if($index != 0) {
				$this->addToken(',');
			}
			if(preg_match('/^\w+$/', $name)) {
				// doesn't need escaping
				$this->addToken($name);
			} else {
				$this->addExpression($name);
			}
			$this->addToken(':');
			$this->addExpression($item);
			$index++;
		}
		$this->addToken('}');
	}
	
	protected function addVariable($var) {
		$this->addToken($var->name);
	}
	
	protected function addVariableDeclaration($var) {
		$this->addToken('var');
		$this->addToken($var->name);
		if($var->value !== null) {
			$this->addToken('=');
			$this->addExpression($var->value);
		}
	}
	
	protected function addToken($token, $needSpace = null) {
		if($this->lastToken) {
			if($needSpace === null) {
				$needSpace = true;
				switch($this->lastToken) {
					case '.':
					case '!':
					case '(':
					case '{':
					case '[': $needSpace = false; break;
				}
				switch($token) {
					case '.':
					case ',':
					case '(':
					case ')':
					case '}':
					case ']': $needSpace = false; break;
				}
			}
			if($needSpace) {
				$this->line .= ' ';
			}
		}
		$this->line .= $token;
		$this->lastToken = $token;		
	}
	
	protected function startLine() {
		$this->line = str_repeat("\t", $this->indent);
	}
	
	protected function endLine() {
		if($this->lastToken) {
			switch($this->lastToken) {
				case '{':
				case '}';
					break;
				default:
					$this->line .= ';';
					break;
			}
		}
		$this->lines[] = $this->line;
		$this->line = $this->lastToken = null;
	}
	
	protected function startBlock() {
		if(!$this->line) {
			$this->startLine();
		}
		$this->addToken('{');
		$this->endLine();
		$this->indent++;
	}
	
	protected function endBlock() {
		$this->indent--;
		$this->startLine();
		$this->addToken('}');
		//$this->endLine();
	}
}

?>