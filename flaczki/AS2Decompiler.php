<?php

class AS2Decompiler {
	protected static $undefined;

	public function decompile($byteCodes) {
		// set up execution environment
		$cxt = new AS2DecompilerContext;
		$cxt->stack = array();
		$cxt->registers = array();
		$cxt->byteCodes = $byteCodes;
		$cxt->byteCodeLength = strlen($byteCodes);
		$cxt->nextPosition = 0;
		
		if(!self::$undefined) {
			self::$undefined = new AS2Variable;
			self::$undefined->name = 'undefined';
		}
			
		// decompile the ops into expressions
		$expressions = $this->decompileInstructions($cxt);
		
		// divide ops into basic blocks
		$blocks = $this->createBasicBlocks($expressions);
		unset($expressions);
		
		// recreate loops and conditional statements
		$this->structureLoops($blocks);
	}
	
	protected function decompileInstructions($cxt) {
		$expressions = array();	
		$contexts = array($cxt);
		while($contexts) {
			$cxt = array_shift($contexts);
			while(($expr = $this->decompileNextInstruction($cxt)) !== false) {
				if($expr) {
					if($expr instanceof AS2FlowControl) {
						if($expr instanceof AS2ConditionalBranch) {
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
							$cxtT->nextPosition = $expr->positionIfTrue;
							$contexts[] = $cxtT;
							
							$cxt->nextPosition = $expr->positionIfFalse;
						} else if($expr instanceof AS2UnconditionalBranch) {
							// jump to the location and continue
							$cxt->nextPosition = $expr->position;
						}
					}
					
					if(!isset($expressions[$cxt->lastPosition])) {
						$expressions[$cxt->lastPosition] = $expr;
					} else {
						// we've been here already
						break;
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
			$cxtT->nextPosition = $branch->positionIfTrue;
			$cxtT->relatedBranch = $branch;
			$cxtT->branchOnTrue = true;
			$cxtF = clone $cxt;
			$cxtF->nextPosition = $branch->positionIfFalse;		
			$cxtF->relatedBranch = $branch;
			$cxtF->branchOnTrue = false;
			$contexts = array($cxtT, $cxtF);
			$count = 0;
			while($contexts) {
				$cxt = array_shift($contexts);
				while(($expr = $this->decompileNextInstruction($cxt)) !== false) {
					if($expr) {
						if($expr instanceof AS2ConditionalBranch) {
							if($cxt->branchOnTrue) {
								$cxt->relatedBranch->branchIfTrue = $expr;
							} else {
								$cxt->relatedBranch->branchIfFalse = $expr;
							}
							$cxtT = clone $cxt;
							$cxtT->nextPosition = $expr->positionIfTrue;
							$cxtT->relatedBranch = $expr;
							$cxtT->branchOnTrue = true;
							$contexts[] = $cxtT;
							$cxt->nextPosition = $expr->positionIfFalse;
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
				$logicalExpr = new AS2BinaryOperation;
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
				$logicalExpr = new AS2BinaryOperation;
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
		$cxtT->nextPosition = $branch->position;
		$stackHeight = count($cxtF->stack);
		$uBranch = null;
		// keep decompiling until we hit an unconditional branch
		while(($expr = $this->decompileNextInstruction($cxtF)) !== false) {
			if($expr) {
				if($expr instanceof AS2UnconditionalBranch) {
					// something should have been pushed onto the stack
					if(count($cxtF->stack) == $stackHeight + 1) {
						$uBranch = $expr;
						break;
					} else {
						return false;
					}
				} else if($expr instanceof AS2ConditionalBranch) {
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
			while(($expr = $this->decompileNextInstruction($cxtF)) !== false) {
				if($expr) {
					break;
				}
			}
			
			// get the value for the branch by decompiling up to the destination of the unconditional jump 
			while(($expr = $this->decompileNextInstruction($cxtT)) !== false) {
				if($expr) {
					// no expression should be generated
					return false;
				}
				if($cxtT->nextPosition == $uBranch->position) {
					break;
				}
			}
			if(count($cxtT->stack) == $stackHeight + 1) {			
				$valueT = array_pop($cxtT->stack);
				// swap the values if we branch on false
				$branchOnTrue = ($branch->position == $branch->positionIfTrue);
				$expr = new AS2TernaryConditional;
				$expr->condition = $branch->condition;
				$expr->valueIfTrue = ($branchOnTrue) ? $valueT : $valueF;
				$expr->valueIfFalse = ($branchOnTrue) ? $valueF : $valueT;
				$expr->precedence = 14;
				// push the expression on to the caller's context  and advance the instruction pointer 
				// to the jump destination
				array_push($cxt->stack, $expr);
				$cxt->nextPosition = $uBranch->position;
				return true;
			}
		}
		return false;
	}
	
	protected function createBasicBlocks($expressions) {
		// find block entry positions
		$isEntry = array(0 => true);
		foreach($expressions as $ip => $expr) {
			if($expr instanceof AS2ConditionalBranch) {
				$isEntry[$expr->positionIfTrue] = true;
				$isEntry[$expr->positionIfFalse] = true;
			} else if($expr instanceof AS2UnconditionalBranch) {
				$isEntry[$expr->position] = true;
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
				$block = new AS2BasicBlock;
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
			if($block->lastExpression instanceof AS2ConditionalBranch) {
				$block->to[] = $block->lastExpression->positionIfTrue;
				$block->to[] = $block->lastExpression->positionIfFalse;
			} else if($block->lastExpression instanceof AS2UnconditionalBranch) {
				$block->to[] = $block->lastExpression->position;
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
						if($headerBlock->lastExpression instanceof AS2ConditionalBranch) {
							$loop = new AS2Loop;
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
		$loop = new AS2Loop;
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
	
	public function decompileNextInstruction($cxt) {
		static $opNames = array(
			0x0A => 'Add',
			0x47 => 'Add2',
			0x10 => 'And',
			0x33 => 'AsciiToChar',
			0x60 => 'BitAnd',
			0x63 => 'BitLShift',
			0x61 => 'BitOr',
			0x64 => 'BitRShift',
			0x65 => 'BitURShift',
			0x62 => 'BitXor',
			0x9E => 'Call',
			0x3D => 'CallFunction',
			0x52 => 'CallMethod',
			0x2B => 'CastOp',
			0x32 => 'CharToAscii',
			0x24 => 'CloneSprite',
			0x88 => 'ConstantPool',
			0x51 => 'Decrement',
			0x9B => 'DefineFunction',
			0x8E => 'DefineFunction2',
			0x3C => 'DefineLocal',
			0x41 => 'DefineLocal2',
			0x3A => 'Delete',
			0x3B => 'Delete2',
			0x0D => 'Divide',
			0x28 => 'EndDrag',
			0x46 => 'Enumerate',
			0x55 => 'Enumerate2',
			0x0E => 'Equals',
			0x49 => 'Equals2',
			0x69 => 'Extends',
			0x4E => 'GetMember',
			0x22 => 'GetProperty',
			0x34 => 'GetTime',
			0x83 => 'GetURL',
			0x9A => 'GetURL2',
			0x1C => 'GetVariable',
			0x81 => 'GotoFrame',
			0x9F => 'GotoFrame2',
			0x8C => 'GoToLabel',
			0x67 => 'Greater',
			0x9D => 'If',
			0x2C => 'ImplementsOp',
			0x50 => 'Increment',
			0x42 => 'InitArray',
			0x43 => 'InitObject',
			0x54 => 'InstanceOf',
			0x99 => 'Jump',
			0x0F => 'Less',
			0x48 => 'Less2',
			0x37 => 'MBAsciiToChar',
			0x36 => 'MBCharToAscii',
			0x35 => 'MBStringExtract',
			0x31 => 'MBStringLength',
			0x3F => 'Modulo',
			0x0C => 'Multiply',
			0x53 => 'NewMethod',
			0x40 => 'NewObject',
			0x04 => 'NextFrame',
			0x00 => 'NoOp',
			0x12 => 'Not',
			0x11 => 'Or',
			0x06 => 'Play',
			0x17 => 'Pop',
			0x05 => 'PrevFrame',
			0x96 => 'Push',
			0x4C => 'PushDuplicate',
			0x30 => 'RandomNumber',
			0x25 => 'RemoveSprite',
			0x3E => 'Return',
			0x4F => 'SetMember',
			0x23 => 'SetProperty',
			0x8B => 'SetTarget',
			0x20 => 'SetTarget2',
			0x1D => 'SetVariable',
			0x4D => 'StackSwap',
			0x27 => 'StartDrag',
			0x07 => 'Stop',
			0x09 => 'StopSounds',
			0x87 => 'StoreRegister',
			0x66 => 'StrictEquals',
			0x21 => 'StringAdd',
			0x13 => 'StringEquals',
			0x15 => 'StringExtract',
			0x68 => 'StringGreater',
			0x14 => 'StringLength',
			0x29 => 'StringLess',
			0x0B => 'Subtract',
			0x45 => 'TargetPath',
			0x08 => 'ToggleQualty',
			0x18 => 'ToInteger',
			0x4A => 'ToNumber',
			0x4B => 'ToString',
			0x26 => 'Trace',
			0x8F => 'Try',
			0x44 => 'TypeOf',
			0x8A => 'WaitForFrame',
			0x8D => 'WaitForFrame2',
			0x94 => 'With',
		);
		
		if($cxt->nextPosition < $cxt->byteCodeLength) {
			$cxt->lastPosition = $cxt->nextPosition;
			$code = ord($cxt->byteCodes[$cxt->nextPosition++]);
			$name = $opNames[$code];
			$method = "do$name";
			if($code >= 0x80) {
				$count = $this->extractUI16($cxt->byteCodes, $cxt->nextPosition);
				$data = substr($cxt->byteCodes, $cxt->nextPosition, $count);
				$cxt->nextPosition += $count;
				$expr = $this->$method($cxt, $data);
			} else {
				$expr = $this->$method($cxt);
			}
			return $expr;
		} else {
			return false;
		}
	}
	
	protected function doBinaryOp($cxt, $operator, $precedence) {
		$expr = new AS2BinaryOperation;
		$expr->operator = $operator;
		$expr->operand2 = array_pop($cxt->stack);
		$expr->operand1 = array_pop($cxt->stack);
		$expr->precedence = $precedence;
		array_push($cxt->stack, $expr);
	}

	protected function doUnaryOp($cxt, $operator, $precedence) {
		$expr = new AS2UnaryOperation;
		$expr->operator = $operator;
		$expr->operand = array_pop($cxt->stack);
		$expr->precedence = $precedence;
		array_push($cxt->stack, $expr);
	}
	
	protected function doCallTargetMethod($cxt) {
		if($cxt->target) {
			$name = array_pop($cxt->stack);
			array_push($cxt->stack, $cxt->target);
			array_push($cxt->stack, $name);
			$this->doCallMethod($cxt);
		} else {
			$this->doCallFunction($cxt);
		}
		return $this->doPop($cxt);
	}
	
	protected function doAdd($cxt) {
		$this->doBinaryOp($cxt, '+', 5);
	}

	protected function doAdd2($cxt) {
		$this->doBinaryOp($cxt, '+', 5);
	}

	protected function doAnd($cxt) {
		$this->doBinaryOp($cxt, '&&', 12);
	}

	protected function doAsciiToChar($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'chr');
		$this->doCallFunction($cxt);
	}

	protected function doBitAnd($cxt) {
		$this->doBinaryOp($cxt, '&', 9);
	}

	protected function doBitLShift($cxt) {
		$this->doBinaryOp($cxt, '<<', 6);
	}

	protected function doBitOr($cxt) {
		$this->doBinaryOp($cxt, '|', 11);
	}

	protected function doBitRShift($cxt) {
		$this->doBinaryOp($cxt, '>>', 6);
	}

	protected function doBitURShift($cxt) {
		$this->doBinaryOp($cxt, '>>>', 6);
	}

	protected function doBitXor($cxt) {
		$this->doBinaryOp($cxt, '^', 10);
	}

	protected function doCall($cxt, $data) {
	}

	protected function doCallFunction($cxt) {
		$expr = new AS2FunctionCall;
		$expr->name = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$expr->arguments = array();
		for($i = 0; $i < $argumentCount; $i++) {
			$expr->arguments[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $expr);
	}

	protected function doCallMethod($cxt) {
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$this->doGetMember($cxt->stack);
		$this->doCallFunction($cxt);
	}

	protected function doCastOp($cxt, $data) {
	}

	protected function doCharToAscii($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'ord');
		$this->doCallFunction($cxt);
	}

	protected function doCloneSprite($cxt, $data) {
	}

	protected function doConstantPool($cxt, $data) {
		$p = 0;
		$count = $this->extractUI16($data, $p);
		$cxt->constantPool = array();
		for($i = 0; $i < $count; $i++) {
			$cxt->constantPool[] = $this->extractString($data, $p);
		}
	}

	protected function doDecrement($cxt) {
		$this->doUnaryOp($cxt, '--', 3);
	}

	protected function doDefineFunction($cxt, $data) {
	}

	protected function doDefineFunction2($cxt, $data) {
	}

	protected function doDefineLocal($cxt) {
		$expr = new AS2VariableDeclaration;
		$expr->value = array_pop($cxt->stack);
		$expr->name = array_pop($cxt->stack);
		return $expr;
	}

	protected function doDefineLocal2($cxt) {
		$expr = new AS2VariableDeclaration;
		$expr->name = array_pop($cxt->stack);
		return $expr;
	}

	protected function doDelete($cxt) {
		$this->doUnaryOp($cxt, 'delete', 3);
	}

	protected function doDelete2($cxt) {
		$this->doUnaryOp($cxt, 'delete', 3);
	}

	protected function doDivide($cxt) {
		$this->doBinaryOp($cxt, '/', 4);
	}

	protected function doEndDrag($cxt, $data) {
	}

	protected function doEnumerate($cxt, $data) {
	}

	protected function doEnumerate2($cxt, $data) {
	}

	protected function doEquals($cxt) {
		$this->doBinaryOp($cxt, '==', 8);
	}

	protected function doEquals2($cxt) {
		$this->doBinaryOp($cxt, '==', 8);
	}

	protected function doExtends($cxt, $data) {
	}

	protected function doGetMember($cxt, $data) {
		$expr = new AS2Variable;
		$expr->name = array_pop($cxt->stack);
		array_push($expr);
		$this->doBinaryOp($cxt, '.', 1);
	}

	protected function doGetProperty($cxt, $data) {
		static $propertyNames = array('_x', '_y', '_xscale', '_yscale', '_currentframe', '_totalframes', '_alpha', '_visible', '_width', '_height', '_rotation', '_target', '_framesloaded', '_name', '_droptarget', '_url', '_highquality', '_focusrect', '_soundbuftime', '_quality', '_xmouse', '_ymouse' );
		$index = array_pop($cxt->stack);
		$expr = new AS2Variable;
		$expr->name = array_pop($cxt->stack);
		array_push($expr);
		$this->doBinaryOp($cxt, '.', 1);
	}

	protected function doGetTime($cxt, $data) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'getTimer');
		$this->doCallFunction($cxt);
	}

	protected function doGetURL($cxt, $data) {
		$p = 0;
		$target = $this->extractString($data, $p);
		$url = $this->extractString($data, $p);
		$flags = ($target == '_level0' || $target == '_level1') ? 0x02 : 0;
		array_push($cxt->stack, $url);
		array_push($cxt->stack, $target);
		$this->doGetURL2($cxt, chr($flags));
	}

	protected function doGetURL2($cxt, $data) {
		$flags = ord($data[0]);
		$method = $flags >> 6;
		$target = array_pop($cxt->stack);
		$url = array_pop($cxt->stack);
		$argumentCount = 0;
		if($method == 1) {
			array_push($cxt->stack, 'GET');
			$argumentCount++;
		} else if($method == 2) {
			array_push($cxt->stack, 'POST');
			$argumentCount++;
		}
		if($flags & 0x02) {
			// loadVariable or loadMovie
			if($flags & 0x01) {
				array_push($cxt->stack, $target);
				array_push($cxt->stack, $url);
				$argumentCount += 2;
				array_push($cxt->stack, $argumentCount);
				array_push($cxt->stack, 'loadVariable');
				$this->doCallFunction($cxt);
			} else {
				array_push($cxt->stack, $url);
				$argumentCount += 1;
				array_push($cxt->stack, $argumentCount);				
				array_push($cxt->stack, $target);
				$this->doGetVariable($cxt);
				array_push($cxt->stack, 'loadMovie');
				$this->doCallMethod($cxt);
			}
		} else {
			array_push($cxt->stack, $target);
			array_push($cxt->stack, $url);
			$argumentCount += 2;
			array_push($cxt->stack, $argumentCount);
			array_push($cxt->stack, 'getURL');
			$this->doCallFunction($cxt);
		}
	}

	protected function doGetVariable($cxt) {
		$expr = new AS2Variable;
		$expr->name = array_pop($cxt->stack);
		array_push($cxt->stack, $expr);
	}

	protected function doGotoFrame($cxt, $data) {
		$index = $this->extractUI16($data);
		array_push($cxt->stack, $index);
		array_push($cxt->stack, 'gotoAndPlay');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doGotoFrame2($cxt, $data) {
		$flags = $this->extractUI16($data);
		if($flags & 0x02) {
			$sceneBias = $this->extractUI16($data, 1);
			$frame = array_pop($cxt->stack);
			array_push($cxt->stack, $frame + $sceneBias);
		}
		array_push($cxt->stack, 1);
		if($flags & 0x01) {
			array_push($cxt->stack, 'gotoAndPlay');
		} else {
			array_push($cxt->stack, 'gotoAndStop');
		}
		return $this->doCallTargetMethod($cxt);
	}

	protected function doGoToLabel($cxt, $data) {
		array_push($cxt->stack, $data);
		array_push($cxt->stack, 'gotoAndPlay');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doGreater($cxt) {
		$this->doBinaryOp($cxt, '>', 7);
	}

	protected function doIf($cxt, $data) {
		$offset = $this->extractSI16($data);
		$ctrl = new AS2ConditionalBranch;
		$ctrl->condition = array_pop($cxt->stack);
		$ctrl->position = $ctrl->positionIfTrue = $cxt->nextPosition + $offset;
		$ctrl->positionIfFalse = $cxt->nextPosition;
		return $ctrl;
	}

	protected function doImplementsOp($cxt, $data) {
	}

	protected function doIncrement($cxt) {
		$this->doUnaryOp($cxt, '++', 3);
	}

	protected function doInitArray($cxt, $data) {
	}

	protected function doInitObject($cxt, $data) {
	}

	protected function doInstanceOf($cxt) {
		$this->doBinaryOp($cxt, 'instanceof', 7);
	}

	protected function doJump($cxt, $data) {
		$offset = $this->extractSI16($data);
		$ctrl = new AS2UnconditionalBranch;
		$ctrl->position = $cxt->nextPosition + $offset;
		return $ctrl;
	}

	protected function doLess($cxt) {
		$this->doBinaryOp($cxt, '<', 7);
	}

	protected function doLess2($cxt) {
		$this->doBinaryOp($cxt, '<', 7);
	}

	protected function doMBAsciiToChar($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'mbchr');
		$this->doCallFunction($cxt);
	}

	protected function doMBCharToAscii($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'mbord');
		$this->doCallFunction($cxt);
	}

	protected function doMBStringExtract($cxt) {
		array_push($cxt->stack, 3);
		array_push($cxt->stack, 'mbsubstring');
		$this->doCallFunction($cxt);
	}

	protected function doMBStringLength($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'mblength');
		$this->doCallFunction($cxt);
	}

	protected function doModulo($cxt) {
		$this->doBinaryOp($cxt, '%', 4);
	}

	protected function doMultiply($cxt) {
		$this->doBinaryOp($cxt, '*', 4);
	}

	protected function doNewMethod($cxt, $data) {
	}

	protected function doNewObject($cxt, $data) {
	}

	protected function doNextFrame($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'nextFrame');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doNoOp($cxt) {
	}

	protected function doNot($cxt) {
		$this->doUnaryOp($cxt, '!', 3);
	}

	protected function doOr($cxt) {
		$this->doBinaryOp($cxt, '||', 13);
	}

	protected function doPlay($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'play');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doPop($cxt) {
		$expr = array_pop($cxt->stack);
		if(!$cxt->stack) {
			return $expr;
		}
	}

	protected function doPrevFrame($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'prevFrame');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doPush($cxt, $data) {
		$p = 0;
		$len = strlen($data);
		do {
			switch(ord($data[$p++])) {
				case 0: $value = $this->extractString($data, $p); break;
				case 1: $value = $this->extractF32($data, $p); break;
				case 2: $value = null; break;
				case 3: $value = $this->undefined; break;
				case 4: $value = $cxt->registers[ ord($data[$p++]) ]; break;
				case 5: $value = ord($data[$p++]); break;
				case 6: $value = $this->extractF64($data, $p); break;
				case 7: $value = $this->extractUI32($data, $p); break;
				case 8: $value = $cxt->constantPool[ ord($data[$p++]) ]; break;
				case 9: $index = $value = $cxt->constantPool[ $this->extractUI16($data, $p) ]; break;
			}
			array_push($cxt->stack, $value);
		} while($p < $len);
	}

	protected function doPushDuplicate($cxt) {
		$index = count($cxt->stack) - 1;
		array_push($cxt->stack[$index]);
	}

	protected function doRandomNumber($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'random');
		$this->doCallFunction($cxt);
	}

	protected function doRemoveSprite($cxt, $data) {
	}

	protected function doReturn($cxt) {
		$expr = new AS2Return;
		$expr->operand = array_pop($cxt->stack);
		return $expr;
	}

	protected function doSetMember($cxt) {
		$value = array_pop($cxt->stack);
		$this->doGetMember($cxt);
		array_push($value);
		$this->doSetVariable($cxt->stack);
	}

	protected function doSetProperty($cxt) {
		$value = array_pop($cxt->stack);
		$this->doGetProperty($cxt);
		array_push($value);
		$this->doSetVariable($cxt->stack);
	}

	protected function doSetTarget($cxt, $data) {
		array_push($cxt->stack, $data);
		$this->doSetTarget2($cxt);
	}

	protected function doSetTarget2($cxt) {
		$name = array_pop($cxt->stack);
		if($name) {
			$expr = new AS2Variable;
			$expr->name = $name;
			$cxt->target = $expr;
		} else {
			$cxt->target = null;
		}
	}

	protected function doSetVariable($cxt) {
		$value = array_pop($cxt->stack);
		$this->doGetVariable($cxt);
		array_push($cxt->stack, $value);
		$this->doBinaryOp($cxt, '=', 15);
	}

	protected function doStackSwap($cxt) {
		$op1 = array_pop($cxt->stack);
		$op2 = array_pop($cxt->stack);
		array_push($cxt->stack, $op1);
		array_push($cxt->stack, $op2);
	}

	protected function doStartDrag($cxt, $data) {
	}

	protected function doStop($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'stop');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doStopSounds($cxt, $data) {
	}

	protected function doStoreRegister($cxt, $data) {
		$regIndex = ord($data[0]);
		$stackIndex = count($cxt->stack) - 1;
		$cxt->registers[$regIndex] = $cxt->stack[$stackIndex];
	}

	protected function doStrictEquals($cxt) {
		$this->doBinaryOp($cxt, '===', 8);
	}

	protected function doStringAdd($cxt) {
		$this->doBinaryOp($cxt, '+', 5);
	}

	protected function doStringEquals($cxt) {
		$this->doBinaryOp($cxt, '==', 8);
	}

	protected function doStringExtract($cxt) {
		array_push($cxt->stack, 3);
		array_push($cxt->stack, 'substring');
		$this->doCallFunction($cxt);
	}

	protected function doStringGreater($cxt) {
		$this->doBinaryOp($cxt, '>', 7);
	}

	protected function doStringLength($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'length');
		$this->doCallFunction($cxt);
	}

	protected function doStringLess($cxt) {
		$this->doBinaryOp($cxt, '<', 7);
	}

	protected function doSubtract($cxt) {
		$this->doBinaryOp($cxt, '-', 5);
	}

	protected function doTargetPath($cxt, $data) {
		// do nothing
	}

	protected function doToggleQualty($cxt, $data) {
	}

	protected function doToInteger($cxt, $data) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'int');
		$this->doCallFunction($cxt);
	}

	protected function doToNumber($cxt, $data) {
		$value = array_pop($cxt->stack);
		if(is_scalar($value)) {
			switch(gettype($value)) {
				case 'boolean': $value = ($value) ? 1.0 : 0.0; break;
				case 'int': $value = (double) $value; break;
				case 'double': break;
				case 'string': $value = is_numeric($value) ? (double) $value : NAN; break;
			}
			array_push($cxt->stack, $value);
		} else if($value == $this->undefined) {
			$value = 'undefined';
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, 'valueOf()');
			$this->doGetMember($cxt);
			array_push($cxt->stack, 1);
			array_push($cxt->stack, 'parseFloat');
			$this->doCallFunction($cxt);
		}
	}

	protected function doToString($cxt, $data) {
		$value = array_pop($cxt->stack);
		if(is_scalar($value)) {
			switch(gettype($value)) {
				case 'boolean': $value = ($value) ? 'true' : 'false'; break;
				case 'int': $value = (string) $value; break;
				case 'double': $value = is_nan($value) ? 'NaN' : (string) $value; break;
				case 'string': break;
			}
			array_push($cxt->stack, $value);
		} else if($value == $this->undefined) {
			$value = 'undefined';
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, 'toString');
			$this->doGetMember($cxt);
		}
	}

	protected function doTrace($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'trace');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doTry($cxt, $data) {
	}

	protected function doTypeOf($cxt) {
		$this->doUnaryOp($cxt, 'typeof', 3);
	}

	protected function doWaitForFrame($cxt, $data) {
	}

	protected function doWaitForFrame2($cxt, $data) {
	}

	protected function doWith($cxt, $data) {
	}
	
	protected function extractString($data, &$offset = 0) {
		$zeroPos = strpos($data, "\x00", $offset);
		if($zeroPos === false) {
			$zeroPos = strlen($data);
		}
		$string = substr($data, $offset, $zeroPos - $offset);
		$offset = $zeroPos + 1;
		return $string;
	}
	
	protected function extractUI16($data, &$offset = 0) {
		if($offset) {
			$data = substr($data, $offset, 2);
		}
		$array = unpack('v', $data);
		$offset += 2;
		return $array[1];
	}
	
	protected function extractSI16($data, &$offset = 0) {
		$value = $this->extractUI16($data, $offset);
		if($value & 0x00008000) {
			$value |= -1 << 16;
		}
		return $value;
	}
	
	protected function extractUI32($data, &$offset = 0) {
		if($offset) {
			$data = substr($data, $offset, 4);
		}
		$array = unpack('V', $data);
		$offset += 4;
		return $array[1];
	}
	
	protected function extractF32($data, &$offset = 0) {
		if($offset) {
			$data = substr($data, $offset, 4);
		}
		$array = unpack('f', $data);
		$offset += 4;
		return $array[1];
	}
	
	protected function extractF64($data, &$offset = 0) {
		if($offset) {
			$data = substr($data, $offset, 8);
		}
		$array = unpack('d', $data);
		$offset += 8;
		return $array[1];
	}
}

class AS2DecompilerContext {
	public $byteCodes;
	public $byteCodeLength;
	public $lastPosition;
	public $nextPosition;
	public $stack = array();
	public $registers = array();
	public $constantPool = array();
	public $target;
	
	public $relatedBranch;
	public $branchOnTrue;
}

class AS2Expression {
}

class AS2Variable extends AS2Expression {
	public $name;
}

class AS2VariableDeclaration extends AS2Expression {
	public $name;
	public $value;
}

class AS2Operation extends AS2Expression {
	public $precedence;
}

class AS2BinaryOperation extends AS2Operation {
	public $operator;
	public $operand1;
	public $operand2;
}

class AS2UnaryOperation extends AS2Operation {
	public $operator;
	public $operand;
}

class AS2TernaryConditional extends AS2Operation {
	public $condition;
	public $valueIfTrue;
	public $valueIfFalse;
}

class AS2Return {
	public $operand;
}

class AS2FunctionCall extends AS2Expression {
	public $name;
	public $arguments;
}

class AS2FlowControl {
}

class AS2UnconditionalBranch extends AS2FlowControl {
	public $position;
}

class AS2ConditionalBranch extends AS2FlowControl {
	public $condition;
	public $position;
	public $positionIfTrue;
	public $positionIfFalse;
}

class AS2BasicBlock {
	public $expressions = array();
	public $lastExpression;
	public $from = array();
	public $to = array();
	public $structured = false;
	public $destination;
	public $prev;
	public $next;
}

class AS2Loop {
	public $contentPositions;
	public $headerPosition;
	public $continuePosition;
	public $breakPosition;
	public $entrancePosition;
}

?>