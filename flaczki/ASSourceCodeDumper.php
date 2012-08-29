<?php

class ASSourceCodeDumper {

	protected $frameIndex;
	protected $symbolCount;
	protected $symbolName;
	protected $symbolNames;
	protected $instanceCount;
	protected $decompilerAS2;
	protected $decompilerAS3;
				
	public function getRequiredTags() {
		return array('DoABC', 'DoAction', 'DoInitAction', 'PlaceObject2', 'PlaceObject3', 'DefineSprite', 'DefineBinaryData', 'ShowFrame');
	}
	
	public function dump($swfFile) {
		$this->frameIndex = 1;
		$this->symbolName = "_root";
		$this->symbolCount = 0;
		$this->symbolNames = array( 0 => $this->symbolName);
		$this->instanceCount = 0;
		$this->processTags($swfFile->tags);
	}

	protected function processTags($tags) {
		foreach($tags as $tag) {
			if($tag instanceof SWFDoABCTag) {
				if(!$this->decompilerAS3) {
					$this->decompilerAS3 = new AS3Decompiler;
				}
				$packages = $this->decompilerAS3->decompile($tag->abcFile);
				$this->printStatements($packages);
			} else if($tag instanceof SWFDoActionTag || $tag instanceof SWFDoInitActionTag) {
				// an empty tag would still contain the zero terminator
				if(strlen($tag->actions) > 1) {
					if($tag instanceof SWFDoInitActionTag) {
						$symbolName = $this->symbolNames[$tag->characterId];
						echo "<div class='comments'>// $symbolName initialization </div>";
					} else {
						echo "<div class='comments'>// $this->symbolName, frame $this->frameIndex </div>";
					}
					if(!$this->decompilerAS2) {
						$this->decompilerAS2 = new AS2Decompiler;
					}
					$statements = $this->decompilerAS2->decompile($tag->actions);
					$this->printStatements($statements);
				}
			} else if($tag instanceof SWFPlaceObject2Tag) {
				$this->instanceCount++;
				if($tag->clipActions) {
					static $eventNames = array(	0x00040000 => "construct",	0x00020000 => "keyPress", 
									0x00010000 => "dragOut", 	0x00008000 => "dragOver",
									0x00004000 => "rollOut", 	0x00002000 => "rollOver",
									0x00001000 => "releaseOutside",	0x00000800 => "release",
									0x00000400 => "press",		0x00000200 => "initialize",
									0x00000100 => "data",		0x00000080 => "keyUp",
									0x00000040 => "keyDown",	0x00000020 => "mouseUp",
									0x00000010 => "mouseDown",	0x00000008 => "mouseMove",
									0x00000004 => "inload",		0x00000002 => "enterFrame",
									0x00000001 => "load"	);
					
					$instanceName = ($tag->name) ? $tag->name : "instance$this->instanceCount";
					$instancePath = "$this->symbolName.$instanceName";
					echo "<div class='comments'>// $instancePath</div>";
					foreach($tag->clipActions as $clipAction) {
						echo "<div>on(";
						$eventCount = 0;
						foreach($eventNames as $flag => $eventName) {
							if($clipAction->eventFlags & $flag) {
								if($eventCount > 0) {
									echo ", ";
								}
								echo "<span class='name'>$eventName</span>";
								$eventCount++;
							}
						}
						echo ") {\n";
						if(!$this->decompilerAS2) {
							$this->decompilerAS2 = new AS2Decompiler;
						}
						$statements = $this->decompilerAS2->decompile($clipAction->actions);
						echo "<div class='code-block'>\n";
						$this->printStatements($statements);
						echo "</div>}\n";
					}
				}
			} else if($tag instanceof SWFShowFrameTag) {
				$this->frameIndex++;
			} else if($tag instanceof SWFDefineSpriteTag) {
				$prevSymbolName = $this->symbolName;
				$prevFrameIndex = $this->frameIndex;
				$prevInstanceCount = $this->instanceCount;
				$this->symbolName = $this->symbolNames[$tag->characterId] = "symbol" . ++$this->symbolCount;
				$this->frameIndex = 1;
				$this->instanceCount = 0;
				$this->processTags($tag->tags);
				$this->symbolName = $prevSymbolName;
				$this->frameIndex = $prevFrameIndex;
				$this->instanceCount = $prevInstanceCount;
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$dumper = clone $this;
					$dumper->dump($tag->swfFile);
				}
			}
		}
	}
	
	protected function printPackages($packages) {
		foreach($packages as $package) {
		}
	}
					
	protected function printExpression($expr, $precedence = null) {
		$type = gettype($expr);
		switch($type) {
			case 'boolean': 
				$text = ($expr) ? 'true' : 'false';
				echo "<span class='boolean'>$text</span>"; 
				break;
			case 'double':
				$text = is_nan($expr) ? 'NaN' : (string) $expr;
				echo "<span class='double'>$text</span>"; 
				break;
			case 'integer': 
				$text = (string) $expr;
				echo "<span class='integer'>$text</span>"; 
				break;
			case 'string':
				$text = '"' . htmlspecialchars(addcslashes($expr, "\\\"\n\r\t")) . '"';
				echo "<span class='string'>$text</span>";
				break;
			case 'NULL':
				echo "<span class='null'>null</span>";
				break;
			case 'object':
				if($expr instanceof AS2Identifier || $expr instanceof AS3Identifier) {
					$text = htmlspecialchars($expr->string);
					echo "<span class='name'>$text</span>";
				} else if($expr instanceof AVM1Undefined || $expr instanceof AVM2Undefined) {
					echo "<span class='undefined'>undefined</span>";
				} else if($expr instanceof AVM1Register || $expr instanceof AVM2Register) {
					if($expr->name) {
						$text = $expr->name;
					} else {
						$text = "REG_$expr->index";
					}
					echo "<span class='register'>$text</span>";
				} else if($expr instanceof AS3TypeCoercion) {
					$this->printExpression($expr->value, $precedence);
				} else if($expr instanceof AS3Argument) {
					$this->printExpression($expr->name);
					echo ":";
					$this->printExpression($expr->type);
				} else if($expr instanceof AS3Accessor)	{
					echo "<span class='keyword'>$expr->type</span> ";
					$this->printExpression($expr->name);
				} else if($expr instanceof AS2Function) {
					echo "<span class='keyword'>function</span>";
					$this->printExpression($expr->name);
					echo "(";
					$this->printExpressions($expr->arguments);
					echo ") {<div class='code-block'>\n";
					$this->printStatements($expr->statements);
					echo "</div>}";
				} else if($expr instanceof AS2FunctionCall || $expr instanceof AS3FunctionCall) {
					$this->printExpression($expr->name);
					echo "(";
					$this->printExpressions($expr->arguments);
					echo ")";
				} else if($expr instanceof AS2VariableDeclaration) {
					echo "<span class='keyword'>var</span> ";
					$this->printExpression($expr->name);
					if(!($expr->value instanceof AVM1Undefined)) {
						echo " = ";
						$this->printExpression($expr->value);
					}
				} else if($expr instanceof AS3VariableDeclaration) {
					echo "<span class='keyword'>var</span> ";
					$this->printExpression($expr->name);
					echo ":";
					$this->printExpression($expr->type);
					if(!($expr->value instanceof AVM2Undefined)) {
						echo " = ";
						$this->printExpression($expr->value);
					}
				} else if($expr instanceof AS2ArrayInitializer || $expr instanceof AS3ArrayInitializer) {
					if($expr->items) {
						echo "[ ";
						$this->printExpressions($expr->items);
						echo " ]";
					} else {
						echo "[]";
					}
				} else if($expr instanceof AS2ObjectInitializer || $expr instanceof AS3ObjectInitializer) {
					if($expr->items) {
						echo "{ ";
						$count = 0;
						foreach($expr->items as $name => $value) {
							if($count++ > 0) {
								echo ", ";
							}
							$this->printExpression($name);
							echo ": ";
							$this->printExpression($value);
						}
						echo " }";
					} else {
						echo "{}";
					}
				} else if($expr instanceof AS2Operation || $expr instanceof AS3Operation) {
					static $noSpace = array('.' => true, '!' => true, '~' => true, '++' => true, '--' => true);
					if($precedence !== null) {
						if($expr instanceof AS2Operation && $precedence < $expr->precedence) {
							$needParentheses = true;
						} else if($expr instanceof AS3Operation && $precedence < $expr->precedence) {
							$needParentheses = true;
						} else {
							$needParentheses = false;
						}
					} else {
						$needParentheses = false;
					}
					if($needParentheses) {
						echo "(";
					}
					if($expr instanceof AS2BinaryOperation || $expr instanceof AS3BinaryOperation) {
						$this->printExpression($expr->operand1, $expr->precedence);
						echo isset($noSpace[$expr->operator]) ? $expr->operator : " $expr->operator ";
						$this->printExpression($expr->operand2, $expr->precedence);
					} else if($expr instanceof AS2UnaryOperation || $expr instanceof AS3UnaryOperation) {
						echo isset($noSpace[$expr->operator]) ? $expr->operator : " $expr->operator ";
						$this->printExpression($expr->operand, $expr->precedence);
					} else if($expr instanceof AS2TernaryConditional || $expr instanceof AS3TernaryConditional) {
						$this->printExpression($expr->condition, $expr->precedence);
						echo " ? ";
						$this->printExpression($expr->valueIfTrue, $expr->precedence);
						echo " : ";
						$this->printExpression($expr->valueIfFalse, $expr->precedence);
					} else if($expr instanceof AS2ArrayAccess || $expr instanceof AS3ArrayAccess) {
						$this->printExpression($expr->array);
						echo "[";
						$this->printExpression($expr->index);
						echo "]";
					}
					if($needParentheses) {
						echo ")";
					}
				} else {
					echo "!!!" . get_class($expr) . "!!!";
				}
				break;
		}
	}
	
	protected function printExpressions($expressions) {
		foreach($expressions as $index => $expr) {
			if($index > 0) {
				echo ", ";
			}
			$this->printExpression($expr);
		}
	}
	
	protected function printStatement($stmt) {
		if($stmt instanceof AS2SimpleStatement || $stmt instanceof AS3SimpleStatement) {
			if($stmt instanceof AS2BasicStatement || $stmt instanceof AS3BasicStatement) {
				$this->printExpression($stmt->expression);
			} else if($stmt instanceof AS2Break || $stmt instanceof AS3Break) {
				echo "<span class='keyword'>break</span>";
			} else if($stmt instanceof AS2Continue || $stmt instanceof AS3Continue) {
				echo "<span class='keyword'>continue</span>";
			} else if($stmt instanceof AS2Return || $stmt instanceof AS3Return) {
				echo "<span class='keyword'>return</span>";
				if(!($stmt->value instanceof AVM1Undefined) && !($stmt->value instanceof AVM2Undefined)) {
					echo " ";
					$this->printExpression($stmt->value);
				}
			} else if($stmt instanceof AS2Throw || $stmt instanceof AS3Throw) {
				echo "<span class='keyword'>throw</span>(";
				$this->printExpression($stmt->object);
				echo ")";
			} else if($stmt instanceof AS3ClassVariable) {
				if($stmt->access) {
					echo "<span class='keyword'>$stmt->access</span> ";
				}
				if($stmt->scope) {
					echo "<span class='keyword'>$stmt->scope</span> ";
				}
				echo "<span class='keyword'>var</span> ";
				$this->printExpression($stmt->name);
				echo ":";
				$this->printExpression($stmt->type);
				if(!($stmt->value instanceof AVM2Undefined)) {
					echo " = ";
					$this->printExpression($stmt->value);
				}
			} else if($stmt instanceof AS3ClassConstant) {
				if($stmt->access) {
					echo "<span class='keyword'>$stmt->access</span> ";
				}
				if($stmt->scope) {
					echo "<span class='keyword'>$stmt->scope</span> ";
				}
				echo "<span class='keyword'>const</span> ";
				$this->printExpression($stmt->name);
				echo ":";
				$this->printExpression($stmt->type);
				if(!($stmt->value instanceof AVM2Undefined)) {
					echo " = ";
					$this->printExpression($stmt->value);
				}
			}
			echo ";";
		} else if($stmt instanceof AS2CompoundStatement || $stmt instanceof AS3CompoundStatement) {
			if($stmt instanceof AS2IfElse || $stmt instanceof AS3IfElse) {
				echo "<span class='keyword'>if</span>(";
				$this->printExpression($stmt->condition);
				echo ") {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statementsIfTrue);
				echo "</div>}\n";
				if($stmt->statementsIfFalse) {
					if(count($stmt->statementsIfFalse) == 1 && $stmt->statementsIfFalse[0] instanceof AS2IfElse) {
						// else if
						echo "<span class='keyword'>else</span> ";
						$this->printStatement($stmt->statementsIfFalse[0]);
					} else {
						echo "<span class='keyword'>else</span> {\n<div class='code-block'>\n";
						$this->printStatements($stmt->statementsIfFalse);
						echo "</div>}\n";
					}
				}
			} else if($stmt instanceof AS2While || $stmt instanceof AS3While) {
				echo "<span class='keyword'>while</span>(";
				$this->printExpression($stmt->condition);
				echo ") {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statements);
				echo "</div>}\n";
			} else if($stmt instanceof AS2ForIn || $stmt instanceof AS3ForIn) {
				echo "<span class='keyword'>for</span>(";
				$this->printExpression($stmt->condition);
				echo ") {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statements);
				echo "</div>}\n";
			} else if($stmt instanceof AS2DoWhile || $stmt instanceof AS3DoWhile) {
				echo "<span class='keyword'>do {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statements);
				echo "</div>} while(";
				$this->printExpression($stmt->condition);
				echo ");\n";
			} else if($stmt instanceof AS2TryCatch || $stmt instanceof AS3TryCatch) {
				echo "<span class='keyword'>try</span> {\n<div class='code-block'>\n";
				$this->printStatements($stmt->tryStatements);
				echo "</div>}\n";
				if($stmt->catchStatements) {
					echo "<span class='keyword'>catch</span>(";
					$this->printExpression($stmt->catchObject);
					echo ") {\n<div class='code-block'>\n";
					$this->printStatements($stmt->catchStatements);
					echo "</div>}\n";
				}
				if($stmt->finallyStatements) {
					echo "<span class='keyword'>finally</span> {\n<div class='code-block'>\n";
					$this->printStatements($stmt->catchStatements);
					echo "</div>}\n";
				}
			} else if($stmt instanceof AS2With) {
				echo "<span class='keyword'>with</span>(";
				$this->printExpression($stmt->object);
				echo ") {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statements);
				echo "</div>}\n";
			} else if($stmt instanceof AS2IfFrameLoaded) {
				echo "<span class='keyword'>ifFrameLoaded</span>(";
				$this->printExpression($stmt->frame);
				echo ") {\n<div class='code-block'>\n";
				$this->printStatements($stmt->statements);
				echo "</div>}\n";
			} else if($stmt instanceof AS3Package) {
				echo "<span class='keyword'>package</span> <span class='name'>";
				$this->printExpression($stmt->namespace);
				echo "</span> {\n<div class='code-block'>\n";
				foreach($stmt->imports as $import) {
					echo "<div><span class='keyword'>import</span> ";
					$this->printExpression($import);
					echo ";</div>";
				}
				foreach($stmt->members as $member) {
					if($member->access == 'public') {
						echo "<div>\n";
						$this->printStatement($member);
						echo "</div>\n";
					}
				}
				echo "</div>}\n";
				foreach($stmt->members as $member) {
					if($member->access != 'public') {
						echo "<div>\n";
						$this->printStatement($member);
						echo "</div>\n";
					}
				}
			} else if($stmt instanceof AS3Class) {
				if($stmt->access) {
					echo "<span class='keyword'>$stmt->access</span> ";
				}
				echo "<span class='keyword'>class</span> <span class='name'>";
				$this->printExpression($stmt->name);
				echo "</span> {\n<div class='code-block'>\n";
				$this->printStatements($stmt->members);
				echo "</div>}\n";
			} else if($stmt instanceof AS3ClassMethod || $stmt instanceof AS3Function) {
				if($stmt->access) {
					echo "<span class='keyword'>$stmt->access</span> ";
				}
				if($stmt->scope) {
					echo "<span class='keyword'>$stmt->scope</span> ";
				}
				echo "<span class='keyword'>function</span> ";
				$this->printExpression($stmt->name);
				echo "(";
				$this->printExpressions($stmt->arguments);
				echo ")";
				echo ":";
				$this->printExpression($stmt->returnType);				
				if($stmt->statements) {
					echo " {\n<div class='code-block'>\n";
					$this->printStatements($stmt->statements);
					echo "</div>}\n";
				} else {
					echo ";";
				}
			}
		}
	}
	
	protected function printStatements($statements) {
		foreach($statements as $stmt) {
			echo "<div>\n";
			$this->printStatement($stmt);
			echo "</div>\n";
		}
	}
}

?>