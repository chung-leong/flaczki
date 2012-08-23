<?php

class AS3Decompiler {

	protected $imports;
	
	public function decompile($abcFile) {
		$decoder = new AVM2Decoder;
		$scripts = $decoder->decode($abcFile);
		$packages = array();
		
		foreach($scripts as $script) {
			$packages[] = $this->decompileScript($script);
		}
		return $packages;
	}
	
	protected function importName($vmName) {
		$vmNamespace = $vmName->namespace;
		if($vmNamespace->string) {
			if(!in_array($vmNamespace->string, $this->imports)) {
				$this->imports[] = new AS3Identifier($vmNamespace->string);
			}
		}
		return new AS3Identifier($vmName->string ? $vmName->string : '*');
	}
	
	protected function importArguments($vmArguments) {
		$arguments = array();
		foreach($vmArguments as $vmArgument) {
			$name = new AS3Identifier($vmArgument->name->string);
			$type = $this->importName($vmArgument->type);
			$arguments[] = new AS3Argument($name, $type);
		}
		return $arguments;
	}
	
	protected function getAccessModifier($vmNamespace) {
		if($vmNamespace instanceof AVM2ProtectedNamespace) {
			return "protected";
		} else if($vmNamespace instanceof AVM2PrivateNamespace) {
			return "private";
		} else if($vmNamespace instanceof AVM2PackageNamespace) {
			return "public";
		} else if($vmNamespace instanceof AVM2InternalNamespace) {
			return "internal";
		}
	}
				
	protected function decompileScript($vmScript) {
		$this->imports = array();
		$package = new AS3Package;
		$this->decompileMembers($vmScript->members, null, $package);
		$package->imports = $this->imports;
		return $package;
	}
	
	protected function decompileMembers($vmMembers, $scope, $container) {
		$slots = array();
		foreach($vmMembers as $index => $vmMember) {
			$name = new AS3Identifier($vmMember->name->string);
			$access = $this->getAccessModifier($vmMember->name->namespace);
			
			if($vmMember->object instanceof AVM2Class) {
				$member = new AS3Class($name, $access);
				$this->decompileMembers($vmMember->object->members, 'static', $member);
				$this->decompileMembers($vmMember->object->instance->members, null, $member);
				if($access == 'public') {
					$container->namespace = new AS3Identifier($vmMember->name->namespace->string);
				}
			} else if($vmMember->object instanceof AVM2Method) {
				$returnType = $this->importName($vmMember->object->returnType);
				$arguments = $this->importArguments($vmMember->object->arguments);
				if($vmMember->type == AVM2ClassMember::MEMBER_GETTER) {
					$name = new AS3Accessor('get', $name);
				} else if($vmMember->type == AVM2ClassMember::MEMBER_SETTER) {
					$name = new AS3Accessor('set', $name);
				}
				if($container instanceof AS3Class) {
					$member = new AS3ClassMethod($name, $arguments, $returnType, $access, $scope);
				} else {
					$member = new AS3Function($name, $arguments, $returnType, $access);
				}
				$member->operations = $vmMember->object->body->operations;
			} else if($vmMember->object instanceof AVM2Variable) {
				$member = new AS3ClassVariable($name, $vmMember->object->value, $this->importName($vmMember->object->type), $access, $scope);
			} else if($vmMember->object instanceof AVM2Constant) {
				$member = new AS3ClassConstant($name, $vmMember->object->value, $this->importName($vmMember->object->type), $access, $scope);
			}
			$container->members[] = $member;
			if($vmMember->slotId) {
				$slots[$vmMember->slotId] = $member;
			}
		}
		/*foreach($container->members as $member) {
			if($member instanceof AS3ClassMethod || $member instanceof AS3Function) {
				$cxt = new AS3DecompilerContext;
				$cxt->opQueue = $member->operations;
				unset($member->operations);
				$this->decompileFunctionBody($cxt, $member);
			}
		}*/
	}
	
	protected function decompileClass($vmClass) {
		$class = new AS3Class;
		for($i = 0; $i < 2; $i++) {
			if($i == 0) {
				$vmMembers = $vmClass->members;
				$scope = null;
			} else {
				$vmMembers = $vmClass->instance->members;
				$scope = null;
			}
			foreach($vmMembers as $vmMember) {
				$vmObject = $vmMember->object;
				if($vmObject instanceof AVM2Method) {
					$member = new AS3ClassMethod;
					
				} else if($vmObject instanceof AVM2Variable) {
					$member = new AS3ClassVariable;
					$member->type = $this->importName($vmObject->type);
					$member->value = $vmObject->value;
				} else if($vmObject instanceof AVM2Constant) {
					$member = new AS3ClassConstant;
					$member->type = $this->importName($vmObject->type);
					$member->value = $vmObject->value;
				}
				
				$vmName = $vmMember->name;
				$vmNamespace = $vmName->namespace;
				$member->scope = $scope;
			}
		}
		return $class;
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
						if($stmt instanceof AS3DecompilerFlowControl) {
							if($stmt instanceof AS3DecompilerBranch) {
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
							} else if($stmt instanceof AS3DecompilerJump) {
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
						if($stmt instanceof AS3DecompilerBranch) {
							if($stmt->condition instanceof AS3BinaryOperation && $stmt->condition->operand1 instanceof AS3DecompilerEnumeration) {
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
				$branch->condition = new AS3BinaryOperation($branch->branchIfTrue->condition, '&&', $branch->condition, 12);
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
				$branch->condition = new AS3BinaryOperation($branch->branchIfFalse->condition, '||', $branch->condition, 13);
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
				if($stmt instanceof AS3DecompilerJump) {
					// something should have been pushed onto the stack
					if(count($cxtF->stack) == $stackHeight + 1) {
						$uBranch = $stmt;
						break;
					} else {
						return false;
					}
				} else if($stmt instanceof AS3DecompilerBranch) {
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
				array_push($cxt->stack, new AS3TernaryConditional($branch->condition, $valueT, $valueF, 14));
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
				$method = "do_$op->name";
				$expr = $this->$method($cxt);
				if($expr instanceof AS3Expression) {
					return new AS3BasicStatement($expr);
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
			if($expr instanceof AS3DecompilerBranch) {
				$isEntry[$expr->addressIfTrue] = true;
				$isEntry[$expr->addressIfFalse] = true;
			} else if($expr instanceof AS3DecompilerJump) {
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
				$block = new AS3DecompilerBasicBlock;
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
			if($block->lastStatement instanceof AS3DecompilerBranch) {
				$block->to[] = $block->lastStatement->addressIfTrue;
				$block->to[] = $block->lastStatement->addressIfFalse;
			} else if($block->lastStatement instanceof AS3DecompilerJump) {
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
				if($entryBlock->lastStatement instanceof AS3DecompilerJump) {
					if($condition instanceof AS3BinaryOperation && $condition->operand1 instanceof AS3DecompilerEnumeration) {
						// a for in loop						
						$stmt = new AS3ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS3binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($entryBlock->lastStatement->address == $headerBlock->lastStatement->addressIfTrue) {
							// it goes to the beginning of the loop so it must be a do-while
							$stmt = new AS3DoWhile;
						} else {			
							$stmt = new AS3While;
						}
					}
					$entryBlock->lastStatement = $stmt;
				} else {
					// we just fall into the loop
					if($condition instanceof AS3BinaryOperation && $condition->operand1 instanceof AS3DecompilerEnumeration) {
						// a for in loop						
						$stmt = new AS3ForIn;
						$enumeration = $condition->operand1;
						$condition = new AS3binaryOperation($enumeration->container, 'in', $enumeration->variable, 7);
					} else {
						if($loop->headerAddress == $loop->continueAddress) {
							// the conditional statement is at the "bottom" of the loop so it's a do-while
							$stmt = new AS3DoWhile;
						} else {
							$stmt = new AS3While;
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
		if($block->lastStatement instanceof AS3DecompilerJump && !$block->structured) {
			// if it's a jump to the break address (i.e. to the block right after the while loop) then it's a break 
			// if it's a jump to the continue address (i.e. the tail block containing the backward jump) then it's a continue 
			if($block->lastStatement->address === $breakAddress) {
				$block->lastStatement = new AS3Break;
				$block->structured;
			} else if($block->lastStatement->address === $continueAddress) {
				$block->lastStatement = new AS3Continue;
				$block->structured;
			}
		}
	}
		
	protected function structureIf($block, $blocks) {
		if($block->lastStatement instanceof AS3DecompilerBranch && !$block->structured) {
			$tBlock = $blocks[$block->lastStatement->addressIfTrue];
			$fBlock = $blocks[$block->lastStatement->addressIfFalse];
			
			$if = new AS3IfElse;
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
						if($headerBlock->lastStatement instanceof AS3DecompilerBranch) {
							$loop = new AS3DecompilerLoop;
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
		$loop = new AS3DecompilerLoop;
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
		if($expr instanceof AS3Expression) {
			if($expr instanceof AS3FunctionCall) {
				return true;
			}
			if($expr instanceof AS3Assignment) {
				return true;
			}
		}
		return false;
	}
	
	protected function invertCondition($expr) {
		if($expr instanceof AS3UnaryOperation) {
			if($expr->operator == '!') {
				return $expr->operand;
			}
		} else if($expr instanceof AS3BinaryOperation) {
			if($newOperator = $this->invertBinaryOperator($expr->operator)) {
				$newExpr = clone $expr;
				$newExpr->operator = $newOperator;
				return $newExpr;
			}
		}
		$newExpr = new AS3UnaryOperation('!', $expr, 3);
		return $newExpr;
	}
	
	protected function do_add($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5, 'Number'));
	}
	
	protected function do_add_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5, 'int'));
	}
	
	protected function do_applytype($cxt) {
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
	
	protected function do_astype($cxt) {
		$name = $cxt->op->op1;
		$this->exportNamespace($cxt, $name);
		$name = new AS3Identifier($name->string);
		array_push($cxt->stack, new AS3BinaryOperation($name, 'as', array_pop($cxt->stack), 7, $name));
	}
	
	protected function do_astypelate($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'as', array_pop($cxt->stack), 7, '*'));
	}
	
	protected function do_bitand($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '&', array_pop($cxt->stack), 9, 'int'));
	}
	
	protected function do_bitnot($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('~', array_pop($cxt->stack), 3, 'int'));
	}
	
	protected function do_bitor($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '|', array_pop($cxt->stack), 11, 'int'));
	}
	
	protected function do_bitxor($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '^', array_pop($cxt->stack), 10, 'int'));
	}
	
	protected function do_bkpt($cxt) {
		// do nothing
	}
	
	protected function do_bkptline($cxt) {
		// do nothing
	}
	
	protected function do_call($cxt) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->function->name = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
			
	protected function do_callmethod($cxt) {
		$expr = new AS3MethodCall;
		$expr->args = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->getSlotName($expr->receiver, $op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callproperty($cxt) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->resolveName($cxt, $op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callproplex($cxt) {
		$this->do_callproperty($cxt);
	}
	
	protected function do_callpropvoid($cxt) {
		$this->do_callproperty($cxt);
		return array_pop($cxt->stack);
	}
	
	protected function do_callstatic($cxt) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->getMethodName($op->op1);
		$expr->function->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->function->receiver, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callstaticvoid($cxt) {
		$this->callstatic($cxt);
		return array_pop($cxt->stack);
	}
	
	protected function do_callsuper($cxt) {		
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->function = new AS3PropertyLookup;
		$expr->function->name = $this->resolveName($cxt, $op->op1);
		$object = array_pop($cxt->stack);
		$expr->function->receiver = $this->getParent($object);
		$expr->type = $this->getPropertyType($super, $expr->function->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_callsupervoid($cxt) {
		$this->do_callsuper($cxt);
		return $this->do_pop($cxt);
	}
	
	protected function do_coerce($cxt) {
		$type = $this->resolveName($cxt, $op->op1);
		$this->do_convert_x($cxt, $type);
	}
	
	protected function do_checkfilter($cxt) {
		// nothing happens
	}
	
	protected function do_coerce_a($cxt) {
		$this->do_convert_x($cxt, '*');
	}
	
	protected function do_coerce_s($cxt) {
		$this->do_convert_x($cxt, 'String');
	}
	
	protected function do_construct($cxt) {
		$expr = new AS3ConstructorCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->receiver = array_pop($cxt->stack);
		$expr->name = $expr->receiver->name;
		$expr->type = $expr->receiver->type;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_constructprop($cxt) {
		$expr = new AS3ConstructorCall;
		$expr->arguments = ($op->op2) ? array_splice($cxt->stack, -$op->op2) : array();
		$expr->name = $this->resolveName($cxt, $op->op1);
		$expr->receiver = array_pop($cxt->stack);
		$expr->type = $expr->name;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_constructsuper($cxt) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->function = $this->nameSuper;
		$expr->receiver = array_pop($cxt->stack);
		return $expr;
	}
	
	protected function do_convert_b($cxt) {
		$this->do_convert_x($cxt, 'Boolean');
	}
	
	protected function do_convert_d($cxt) {
		$this->do_convert_x($cxt, 'Number');
	}
	
	protected function do_convert_i($cxt) {
		$this->do_convert_x($cxt, 'int');
	}
	
	protected function do_convert_o($cxt) {
		$this->do_convert_x($cxt, 'Object');
	}
	
	protected function do_convert_s($cxt) {
		$this->do_convert_x($cxt, 'String');
	}
	
	protected function do_convert_u($cxt) {
		$this->do_convert_x($cxt, 'uint');
	}
	
	protected function do_convert_x($cxt, $type) {
		$val = array_pop($cxt->stack);
		if($val instanceof AS3Expression) {
			if($val->type != $type) {
				$val = new AS3UnaryOperation("($type)", $val, 3, $type);
			}
		}
		array_push($cxt->stack, $val);
	}
	
	protected function do_debug($cxt) {
		// do nothing
	}
	
	protected function do_debugfile($cxt) {
		// do nothing
	}
	
	protected function do_debugline($cxt) {
		// do nothing
	}
	
	protected function do_declocal($cxt) {
		return $this->do_unary_op_local($cxt, $op->op1, '--', 3, 'Number');
	}
	
	protected function do_declocal_i($cxt) {
		return $this->do_unary_op_local($cxt, $op->op1, '--', 3, 'Number');
	}
	
	protected function do_decrement($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1.0, '-', array_pop($cxt->stack), 5, 'Number'));
	}
	
	protected function do_decrement_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1, '-', array_pop($cxt->stack), 5, 'int'));
	}
	
	protected function do_deleteproperty($cxt) {
		$name = $cxt->op->op1;
		$name = new AS3Identifier($name->string);
		$object = array_pop($cxt->stack);
		$property = new AS3BinaryOperation($name, '.', $object, 1, '*');
		array_push($cxt->stack, new AS3UnaryOperation('delete', $property, 3, 'Boolean'));
	}
	
	protected function do_divide($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '/', array_pop($cxt->stack), 4, 'Number'));
	}
	
	protected function do_dup($cxt) {
		array_push($cxt->stack, $cxt->stack[count($cxt->stack) - 1]);
	}
	
	protected function do_dxns($cxt) {
		$xmlNS = $this->getString($op->op1);
	}
			
	protected function do_dxnslate($cxt) {
		$xmlNS = array_pop($cxt->stack);
	}
	
	protected function do_equals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8, 'Boolean'));
	}
	
	/* TODO
	protected function do_esc_xattr($cxt) {
	}*/
	
	/* TODO
	protected function do_esc_xelem($cxt) {
	}*/
	
	protected function do_findproperty($cxt) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = $this->searchScopeStack($cxt, $name, false);	
		array_push($cxt->stack, $object);
	}
	
	protected function do_findpropstrict($cxt) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = $this->searchScopeStack($cxt, $name, true);	
		array_push($cxt->stack, $object);
	}
	
	/* TODO
	protected function do_getdescendants($cxt) {
	}*/
	
	protected function do_getglobalscope($cxt) {
		array_push($cxt->stack, $this->global);
	}
	
	protected function do_getglobalslot($cxt) {
		$expr = new AS3PropertyLookUp;
		$expr->receiver = $this->global;
		$expr->name = $this->getSlotName($this->global, $op->op1);
		$expr->type = $this->getPropertyType($this->global, $expr->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getproperty($cxt) {
		$expr = new AS3PropertyLookUp;
		$expr->name = $this->resolveName($cxt, $op->op1);
		$expr->receiver = array_pop($cxt->stack);
		$expr->type = $this->getPropertyType($expr->receiver, $expr->name);
		array_push($cxt->stack, $expr);
	}
	
	protected function do_getlex($cxt) {
		$name = $this->importName($cxt->op->op1);
		array_push($cxt->stack, $name);
	}
	
	protected function do_getlocal($cxt) {
		$register = $op->op1;
		array_push($cxt->stack, $register);
	}
	
	protected function do_getslot($cxt) {
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
	
	protected function do_getscopeobject($cxt) {
		$object = $cxt->scopeStack[$cxt->op->op1];
		array_push($cxt->stack, $object);
	}
	
	protected function do_getsuper($cxt) {
		$name = $this->resolveName($cxt, $op->op1);
		$object = array_pop($cxt->stack);
		$expr = new AS3PropertyLookup;
		$expr->name = $name;
		$expr->receiver = $this->getParent($object);
		$expr->type = $this->getPropertyType($object, $name);
		array_push($cxt->stack, $expr);
	}
		
	protected function do_greaterthan($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_greaterequals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_hasnext($cxt) {
		array_push($cxt->stack, new AS3HasNext(array_pop($cxt->stack), array_pop($cxt->stack)));
	}
	
	protected function do_hasnext2($cxt) {
		array_push($cxt->stack, new AS3HasNext($cxt->op->op2, $cxt->op->op1));
	}
	
	protected function do_ifeq($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8, 'Boolean');
		// the if instruction is 4 bytes long
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_iffalse($cxt) {
		$condition = new AS3UnaryOperation('!', array_pop($cxt->stack), 3, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifge($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifgt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifle($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
		
	protected function do_iflt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifne($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '!=', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifnge($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 8, 'Boolean');
		$condition = new AS3UnaryOperation('!', $condition, 3, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifngt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 8, 'Boolean');
		$condition = new AS3UnaryOperation('!', $condition, 3, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifnle($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 8, 'Boolean');
		$condition = new AS3UnaryOperation('!', $condition, 3, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifnlt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 8, 'Boolean');
		$condition = new AS3UnaryOperation('!', $condition, 3, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_ifstricteq($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '===', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}	
	
	protected function do_ifstrictne($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '!==', array_pop($cxt->stack), 8, 'Boolean');
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_iftrue($cxt) {
		return new AS3DecompilerBranch(array_pop($cxt->stack), $cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_inclocal($cxt) {
		return new AS3UnaryOperation($cxt->op->op1, '++', 3, 'Number');
	}
	
	protected function do_inclocal_i($cxt) {
		return new AS3UnaryOperation($cxt->op->op1, '++', 3, 'int');
	}
	
	protected function do_in($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'in', array_pop($cxt->stack), 7, 'Boolean'));
	}

	protected function do_increment($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1.0, '+', array_pop($cxt->stack), 5, 'Number'));
	}
	
	protected function do_increment_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1, '+', array_pop($cxt->stack), 5, 'int'));
	}
	
	protected function do_initproperty($cxt) {
		return $this->do_setproperty($cxt);
	}
	
	protected function do_instanceof($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'instanceof', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_istype($cxt) {
		$name = $this->importName($cxt, $cxt->op->op1);
		array_push($cxt->stack, new AS3BinaryOperation($name, 'is', array_pop($cxt->stack), 7, 'Boolean'));
	}

	protected function do_istypelate($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'is', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_jump($cxt) {
		return new AS3DecompilerJump($cxt->lastAddress, $cxt->op->op1);
	}
	
	protected function do_kill($cxt) {
		unset($cxt->registerDeclared[$cxt->op->op1->index]);
	}
	
	protected function do_label($cxt) {
		// do nothing
	}
	
	protected function do_lf32($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_lf64($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_li16($cxt) {
		// ignore--Alchemy instruction
	}

	protected function do_li32($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_li8($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_lessequals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_lessthan($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 7, 'Boolean'));
	}
	
	protected function do_lookupswitch($cxt) {
		return new AS3DecompilerSwitch($cxt->lastAddress, $cxt->op->op1, $cxt->op->op3);
	}
	
	protected function do_lshift($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<<', array_pop($cxt->stack), 6, 'int'));
	}
			
	protected function do_modulo($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '%', array_pop($cxt->stack), 4, 'Number'));
	}
			
	protected function do_multiply($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '*', array_pop($cxt->stack), 4, 'Number'));
	}
			
	protected function do_multiply_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '*', array_pop($cxt->stack), 4, 'int'));
	}
	
	protected function do_negate($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('-', array_pop($cxt->stack), 3, 'Number'));
	}
	
	protected function do_negate_i($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('-', array_pop($cxt->stack), 3, 'int'));
	}
	
	protected function do_newarray($cxt) {
		$count = $cxt->op->op1;
		$items = ($count) ? array_splice($cxt->stack, -$count) : array();
		array_push($cxt->stack, new AS3ArrayInitializer($items));
	}
	
	protected function do_newactivation($cxt) {
		$expr = new AS3Scope;
		$expr->type = 'Object';
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newcatch($cxt) {
		$expr = new AS3Scope;
		$expr->type = 'Object';
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newclass($cxt) {
		// TODO
	}
	
	protected function do_newfunction($cxt) {
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
	
	protected function do_newobject($cxt) {
		$count = $cxt->op->op1 * 2;
		$items = ($count) ? array_splice($cxt->stack, -$count) : array();
		array_push($cxt->stack, new AS3ObjectInitializer($items));
	}
	
	protected function do_nextname($cxt) {
		array_push($cxt->stack, new AS3DecompilerNextName(array_pop($cxt->stack), array_pop($cxt->stack)));
	}
	
	protected function do_nextvalue($cxt) {		
		array_push($cxt->stack, new AS3DecompilerNextValue(array_pop($cxt->stack), array_pop($cxt->stack)));
	}
	
	protected function do_nop($cxt) {
	}
	
	protected function do_not($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('!', array_pop($cxt->stack), 3, 'Boolean'));
	}
	
	protected function do_pop($cxt) {
		$expr = array_pop($cxt->stack);
		if(!$cxt->stack) {
			if($this->checkSideEffect($expr)) {
				return $expr;
			}
		}
	}
	
	protected function do_pushbyte($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_popscope($cxt) {
		array_pop($cxt->scopeStack);
	}
	
	protected function do_pushdouble($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}

	protected function do_pushfalse($cxt) {
		array_push($cxt->stack, false);
	}
	
	protected function do_pushint($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_pushnamespace($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
			
	protected function do_pushnan($cxt) {
		array_push($cxt->stack, NAN);
	}
			
	protected function do_pushnull($cxt) {
		array_push($cxt->stack, null);
	}
			
	protected function do_pushscope($cxt) {
		$val = array_pop($cxt->stack);
		array_push($cxt->scopeStack, $val);
	}
	
	protected function do_pushshort($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_pushstring($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_pushtrue($cxt) {
		array_push($cxt->stack, true);
	}
	
	protected function do_pushundefined($cxt) {
		array_push($cxt->stack, AVM2Undefined::$singleton);
	}
	
	protected function do_pushuint($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_pushwith($cxt) {
		$object = array_pop($cxt->stack);
		array_push($cxt->scopeStack, $object);
	}
	
	protected function do_returnvalue($cxt) {
		return new AS3Return(array_pop($cxt->stack));
	}
	
	protected function do_returnvoid($cxt) {
		return new AS3Return(AVM2Undefined::$singleton);
	}
	
	protected function do_rshift($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>>', array_pop($cxt->stack), 6, 'int'));
	}
	
	protected function do_setglobalslot($cxt) {
		// TODO		
	}
	
	protected function do_setlocal($cxt) {
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
	
	protected function do_setproperty($cxt) {
		$val = array_pop($cxt->stack);
		$this->do_getproperty($cxt);
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

	protected function do_setslot($cxt) {
		$val = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if($object instanceof AS3Scope) {
			$object->members[- $op->op1] = $val;
		} else {
			$this->do_getslot($cxt);
			$var = array_pop($cxt->stack);
			return $this->do_set($cxt, $var, $val);
		}
	}

	protected function do_setsuper($cxt) {
	}

	protected function do_sf_32($cxt) {
		// ignore--Alchemy instruction
	}

	protected function do_sf_64($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_16($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_32($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_si_8($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_subtract($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '-', array_pop($cxt->stack), 5, 'Number'));
	}
	
	protected function do_subtract_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '-', array_pop($cxt->stack), 5, 'int'));
	}

	protected function do_strictequals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '===', array_pop($cxt->stack), 8, 'Boolean'));
	}
	
	protected function do_swap($cxt) {
		$val1 = array_pop($cxt->stack);
		$val2 = array_pop($cxt->stack);
		array_push($cxt->stack, $val1);
		array_push($cxt->stack, $val2);
	}
	
	protected function do_sxi_1($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_sxi_16($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_sxi_8($cxt) {
		// ignore--Alchemy instruction
	}
	
	protected function do_throw($cxt) {
		return new AS3Throw(array_pop($cxt->stack));
	}
	
	protected function do_typeof($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('typeof', array_pop($cxt->stack), 3, 'String'));
	}
	
	protected function do_urshift($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>>>', array_pop($cxt->stack), 6, 'uint'));
	}
}

class AS3Expression {
	public $type;
}

class AS3SimpleStatement {
}

class AS3CompoundStatement {
}

class AS3BasicStatement extends AS3SimpleStatement {
	public $expression;
	
	public function __construct($expr) {
		$this->expression = $expr;
	}
}

class AS3Identifier extends AS3Expression {
	public $string;
	
	public function __construct($name) {
		$this->string = $name;
	}
}

class AS3VariableDeclaration extends AS3Expression {
	public $name;
	public $value;
	
	public function __construct($value, $name, $type) {
		if(is_string($name)) {
			$name = new AS3Identifier($name);
		}
		$this->name = $name;
		$this->value = $value;
	}
}

class AS3Operation extends AS3Expression {
	public $precedence;
}

class AS3BinaryOperation extends AS3Operation {
	public $operator;
	public $operand1;
	public $operand2;
	
	public function __construct($operand2, $operator, $operand1, $precedence, $type) {
		$this->operator = $operator;
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->precedence = $precedence;
		$this->type = $type;
	}
}

class AS3Assignment extends AS3BinaryOperation {

	public function __construct($operand2, $operand1, $type) {
		$this->operator = '=';
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->precedence = 15;
		$this->type = $type;
	}
}

class AS3UnaryOperation extends AS3Operation {
	public $operator;
	public $operand;
	
	public function __construct($operator, $operand, $precedence, $type) {
		$this->operator = $operator;
		$this->operand = $operand;
		$this->precedence = $precedence;
		$this->type = $type;
	}
}

class AS3TernaryConditional extends AS3Operation {
	public $condition;
	public $valueIfTrue;
	public $valueIfFalse;
	
	public function __construct($condition, $valueIfTrue, $valueIfFalse, $type) {
		$this->condition = $condition;
		$this->valueIfTrue = $valueIfTrue;
		$this->valueIfFalse = $valueIfFalse;
		$this->precedence = 14;
		$this->type = $type;
	}
}

class AS3ArrayAccess extends AS3Operation {
	public $array;
	public $index;
	
	public function __construct($index, $array, $type) {
		$this->array = $array;
		$this->index = $index;
		$this->precedence = 1;
		$this->type = $type;
	}
}

class AS3FunctionCall extends AS3Expression {
	public $name;
	public $arguments;
	
	public function __construct($object, $name, $arguments, $type) {
		if(is_string($name)) {
			$name = new AS3Identifier($name);
		}
		if($object) {
			$name = new AS3BinaryOperation($name, '.', $object, 1);
		}
		$this->name = $name;
		$this->arguments = $arguments;
		$this->type = $type;
	}
}

class AS3ArrayInitializer extends AS3Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
		$this->type = 'Array';
	}
}

class AS3ObjectInitializer extends AS3Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
		$this->type = 'Object';
	}
}

class AS3Return extends AS3SimpleStatement {
	public $value;
	
	public function __construct($value) {
		$this->value = $value;
	}
}

class AS3Throw extends AS3SimpleStatement {
	public $object;
	
	public function __construct($object) {
		$this->object = $object;
	}
}

class AS3Break extends AS3SimpleStatement {
}

class AS3Continue extends AS3SimpleStatement {
}

class AS3IfElse extends AS3CompoundStatement {
	public $condition;
	public $statementsIfTrue = array();
	public $statementsIfFalse = array();
}

class AS3DoWhile extends AS3CompoundStatement {
	public $condition;
	public $statements = array();
}

class AS3While extends AS3DoWhile {
}

class AS3ForIn extends AS3DoWhile {
}

class AS3TryCatch extends AS3CompoundStatement {
	public $tryStatements;
	public $catchObject;
	public $catchStatements;
	public $finallyStatements;
	
	public function __construct($try, $catch, $finally) {
		$this->tryStatements = $try->statements;
		$this->catchObject = $catch->arguments[0];
		$this->catchStatements = $catch->statements;
		$this->finallyStatements = $finally->statements;
	}
}

class AS3Function extends AS3CompoundStatement {
	public $access;
	public $scope;
	public $name;
	public $arguments = array();
	public $statements = array();
	public $returnType;
	
	public function __construct($name, $arguments, $returnType, $access, $scope) {
		$this->name = $name;
		$this->arguments = $arguments;
		$this->returnType = $returnType;
		$this->access = $access;
		$this->scope = $scope;
	}
}

class AS3Argument extends AS3SimpleStatement {
	public $name;
	public $type;
	public $defaultValue;
	
	public function __construct($name, $type, $defaultValue) {
		$this->name = $name;
		$this->type = $type;
		$this->defaultValue = $defaultValue;
	}
}

class AS3Accessor extends AS3SimpleStatement {
	public $type;
	public $name;
	
	public function __construct($type, $name) {
		$this->type = $type;
		$this->name = $name;
	}
}

class AS3Package extends AS3CompoundStatement {
	public $namespace;
	public $imports = array();
	public $members = array();
}

class AS3Class extends AS3CompoundStatement {
	public $access;
	public $name;
	public $members = array();
	
	public function __construct($name, $access) {
		$this->access = $access;
		$this->name = $name;
	}
}

class AS3ClassMethod extends AS3CompoundStatement {
	public $access;
	public $scope;
	public $name;
	public $arguments;
	public $returnType;
	public $statements;
	
	public function __construct($name, $arguments, $returnType, $access, $scope) {
		$this->name = $name;
		$this->arguments = $arguments;
		$this->returnType = $returnType;
		$this->access = $access;
		$this->scope = $scope;
	}
}

class AS3ClassConstant extends AS3SimpleStatement {
	public $access;
	public $scope;
	public $name;
	public $value;
	public $type;
	
	public function __construct($name, $value, $type, $access, $scope) {
		$this->access = $access;
		$this->scope = $scope;
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}

class AS3ClassVariable extends AS3SimpleStatement {
	public $access;
	public $scope;
	public $name;
	public $value;
	public $type;
	
	public function __construct($name, $value, $type, $access, $scope) {
		$this->access = $access;
		$this->scope = $scope;
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}

// structures used in the decompiling process

class AS3DecompilerContext {
	public $op;
	public $opQueue;
	public $lastAddress = 0;
	public $nextAddress = 0;
	public $stack = array();
	public $registerDeclared = array();
	
	public $relatedBranch;
	public $branchOnTrue;
}

class AS3DecompilerFlowControl {
}

class AS3DecompilerEnumeration {
	public $container;
	public $variable;
	
	public function __construct($container) {
		$this->container = $container;
	}
}

class AS3DecompilerJump extends AS3DecompilerFlowControl {
	public $address;
	
	public function __construct($address, $offset) {
		$this->address = $address + $offset;
	}
}

class AS3DecompilerBranch extends AS3DecompilerFlowControl {
	public $condition;
	public $addressIfTrue;
	public $addressIfFalse;
	
	public function __construct($condition, $address, $offset) {
		$this->condition = $condition;
		$this->addressIfTrue = $address + $offset;
		$this->addressIfFalse = $address;
	}
}

class AS3DecompilerBasicBlock {
	public $statements = array();
	public $lastStatement;
	public $from = array();
	public $to = array();
	public $structured = false;
	public $destination;
	public $prev;
	public $next;
}

class AS3DecompilerLoop {
	public $contentAddresses = array();
	public $headerAddress;
	public $continueAddress;
	public $breakAddress;
	public $entranceAddress;
}

?>