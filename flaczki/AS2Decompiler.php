<?php

class AS2Decompiler {
	protected static $undefined;
	
	protected $constantPool;

	public function decompile($byteCodes) {
		if(!self::$undefined) {
			self::$undefined = new AS2Variable;
			self::$undefined->name = 'undefined';
		}
		$this->constantPool = array();
			
		$cxt = $this->createContext($byteCodes);
		$function = new AS2Function;
		$this->decompileFunctionBody($cxt, $function);
		
		$r = new AS2SourceReconstructor;
		print_r($r->reconstruct($function->expressions));

		return $function->expressions;
	}
	
	protected function createContext($byteCodes, $preloadedVariables = array()) {
		$cxt = new AS2DecompilerContext;
		$cxt->byteCodes = $byteCodes;
		$cxt->byteCodeLength = strlen($byteCodes);
		$cxt->nextPosition = 0;
		
		foreach($preloadedVariables as $index => $name) {
			$var = new AS2Variable;
			$var->name = $name;
			$cxt->registers[$index] = $var;
		}
		return $cxt;
	}
	
	protected function decompileFunctionBody($cxt, $function) {
		// decompile the ops into expressions
		$expressions = array();	
		$contexts = array($cxt);
		while($contexts) {
			$cxt = array_shift($contexts);
			while(($expr = $this->decompileNextInstruction($cxt)) !== false) {				
				if($expr) {
					if(!isset($expressions[$cxt->lastPosition])) {
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
					
						$expressions[$cxt->lastPosition] = $expr;
					} else {
						// we've been here already
						break;
					}
				}
			}
		}
		
		// create basic blocks
		$blocks = $this->createBasicBlocks($expressions);
		unset($expressions);
		
		// look for loops, from the innermost to the outermost
		$loops = $this->createLoops($blocks, $function);
		
		// recreate loops and conditional statements
		$this->structureLoops($loops, $blocks, $function);

		// assign names to unnamed variables
		foreach($cxt->unnamedLocalVariables as $index => $var) {
			$var->name = "lv" . ($index + 1);
		}
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
			$scanned = array();
			while($contexts) {
				$cxt = array_shift($contexts);
				$scanned[$cxt->nextPosition] = true;
				while(($expr = $this->decompileNextInstruction($cxt)) !== false) {
					if($expr) {
						if($expr instanceof AS2ConditionalBranch) {
							if($cxt->branchOnTrue) {
								$cxt->relatedBranch->branchIfTrue = $expr;
							} else {
								$cxt->relatedBranch->branchIfFalse = $expr;
							}
							
							if(!isset($scanned[$expr->positionIfTrue]) && !isset($scanned[$expr->positionIfFalse])) {
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
	
	public function decompileNextInstruction($cxt) {
		static $handlers = array(
			0x0A => 'doAdd',
			0x47 => 'doAdd2',
			0x10 => 'doAnd',
			0x33 => 'doAsciiToChar',
			0x60 => 'doBitAnd',
			0x63 => 'doBitLShift',
			0x61 => 'doBitOr',
			0x64 => 'doBitRShift',
			0x65 => 'doBitURShift',
			0x62 => 'doBitXor',
			0x9E => 'doCall',
			0x3D => 'doCallFunction',
			0x52 => 'doCallMethod',
			0x2B => 'doCastOp',
			0x32 => 'doCharToAscii',
			0x24 => 'doCloneSprite',
			0x88 => 'doConstantPool',
			0x51 => 'doDecrement',
			0x9B => 'doDefineFunction',
			0x8E => 'doDefineFunction2',
			0x3C => 'doDefineLocal',
			0x41 => 'doDefineLocal2',
			0x3A => 'doDelete',
			0x3B => 'doDelete2',
			0x0D => 'doDivide',
			0x28 => 'doEndDrag',
			0x46 => 'doEnumerate',
			0x55 => 'doEnumerate2',
			0x0E => 'doEquals',
			0x49 => 'doEquals2',
			0x69 => 'doExtends',
			0x4E => 'doGetMember',
			0x22 => 'doGetProperty',
			0x34 => 'doGetTime',
			0x83 => 'doGetURL',
			0x9A => 'doGetURL2',
			0x1C => 'doGetVariable',
			0x81 => 'doGotoFrame',
			0x9F => 'doGotoFrame2',
			0x8C => 'doGoToLabel',
			0x67 => 'doGreater',
			0x9D => 'doIf',
			0x2C => 'doImplementsOp',
			0x50 => 'doIncrement',
			0x42 => 'doInitArray',
			0x43 => 'doInitObject',
			0x54 => 'doInstanceOf',
			0x99 => 'doJump',
			0x0F => 'doLess',
			0x48 => 'doLess2',
			0x37 => 'doMBAsciiToChar',
			0x36 => 'doMBCharToAscii',
			0x35 => 'doMBStringExtract',
			0x31 => 'doMBStringLength',
			0x3F => 'doModulo',
			0x0C => 'doMultiply',
			0x53 => 'doNewMethod',
			0x40 => 'doNewObject',
			0x04 => 'doNextFrame',
			0x12 => 'doNot',
			0x11 => 'doOr',
			0x06 => 'doPlay',
			0x17 => 'doPop',
			0x05 => 'doPrevFrame',
			0x96 => 'doPush',
			0x4C => 'doPushDuplicate',
			0x30 => 'doRandomNumber',
			0x25 => 'doRemoveSprite',
			0x3E => 'doReturn',
			0x4F => 'doSetMember',
			0x23 => 'doSetProperty',
			0x8B => 'doSetTarget',
			0x20 => 'doSetTarget2',
			0x1D => 'doSetVariable',
			0x4D => 'doStackSwap',
			0x27 => 'doStartDrag',
			0x07 => 'doStop',
			0x09 => 'doStopSounds',
			0x87 => 'doStoreRegister',
			0x66 => 'doStrictEquals',
			0x21 => 'doStringAdd',
			0x13 => 'doStringEquals',
			0x15 => 'doStringExtract',
			0x68 => 'doStringGreater',
			0x14 => 'doStringLength',
			0x29 => 'doStringLess',
			0x0B => 'doSubtract',
			0x45 => 'doTargetPath',
			0x08 => 'doToggleQualty',
			0x18 => 'doToInteger',
			0x4A => 'doToNumber',
			0x4B => 'doToString',
			0x26 => 'doTrace',
			0x8F => 'doTry',
			0x44 => 'doTypeOf',
			0x8A => 'doWaitForFrame',
			0x8D => 'doWaitForFrame2',
			0x94 => 'doWith',
		);
		
		if($cxt->nextPosition < $cxt->byteCodeLength) {
			$cxt->lastPosition = $cxt->nextPosition;
			$cxt->dataRemaining = 1;
			$code = $this->readUI8($cxt);
			if($code) {
				$method = $handlers[$code];
				if($code >= 0x80) {
					$cxt->dataRemaining = 2;
					$cxt->dataRemaining = $this->readUI16($cxt);
				} 
				$expr = $this->$method($cxt);
				if($cxt->dataRemaining) {
					$cxt->nextPosition += $cxt->dataRemaining;
				}
				return $expr;
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

	protected function structureLoops($loops, $blocks, $function) {
		// convert the loops into either while or do-while
		foreach($loops as $loop) {
			if($loop->headerPosition !== null) {
				// header is the block containing the conditional statement
				// it is not the first block
				$headerBlock = $blocks[$loop->headerPosition];
				$headerBlock->structured = true;
				
				// see if there's an unconditional branch into the loop
				$entryBlock = $blocks[$loop->entrancePosition];
				if($entryBlock->lastExpression instanceof AS2UnconditionalBranch) {
					if($entryBlock->lastExpression->position == $headerBlock->lastExpression->position) {
						// it goes to the beginning of the loop so it must be a do-while
						$expr = new AS2DoWhile;
					} else {			
						$expr = new AS2While;
					}
					$entryBlock->lastExpression = $expr;
				} else {
					// we just fall into the loop
					if($loop->headerPosition == $loop->continuePosition) {
						// the conditional statement is at the "bottom" of the loop so it's a do-while
						$expr = new AS2DoWhile;
					} else {
						$expr = new AS2While;
					}
					$entryBlock->expressions[] = $expr;
				}

				if($headerBlock->lastExpression->positionIfFalse == $loop->continuePosition) {
					// if the loop continues only when the condition is false
					// then we need to invert the condition
					$expr->condition = $this->invertCondition($headerBlock->lastExpression->condition);
				} else {
					$expr->condition = $headerBlock->lastExpression->condition;
				}
			} else {
				// no header--the "loop" is the function itself
				$expr = $function;
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

			// mark remaining blocks that hasn't been marked as belonging to the loop
			foreach($loop->contentPositions as $blockPosition) {
				$block = $blocks[$blockPosition];
				if($block->destination === null) {
					$block->destination =& $expr->expressions;
				}
			}
		}
		
		// copy expressions to where they belong
		foreach($blocks as $ip => $block) {
			if(is_array($block->destination)) {
				foreach($block->expressions as $expr) {
					if($expr instanceof AS2Expression) {
						$block->destination[] = $expr;
					}
				}
			}
		}
	}
	
	protected function structureBreakContinue($block, $continuePosition, $breakPosition) {
		if($block->lastExpression instanceof AS2UnconditionalBranch && !$block->structured) {
			// if it's a jump to the break position (i.e. to the block right after the while loop) then it's a break 
			// if it's a jump to the continue position (i.e. the tail block containing the backward jump) then it's a continue 
			if($block->lastExpression->position === $breakPosition) {
				$block->lastExpression = new AS2Break;
				$block->structured;
			} else if($block->lastExpression->position === $continuePosition) {
				$block->lastExpression = new AS2Continue;
				$block->structured;
			}
		}
	}
		
	protected function structureIf($block, $blocks) {
		if($block->lastExpression instanceof AS2ConditionalBranch && !$block->structured) {
			$tBlock = $blocks[$block->lastExpression->positionIfTrue];
			$fBlock = $blocks[$block->lastExpression->positionIfFalse];
			
			$if = new AS2IfElse;
			// the false block contains the expressions inside the if statement (the conditional jump is there to skip over it)
			// the condition for the if statement is thus the invert
			$if->condition = $this->invertCondition($block->lastExpression->condition);
			$fBlock->destination =& $if->expressionsIfTrue;
			
			// if there's no other way to enter the true block, then its statements must be in an else block
			if(count($tBlock->from) == 1) {
				$tBlock->destination =& $if->expressionsIfFalse;
			} else {
				$if->expressionsIfFalse = null;
			}
			$block->lastExpression = $if;
			$block->structured = true;
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
						if($headerBlock->lastExpression instanceof AS2ConditionalBranch) {
							$loop = new AS2Loop;
							$loop->headerPosition = $to;
							$loop->continuePosition = $blockPosition;
							$loop->breakPosition = $headerBlock->lastExpression->positionIfFalse;
							$loop->entrancePosition = $headerBlock->from[0];
							$loop->contentPositions = array($loop->headerPosition);
							if($loop->continuePosition != $loop->headerPosition) {
								$loop->contentPositions[] = $loop->continuePosition;
							}
							
							// all blocks that leads to the continue block are in the loop
							// we won't catch blocks whose last statement is return, but that's okay
							$stack = array($loop->continuePosition);
							while($stack) {
								$fromPosition = array_pop($stack);
								$fromBlock = $blocks[$fromPosition];
								foreach($fromBlock->from as $fromPosition) {									
									if($fromPosition != $loop->entrancePosition && !in_array($fromPosition, $loop->contentPositions)) {
										$loop->contentPositions[] = $fromPosition;
										array_push($stack, $fromPosition);
									}
								}
							}
							
							sort($loop->contentPositions);
							$loops[$blockPosition] = $loop;
							$block->structured = true;
						}
					}
				}
			}
		}
		
		// sort the loops, moving innermost ones to the beginning
		usort($loops, array($this, 'compareLoops'));
		
		// add outer loop encompassing the function body
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
	
	protected function invertBinaryOperator($operator) {
		switch($operator) {
			case '==': return '!='; 
			case '!=': return '==';
			case '===': return '!==';
			case '!==': return '===';
			case '!==': return '===';
			case '>': return '<=';
			case '<': return '>=';
			case '>=': return '<';
			case '<=': return '>';
		}
	}
	
	protected function invertCondition($expr) {
		if($expr instanceof AS2UnaryOperation) {
			if($expr->operator == '!') {
				return $expr->operand;
			}
		} else if($expr instanceof AS2BinaryOperation) {
			if($newOperator = $this->invertBinaryOperator($expr->operator)) {
				$newExpr = clone $expr;
				$newExpr->operator = $newOperator;
				return $newExpr;
			}
		}
		$newExpr = new AS2UnaryOperation;
		$newExpr->operator = '!';
		$newExpr->operand = $expr;
		$newExpr->precedence = 3;
		return $newExpr;
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

	protected function doCall($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'call');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doCallFunction($cxt) {
		$expr = new AS2FunctionCall;
		$name = array_pop($cxt->stack);
		if(is_scalar($name)) {
			$expr->name = new AS2Variable;
			$expr->name->name = $name;
		} else {
			$expr->name = $name;
		}
		$argumentCount = array_pop($cxt->stack);
		for($i = 0; $i < $argumentCount; $i++) {
			$expr->arguments[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $expr);
	}

	protected function doCallMethod($cxt) {
		$name = array_pop($cxt->stack);
		if($name && $name !== self::$undefined) {
			$object = array_pop($cxt->stack);
			if(!($object instanceof AS2Variable) || $object->name != 'this') {
				array_push($cxt->stack, $object);
				array_push($cxt->stack, $name);
				$this->doGetMember($cxt);
			} else {
				array_push($cxt->stack, $name);
			}
		}
		$this->doCallFunction($cxt);
	}

	protected function doCastOp($cxt) {
		echo "doCastOp\n";
	}

	protected function doCharToAscii($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'ord');
		$this->doCallFunction($cxt);
	}

	protected function doCloneSprite($cxt) {
		array_push($cxt->stack, 3);
		array_push($cxt->stack, 'duplicateMovieClip');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doConstantPool($cxt) {
		$count = $this->readUI16($cxt);
		$this->constantPool = array();
		for($i = 0; $i < $count; $i++) {
			$this->constantPool[] = $this->readString($cxt);
		}
	}

	protected function doDecrement($cxt) {
		array_push($cxt->stack, 1);
		$this->doSubtract($cxt);
	}

	protected function doDefineFunction($cxt) {
		$expr = new AS2Function;
		$name = $this->readString($cxt);
		if($name) {
			$expr->name = new AS2Variable;
			$expr->name->name = $name;
		}
		$argumentCount = $this->readUI16($cxt);
		for($i = 0; $i < $argumentCount; $i++) {
			$argument = new AS2Variable;
			$argument->name = $this->readString($cxt);
			$expr->arguments[] = $argument;
		}
		$codeSize = $this->readUI16($cxt);
		$cxt->dataRemaining = $codeSize;
		$byteCodes = $this->readBytes($cxt, $codeSize);
		$cxtF = $this->createContext($byteCodes);
		$this->decompileFunctionBody($cxtF, $expr);
		if($expr->name) {
			return $expr;
		} else {
			array_push($cxt->stack, $expr);
		}
	}

	protected function doDefineFunction2($cxt) {
		$expr = new AS2Function;
		$name = $this->readString($cxt);
		if($name) {
			$expr->name = new AS2Variable;
			$expr->name->name = $name;
		}
		$argumentCount = $this->readUI16($cxt);
		$registerCount = $this->readUI8($cxt);
		$flags = $this->readUI16($cxt);
		$preloadedVariables = array();
		for($i = 0; $i < $argumentCount; $i++) {
			$registerIndex = $this->readUI8($cxt);
			$argument = new AS2Variable;
			$argument->name = $this->readString($cxt);
			$expr->arguments[] = $argument;
			if($registerIndex) {
				$preloadedVariables[$registerIndex] = $argument->name;
			}
		}
		$registerIndex = 1;
		if($flags & 0x0001) {	// PreloadThis
			$preloadedVariables[$registerIndex++] = 'this';
		}
		if($flags & 0x0004) {	// PreloadArguments
			$preloadedVariables[$registerIndex++] = 'arguments';
		}
		if($flags & 0x0010) {	// PreloadSuper
			$preloadedVariables[$registerIndex++] = 'super';
		}
		if($flags & 0x0040) {	// PreloadRoot
			$preloadedVariables[$registerIndex++] = '_root';
		}
		if($flags & 0x0080) {	// PreloadParent
			$preloadedVariables[$registerIndex++] = '_parent';
		}
		if($flags & 0x0100) {	// PreloadGlobal
			$preloadedVariables[$registerIndex++] = '_global';
		}
		$codeSize = $this->readUI16($cxt);
		$cxt->dataRemaining = $codeSize;
		$byteCodes = $this->readBytes($cxt, $codeSize);
		$cxtF = $this->createContext($byteCodes, $preloadedVariables);
		$this->decompileFunctionBody($cxtF, $expr);
		if($expr->name) {
			return $expr;
		} else {
			array_push($cxt->stack, $expr);
		}
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

	protected function doEndDrag($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'stopDrag');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doEnumerate($cxt) {
		echo "doEnumerate\n";
	}

	protected function doEnumerate2($cxt) {
		echo "doEnumerate2\n";
	}

	protected function doEquals($cxt) {
		$this->doBinaryOp($cxt, '==', 8);
	}

	protected function doEquals2($cxt) {
		$this->doBinaryOp($cxt, '==', 8);
	}

	protected function doExtends($cxt) {
		$superclass = array_pop($cxt->stack);
		$subclass = array_pop($cxt->stack);
		
		// Subclass.prototype = { __proto__: Superclass.prototype, __constructor__: Superclass };
		$prototype = new AS2Variable;
		$prototype->name = 'prototype';
		$superclassPrototype = new AS2BinaryOperation;
		$superclassPrototype->operator = '.';
		$superclassPrototype->operand1 = $superclass;
		$superclassPrototype->operand2 = $prototype;
		$superclassPrototype->precedence = 1;
		$prototypeObject = new AS2ObjectInitializer;
		$prototypeObject->items = array( '__proto__' => $superclassPrototype, '__constructor__' => $superclass );
		
		array_push($cxt->stack, $subclass);
		array_push($cxt->stack, 'prototype');
		array_push($cxt->stack, $prototypeObject);
		return $this->doSetMember($cxt);
	}

	protected function doGetMember($cxt) {
		$name = array_pop($cxt->stack);
		if(is_string($name) && preg_match('/^\w+$/', $name)) {
			$expr = new AS2Variable;
			$expr->name = $name;
			array_push($cxt->stack, $expr);
			$this->doBinaryOp($cxt, '.', 1);
		} else {
			$expr = new AS2ArrayAssessor;
			$expr->array = array_pop($cxt->stack);
			$expr->index = $name;
			array_push($cxt->stack, $expr);
		}
	}

	protected function doGetProperty($cxt) {
		static $propertyNames = array('_x', '_y', '_xscale', '_yscale', '_currentframe', '_totalframes', '_alpha', '_visible', '_width', '_height', '_rotation', '_target', '_framesloaded', '_name', '_droptarget', '_url', '_highquality', '_focusrect', '_soundbuftime', '_quality', '_xmouse', '_ymouse' );
		$index = array_pop($cxt->stack);
		$expr = new AS2Variable;
		$expr->name = array_pop($cxt->stack);
		array_push($expr);
		$this->doBinaryOp($cxt, '.', 1);
	}

	protected function doGetTime($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'getTimer');
		$this->doCallFunction($cxt);
	}

	protected function doGetURL($cxt) {
		$target = $this->readString($cxt);
		$url = $this->readString($cxt);
		$flags = ($target == '_level0' || $target == '_level1') ? 0x02 : 0;
		array_push($cxt->stack, $url);
		array_push($cxt->stack, $target);
		$this->doGetURL2($cxt, chr($flags));
	}

	protected function doGetURL2($cxt) {
		$flags = $this->readUI8($cxt);
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

	protected function doGotoFrame($cxt) {
		$index = $this->readUI16($cxt);
		array_push($cxt->stack, $index);
		array_push($cxt->stack, 'gotoAndPlay');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doGotoFrame2($cxt) {
		$flags = $this->readUI16($cxt);
		if($flags & 0x02) {
			$sceneBias = $this->readUI16($cxt);
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

	protected function doGoToLabel($cxt) {
		$label = $this->readString($cxt);
		array_push($cxt->stack, $label);
		array_push($cxt->stack, 'gotoAndPlay');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doGreater($cxt) {
		$this->doBinaryOp($cxt, '>', 7);
	}

	protected function doIf($cxt) {
		$offset = $this->readSI16($cxt);
		$ctrl = new AS2ConditionalBranch;
		$ctrl->condition = array_pop($cxt->stack);
		$ctrl->position = $ctrl->positionIfTrue = $cxt->nextPosition + $offset;
		$ctrl->positionIfFalse = $cxt->nextPosition;
		return $ctrl;
	}

	protected function doImplementsOp($cxt) {
		$class = array_pop($cxt->stack);
		$count = array_pop($cxt->stack);
		$interfaces = new AS2ArrayInitializer;
		for($i = 0; $i < $count; $i++) {
			$interfaces->items[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $class);
		array_push($cxt->stack, 'interfaces');
		array_push($cxt->stack, $interfaces);
		return $this->doSetMember($cxt);
	}

	protected function doIncrement($cxt) {
		array_push($cxt->stack, 1);
		$this->doAdd($cxt);
	}

	protected function doInitArray($cxt) {
		$expr = new AS2ArrayInitializer;
		$count = array_pop($cxt->stack);
		for($i = 0; $i < $count; $i++) {
			$expr->items[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $expr);
	}

	protected function doInitObject($cxt) {
		$expr = new AS2ObjectInitializer;
		$count = array_pop($cxt->stack);
		for($i = 0; $i < $count; $i++) {
			$value = array_pop($cxt->stack);
			$name = array_pop($cxt->stack);
			$expr->items[$name] = $value;
		}
		array_push($cxt->stack, $expr);
	}

	protected function doInstanceOf($cxt) {
		$this->doBinaryOp($cxt, 'instanceof', 7);
	}

	protected function doJump($cxt) {
		$offset = $this->readSI16($cxt);
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

	protected function doNewMethod($cxt) {
		echo "NewMethod\n";
	}

	protected function doNewObject($cxt) {
		$this->doCallFunction($cxt);
		$this->doUnaryOp($cxt, 'new', 1);
	}

	protected function doNextFrame($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'nextFrame');
		return $this->doCallTargetMethod($cxt);
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
			if($expr instanceof AS2Expression && !($expr instanceof AS2Variable)) {
				return $expr;
			}
		}
	}

	protected function doPrevFrame($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'prevFrame');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doPush($cxt) {
		do {
			$type = $this->readUI8($cxt);
			switch($type) {
				case 0: $value = $this->readString($cxt); break;
				case 1: $value = $this->readF32($cxt); break;
				case 2: $value = null; break;
				case 3: $value = self::$undefined; break;
				case 4: $value = $cxt->registers[ $this->readUI8($cxt) ]; break;
				case 5: $value = $this->readUI8($cxt); break;
				case 6: $value = $this->readF64($cxt); break;
				case 7: $value = $this->readUI32($cxt); break;
				case 8: $value = $this->constantPool[ $this->readUI8($cxt) ]; break;
				case 9: $index = $value = $this->constantPool[ $this->readUI16($cxt) ]; break;
			}
			array_push($cxt->stack, $value);
		} while($cxt->dataRemaining);
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

	protected function doRemoveSprite($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'removeMovieClip');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doReturn($cxt) {
		$expr = new AS2Return;
		$expr->operand = array_pop($cxt->stack);
		return $expr;
	}

	protected function doSetMember($cxt) {
		$value = array_pop($cxt->stack);
		$this->doGetMember($cxt);
		array_push($cxt->stack, $value);
		$this->doBinaryOp($cxt, '=', 15);
		$expr = array_pop($cxt->stack);
		return $expr;
	}

	protected function doSetProperty($cxt) {
		$value = array_pop($cxt->stack);
		$this->doGetProperty($cxt);
		array_push($value);
		$this->doBinaryOp($cxt, '=', 15);
		$expr = array_pop($cxt->stack);
		return $expr;
	}

	protected function doSetTarget($cxt) {
		$name = $this->readString($cxt);
		array_push($cxt->stack, $name);
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
		$expr = array_pop($cxt->stack);
		return $expr;
	}

	protected function doStackSwap($cxt) {
		$op1 = array_pop($cxt->stack);
		$op2 = array_pop($cxt->stack);
		array_push($cxt->stack, $op1);
		array_push($cxt->stack, $op2);
	}

	protected function doStartDrag($cxt) {
		$object = array_pop($cxt->stack);
		$lockCenter = array_pop($cxt->stack);
		$constrain = array_pop($cxt->stack);
		$argumentCount = 0;
		if($constrain) {
			$y2 = array_pop($cxt->stack);
			$x2 = array_pop($cxt->stack);
			$y1 = array_pop($cxt->stack);
			$x1 = array_pop($cxt->stack);
			
			array_push($cxt->stack, $y2);
			array_push($cxt->stack, $x2);
			array_push($cxt->stack, $y1);
			array_push($cxt->stack, $x1);
			$argumentCount += 4;
		}
		if($lockCenter || $constrain) {
			array_push($cxt->stack, $lockCenter);
			$argumentCount++;
		}
		array_push($cxt->stack, $object);
		$argumentCount++;
		array_push($cxt->stack, $argumentCount);
		array_push($cxt->stack, 'startDrag');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doStop($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'stop');
		return $this->doCallTargetMethod($cxt);
	}

	protected function doStopSounds($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'stopAllSounds');
		return $this->doCallFunction($cxt);
	}

	protected function doStoreRegister($cxt) {
		$regIndex = $this->readUI8($cxt);
		$stackIndex = count($cxt->stack) - 1;
		$value = $cxt->stack[$stackIndex];
		if(isset($cxt->registers[$regIndex])) {
			array_push($cxt->stack, $cxt->registers[$regIndex]);
			array_push($cxt->stack, $value);
			$expr = $this->doSetVariable($cxt);
		} else {
			$var = new AS2Variable;
			$expr = new AS2VariableDeclaration;
			$expr->value = $value;
			$expr->name =& $var->name;
			$var->name = 'reg' . $regIndex;
			$cxt->registers[$regIndex] = $var;
			$cxt->unnamedLocalVariables[] = $var;
		}
		return $expr;
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

	protected function doTargetPath($cxt) {
		// do nothing
	}

	protected function doToggleQualty($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'toggleHighQuality');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doToInteger($cxt) {
		array_push($cxt->stack, 1);
		array_push($cxt->stack, 'int');
		$this->doCallFunction($cxt);
	}

	protected function doToNumber($cxt) {
		$value = array_pop($cxt->stack);
		if(is_scalar($value)) {
			switch(gettype($value)) {
				case 'boolean': $value = ($value) ? 1.0 : 0.0; break;
				case 'int': $value = (double) $value; break;
				case 'double': break;
				case 'string': $value = is_numeric($value) ? (double) $value : NAN; break;
			}
			array_push($cxt->stack, $value);
		} else if($value == self::$undefined) {
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

	protected function doToString($cxt) {
		$value = array_pop($cxt->stack);
		if(is_scalar($value)) {
			switch(gettype($value)) {
				case 'boolean': $value = ($value) ? 'true' : 'false'; break;
				case 'int': $value = (string) $value; break;
				case 'double': $value = is_nan($value) ? 'NaN' : (string) $value; break;
				case 'string': break;
			}
			array_push($cxt->stack, $value);
		} else if($value == self::$undefined) {
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

	protected function doTry($cxt) {
		$expr = new AS2Try;
		$flags = $this->readUI8($cxt);
		$trySize = $this->readUI16($cxt);
		$catchSize = $this->readUI16($cxt);
		$finallySize = $this->readUI16($cxt);
		if($flags & 0x04) {	// CatchInRegister
			$catchName = null;
			$catchRegister = $this->readUI8($cxt);
		} else {
			$catchName = $this->readString($cxt);
			$catchRegister = null;
		}
		$cxt->dataRemaining = $trySize + $catchSize + $finallySize;
		
		$tryByteCodes = $this->readBytes($cxt, $trySize);
		$catchByteCodes = ($flags & 0x01) ? $this->readBytes($cxt, $catchSize) : null;
		$finallyByteCodes = ($flags & 0x02) ? $this->readBytes($cxt, $finallySize) : null;
		
		// save the context
		$cxtS = clone $cxt;
		
		// decompile try block
		$function = new AS2Function;
		$cxt->byteCodes = $tryByteCodes;
		$cxt->byteCodeLength = $trySize;
		$cxt->lastPosition = $cxt->nextPosition = 0;
		$this->decompileFunctionBody($cxt, $function);
		$expr->tryExpressions = $function->expressions;
		
		if($flags & 0x01) {
			// decompile catch block
			$function = new AS2Function;
			if($catchRegister) {
				if(!isset($cxt->registers[$catchRegister])) {
					$var = new AS2Variable;
					$var->name = 'error';
					$cxt->registers[$catchRegister] = $var;
				}
				$expr->catchObject = $cxt->registers[$catchRegister];
			} else {
				$var = new AS2Variable;
				$var->name = $catchName;
				$expr->catchObject = $var;
			}
			$cxt->byteCodes = $catchByteCodes;
			$cxt->byteCodeLength = $catchSize;
			$cxt->lastPosition = $cxt->nextPosition = 0;
			$this->decompileFunctionBody($cxt, $function);
			$expr->catchExpressions = $function->expressions;
		}
		if($flags & 0x02) {
			// decompile finally block
			$function = new AS2Function;
			$byteCodes = $this->readBytes($cxt, $finallySize);
			$cxt->byteCodes = $finallyByteCodes;
			$cxt->byteCodeLength = $finallySize;
			$cxt->lastPosition = $cxt->nextPosition = 0;
			$this->decompileFunctionBody($cxt, $function);
			$expr->finallyExpressions = $function->expressions;
		}
		
		// restore context
		$cxt->byteCodes = $cxtS->byteCodes;
		$cxt->byteCodeLength = $cxtS->byteCodeLength;
		$cxt->lastPosition = $cxtS->lastPosition;
		$cxt->nextPosition = $cxtS->nextPosition;
		$cxt->stack = $cxtS->stack;
		
		return $expr;
	}

	protected function doTypeOf($cxt) {
		$this->doUnaryOp($cxt, 'typeof', 3);
	}

	protected function doWaitForFrame($cxt) {
		$frame = $this->readUI16($cxt);
		array_push($cxt->stack, $frame);
		return $this->doWaitForFrame2($cxt);
	}

	protected function doWaitForFrame2($cxt) {
		$expr = new AS2IfFrameLoaded;
		$expr->frame = array_pop($cxt->stack);
		$skipCount = $this->readUI8($cxt);
		// calculate the length of the bytecodes based on skipCount
		$startIndex = $cxt->nextPosition;
		while($skipCount-- > 0 && $cxt->nextPosition < $cxt->byteCodeLength) {
			$cxt->dataRemaining = 1;
			$code = $this->readUI8($cxt);
			if($code >= 0x80) {
				$cxt->dataRemaining = 2;
				$cxt->nextPosition += $this->readUI16($cxt);
			} 
		}
		$size = $cxt->nextPosition - $startIndex;		
		$cxt->nextPosition = $startIndex;		
		$cxt->dataRemaining = $size;
		$byteCodes = $this->readBytes($cxt, $size);
		$cxt = $this->createContext($byteCodes);
		$this->decompileFunctionBody($cxt, $expr);
		return $expr;
	}

	protected function doWith($cxt) {
		$expr = new AS2With;
		$expr->object = array_pop($cxt->stack);
		$size = $this->readUI16($cxt);
		$cxt->dataRemaining = $size;
		$byteCodes = $this->readBytes($cxt, $size);
		$cxt = $this->createContext($byteCodes);
		$this->decompileFunctionBody($cxt, $expr);
		return $expr;
	}
	
	protected function readString($cxt) {
		$zeroPos = strpos($cxt->byteCodes, "\x00", $cxt->nextPosition);
		if($zeroPos === false) {
			$zeroPos = strlen($cxt->byteCodes);
		}
		$length = $zeroPos - $cxt->nextPosition;
		if($length < $cxt->dataRemaining) {
			$string = substr($cxt->byteCodes, $cxt->nextPosition, $length);
			$cxt->nextPosition += $length + 1;
			$cxt->dataRemaining -= $length + 1;
			return $string;
		} else {
			$cxt->dataRemaining = 0;
		}
	}
	
	protected function readBytes($cxt, $count) {
		if($count <= $cxt->dataRemaining) {
			$data = substr($cxt->byteCodes, $cxt->nextPosition, $count);
			$cxt->nextPosition += $count;
			$cxt->dataRemaining -= $count;
			return $data;
		} else {
			$cxt->dataRemaining = 0;
		}
	}
	
	protected function readUI8($cxt) {
		if($cxt->dataRemaining >= 1) {
			$value = ord($cxt->byteCodes[$cxt->nextPosition++]);
			$cxt->dataRemaining--;
			return $value;
		}
	}
	
	protected function readUI16($cxt) {
		if($cxt->dataRemaining >= 2) {
			$data = substr($cxt->byteCodes, $cxt->nextPosition, 2);
			$array = unpack('v', $data);
			$cxt->nextPosition += 2;
			$cxt->dataRemaining -= 2;
			return $array[1];
		} else {
			$cxt->dataRemaining = 0;
		}
	}
	
	protected function readSI16($cxt) {
		$value = $this->readUI16($cxt);
		if($value !== null) {
			if($value & 0x00008000) {
				$value |= -1 << 16;
			}
			return $value;
		}
	}
	
	protected function readUI32($cxt) {
		if($cxt->dataRemaining >= 4) {
			$data = substr($cxt->byteCodes, $cxt->nextPosition, 4);
			$array = unpack('V', $data);
			$cxt->nextPosition += 4;
			$cxt->dataRemaining -= 4;
			return $array[1];
		} else {
			$cxt->dataRemaining = 0;
		}
	}
	
	protected function readF32($cxt) {
		if($cxt->dataRemaining >= 4) {
			$data = substr($cxt->byteCodes, $cxt->nextPosition, 4);
			$array = unpack('f', $data);
			$cxt->nextPosition += 4;
			$cxt->dataRemaining -= 4;
			return $array[1];
		} else {
			$cxt->dataRemaining = 0;
		}
	}
	
	protected function readF64($cxt) {
		if($cxt->dataRemaining >= 8) {
			$data = substr($cxt->byteCodes, $cxt->nextPosition, 8);
			$array = unpack('d', $data);
			$cxt->nextPosition += 8;
			$cxt->dataRemaining -= 8;
			return $array[1];
		} else {
			$cxt->dataRemaining = 0;
		}
	}
}

class AS2DecompilerContext {
	public $byteCodes;
	public $byteCodeLength;
	public $lastPosition;
	public $nextPosition;
	public $dataRemaining;

	public $stack = array();
	public $registers = array();
	public $unnamedLocalVariables = array();
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

class AS2Return extends AS2Expression {
	public $operand;
}

class AS2FunctionCall extends AS2Expression {
	public $name;
	public $arguments = array();
}

class AS2ArrayInitializer extends AS2Expression {
	public $items = array();
}

class AS2ArrayAssessor extends AS2Expression {
	public $array;
	public $index;
}

class AS2ObjectInitializer extends AS2Expression {
	public $items = array();
}

class AS2Break extends AS2Expression {
}

class AS2Continue extends AS2Expression {
}

class AS2IfElse extends AS2Expression {
	public $condition;
	public $expressionsIfTrue = array();
	public $expressionsIfFalse = array();
}

class AS2DoWhile extends AS2Expression {
	public $condition;
	public $expressions = array();
}

class AS2While extends AS2DoWhile {
}

class AS2ForIn extends AS2DoWhile {
}

class AS2Try extends AS2Expression {
	public $tryExpressions;
	public $catchObject;
	public $catchExpressions;
	public $finallyExpressions;
}

class AS2Throw extends AS2Expression {
}

class AS2With extends AS2Expression {
	public $object;
	public $expressions = array();
}

class AS2IfFrameLoaded extends AS2Expression {
	public $frame;
	public $expressions = array();
}

class AS2Function extends AS2Expression {
	public $name;
	public $arguments = array();
	public $expressions = array();
}

class AS2FlowControl {
}

class AS2UnconditionalBranch extends AS2FlowControl {
	public $position;
}

class AS2ConditionalBranch extends AS2FlowControl {
	public $condition;
	public $position;
	public $positionIfTrue = array();
	public $positionIfFalse = array();
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
	public $contentPositions = array();
	public $headerPosition;
	public $continuePosition;
	public $breakPosition;
	public $entrancePosition;
}

?>