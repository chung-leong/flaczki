<?php

class AS2Decompiler {
	
	protected $constantPool;

	public function decompile($operations) {
		$this->constantPool = array();
			
		$cxt = new AS2DecompilerContext;
		$cxt->opQueue = $operations;
		$function = new AS2Function(null, null);
		$this->decompileFunctionBody($cxt, $function);
		
		$r = new AS2SourceReconstructor;
		print_r($r->reconstruct($function->expressions));
		///print_r($function->expressions);

		return $function->expressions;
	}
	
	protected function decompileFunctionBody($cxt, $function) {
		// decompile the ops into expressions
		$expressions = array();	
		$contexts = array($cxt);
		reset($cxt->opQueue);
		$cxt->nextPosition = key($cxt->opQueue);
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
				$branch->condition = new AS2BinaryOperation($branch->branchIfTrue->condition, '&&', $branch->condition, 12);
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
				$branch->condition = new AS2BinaryOperation($branch->branchIfFalse->condition, '||', $branch->condition, 13);
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
				// push the expression on to the caller's context 
				// and advance the instruction pointer to the jump destination
				array_push($cxt->stack, new AS2TernaryConditional($branch->condition, $valueT, $valueF, 14));
				$cxt->nextPosition = $uBranch->position;
				return true;
			}
		}
		return false;
	}
	
	public function decompileNextInstruction($cxt) {
		while($op = current($cxt->opQueue)) {
			if(key($cxt->opQueue) == $cxt->nextPosition) {
				$cxt->lastPosition = $cxt->nextPosition;
				next($cxt->opQueue);
				$cxt->nextPosition = key($cxt->opQueue);
				$cxt->op = $op;
				$method = "do$op->name";
				$expr = $this->$method($cxt);
				return $expr;
			} else {
				next($cxt->opQueue);
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
				
				if($headerBlock->lastExpression->positionIfFalse == $loop->continuePosition) {
					// if the loop continues only when the condition is false
					// then we need to invert the condition
					$condition = $this->invertCondition($headerBlock->lastExpression->condition);
				} else {
					$condition = $headerBlock->lastExpression->condition;
				}
				
				// see if there's an unconditional branch into the loop
				$entryBlock = $blocks[$loop->entrancePosition];
				if($entryBlock->lastExpression instanceof AS2UnconditionalBranch) {
					if($condition instanceof AS2BinaryOperation && $condition->operand1 instanceof AS2Enumeration) {
						// a for in loop						
						$expr = new AS2ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS2binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($entryBlock->lastExpression->position == $headerBlock->lastExpression->position) {
							// it goes to the beginning of the loop so it must be a do-while
							$expr = new AS2DoWhile;
						} else {			
							$expr = new AS2While;
						}
					}
					$entryBlock->lastExpression = $expr;
				} else {
					// we just fall into the loop
					if($condition instanceof AS2BinaryOperation && $condition->operand1 instanceof AS2Enumeration) {
						// a for in loop						
						$expr = new AS2ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS2binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($loop->headerPosition == $loop->continuePosition) {
							// the conditional statement is at the "bottom" of the loop so it's a do-while
							$expr = new AS2DoWhile;
						} else {
							$expr = new AS2While;
						}
					}
					$entryBlock->expressions[] = $expr;
				}
				$expr->condition = $condition;

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
	
	protected function getPropertyName($id) {
		static $propertyNames = array('_x', '_y', '_xscale', '_yscale', '_currentframe', '_totalframes', '_alpha', '_visible', '_width', '_height', '_rotation', '_target', '_framesloaded', '_name', '_droptarget', '_url', '_highquality', '_focusrect', '_soundbuftime', '_quality', '_xmouse', '_ymouse' );
		return $propertyNames[$id];
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
	
	protected function checkSideEffect($expr) {
		if($expr instanceof AS2Expression) {
			if($expr instanceof AS2FunctionCall) {
				return true;
			}
			if($expr instanceof AS2BinaryOperation) {
				switch($expr->operator) {
					case '=': return true;
				}
			}
		}
		return false;
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
		$newExpr = new AS2UnaryOperation('!', $expr, 3);
		return $newExpr;
	}
	
	protected function doAdd($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5));
	}

	protected function doAdd2($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5));
	}

	protected function doAnd($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '&&', array_pop($cxt->stack), 12));
	}

	protected function doAsciiToChar($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'chr', array_splice($cxt->stack, -1)));
	}

	protected function doBitAnd($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '&', array_pop($cxt->stack), 9));
	}

	protected function doBitLShift($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '<<', array_pop($cxt->stack), 6));
	}

	protected function doBitOr($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '|', array_pop($cxt->stack), 11));
	}

	protected function doBitRShift($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '>>', array_pop($cxt->stack), 6));
	}

	protected function doBitURShift($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '>>>', array_pop($cxt->stack), 6));
	}

	protected function doBitXor($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '^', array_pop($cxt->stack), 10));
	}

	protected function doCall($cxt) {
		return new AS2FunctionCall(null, 'call', array_splice($cxt->stack, -1));
	}

	protected function doCallFunction($cxt) {
		$name = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments();
		for($i = 0; $i < $argumentCount; $i++) {
			$arguments[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, new AS2FunctionCall(null, $name, $arguments));
	}

	protected function doCallMethod($cxt) {
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments = array_reverse(array_splice($cxt->stack, -$argumentCount));
		if($name && !($name instanceof AVM1Undefined)) {
			if($object instanceof AS2Identifier && $object->string == 'this') {
				$object = null;
			}
		} else {
			// constructor
			$name = $object;
			$object = null;
		}
		array_push($cxt->stack, new AS2FunctionCall($object, $name, $arguments));
	}

	protected function doCastOp($cxt) {
		echo "doCastOp\n";
	}

	protected function doCharToAscii($cxt) {
		return new AS2FunctionCall(null, 'ord', array_splice($cxt->stack, -1));
	}

	protected function doCloneSprite($cxt) {
		return new AS2FunctionCall(null, 'duplicateMovieClip', array_splice($cxt->stack, -3));
	}

	protected function doConstantPool($cxt) {
		$this->constantPool = $cxt->op->op2;
	}

	protected function doDecrement($cxt) {
		array_push($cxt->stack, 1);
		$this->doSubtract($cxt);
	}

	protected function doDefineFunction($cxt) {
		$name = $cxt->op->op1;
		$arguments = $cxt->op->op3;
		$operations = $cxt->op->op5;
		$function = new AS2Function($name, $arguments);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $operations;
		$this->decompileFunctionBody($cxtF, $function);
		if($function->name) {
			return $function;
		} else {
			array_push($cxt->stack, $function);
		}
	}

	protected function doDefineFunction2($cxt) {
		$name = $cxt->op->op1;
		$arguments = $cxt->op->op5;
		$operations = $cxt->op->op7;
		$function = new AS2Function($name, $arguments);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $operations;
		$this->decompileFunctionBody($cxtF, $function);
		if($function->name) {
			return $function;
		} else {
			array_push($cxt->stack, $function);
		}
	}

	protected function doDefineLocal($cxt) {
		return new AS2VariableDeclaration(array_pop($cxt->stack), array_pop($cxt->stack));
	}

	protected function doDefineLocal2($cxt) {
		return new AS2VariableDeclaration(null, array_pop($cxt->stack));
	}

	protected function doDelete($cxt) {
		array_push($cxt->stack, new AS2UnaryOperation('delete', array_pop($cxt->stack), 3));
	}

	protected function doDelete2($cxt) {
		array_push($cxt->stack, new AS2UnaryOperation('delete', array_pop($cxt->stack), 3));
	}

	protected function doDivide($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '/', array_pop($cxt->stack), 4));
	}

	protected function doEndDrag($cxt) {
		array_push($cxt->stack, 0);
		array_push($cxt->stack, 'stopDrag');
		$this->doCallFunction($cxt);
		return $this->doPop($cxt);
	}

	protected function doEnumerate($cxt) {
		$this->doGetVariable($cxt);
		$this->doEnumerate2($cxt);
	}

	protected function doEnumerate2($cxt) {
		$object = array_pop($cxt->stack);
		$expr = new AS2Enumeration;
		$expr->container = $object;
		array_push($cxt->stack, $expr);
	}

	protected function doEquals($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8));
	}

	protected function doEquals2($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8));
	}

	protected function doExtends($cxt) {
		$superclass = array_pop($cxt->stack);
		$subclass = array_pop($cxt->stack);
		
		// Subclass.prototype = { __proto__: Superclass.prototype, __constructor__: Superclass };
		$prototype = new AS2Identifier('prototype');
		$subclassPrototype = new AS2BinaryOperation($prototype, '.', $subclass, 1);
		$superclassPrototype = new AS2BinaryOperation($prototype, '.', $superclass, 1);
		$prototypeObject = new AS2ObjectInitializer(array( '__proto__' => $superclassPrototype, '__constructor__' => $superclass ));
		return new AS2BinaryOperation($prototypeObject, '=', $subclassPrototype, 15);
	}

	protected function doGetMember($cxt) {
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if(is_string($name) && preg_match('/^\w+$/', $name)) {
			$name = new AS2Identifier($name);
			array_push($cxt->stack, new AS2BinaryOperation($name, '.', $object, 1));
		} else {
			array_push($cxt->stack, new AS2ArrayAssessor($name, $object));
		}
	}

	protected function doGetProperty($cxt) {
		$id = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$name = $this->getPropertyName($id);
		$var = new AS2Identifier($name);
		array_push($cxt->stack, new AS2BinaryOperation($var, '.', $object, 1));
	}

	protected function doGetTime($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'getTimer', array()));
	}

	protected function doGetURL($cxt) {
		$url = $cxt->op->op1;
		$target = $cxt->op->op2;
		$arguments = array($url, $target);
		$name = ($target == '_level0' || $target == '_level1') ? 'loadMovie' : 'getURL';
		return new AS2FunctionCall(null, $name, $arguments);
	}

	protected function doGetURL2($cxt) {
		$flags = $cxt->op->op1;		
		$target = array_pop($cxt->stack);
		$url = array_pop($cxt->stack);
		$arguments = array($url, $target);
		switch($flags >> 6) {
			case 1: $arguments[] = 'GET'; break;
			case 2: $arguments[] = 'POST'; break;
		}
		switch($flags & 0x03) {
			case 2: $name = 'loadMovie'; break;
			case 3: $name = 'loadVariables'; break;
			default: $name = 'getURL'; break;
		}
		return new AS2FunctionCall(null, $name, $arguments);
	}

	protected function doGetVariable($cxt) {
		array_push($cxt->stack, new AS2Identifier(array_pop($cxt->stack)));
	}

	protected function doGotoFrame($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'gotoAndPlay', array($cxt->op->op1));
	}

	protected function doGotoFrame2($cxt) {
		$flags = $cxt->op->op1;
		$sceneBias = $cxt->op->op2;
		$frame = array_pop($cxt->stack);
		$arguments = array();
		if($sceneBias !== null) {
			// TODO: handle scene name 
		}
		$arguments[] = $frame;
		$name = ($flags & 0x01) ? 'gotoAndPlay' : 'gotoAndStop';
		$object = $cxt->currentTarget;
		return new AS2FunctionCall($object, $name, $arguments);
	}

	protected function doGoToLabel($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'gotoAndPlay', array($cxt->op->op1));
	}

	protected function doGreater($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 7));
	}

	protected function doIf($cxt) {
		// the if instruction is 5 bytes long
		return new AS2ConditionalBranch(array_pop($cxt->stack), $cxt->lastPosition + 5, $cxt->op->op1);
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
		$count = array_pop($cxt->stack);
		$items = array_reverse(array_splice($cxt->stack, -$count));
		array_push($cxt->stack, new AS2ArrayInitializer($items));
	}

	protected function doInitObject($cxt) {
		$count = array_pop($cxt->stack);
		$items = array();
		for($i = 0; $i < $count; $i++) {
			$value = array_pop($cxt->stack);
			$name = array_pop($cxt->stack);
			$items[$name] = $value;
		}
		array_push($cxt->stack, new AS2ObjectInitializer($items));
	}

	protected function doInstanceOf($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), 'instanceof', array_pop($cxt->stack), 7));
	}

	protected function doJump($cxt) {
		// the jump instruction is also 5 bytes long
		return new AS2UnconditionalBranch($cxt->lastPosition + 5, $cxt->op->op1);
	}

	protected function doLess($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 7));
	}

	protected function doLess2($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 7));
	}

	protected function doMBAsciiToChar($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'mbchr', array_splice($cxt->stack, -1)));
	}

	protected function doMBCharToAscii($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'mbord', array_splice($cxt->stack, -1)));
	}

	protected function doMBStringExtract($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'mbsubstring', array_splice($cxt->stack, -3)));
	}

	protected function doMBStringLength($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'mblength', array_splice($cxt->stack, -1)));
	}

	protected function doModulo($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '%', array_pop($cxt->stack), 4));
	}

	protected function doMultiply($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '*', array_pop($cxt->stack), 4));
	}

	protected function doNewMethod($cxt) {
		echo "NewMethod\n";
	}

	protected function doNewObject($cxt) {
		$name = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments = array_reverse(array_splice($cxt->stack, -$argumentCount));
		$constructor = new AS2Functioncall(null, $name, $arguments);
		array_push($cxt->stack, new AS2UnaryOperation('new', $constructor, 3));
	}

	protected function doNextFrame($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'nextFrame', array());
	}

	protected function doNot($cxt) {
		array_push($cxt->stack, new AS2UnaryOperation('!', array_pop($cxt->stack), 3));
	}

	protected function doOr($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '||', array_pop($cxt->stack), 13));
	}

	protected function doPlay($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'play', array());
	}

	protected function doPop($cxt) {
		$expr = array_pop($cxt->stack);
		if(!$cxt->stack) {
			if($this->checkSideEffect($expr)) {
				return $expr;
			}
		}
	}

	protected function doPrevFrame($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'prevFrame', array());
	}

	protected function doPush($cxt) {
		foreach($cxt->op->op1 as $value) {
			array_push($cxt->stack, $value);
		}
	}

	protected function doPushDuplicate($cxt) {
		array_push($cxt->stack, $cxt->stack[ count($cxt->stack) - 1 ]);
	}

	protected function doRandomNumber($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'random', array_splice($this->stack, -1)));
	}

	protected function doRemoveSprite($cxt) {
		return new AS2FunctionCall(null, 'removeMovieClip', array_splice($this->stack, -1));
	}

	protected function doReturn($cxt) {
		return new AS2Return(array_pop($cxt->stack));
	}

	protected function doSetMember($cxt) {
		$value = array_pop($cxt->stack);
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if(is_string($name) && preg_match('/^\w+$/', $name)) {
			$name = new AS2Identifier($name);
			$var = new AS2BinaryOperation($name, '.', $object, 1);
		} else {
			$var = new AS2ArrayAssessor($name, $object);
		}
		return new AS2BinaryOperation($value, '=', $var, 15);
	}

	protected function doSetProperty($cxt) {
		$id = $cxt->op->op1;
		$value = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$name = new AS2Identifier($this->getPropertyName($id));
		$var = new AS2BinaryOperation($name, '.', $object, 1);
		return new AS2BinaryOperation($value, '=', $var, 15);
	}

	protected function doSetTarget($cxt) {
		// TODO
		$name = $this->readString($cxt);
		$this->currentTarget = $name;
	}

	protected function doSetTarget2($cxt) {
		// TODO--need to do eval if it's string not a constant
		$name = array_pop($cxt->stack);
		if($name) {
			$cxt->currentTarget = new AS2Identifier($name);
		} else {
			$cxt->currentTarget = null;
		}
	}

	protected function doSetVariable($cxt) {
		$value = array_pop($cxt->stack);
		$name = array_pop($cxt->stack);
		$var = new AS2Identifier($name);
		return new AS2BinaryOperation($value, '=', $var, 15);
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
		$arguments = array($lockCenter);
		if($constrain) {
			$arguments = array_splice($arguments, 1, 0, array_splice($cxt->stack, -4));
		}
		return new AS2FunctionCall($object, 'startDrag', $arguments);
	}

	protected function doStop($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'stop', array());
	}

	protected function doStopSounds($cxt) {
		return new AS2FunctionCall($cxt->currentTarget, 'stopAllSounds', array());
	}

	protected function doStoreRegister($cxt) {
		$register = $cxt->op->op1;
		$stackIndex = count($cxt->stack) - 1;
		if($stackIndex >= 0) {
			$value = $cxt->stack[$stackIndex];
			if($value instanceof AS2Enumeration) {
			} else {
			}
		}
	}

	protected function doStrictEquals($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '===', array_pop($cxt->stack), 8));
	}

	protected function doStringAdd($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5));
	}

	protected function doStringEquals($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8));
	}

	protected function doStringExtract($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'substring', array_splice($cxt->stack, -3)));
	}

	protected function doStringGreater($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 7));
	}

	protected function doStringLength($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'substring', array_splice($cxt->stack, -1)));
	}

	protected function doStringLess($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 7));
	}

	protected function doSubtract($cxt) {
		array_push($cxt->stack, new AS2BinaryOperation(array_pop($cxt->stack), '-', array_pop($cxt->stack), 5));
	}

	protected function doTargetPath($cxt) {
		array_push($cxt->stack, new AS2FunctionCall(null, 'string', array_splice($cxt->stack, -1)));
	}

	protected function doToggleQualty($cxt) {
		return new AS2FunctionCall(null, 'toggleHighQuality', array());
	}

	protected function doToInteger($cxt) {
		return new AS2FunctionCall(null, 'int', array_splice($cxt->stack, -1));
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
		} else if($value instanceof AVM1Undefined) {
			array_push($cxt->stack, NAN);
		} else {
			array_push($cxt->stack, new AS2FunctionCall(null, 'parseFloat', array($value)));
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
		} else if($value instanceof AVM1Undefined) {
			array_push($cxt->stack, 'undefined');
		} else {
			array_push($cxt->stack, new AS2FunctionCall($value, 'toString', array()));
		}
	}

	protected function doTrace($cxt) {
		return new AS2FunctionCall(null, 'trace', array_splice($cxt->stack, -1));
	}

	protected function doTry($cxt) {
		$tryOps = $cxt->op->op6;
		$catchVar = $cxt->op->op5;
		$catchOps = $cxt->op->op7;
		$finallyOps = $cxt->op->op8;
		
		// decompile try block
		$try = new AS2Function(null, null);
		$cxtT = new AS2DecompilerContext;
		$cxtT->opQueue = $tryByteCodes;
		$this->decompileFunctionBody($cxtT, $try);
		
		if($catchOps) {
			// decompile catch block
			if($catchVar instanceof AVM1Register) {
				$catchVar = $catchVar->name ? $catchVar->name : "REG_$catchVar->index";
			}
			$catch = new AS2Function(null, array($catchVar));
			$cxtC->opQueue = $catchOps;
			$this->decompileFunctionBody($cxtC, $catch);
		} else {
			$catch = null;
		}
		if($finallyOps) {
			// decompile finally block
			$finally = new AS2Function(null, null);
			$cxtF->opQueue = $finallyOps;
			$this->decompileFunctionBody($cxtF, $finally);
		} else {
			$finally = null;
		}
		return AS2TryCatch($try, $catch, $finally);
	}

	protected function doTypeOf($cxt) {
		array_push($cxt->stack, new AS2UnaryOperation('typeof', array_pop($cxt->stack), 3));
	}

	protected function doWaitForFrame($cxt) {
		$frame = $cxt->op->op1;
		$operations = $cxt->op->op3;
		$ifFrameLoaded = new AS2IfFrameLoaded($frame);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $operations;
		$this->decompileFunctionBody($cxtF, $ifFrameLoaded);
		return $ifFrameLoaded;
	}

	protected function doWaitForFrame2($cxt) {
		$operations = $cxt->op->op2;
		$frame = array_pop($cxt->stack);
		$ifFrameLoaded = new AS2IfFrameLoaded($frame);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $operations;
		$this->decompileFunctionBody($cxtF, $ifFrameLoaded);
		return $ifFrameLoaded;
	}

	protected function doWith($cxt) {
		$operations = $cxt->op->op2;
		$object = array_pop($cxt->stack);
		$with = new AS2With($object);
		$cxtW = new AS2DecompilerContext;
		$cxtW->opQueue = $operations;
		$this->decompileFunctionBody($cxtW, $with);
		return $with;
	}
}

class AS2DecompilerContext {
	public $op;
	public $opQueue;
	public $lastPosition = 0;
	public $nextPosition = 0;
	public $stack = array();
	public $currentTarget;
	
	public $relatedBranch;
	public $branchOnTrue;
}

class AS2Expression {
}

class AS2Identifier extends AS2Expression {
	public $string;
	
	public function __construct($name) {
		$this->string = $name;
	}
}

class AS2VariableDeclaration extends AS2Expression {
	public $name;
	public $value;
	
	public function __construct($value, $name) {
		$this->name = $name;
		$this->value = $value;
	}
}

class AS2Operation extends AS2Expression {
	public $precedence;
}

class AS2BinaryOperation extends AS2Operation {
	public $operator;
	public $operand1;
	public $operand2;
	
	public function __construct($operand2, $operator, $operand1, $precedence) {
		$this->operator = $operator;
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->precedence = $precedence;
	}
}

class AS2UnaryOperation extends AS2Operation {
	public $operator;
	public $operand;
	
	public function __construct($operator, $operand, $precedence) {
		$this->operator = $operator;
		$this->operand = $operand;
		$this->precedence = $precedence;
	}
}

class AS2TernaryConditional extends AS2Operation {
	public $condition;
	public $valueIfTrue;
	public $valueIfFalse;
	
	public function __construct($condition, $valueIfTrue, $valueIfFalse) {
		$this->condition = $condition;
		$this->valueIfTrue = $valueIfTrue;
		$this->valueIfFalse = $valueIfFalse;
	}
}

class AS2Return extends AS2Expression {
	public $value;
	
	public function __construct($value) {
		$this->value = $value;
	}
}

class AS2FunctionCall extends AS2Expression {
	public $name;
	public $arguments;
	
	public function __construct($object, $name, $arguments) {
		if(is_string($name)) {
			$name = new AS2Identifier($name);
		}
		if($object) {
			$name = new AS2BinaryOperation($name, '.', $object, 1);
		}
		$this->name = $name;
		$this->arguments = $arguments;
	}
}

class AS2ArrayInitializer extends AS2Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
	}
}

class AS2ArrayAssessor extends AS2Expression {
	public $array;
	public $index;
	
	public function __construct($index, $array) {
		$this->array = $array;
		$this->index = $index;
	}
}

class AS2ObjectInitializer extends AS2Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
	}
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

class AS2TryCatch extends AS2Expression {
	public $tryExpressions;
	public $catchObject;
	public $catchExpressions;
	public $finallyExpressions;
	
	public function __construct($try, $catch, $finally) {
		$this->tryExpressions = $try->expressions;
		$this->catchObject = $catch->arguments[0];
		$this->catchExpressions = $catch->expressions;
		$this->finallyExpressions = $finally->expressions;
	}
}

class AS2Throw extends AS2Expression {
}

class AS2With extends AS2Expression {
	public $object;
	public $expressions = array();
	
	public function __construct($object) {
		$this->object = $object;
	}
}

class AS2IfFrameLoaded extends AS2Expression {
	public $frame;
	public $expressions = array();
	
	public function __construct($frame) {
		$this->frame = $frame;
	}
}

class AS2Function extends AS2Expression {
	public $name;
	public $arguments = array();
	public $expressions = array();
	
	public function __construct($name, $arguments) {
		$this->name = $name;
		$this->arguments = $arguments;
	}
}

class AS2FlowControl {
}

class AS2Enumeration {
	public $container;
	public $variable;
}

class AS2UnconditionalBranch extends AS2FlowControl {
	public $position;
	
	public function __construct($position, $offset) {
		$this->position = $position + $offset;
	}
}

class AS2ConditionalBranch extends AS2FlowControl {
	public $condition;
	public $position;
	public $positionIfTrue;
	public $positionIfFalse;
	
	public function __construct($condition, $position, $offset) {
		$this->condition = $condition;
		$this->positionIfTrue = $position + $offset;
		$this->positionIfFalse = $this->position = $position;
	}
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