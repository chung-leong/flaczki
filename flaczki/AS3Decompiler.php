<?php

class AS3Decompiler {

	protected $decoder;
	protected $imports;
	protected $nameMap;
	protected $global;
	protected $this;
	
	public function __construct() {
		$this->decoder = new AVM2Decoder;
		$this->global = new AVM2GlobalScope;
	}
	
	public function decompile($abcFile) {
		$scripts = $this->decoder->decode($abcFile);
		
		// add the script members to the global scope
		foreach($scripts as $script) {			
			foreach($script->members as $member) {
				$this->global->members[] = $member;
			}
		}
		
		$packages = array();
		
		foreach($scripts as $script) {
			$packages[] = $this->decompileScript($script);
		}
		return $packages;
	}
	
	protected function importName($vmName) {
		if($vmName instanceof AVM2QName) {
			if(!isset($this->nameMap[$vmName->string])) {
				$this->nameMap[$vmName->string] = $vmName;
				if($vmName->string && $vmName->namespace->string) {
					$qname = "{$vmName->namespace->string}.{$vmName->string}";
					$this->imports[] = new AS3Identifier($qname);
				}
			}
			return new AS3Identifier($vmName->string ? $vmName->string : '*');
		} else if($vmName instanceof AVMGenericName) {
			$name = $this->importName($vmName->name);
			$name = $name->string;
			$types = array();
			foreach($vmName->types as $vmTypeName) {
				$type = $this->importName($vmTypeName);
				$types[] = $type->string;
			}
			$types = implode(', ', $types);
			return new AS3Identifier("{$name}.<$types>");
		}
	}
	
	protected function importArguments($vmArguments) {
		$arguments = array();
		foreach($vmArguments as $vmArgument) {
			$name = new AS3Identifier($vmArgument->name->string);
			$type = $this->importName($vmArgument->type);
			$arguments[] = new AS3Argument($name, $type, $vmArgument->value);
		}
		return $arguments;
	}
	
	protected function resolveName($cxt, $vmName) {
		if($vmName instanceof AVM2QName || $vmName instanceof AVM2Multiname) {
			return new AS3Identifier($vmName->string);
		} else if($vmName instanceof AVM2RTQName || $vmName instanceof AVM2MultinameL) {
			$name = array_pop($cxt->stack);
			return $name;
		} else {
			dump($vmName);
			echo "resolveName";
			exit;
		}
	}
	
	protected function searchScopeStack($cxt, $vmName) {
		for($i = count($cxt->scopeStack) - 1; $i >= 0; $i--) {
			$scopeObject = $vmObject = $cxt->scopeStack[$i];
			if($scopeObject instanceof AVM2Register) {
				if($scopeObject->index == 0) {
					$vmObject = $this->this;
				}
			}
			foreach($vmObject->members as $member) {
				if($member->name == $vmName) {
					return $scopeObject;
				}
			}
		}
		return $this->global;
	}
	
	protected function decompileScript($vmScript) {
		$this->imports = array();
		$this->nameMap = array();
		$package = new AS3Package;
		$this->decompileMembers($vmScript->members, null, $package);
		$package->imports = $this->imports;
		return $package;
	}
	
	protected function decompileMembers($vmMembers, $vmObject, $container) {
		$slots = array();
		$this->this = $vmObject;
		$scope = ($vmObject instanceof AVM2Class) ? 'static' : null;
		foreach($vmMembers as $index => $vmMember) {
			$name = new AS3Identifier($vmMember->name->string);
			$modifiers = array();
			
			// add inherietance modifier
			if($vmMember->flags & AVM2ClassMember::ATTR_FINAL) {
				$modifiers[] = "final";
			}
			if($vmMember->flags & AVM2ClassMember::ATTR_OVERRIDE) {
				$modifiers[] = "override";
			}
			
			// add access modifier
			$vmNamespace = $vmMember->name->namespace;
			if($vmNamespace instanceof AVM2ProtectedNamespace) {
				$modifiers[] = "protected";
			} else if($vmNamespace instanceof AVM2PrivateNamespace) {
				$modifiers[] = "private";
			} else if($vmNamespace instanceof AVM2PackageNamespace) {
				$modifiers[] = "public";
				if($container instanceof AS3Package) {
					$container->namespace = ($vmNamespace->string) ? new AS3Identifier($vmNamespace->string) : null;
				}
			} else if($vmNamespace instanceof AVM2InternalNamespace) {
				$modifiers[] = "internal";
			}
			
			// add scope modifier
			if($scope) {
				$modifiers[] = $scope;
			}
			
			if($vmMember->object instanceof AVM2Class) {
				if(!($vmMember->object->instance->flags & AVM2ClassInstance::ATTR_SEALED)) {
					$modifiers[] = "dynamic";
				}			
				$member = ($vmMember->object->instance->flags & AVM2ClassInstance::ATTR_INTERFACE) ? new AS3Interface($name, $modifiers) : new AS3Class($name, $modifiers);
				$this->decompileMembers($vmMember->object->members, $vmMember->object, $member);
				$this->decompileMembers($vmMember->object->instance->members, $vmMember->object->instance, $member);
			} else if($vmMember->object instanceof AVM2Method) {
				$returnType = $this->importName($vmMember->object->returnType);
				$arguments = $this->importArguments($vmMember->object->arguments);
				if($vmMember->type == AVM2ClassMember::TYPE_GETTER) {
					$name = new AS3Accessor('get', $name);
				} else if($vmMember->type == AVM2ClassMember::TYPE_SETTER) {
					$name = new AS3Accessor('set', $name);
				}
				if($container instanceof AS3Class) {
					$member = new AS3ClassMethod($name, $arguments, $returnType, $modifiers);
				} else {
					$member = new AS3Function($name, $arguments, $returnType, $modifiers);
				}
				if($vmMember->object->body) {
					$cxt = new AS3DecompilerContext;
					$cxt->opQueue = $vmMember->object->body->operations;
					
					// set register types of arguments
					$registerTypes = array();
					foreach($arguments as $index => $argument) {
						$registerTypes[$index + 1] = $argument->type;
					}
					$cxt->registerTypes = $registerTypes;
					$this->decompileFunctionBody($cxt, $member);
				}
			} else if($vmMember->object instanceof AVM2Variable) {
				$member = new AS3ClassVariable($name, $vmMember->object->value, $this->importName($vmMember->object->type), $modifiers);
			} else if($vmMember->object instanceof AVM2Constant) {
				$member = new AS3ClassConstant($name, $vmMember->object->value, $this->importName($vmMember->object->type), $modifiers);
			}
			$container->members[] = $member;
			if($vmMember->slotId) {
				$slots[$vmMember->slotId] = $member;
			}
		}
	}
	
	protected function decompileFunctionBody($cxt, $function) {
		// decompile the ops into statements
		$statements = array();	
		$contexts = array($cxt);
		$cxt->opAddresses = array_keys($cxt->opQueue);
		$cxt->addressIndex = 0;
		$cxt->nextAddress = $cxt->opAddresses[0];
		while($contexts) {
			$cxt = array_shift($contexts);
			$show = false;
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
			$scanned = array();
			$hasSubbranches = false;
			$callerCxt = $cxt;
			while($contexts) {
				$cxt = array_shift($contexts);
				$scanned[$cxt->nextAddress] = true;
				while(($stmt = $this->decompileNextInstruction($cxt)) !== false) {
					if($stmt) {
						if($stmt instanceof AS3DecompilerBranch) {
							if($cxt->branchOnTrue) {
								$cxt->relatedBranch->branchIfTrue = $stmt;
							} else {
								$cxt->relatedBranch->branchIfFalse = $stmt;
							}
							
							if(!isset($scanned[$stmt->addressIfTrue]) || !isset($scanned[$stmt->addressIfFalse])) {
								$cxtT = clone $cxt;
								$cxtT->nextAddress = $stmt->addressIfTrue;
								$cxtT->relatedBranch = $stmt;
								$cxtT->branchOnTrue = true;
								$contexts[] = $cxtT;
								$cxt->nextAddress = $stmt->addressIfFalse;
								$cxt->relatedBranch = $stmt;
								$cxt->branchOnTrue = false;
								$hasSubbranches = true;
							} else {
								break;
							}
						} else {
							break;
						}
					}
				}
			}
			
			if($hasSubbranches) {
				// collapsed branches into AND/OR statements
				// first, get rid of duplicates to simplify the logic
				$this->collapseDuplicateBranches($branch);
				
				// keep reducing until there are no more changes
				do {
					$changed = $this->collapseBranches($branch);
				} while($changed);
				//unset($branch->branchIfTrue, $branch->branchIfFalse);

				// update the instruction pointer and clear the stack
				$callerCxt->nextAddress = $branch->addressIfFalse;
				while($callerCxt->stack) {
					array_pop($callerCxt->stack);
				}
			}
		}
	}
	
	protected function compareConditions($condition1, $condition2) {
		$inverted = false;
		while($condition1 instanceof AS3Negation) {
			$condition1 = $condition1->operand;
			$inverted = !$inverted;
		}
		while($condition2 instanceof AS3Negation) {
			$condition2 = $condition2->operand;
			$inverted = !$inverted;
		}
		if($condition1 === $condition2) {
			return ($inverted) ? -1 : 1;
		}
		return 0;
	}
	
	protected function collapseDuplicateBranches($branch) {
		if(isset($branch->branchIfTrue)) {
			$this->collapseDuplicateBranches($branch->branchIfTrue);
			if($relation = $this->compareConditions($branch->condition, $branch->branchIfTrue->condition)) {
				if($relation == 1) {
					$branch->addressIfTrue = $branch->branchIfTrue->addressIfTrue;
					if(isset($branch->branchIfTrue->branchIfTrue)) {
						$branch->branchIfTrue = $branch->branchIfTrue->branchIfTrue;
					} else {
						unset($branch->branchIfTrue);
					}
				} else {
					$branch->addressIfTrue = $branch->branchIfTrue->addressIfFalse;
					if(isset($branch->branchIfTrue->branchIfFalse)) {
						$branch->branchIfTrue = $branch->branchIfTrue->branchIfFalse;
					} else {
						unset($branch->branchIfTrue);
					}
				}
			}
		}
		if(isset($branch->branchIfFalse)) {
			$this->collapseDuplicateBranches($branch->branchIfFalse);
			if($relation = $this->compareConditions($branch->condition, $branch->branchIfFalse->condition)) {
				if($relation == 1) {
					$branch->addressIfFalse = $branch->branchIfFalse->addressIfFalse;
					if(isset($branch->branchIfFalse->branchIfFalse)) {
						$branch->branchIfFalse = $branch->branchIfFalse->branchIfFalse;
					} else {
						unset($branch->branchIfFalse);
					}
				} else {
					$branch->addressIfFalse = $branch->branchIfFalse->addressIfTrue;
					if(isset($branch->branchIfFalse->branchIfTrue)) {
						$branch->branchIfFalse = $branch->branchIfFalse->branchIfTrue;
					} else {
						unset($branch->branchIfFalse);
					}
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
			} else if($branch->branchIfTrue->addressIfTrue == $branch->addressIfFalse) {
				$branch->condition = new AS3BinaryOperation($this->invertCondition($branch->branchIfTrue->condition), '&&', $branch->condition, 12);
				$branch->addressIfTrue = $branch->branchIfTrue->addressIfFalse;
				if(isset($branch->branchIfTrue->branchIfFalse)) {
					$branch->branchIfTrue = $branch->branchIfTrue->branchIfFalse;
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
			} else if($branch->branchIfFalse->addressIfFalse == $branch->addressIfTrue) {
				$branch->condition = new AS3BinaryOperation($this->invertCondition($branch->branchIfFalse->condition), '||', $branch->condition, 13);
				$branch->addressIfFalse = $branch->branchIfFalse->addressIfTrue;
				if(isset($branch->branchIfFalse->branchIfTrue)) {
					$branch->branchIfFalse = $branch->branchIfFalse->branchIfTrue;
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
		if($cxt->nextAddress !== null) {
			if($cxt->opAddresses[$cxt->addressIndex] != $cxt->nextAddress) {
				$cxt->addressIndex = array_search($cxt->nextAddress, $cxt->opAddresses, true);
			}
			if($cxt->addressIndex !== false) {
				$op = $cxt->opQueue[$cxt->nextAddress];
				$cxt->lastAddress = $cxt->nextAddress;
				$cxt->addressIndex++;
				$cxt->nextAddress = (isset($cxt->opAddresses[$cxt->addressIndex])) ? $cxt->opAddresses[$cxt->addressIndex] : null;
				$cxt->op = $op;
				$method = "do_$op->name";
				$expr = $this->$method($cxt);
				if($expr instanceof AS3Expression) {
					return new AS3BasicStatement($expr);
				} else {
					return $expr;
				}
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
				
				$entryBlock = $blocks[$loop->entranceAddress];
				if($condition instanceof AS3DecompilerHasNext) {
					//  a for-in or for-each loop
					$stmt = new AS3ForIn;
					
					// first, look for the assignments to temporary variable in the block before
					$condition = $headerBlock->lastStatement->condition;
					$loopIndex = $loopObject = null;
					for($setStmt = end($entryBlock->statements); $setStmt && !($loopIndex && $loopObject); $setStmt = prev($entryBlock->statements)) {
						if($setStmt instanceof AS3BasicStatement) {
							if($setStmt->expression instanceof AS3Assignment) { 
								if($setStmt->expression->operand1 === $condition->index) {
									$loopIndex = $setStmt->expression->operand2;
									unset($entryBlock->statements[key($entryBlock->statements)]);
								} else if($setStmt->expression->operand1 === $condition->object) {
									$loopObject = $setStmt->expression->operand2;
									unset($entryBlock->statements[key($entryBlock->statements)]);
								}
							} else if($setStmt->expression instanceof AS3VariableDeclaration) {
								if($setStmt->expression->name === $condition->index) {
									$loopIndex = $setStmt->expression->value;
									unset($entryBlock->statements[key($entryBlock->statements)]);
								} else if($setStmt->expression->name === $condition->object) {
									$loopObject = $setStmt->expression->value;
									unset($entryBlock->statements[key($entryBlock->statements)]);
								}
							}
						}
					}
						
					// look for assignment to named variable 
					$firstBlock = $blocks[$headerBlock->lastStatement->addressIfTrue];
					$loopVar = $loopValue = null;
					for($setStmt = reset($firstBlock->statements); $setStmt && !$loopVar; $setStmt = next($firstBlock->statements)) {
						if($setStmt instanceof AS3BasicStatement) {
							if($setStmt->expression instanceof AS3Assignment) {
								$loopVar = $setStmt->expression->operand1;
								$loopValue = $setStmt->expression->operand2;
								unset($firstBlock->statements[key($firstBlock->statements)]);
							} else if($setStmt->expression instanceof AS3VariableDeclaration) {
								$loopVar = $setStmt->expression->name;
								$loopValue = $setStmt->expression->value;
								unset($firstBlock->statements[key($firstBlock->statements)]);
							}
						}
					}
					if($loopValue instanceof AS3TypeCoercion) {
						$loopValue = $loopValue->value;
					}
					$condition = new AS3BinaryOperation($loopObject, 'in', $loopVar, 7);
					if($loopValue instanceof AS3DecompilerNextValue) {
						$stmt = new AS3ForEach;
					} else {
						$stmt = new AS3ForIn;
					}
				} else {
					// it's a do-while if the conditional statement is at the "bottom" of the loop
					if($loop->headerAddress == $loop->continueAddress + 1) {
						$stmt = new AS3DoWhile;
					} else {
						$stmt = new AS3While;
					}
				}
				$stmt->condition = $condition;
				
				// see if there's an unconditional branch into the loop
				if($entryBlock->lastStatement instanceof AS3DecompilerJump) {
					// replace the jump
					$entryBlock->lastStatement = $stmt;
				} else {
					// we just fall into the loop--put the statement at the end
					$entryBlock->statements[] = $stmt;
				}
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
			
			if($block->lastStatement->addressIfTrue > $block->lastStatement->addressIfFalse) {
				// the false block contains the statements inside the if (the conditional jump is there to skip over it)
				// the condition for the if statement is thus the invert
				$condition = $this->invertCondition($block->lastStatement->condition);
				$ifBlock = $fBlock;
				$elseBlock = $tBlock;
			} else {
				$condition = $block->lastStatement->condition;
				$ifBlock = $tBlock;
				$elseBlock = $fBlock;
			}
			
			$if = new AS3IfElse;
			$if->condition = $condition;
			$ifBlock->destination =& $if->statementsIfTrue;
			
			// if there's no other way to enter the other block, then its statements must be in an else block
			if(count($elseBlock->from) == 1) {
				$elseBlock->destination =& $if->statementsIfFalse;
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
	
	protected function invertCondition($expr) {
		if($expr instanceof AS3Negation) {
			return $expr->operand;
		} else if($expr instanceof AS3BinaryOperation) {
			if($newOperator = $this->invertBinaryOperator($expr->operator)) {
				$newExpr = clone $expr;
				$newExpr->operator = $newOperator;
				return $newExpr;
			} else if($expr->operator == '||') {
				if($expr->operand1 instanceof AS3Negation && $expr->operand2 instanceof AS3Negation) {
					return new AS3BinaryOperation($expr->operand2->operand, '&&', $expr->operand1->operand, 12);
				}
			} else if($expr->operator == '&&') {
				if($expr->operand1 instanceof AS3Negation && $expr->operand2 instanceof AS3Negation) {
					return new AS3BinaryOperation($expr->operand2->operand, '||', $expr->operand1->operand, 13);
				}
			}
		}
		$newExpr = new AS3Negation($expr);
		return $newExpr;
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
	
	protected function do_add($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5));
	}
	
	protected function do_add_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '+', array_pop($cxt->stack), 5));
	}
	
	protected function do_applytype($cxt) {
		$types = array();
		$count = $cxt->op->op1;		
		for($i = 0; $i < $count; $i++) {
			$type = array_pop($cxt->stack);
			$types[] = $type->string;
		}
		$types = implode(', ', $types);
		$baseType = array_pop($cxt->stack);
		$baseType = $baseType->string;
		array_push($cxt->stack, new AS3Identifier("$baseType.<$types>"));
	}
	
	protected function do_astype($cxt) {
		$name = $this->importName($cxt->op->op1);
		array_push($cxt->stack, new AS3BinaryOperation($name, 'as', array_pop($cxt->stack), 7));
	}
	
	protected function do_astypelate($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'as', array_pop($cxt->stack), 7));
	}
	
	protected function do_bitand($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '&', array_pop($cxt->stack), 9));
	}
	
	protected function do_bitnot($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('~', array_pop($cxt->stack), 3));
	}
	
	protected function do_bitor($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '|', array_pop($cxt->stack), 11));
	}
	
	protected function do_bitxor($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '^', array_pop($cxt->stack), 10));
	}
	
	protected function do_bkpt($cxt) {
		// do nothing
	}
	
	protected function do_bkptline($cxt) {
		// do nothing
	}
	
	protected function do_call($cxt) {
		$argumentCount = $cxt->op->op1;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$object = array_pop($cxt->stack);
		$name = array_pop($cxt->stack);
		array_push($cxt->stack, new AS3FunctionCall($object, $name, $arguments));
	}
			
	protected function do_callmethod($cxt) {
		$argumentCount = $cxt->op->op2;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$object = array_pop($cxt->stack);
		$name = $this->getSlotName($object, $cxt->op->op1);
		array_push($cxt->stack, new AS3FunctionCall($object, $name, $arguments));

	}
	
	protected function do_callproperty($cxt) {
		$argumentCount = $cxt->op->op2;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);		
		array_push($cxt->stack, new AS3FunctionCall($object, $name, $arguments));
	}
	
	protected function do_callproplex($cxt) {
		$this->do_callproperty($cxt);
	}
	
	protected function do_callpropvoid($cxt) {
		$this->do_callproperty($cxt);
		return array_pop($cxt->stack);
	}
	
	protected function do_callstatic($cxt) {
		$argumentCount = $cxt->op->op2;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$name = $this->getMethodName($op->op1);
		$object = array_pop($cxt->stack);
		array_push($cxt->stack, new AS3FunctionCall($object, $name, $arguments));
	}
	
	protected function do_callstaticvoid($cxt) {
		$this->callstatic($cxt);
		return array_pop($cxt->stack);
	}
	
	protected function do_callsuper($cxt) {		
		$argumentCount = $cxt->op->op2;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		$object = new AS3Identifier('super');
		array_push($cxt->stack, new AS3FunctionCall($object, $name, $arguments));
	}
	
	protected function do_callsupervoid($cxt) {
		$this->do_callsuper($cxt);
		return $this->do_pop($cxt);
	}
	
	protected function do_coerce($cxt) {
		$type = $this->importName($cxt->op->op1);
		array_push($cxt->stack, new AS3TypeCoercion($type, array_pop($cxt->stack))); 
	}
	
	protected function do_checkfilter($cxt) {
		// nothing happens
	}
	
	protected function do_coerce_a($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == '*') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('*', $value)); 
		}
	}
	
	protected function do_coerce_s($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'String') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('String', $value)); 
		}
	}
	
	protected function do_construct($cxt) {
		$argumentCount = $cxt->op->op1;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$object = array_pop($cxt->stack);
		$constructor = new AS3FunctionCall(null, $object, $arguments);
		array_push($cxt->stack, new AS3UnaryOperation('new', $constructor, 1));
	}
	
	protected function do_constructprop($cxt) {		
		$argumentCount = $cxt->op->op2;
		$arguments = ($argumentCount > 0) ? array_splice($cxt->stack, -$argumentCount) : array();
		$name = $this->importName($cxt->op->op1);
		$object = array_pop($cxt->stack);
		if($object instanceof AVM1GlobalScope) {
			// don't need to reference global
			$object = null;
		}
		$constructor = new AS3FunctionCall($object, $name, $arguments);
		array_push($cxt->stack, new AS3UnaryOperation('new', $constructor, 1));
	}
	
	protected function do_constructsuper($cxt) {
		$expr = new AS3MethodCall;
		$expr->arguments = ($op->op1) ? array_splice($cxt->stack, -$op->op1) : array();
		$expr->function = $this->nameSuper;
		$expr->receiver = array_pop($cxt->stack);
		return $expr;
	}
	
	protected function do_convert_b($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'Boolean') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('Boolean', $value)); 
		}
	}
	
	protected function do_convert_d($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'Number') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('Number', $value)); 
		}
	}
	
	protected function do_convert_i($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'int') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('int', $value)); 
		}
	}
	
	protected function do_convert_o($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'Object') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('Object', $value)); 
		}
	}
	
	protected function do_convert_s($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'String') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('String', $value)); 
		}
	}
	
	protected function do_convert_u($cxt) {
		$value = array_pop($cxt->stack);
		if($value instanceof AS3TypeCoercion && $value->type->string == 'uint') {
			array_push($cxt->stack, $value);
		} else {
			array_push($cxt->stack, new AS3TypeCoercion('uint', $value)); 
		}
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
		return new AS3UnaryOperation('--', $cxt->op->op1, 3);
	}
	
	protected function do_declocal_i($cxt) {
		return new AS3UnaryOperation('--', $cxt->op->op1, 3);
	}
	
	protected function do_decrement($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1.0, '-', array_pop($cxt->stack), 5));
	}
	
	protected function do_decrement_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1, '-', array_pop($cxt->stack), 5));
	}
	
	protected function do_deleteproperty($cxt) {
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		if(!($object instanceof AVM2GlobalScope || $object instanceof AVM2ActivationObject)) {
			$property = new AS3BinaryOperation($name, '.', $object, 1);
		} else {
			$property = $name;
		}
		array_push($cxt->stack, new AS3UnaryOperation('delete', $property, 3));
	}
	
	protected function do_divide($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '/', array_pop($cxt->stack), 4));
	}
	
	protected function do_dup($cxt) {
		array_push($cxt->stack, $cxt->stack[count($cxt->stack) - 1]);
	}
	
	protected function do_dxns($cxt) {
		$xmlNS = $cxt->op->op1;
	}
			
	protected function do_dxnslate($cxt) {
		$xmlNS = array_pop($cxt->stack);
	}
	
	protected function do_equals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8));
	}
	
	/* TODO
	protected function do_esc_xattr($cxt) {
	}*/
	
	/* TODO
	protected function do_esc_xelem($cxt) {
	}*/
	
	protected function do_findproperty($cxt) {
		$object = $this->searchScopeStack($cxt, $cxt->op->op1);
		array_push($cxt->stack, $object);
	}
	
	protected function do_findpropstrict($cxt) {
		$object = $this->searchScopeStack($cxt, $cxt->op->op1);
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
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		if(!($object instanceof AVM2GlobalScope || $object instanceof AVM2ActivationObject)) {
			if($name instanceof AS3Identifier) {
				array_push($cxt->stack, new AS3BinaryOperation($name, '.', $object, 1));
			} else {
				array_push($cxt->stack, new AS3ArrayAccess($name, $object));
			}
		} else {
			array_push($cxt->stack, $name);
		}
	}
	
	protected function do_getlex($cxt) {
		$name = $this->resolveName($cxt, $cxt->op->op1);
		array_push($cxt->stack, $name);
	}
	
	protected function do_getlocal($cxt) {
		array_push($cxt->stack, $cxt->op->op1);
	}
	
	protected function do_getslot($cxt) {
		$object = array_pop($cxt->stack);
		if($object instanceof AVM2ActivionObject) {
			array_push($cxt->stack, $object->slots[$cxt->op->op1]);
		} else {
			dump($object);
			echo "do_getslot";
			exit;
		}
	}
	
	protected function do_getscopeobject($cxt) {
		$object = $cxt->scopeStack[$cxt->op->op1];
		array_push($cxt->stack, $object);
	}
	
	protected function do_getsuper($cxt) {
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		$object = new AS3Identifier("super");
		if($name instanceof AS3Identifier) {
			array_push($cxt->stack, new AS3BinaryOperation($name, '.', $object, 1));
		} else {
			array_push($cxt->stack, new AS3ArrayAccess($name, $object));
		}
	}
		
	protected function do_greaterthan($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 7));
	}
	
	protected function do_greaterequals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 7));
	}
	
	protected function do_hasnext($cxt) {
		array_push($cxt->stack, new AS3DecompilerHasNext(array_pop($cxt->stack), array_pop($cxt->stack)));
	}
	
	protected function do_hasnext2($cxt) {
		array_push($cxt->stack, new AS3DecompilerHasNext($cxt->op->op2, $cxt->op->op1));
	}
	
	protected function do_ifeq($cxt) {
		// the if instruction is 4 bytes long
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '==', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_iffalse($cxt) {
		$condition = array_pop($cxt->stack);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, false);
	}
	
	protected function do_ifge($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_ifgt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_ifle($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
		
	protected function do_iflt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_ifne($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '!=', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_ifnge($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>=', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, false);
	}
	
	protected function do_ifngt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '>', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, false);
	}
	
	protected function do_ifnle($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, false);
	}
	
	protected function do_ifnlt($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, false);
	}
	
	protected function do_ifstricteq($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '===', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}	
	
	protected function do_ifstrictne($cxt) {
		$condition = new AS3BinaryOperation(array_pop($cxt->stack), '!==', array_pop($cxt->stack), 8);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_iftrue($cxt) {
		$condition = array_pop($cxt->stack);
		return new AS3DecompilerBranch($condition, $cxt->lastAddress + 4, $cxt->op->op1, true);
	}
	
	protected function do_inclocal($cxt) {
		return new AS3UnaryOperation('++', $cxt->op->op1, 3);
	}
	
	protected function do_inclocal_i($cxt) {
		return new AS3UnaryOperation('++', $cxt->op->op1, 3);
	}
	
	protected function do_in($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'in', array_pop($cxt->stack), 7));
	}

	protected function do_increment($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1.0, '+', array_pop($cxt->stack), 5));
	}
	
	protected function do_increment_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(1, '+', array_pop($cxt->stack), 5));
	}
	
	protected function do_initproperty($cxt) {
		return $this->do_setproperty($cxt);
	}
	
	protected function do_instanceof($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'instanceof', array_pop($cxt->stack), 7));
	}
	
	protected function do_istype($cxt) {
		$name = $this->importName($cxt, $cxt->op->op1);
		array_push($cxt->stack, new AS3BinaryOperation($name, 'is', array_pop($cxt->stack), 7));
	}

	protected function do_istypelate($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), 'is', array_pop($cxt->stack), 7));
	}
	
	protected function do_jump($cxt) {
		// the jump instruction is 4 bytes long
		return new AS3DecompilerJump($cxt->lastAddress + 4, $cxt->op->op1);
	}
	
	protected function do_kill($cxt) {
		unset($cxt->registerTypes[$cxt->op->op1->index]);
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
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<=', array_pop($cxt->stack), 7));
	}
	
	protected function do_lessthan($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<', array_pop($cxt->stack), 7));
	}
	
	protected function do_lookupswitch($cxt) {
		return new AS3DecompilerSwitch($cxt->lastAddress, $cxt->op->op1, $cxt->op->op3);
	}
	
	protected function do_lshift($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '<<', array_pop($cxt->stack), 6));
	}
			
	protected function do_modulo($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '%', array_pop($cxt->stack), 4));
	}
			
	protected function do_multiply($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '*', array_pop($cxt->stack), 4));
	}
			
	protected function do_multiply_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '*', array_pop($cxt->stack), 4));
	}
	
	protected function do_negate($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('-', array_pop($cxt->stack), 3));
	}
	
	protected function do_negate_i($cxt) {
		array_push($cxt->stack, new AS3UnaryOperation('-', array_pop($cxt->stack), 3));
	}
	
	protected function do_newarray($cxt) {
		$count = $cxt->op->op1;
		$items = ($count) ? array_splice($cxt->stack, -$count) : array();
		array_push($cxt->stack, new AS3ArrayInitializer($items));
	}
	
	protected function do_newactivation($cxt) {
		$expr = new AVM2ActivionObject;
		array_push($cxt->stack, $expr);
	}
	
	protected function do_newcatch($cxt) {
		$expr = new AVM2ActivionObject;
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
		$value = array_pop($cxt->stack);
		if($value instanceof AS3Negation) {
			array_push($cxt->stack, $value->operand);
		} else {
			array_push($cxt->stack, new AS3Negation($value));
		}
	}
	
	protected function do_pop($cxt) {
		$expr = array_pop($cxt->stack);
		if($this->checkSideEffect($expr)) {
			return $expr;
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
		array_push($cxt->scopeStack, array_pop($cxt->stack));
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
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>>', array_pop($cxt->stack), 6));
	}
	
	/*protected function do_setglobalslot($cxt) {
		// TODO		
	}*/
	
	protected function do_setlocal($cxt) {
		$value = array_pop($cxt->stack);
		if(!($value instanceof AVM2ActivionObject || $value instanceof AVM2GlobalScope)) {
			$var = $cxt->op->op1;
			$type = ($value instanceof AS3TypeCoercion) ? $value->type : '*';
			if(isset($cxt->registerTypes[$var->index])) {
				return new AS3Assignment($value, $var);
			} else {
				$cxt->registerTypes[$var->index] = $type;
				return new AS3VariableDeclaration($value, $var, $type);
			}
		}
	}
	
	protected function do_setproperty($cxt) {
		$value = array_pop($cxt->stack);
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		if(!($object instanceof AVM2GlobalScope || $object instanceof AVM2ActivationObject)) {
			if($name instanceof AS3Identifier) {
				$var = new AS3BinaryOperation($name, '.', $object, 1);
			} else {
				$var = new AS3ArrayAccess($name, $object);
			}
		} else {
			$var = $name;
		}
		return new AS3Assignment($value, $var);
	}
	
	protected function do_setslot($cxt) {
		$slotId = $cxt->op->op1;
		$value = array_pop($cxt->stack);
		$object = array_pop($cxt->stack);
		if($object instanceof AVM2ActivionObject) {
			if(isset($object->slots[$slotId])) {
				$var = $object->slots[$slotId];
				return new AS3Assignment($value, $var);
			} else {
				$var = new AVM2Register;
				$var->name = "SLOT$slotId";
				$object->slots[$slotId] = $var;
				$type = ($value instanceof AS3TypeCoercion) ? $value->type : '*';
				return new AS3VariableDeclaration($value, $var, $type);
			}
		} else {
			//echo "do_setslot";
			//exit;
		}
	}

	protected function do_setsuper($cxt) {
		$value = array_pop($cxt->stack);
		$name = $this->resolveName($cxt, $cxt->op->op1);
		$object = array_pop($cxt->stack);
		$object = new AS3Identifier("super");
		if(!($object instanceof AVM2GlobalScope || $object instanceof AVM2ActivationObject)) {
			if($name instanceof AS3Identifier) {
				$var = new AS3BinaryOperation($name, '.', $object, 1);
			} else {
				$var = new AS3ArrayAccess($name, $object);
			}
		} else {
			$var = $name;
		}
		return new AS3Assignment($value, $var);
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
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '-', array_pop($cxt->stack), 5));
	}
	
	protected function do_subtract_i($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '-', array_pop($cxt->stack), 5));
	}

	protected function do_strictequals($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '===', array_pop($cxt->stack), 8));
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
		array_push($cxt->stack, new AS3UnaryOperation('typeof', array_pop($cxt->stack), 3));
	}
	
	protected function do_urshift($cxt) {
		array_push($cxt->stack, new AS3BinaryOperation(array_pop($cxt->stack), '>>>', array_pop($cxt->stack), 6));
	}
}

class AS3Expression {
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

class AS3TypeCoercion extends AS3Expression {
	public $value;
	public $type;
	
	public function __construct($type, $value) {
		if(is_string($type)) {
			$type = new AS3Identifier($type);
		}		
		$this->value = $value;
		$this->type = $type;
	}
}

class AS3VariableDeclaration extends AS3Expression {
	public $name;
	public $value;
	public $type;
	
	public function __construct($value, $name, $type) {
		if(is_string($type)) {
			$type = new AS3Identifier($type);
		}		
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
	}
}

class AS3Operation extends AS3Expression {
	public $precedence;
}

class AS3BinaryOperation extends AS3Operation {
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

class AS3Assignment extends AS3BinaryOperation {

	public function __construct($operand2, $operand1) {
		$this->operator = '=';
		$this->operand1 = $operand1;
		$this->operand2 = $operand2;
		$this->precedence = 15;
	}
}

class AS3UnaryOperation extends AS3Operation {
	public $operator;
	public $operand;
	
	public function __construct($operator, $operand, $precedence) {
		$this->operator = $operator;
		$this->operand = $operand;
		$this->precedence = $precedence;
	}
}

class AS3Negation extends AS3UnaryOperation {

	public function __construct($operand) {
		$this->operator = '!';
		$this->operand = $operand;
		$this->precedence = 3;
	}
}

class AS3TernaryConditional extends AS3Operation {
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

class AS3ArrayAccess extends AS3Operation {
	public $array;
	public $index;
	
	public function __construct($index, $array) {
		$this->array = $array;
		$this->index = $index;
		$this->precedence = 1;
	}
}

class AS3FunctionCall extends AS3Expression {
	public $name;
	public $arguments;
	
	public function __construct($object, $name, $arguments) {
		if(is_string($name)) {
			$name = new AS3Identifier($name);
		}
		if($object) {
			if(!($object instanceof AVM2GlobalScope)) {
				$name = new AS3BinaryOperation($name, '.', $object, 1, '*');
			}
		}
		$this->name = $name;
		$this->arguments = $arguments;
	}
}

class AS3ArrayInitializer extends AS3Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
	}
}

class AS3ObjectInitializer extends AS3Expression {
	public $items = array();
	
	public function __construct($items) {
		$this->items = $items;
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

class AS3While extends AS3CompoundStatement {
	public $condition;
	public $statements = array();
}

class AS3ForIn extends AS3CompoundStatement {
	public $condition;
	public $statements = array();
}

class AS3ForEach extends AS3CompoundStatement {
	public $condition;
	public $statements = array();
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
	public $arguments;
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
	public $modifiers;
	public $name;
	public $members = array();
	public $interfaces = array();
	
	public function __construct($name, $modifiers) {
		$this->modifiers = $modifiers;
		$this->name = $name;
	}
}

class AS3Interface extends AS3Class {
}

class AS3ClassMethod extends AS3CompoundStatement {
	public $modifiers;
	public $name;
	public $arguments;
	public $returnType;
	public $statements = array();
	
	public function __construct($name, $arguments, $returnType, $modifiers) {
		$this->name = $name;
		$this->arguments = $arguments;
		$this->returnType = $returnType;
		$this->modifiers = $modifiers;
	}
}

class AS3ClassConstant extends AS3SimpleStatement {
	public $modifiers;
	public $name;
	public $value;
	public $type;
	
	public function __construct($name, $value, $type, $modifiers) {
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
		$this->modifiers = $modifiers;
	}
}

class AS3ClassVariable extends AS3SimpleStatement {
	public $modifiers;
	public $name;
	public $value;
	public $type;
	
	public function __construct($name, $value, $type, $modifiers) {
		$this->name = $name;
		$this->value = $value;
		$this->type = $type;
		$this->modifiers = $modifiers;
	}
}

// structures used in the decompiling process

class AS3DecompilerContext {
	public $op;
	public $opQueue;
	public $opAddresses;
	public $addressIndex;
	public $lastAddress = 0;
	public $nextAddress = 0;
	public $stack = array();
	public $scopeStack = array();
	public $registerTypes = array();
	
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
	
	public function __construct($condition, $address, $offset, $branchOn) {
		if($branchOn == false) {
			if($condition instanceof AS3Negation) {
				$this->condition = $condition->operand;
			} else {
				$this->condition = new AS3Negation($condition);
			}
		} else {
			$this->condition = $condition;
		}
		$this->addressIfTrue = $address + $offset;
		$this->addressIfFalse = $address;
	}
}

class AS3DecompilerSwitch extends AS3DecompilerFlowControl {
}

class AS3DecompilerHasNext {
	public $object;
	public $index;

	public function __construct($index, $object) {
		$this->object = $object;
		$this->index = $index;
	}
}

class AS3DecompilerNextName {
}

class AS3DecompilerNextValue {
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