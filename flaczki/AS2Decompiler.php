<?php

class AS2Decompiler {
	
	public function decompile($actions) {
		$decoder = new AVM1Decoder;
		$cxt = new AS2DecompilerContext;
		$cxt->opQueue = $decoder->decode($actions);
		$function = new AS2Function(null, null);
		$this->decompileFunctionBody($cxt, $function);
		return $function->statements;
	}
	
	protected function decompileFunctionBody($cxt, $function) {
		// decompile the ops into statements
		$statements = array();	
		$contexts = array($cxt);
		reset($cxt->opQueue);
		$cxt->nextAddress = key($cxt->opQueue);
		while($contexts) {
			$cxt = array_shift($contexts);
			while(($stmt = $this->decompileNextInstruction($cxt)) !== false) {				
				if($stmt) {
					if(!isset($statements[$cxt->lastAddress])) {
						if($stmt instanceof AS2DecompilerFlowControl) {
							if($stmt instanceof AS2DecompilerBranch) {
								// look for ternary conditional operator
								if($this->decompileTernaryOperator($cxt, $stmt)) {
									// the result is on the stack
									continue;
								}
							
								// look ahead find any logical statements that should be part of 
								// the branch's condition							
								$this->decompileBranch($cxt, $stmt);
							
								// clone the context and add it to the list
								$cxtT = clone $cxt;
								$cxtT->nextAddress = $stmt->addressIfTrue;
								$contexts[] = $cxtT;
							} else if($stmt instanceof AS2DecompilerJump) {
								// jump to the location and continue
								$cxt->nextAddress = $stmt->address;
							}
						}
					
						$statements[$cxt->lastAddress] = $stmt;
					} else {
						// we've been here already
						break;
					}
				}
			}
		}
		
		// create basic blocks
		$blocks = $this->createBasicBlocks($statements);
		unset($statements);

		// look for loops, from the innermost to the outermost
		$loops = $this->createLoops($blocks, $function);
		
		// recreate loops and conditional statements
		$this->structureLoops($loops, $blocks, $function);
	}

	protected function decompileBranch($cxt, $branch) {
		if($branch->addressIfTrue > 0) {
			// find all other conditional branches immediately following this one
			$cxtT = clone $cxt;
			$cxtT->nextAddress = $branch->addressIfTrue;
			$cxtT->relatedBranch = $branch;
			$cxtT->branchOnTrue = true;
			$cxtF = clone $cxt;
			$cxtF->nextAddress = $branch->addressIfFalse;		
			$cxtF->relatedBranch = $branch;
			$cxtF->branchOnTrue = false;
			$contexts = array($cxtT, $cxtF);
			$count = 0;
			$scanned = array();
			while($contexts) {
				$cxt = array_shift($contexts);
				$scanned[$cxt->nextAddress] = true;
				while(($stmt = $this->decompileNextInstruction($cxt)) !== false) {
					if($stmt) {
						if($stmt instanceof AS2DecompilerBranch) {
							if($stmt->condition instanceof AS2BinaryOperation && $stmt->condition->operand1 instanceof AS2DecompilerEnumeration) {
								break;
							}
							if($cxt->branchOnTrue) {
								$cxt->relatedBranch->branchIfTrue = $stmt;
							} else {
								$cxt->relatedBranch->branchIfFalse = $stmt;
							}
							
							if(!isset($scanned[$stmt->addressIfTrue]) && !isset($scanned[$stmt->addressIfFalse])) {
								$cxtT = clone $cxt;
								$cxtT->nextAddress = $stmt->addressIfTrue;
								$cxtT->relatedBranch = $stmt;
								$cxtT->branchOnTrue = true;
								$contexts[] = $cxtT;
								$cxt->nextAddress = $stmt->addressIfFalse;
								$cxt->relatedBranch = $stmt;
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
				$branch->addressIfTrue = $branch->branchIfTrue->addressIfTrue;
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
				$branch->addressIfFalse = $branch->branchIfFalse->addressIfFalse;
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
			if($branch->branchIfTrue->addressIfFalse == $branch->addressIfFalse) {
				$branch->condition = new AS2BinaryOperation($branch->branchIfTrue->condition, '&&', $branch->condition, 12);
				$branch->addressIfTrue = $branch->branchIfTrue->addressIfTrue;
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
			if($branch->branchIfFalse->addressIfTrue == $branch->addressIfTrue) {
				$branch->condition = new AS2BinaryOperation($branch->branchIfFalse->condition, '||', $branch->condition, 13);
				$branch->addressIfFalse = $branch->branchIfFalse->addressIfFalse;
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
		$cxtT->nextAddress = $branch->addressIfTrue;
		$stackHeight = count($cxtF->stack);
		$uBranch = null;
		// keep decompiling until we hit an unconditional branch
		while(($stmt = $this->decompileNextInstruction($cxtF)) !== false) {
			if($stmt) {
				if($stmt instanceof AS2DecompilerJump) {
					// something should have been pushed onto the stack
					if(count($cxtF->stack) == $stackHeight + 1) {
						$uBranch = $stmt;
						break;
					} else {
						return false;
					}
				} else if($stmt instanceof AS2DecompilerBranch) {
					// could be a ternary inside a ternary
					if(!$this->decompileTernaryOperator($cxtF, $stmt)) {
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
			
			// see where the false branch would end up at
			while(($stmt = $this->decompileNextInstruction($cxtF)) !== false) {
				if($stmt) {
					break;
				}
			}
			
			// get the value for the true branch by decompiling up to the destination of the unconditional jump 
			while(($stmt = $this->decompileNextInstruction($cxtT)) !== false) {
				if($stmt) {
					// no statement should be generated
					return false;
				}
				if($cxtT->nextAddress == $uBranch->address) {
					break;
				}
			}
			if(count($cxtT->stack) == $stackHeight + 1) {			
				$valueT = array_pop($cxtT->stack);
				// push the expression on to the caller's context 
				// and advance the instruction pointer to the jump destination
				array_push($cxt->stack, new AS2TernaryConditional($branch->condition, $valueT, $valueF, 14));
				$cxt->nextAddress = $uBranch->address;
				return true;
			}
		}
		return false;
	}
	
	public function decompileNextInstruction($cxt) {
		while($op = current($cxt->opQueue)) {
			if(key($cxt->opQueue) == $cxt->nextAddress) {
				$cxt->lastAddress = $cxt->nextAddress;
				next($cxt->opQueue);
				$cxt->nextAddress = key($cxt->opQueue);
				$cxt->op = $op;
				$method = "do$op->name";
				$expr = $this->$method($cxt);
				if($expr instanceof AS2Expression) {
					return new AS2BasicStatement($expr);
				} else {
					return $expr;
				}
			} else {
				next($cxt->opQueue);
			}
		}
		return false;
	}
	
	protected function createBasicBlocks($statements) {
		// find block entry addresses
		$isEntry = array(0 => true);
		foreach($statements as $ip => $expr) {
			if($expr instanceof AS2DecompilerBranch) {
				$isEntry[$expr->addressIfTrue] = true;
				$isEntry[$expr->addressIfFalse] = true;
			} else if($expr instanceof AS2DecompilerJump) {
				$isEntry[$expr->address] = true;
			}
		}
		
		// put nulls into place where there's no statement 
		foreach($isEntry as $ip => $state) {
			if(!isset($statements[$ip])) {
				$statements[$ip] = null;
			}
		}
		ksort($statements);

		$blocks = array();
		$prev = null;
		foreach($statements as $ip => $expr) {
			if(isset($isEntry[$ip])) {				
				if(isset($block)) {
					$block->next = $ip;
				}
				$block = new AS2DecompilerBasicBlock;
				$blocks[$ip] = $block;
				$block->prev = $prev;
				$prev = $ip;
			}
			if($expr) {
				$block->statements[$ip] = $expr;
				$block->lastStatement =& $block->statements[$ip];
			}
		}

		foreach($blocks as $blockAddress => $block) {
			if($block->lastStatement instanceof AS2DecompilerBranch) {
				$block->to[] = $block->lastStatement->addressIfTrue;
				$block->to[] = $block->lastStatement->addressIfFalse;
			} else if($block->lastStatement instanceof AS2DecompilerJump) {
				$block->to[] = $block->lastStatement->address;
			} else {
				if($block->next !== null) {
					$block->to[] = $block->next;
				}
			}
		}
		
		foreach($blocks as $blockAddress => $block) {
			sort($block->to);
			foreach($block->to as $to) {
				$toBlock = $blocks[$to];
				$toBlock->from[] = $blockAddress;
			}
		}
		return $blocks;
	}

	protected function structureLoops($loops, $blocks, $function) {
		// convert the loops into either while or do-while
		foreach($loops as $loop) {
			if($loop->headerAddress !== null) {
				// header is the block containing the conditional statement
				// it is not the first block
				$headerBlock = $blocks[$loop->headerAddress];
				$headerBlock->structured = true;
				
				if($headerBlock->lastStatement->addressIfFalse == $loop->continueAddress) {
					// if the loop continues only when the condition is false
					// then we need to invert the condition
					$condition = $this->invertCondition($headerBlock->lastStatement->condition);
				} else {
					$condition = $headerBlock->lastStatement->condition;
				}
				
				// see if there's an unconditional branch into the loop
				$entryBlock = $blocks[$loop->entranceAddress];
				if($entryBlock->lastStatement instanceof AS2DecompilerJump) {
					if($condition instanceof AS2BinaryOperation && $condition->operand1 instanceof AS2DecompilerEnumeration) {
						// a for in loop						
						$stmt = new AS2ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS2binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($entryBlock->lastStatement->address == $headerBlock->lastStatement->addressIfTrue) {
							// it goes to the beginning of the loop so it must be a do-while
							$stmt = new AS2DoWhile;
						} else {			
							$stmt = new AS2While;
						}
					}
					$entryBlock->lastStatement = $stmt;
				} else {
					// we just fall into the loop
					if($condition instanceof AS2BinaryOperation && $condition->operand1 instanceof AS2DecompilerEnumeration) {
						// a for in loop						
						$stmt = new AS2ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS2binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($loop->headerAddress == $loop->continueAddress) {
							// the conditional statement is at the "bottom" of the loop so it's a do-while
							$stmt = new AS2DoWhile;
						} else {
							$stmt = new AS2While;
						}
					}
					$entryBlock->statements[] = $stmt;
				}
				$stmt->condition = $condition;

			} else {
				// no header--the "loop" is the function itself
				$stmt = $function;
			}

			// convert jumps to breaks and continues
			foreach($loop->contentAddresses as $blockAddress) {
				$block = $blocks[$blockAddress];
				$this->structureBreakContinue($block, $loop->continueAddress, $loop->breakAddress);
			}

			// recreate if statements
			foreach($loop->contentAddresses as $blockAddress) {
				$block = $blocks[$blockAddress];
				$this->structureIf($block, $blocks);
			}

			// mark remaining blocks that hasn't been marked as belonging to the loop
			foreach($loop->contentAddresses as $blockAddress) {
				$block = $blocks[$blockAddress];
				if($block->destination === null) {
					$block->destination =& $stmt->statements;
				}
			}
		}
		
		// copy statements to where they belong
		foreach($blocks as $ip => $block) {
			if(is_array($block->destination)) {
				foreach($block->statements as $stmt) {
					if($stmt) {
						$block->destination[] = $stmt;
					}
				}
			}
		}
	}
	
	protected function structureBreakContinue($block, $continueAddress, $breakAddress) {
		if($block->lastStatement instanceof AS2DecompilerJump && !$block->structured) {
			// if it's a jump to the break address (i.e. to the block right after the while loop) then it's a break 
			// if it's a jump to the continue address (i.e. the tail block containing the backward jump) then it's a continue 
			if($block->lastStatement->address === $breakAddress) {
				$block->lastStatement = new AS2Break;
				$block->structured;
			} else if($block->lastStatement->address === $continueAddress) {
				$block->lastStatement = new AS2Continue;
				$block->structured;
			}
		}
	}
		
	protected function structureIf($block, $blocks) {
		if($block->lastStatement instanceof AS2DecompilerBranch && !$block->structured) {
			$tBlock = $blocks[$block->lastStatement->addressIfTrue];
			$fBlock = $blocks[$block->lastStatement->addressIfFalse];
			
			$if = new AS2IfElse;
			// the false block contains the statements inside the if (the conditional jump is there to skip over it)
			// the condition for the if statement is thus the invert
			$if->condition = $this->invertCondition($block->lastStatement->condition);
			$fBlock->destination =& $if->statementsIfTrue;
			
			// if there's no other way to enter the true block, then its statements must be in an else block
			if(count($tBlock->from) == 1) {
				$tBlock->destination =& $if->statementsIfFalse;
			} else {
				$if->statementsIfFalse = null;
			}
			$block->lastStatement = $if;
			$block->structured = true;
		}
	}
	
	protected function createLoops($blocks) {
		// see which blocks dominates
		$dominators = array();		
		$dominatedByAll = array();
		foreach($blocks as $blockAddress => $block) {
			$dominatedByAll[$blockAddress] = true;
		}
		foreach($blocks as $blockAddress => $block) {
			if($blockAddress == 0) {
				$dominators[$blockAddress] = array(0 => true);
			} else {
				$dominators[$blockAddress] = $dominatedByAll;
			}
		}
		do {
			$changed = false;
			foreach($blocks as $blockAddress => $block) {
				foreach($block->from as $from) {
					$dominatedByBefore = $dominators[$blockAddress];
					$dominatedBy =& $dominators[$blockAddress];
					$dominatedBy = array_intersect_key($dominatedBy, $dominators[$from]);
					$dominatedBy[$blockAddress] = true;
					if($dominatedBy != $dominatedByBefore) {
						$changed = true;
					}
				}
			}
		} while($changed);
		
		$loops = array();
		foreach($blocks as $blockAddress => $block) {
			if(!$block->structured && $blockAddress != 0) {
				foreach($block->to as $to) {
					// a block dominated by what it goes to is a the tail of the loop
					// that block is the loop header--it contains the conditional statement
					if(isset($dominators[$blockAddress][$to])) {
						$headerBlock = $blocks[$to];
						if($headerBlock->lastStatement instanceof AS2DecompilerBranch) {
							$loop = new AS2DecompilerLoop;
							$loop->headerAddress = $to;
							$loop->continueAddress = $blockAddress;
							$loop->breakAddress = $headerBlock->lastStatement->addressIfFalse;
							$loop->entranceAddress = $headerBlock->from[0];
							$loop->contentAddresses = array($loop->headerAddress);
							if($loop->continueAddress != $loop->headerAddress) {
								$loop->contentAddresses[] = $loop->continueAddress;
							}
							
							// all blocks that leads to the continue block are in the loop
							// we won't catch blocks whose last statement is return, but that's okay
							$stack = array($loop->continueAddress);
							while($stack) {
								$fromAddress = array_pop($stack);
								$fromBlock = $blocks[$fromAddress];
								foreach($fromBlock->from as $fromAddress) {									
									if($fromAddress != $loop->entranceAddress && !in_array($fromAddress, $loop->contentAddresses)) {
										$loop->contentAddresses[] = $fromAddress;
										array_push($stack, $fromAddress);
									}
								}
							}
							
							sort($loop->contentAddresses);
							$loops[$blockAddress] = $loop;
							$block->structured = true;
						}
					}
				}
			}
		}
		
		// sort the loops, moving innermost ones to the beginning
		usort($loops, array($this, 'compareLoops'));
		
		// add outer loop encompassing the function body
		$loop = new AS2DecompilerLoop;
		$loop->contentAddresses = array_keys($blocks);
		$loops[] = $loop;
		
		return $loops;
	}
	
	protected function compareLoops($a, $b) {
		if(in_array($a->headerAddress, $b->contentAddresses)) {
			return -1;
		} else if(in_array($b->headerAddress, $a->contentAddresses)) {
			return 1;
		}
		return 0;
	}
	
	protected function getPropertyName($index) {
		static $propertyNames = array('_x', '_y', '_xscale', '_yscale', '_currentframe', '_totalframes', '_alpha', '_visible', '_width', '_height', '_rotation', '_target', '_framesloaded', '_name', '_droptarget', '_url', '_highquality', '_focusrect', '_soundbuftime', '_quality', '_xmouse', '_ymouse' );
		return $propertyNames[$index];
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
			if($expr instanceof AS2Assignment) {
				return true;
			}
		}
		return false;
	}
	
	protected function isThisVariable($expr) {
		return ($expr instanceof AS2Identifier && $expr->string == "this")
		    || ($expr instanceof AVM1Register && $expr->name == "this");
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
		$arguments = ($argumentCount > 0) ? array_reverse(array_splice($cxt->stack, -$argumentCount)) : array();
		array_push($cxt->stack, new AS2FunctionCall(null, $name, $arguments));
	}

	protected function doCallMethod($cxt) {
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments = ($argumentCount > 0) ? array_reverse(array_splice($cxt->stack, -$argumentCount)) : array();
		if($name && !($name instanceof AVM1Undefined)) {
			if($this->isThisVariable($object)) {
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
		$object = array_pop($cxt->stack);
		$constructor = array_pop($cxt->stack);
		array_push($cxt->stack, new AS2FunctionCall(null, $constructor, array($object)));
	}

	protected function doCharToAscii($cxt) {
		return new AS2FunctionCall(null, 'ord', array_splice($cxt->stack, -1));
	}

	protected function doCloneSprite($cxt) {
		return new AS2FunctionCall(null, 'duplicateMovieClip', array_splice($cxt->stack, -3));
	}

	protected function doConstantPool($cxt) {
	}

	protected function doDecrement($cxt) {
		array_push($cxt->stack, 1);
		$this->doSubtract($cxt);
	}

	protected function doDefineFunction($cxt) {
		$name = $cxt->op->op1;
		$arguments = $cxt->op->op3;
		$functionBody = $cxt->op->op5;
		foreach($arguments as &$argument) {
			if(is_string($argument)) {
				$argument = new AS2Identifier($argument);
			}
		}
		$function = new AS2Function($name, $arguments);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $functionBody->operations;
		$this->decompileFunctionBody($cxtF, $function);
		if($name) {
			return $function;
		} else {
			array_push($cxt->stack, $function);
		}
	}

	protected function doDefineFunction2($cxt) {
		$name = $cxt->op->op1;
		$arguments = $cxt->op->op5;
		$functionBody = $cxt->op->op7;
		foreach($arguments as &$argument) {
			if(is_string($argument)) {
				$argument = new AS2Identifier($argument);
			}
		}
		$function = new AS2Function($name, $arguments);
		$cxtF = new AS2DecompilerContext;
		$cxtF->opQueue = $functionBody->operations;
		$this->decompileFunctionBody($cxtF, $function);
		if($name) {
			return $function;
		} else {
			array_push($cxt->stack, $function);
		}
	}

	protected function doDefineLocal($cxt) {
		return new AS2VariableDeclaration(array_pop($cxt->stack), array_pop($cxt->stack));
	}

	protected function doDefineLocal2($cxt) {
		return new AS2VariableDeclaration(AVM1Undefined::$singleton, array_pop($cxt->stack));
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
		return new AS2FunctionCall($this->currentTarget, 'stopDrag', array());
	}

	protected function doEnumerate($cxt) {
		$name = array_pop($cxt->stack);
		$object = new AS2Identifier($name);
		array_push($cxt->stack, new AS2DecompilerEnumeration($object));
	}

	protected function doEnumerate2($cxt) {
		array_push($cxt->stack, new AS2DecompilerEnumeration(array_pop($cxt->stack)));
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
		return new AS2Assignment($prototypeObject, $subclassPrototype);
	}

	protected function doGetMember($cxt) {
		$name = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if(is_string($name) && preg_match('/^\w+$/', $name)) {
			$name = new AS2Identifier($name);
			array_push($cxt->stack, new AS2BinaryOperation($name, '.', $object, 1));
		} else {
			array_push($cxt->stack, new AS2ArrayAccess($name, $object));
		}
	}

	protected function doGetProperty($cxt) {
		$index = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$name = $this->getPropertyName($index);
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
		$name = array_pop($cxt->stack);
		if(is_string($name)) {
			array_push($cxt->stack, new AS2Identifier($name));
		} else {
			array_push($cxt->stack, new AS2FunctionCall(null, 'eval', array($name)));
		}
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
		return new AS2DecompilerBranch(array_pop($cxt->stack), $cxt->lastAddress + 5, $cxt->op->op1);
	}

	protected function doImplementsOp($cxt) {
		/*$class = array_pop($cxt->stack);
		$count = array_pop($cxt->stack);
		$interfaces = new AS2ArrayInitializer;
		for($i = 0; $i < $count; $i++) {
			$interfaces->items[] = array_pop($cxt->stack);
		}
		array_push($cxt->stack, $class);
		array_push($cxt->stack, 'interfaces');
		array_push($cxt->stack, $interfaces);
		return $this->doSetMember($cxt);*/
	}

	protected function doIncrement($cxt) {
		array_push($cxt->stack, 1);
		$this->doAdd($cxt);
	}

	protected function doInitArray($cxt) {
		$count = array_pop($cxt->stack);
		$items = ($count > 0) ? array_reverse(array_splice($cxt->stack, -$count)) : array();
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
		return new AS2DecompilerJump($cxt->lastAddress + 5, $cxt->op->op1);
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
		$name = array_pop($cxt->stack);
		if(is_string($name)) {
			$name = new AS2Identifier($name);
		}
		$object = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments = ($argumentCount > 0) ? array_reverse(array_splice($cxt->stack, -$argumentCount)) : array();
		$constructor = new AS2Functioncall(null, $name, $arguments);
		array_push($cxt->stack, new AS2UnaryOperation('new', $constructor, 3));
	}

	protected function doNewObject($cxt) {
		$name = array_pop($cxt->stack);
		$argumentCount = array_pop($cxt->stack);
		$arguments = ($argumentCount > 0) ? array_reverse(array_splice($cxt->stack, -$argumentCount)) : array();
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
			$var = new AS2ArrayAccess($name, $object);
		}
		return new AS2Assignment($value, $var);
	}

	protected function doSetProperty($cxt) {
		$value = array_pop($cxt->stack);
		$index = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		$name = new AS2Identifier($this->getPropertyName($index));
		$var = new AS2BinaryOperation($name, '.', $object, 1);
		return new AS2Assignment($value, $var);
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
		return new AS2Assignment($value, $var);
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
			if($value instanceof AS2DecompilerEnumeration) {
				$enumeration = $value;
				if(isset($cxt->registerDeclared[$register->index])) {
					$enumeration->variable = $register;
				} else {
					// need to declare variable
					$var = new AS2VariableDeclaration(AVM1Undefined::$singleton, $register);
					$enumeration->variable = $var;
				}
			} else {
				// replace expression on the stack with the register
				$cxt->stack[$stackIndex] = $register;
				if(isset($cxt->registerDeclared[$register->index])) {
					return new AS2Assignment($value, $register);
				} else {
					$cxt->registerDeclared[$register->index] = true;
					return new AS2VariableDeclaration($value, $register);
				}
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
		$tryBody = $cxt->op->op6;
		$catchVar = $cxt->op->op5;
		$catchBody = $cxt->op->op7;
		$finallyBody = $cxt->op->op8;
		
		// decompile try block
		$try = new AS2Function(null, null);
		$cxtT = new AS2DecompilerContext;
		$cxtT->opQueue = $tryBody->operations;
		$this->decompileFunctionBody($cxtT, $try);
		
		if($catchBody) {
			// decompile catch block
			if($catchVar instanceof AVM1Register) {
				$catchVar = $catchVar->name ? $catchVar->name : "REG_$catchVar->index";
			}
			$catch = new AS2Function(null, array($catchVar));
			$cxtC->opQueue = $catchBody->operations;
			$this->decompileFunctionBody($cxtC, $catch);
		} else {
			$catch = null;
		}
		if($finallyBody) {
			// decompile finally block
			$finally = new AS2Function(null, null);
			$cxtF->opQueue = $finallyBody->operations;
			$this->decompileFunctionBody($cxtF, $finally);
		} else {
			$finally = null;
		}
		return new AS2TryCatch($try, $catch, $finally);
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

class AS2Expression {
}

class AS2SimpleStatement {
}

class AS2CompoundStatement {
}

class AS2BasicStatement extends AS2SimpleStatement {
	public $expression;
	
	public function __construct($expr) {
		$this->expression = $expr;
	}
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
		if(is_string($name)) {
			$name = new AS2Identifier($name);
		}
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

class AS2Assignment extends AS2BinaryOperation {

	public function __construct($operand2, $operand1) {
		$this->operator = '=';
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->precedence = 15;
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
		$this->precedence = 14;
	}
}

class AS2ArrayAccess extends AS2Operation {
	public $array;
	public $index;
	
	public function __construct($index, $array) {
		$this->array = $array;
		$this->index = $index;
		$this->precedence = 1;
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

class AS2ObjectInitializer extends AS2Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
	}
}

class AS2Return extends AS2SimpleStatement {
	public $value;
	
	public function __construct($value) {
		$this->value = $value;
	}
}

class AS2Throw extends AS2SimpleStatement {
	public $object;
	
	public function __construct($object) {
		$this->object = $object;
	}
}

class AS2Break extends AS2SimpleStatement {
}

class AS2Continue extends AS2SimpleStatement {
}

class AS2IfElse extends AS2CompoundStatement {
	public $condition;
	public $statementsIfTrue = array();
	public $statementsIfFalse = array();
}

class AS2DoWhile extends AS2CompoundStatement {
	public $condition;
	public $statements = array();
}

class AS2While extends AS2DoWhile {
}

class AS2ForIn extends AS2DoWhile {
}

class AS2TryCatch extends AS2CompoundStatement {
	public $tryStatements;
	public $catchObject;
	public $catchStatements;
	public $finallyStatements;
	
	public function __construct($try, $catch, $finally) {
		$this->tryStatements = $try->statements;
		$this->catchObject = ($catch) ? $catch->arguments[0] : null;
		$this->catchStatements = ($catch) ? $catch->statements : null;
		$this->finallyStatements = ($finally) ? $finally->statements : null;
	}
}

class AS2With extends AS2CompoundStatement {
	public $object;
	public $statements = array();
	
	public function __construct($object) {
		$this->object = $object;
	}
}

class AS2IfFrameLoaded extends AS2CompoundStatement {
	public $frame;
	public $statements = array();
	
	public function __construct($frame) {
		$this->frame = $frame;
	}
}

class AS2Function extends AS2CompoundStatement {
	public $name;
	public $arguments = array();
	public $statements = array();
	
	public function __construct($name, $arguments) {
		if(is_string($name)) {
			$name = new AS2Identifier($name);
		}
		$this->name = $name;
		$this->arguments = $arguments;
	}
}

// structures used in the decompiling process

class AS2DecompilerContext {
	public $op;
	public $opQueue;
	public $lastAddress = 0;
	public $nextAddress = 0;
	public $stack = array();
	public $registerDeclared = array();
	public $currentTarget;
	
	public $relatedBranch;
	public $branchOnTrue;
}

class AS2DecompilerFlowControl {
}

class AS2DecompilerEnumeration {
	public $container;
	public $variable;
	
	public function __construct($container) {
		$this->container = $container;
	}
}

class AS2DecompilerJump extends AS2DecompilerFlowControl {
	public $address;
	
	public function __construct($address, $offset) {
		$this->address = $address + $offset;
	}
}

class AS2DecompilerBranch extends AS2DecompilerFlowControl {
	public $condition;
	public $addressIfTrue;
	public $addressIfFalse;
	
	public function __construct($condition, $address, $offset) {
		$this->condition = $condition;
		$this->addressIfTrue = $address + $offset;
		$this->addressIfFalse = $address;
	}
}

class AS2DecompilerBasicBlock {
	public $statements = array();
	public $lastStatement;
	public $from = array();
	public $to = array();
	public $structured = false;
	public $destination;
	public $prev;
	public $next;
}

class AS2DecompilerLoop {
	public $contentAddresses = array();
	public $headerAddress;
	public $continueAddress;
	public $breakAddress;
	public $entranceAddress;
}

?>