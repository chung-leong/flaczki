<?php

class ASCodeDumper {

	protected $frameIndex;
	protected $symbolCount;
	protected $symbolName;
	protected $symbolNames;
	protected $instanceCount;
				
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
				$decoder2 = new AVM2Decoder;
				$scripts = $decoder2->decode($tag->abcFile);
				foreach($scripts as $script) {
					$this->printScript($script);
				}
			} else if($tag instanceof SWFDoActionTag || $tag instanceof SWFDoInitActionTag) {
				// an empty tag would still contain the zero terminator
				if(strlen($tag->actions) > 1) {
					if($tag instanceof SWFDoInitActionTag) {
						$symbolName = $this->symbolNames[$tag->characterId];
						echo "<div class='comments'>// $symbolName initialization </div>";
					} else {
						echo "<div class='comments'>// $this->symbolName, frame $this->frameIndex </div>";
					}
					$decoder1 = new AVM1Decoder;
					$operations = $decoder1->decode($tag->actions);
					$this->printOperations($operations);
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
						$decoder1 = new AVM1Decoder;
						$operations = $decoder1->decode($clipAction->actions);
						echo "<div class='code-block'>\n";
						$this->printOperations($operations);
						echo "</div>}\n";
					}
				}
			} else if($tag instanceof SWFShowFrameTag) {
				$this->frameIndex++;
			} else if($tag instanceof SWFDefineSpriteTag) {
				$prevSymbolName = $this->symbolName;
				$prevFrameIndex = $this->frameIndex;
				$prevInstanceCount = $this->instanceCount;
				$this->symbolName = $this->symbolNames[$tag->characterId] = "symbol" . ++$tag->spriteCount;
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

	protected function printScript($script) {
		$this->printMembers($script->members, false);
		// output the initializer 
		echo "<div class='comments'>// script initializer</div>";
		$this->printOperations($script->initializer->body->operations);
	}
	
	protected function printMembers($members, $static) {
		foreach($members as $member) {
			if($member->object instanceof AVM2Class) {
				$static = $member->object;
				$instance = $static->instance;
				echo "<div>\n";
				$this->printMemberModifiers($member->name, false);
				echo "class ";
				$this->printName($member->name);
				echo " {\n";
				
				// print static members
				echo "<div class='code-block'>\n";
				$this->printMembers($static->members, true);
				echo "</div>\n";
				
				// print instance members
				echo "<div class='code-block'>\n";
				$this->printMembers($instance->members, false);
				// print instance constructor
				$constructor = $instance->constructor;
				echo "<div>\n";
				echo "public function ";
				$this->printName($instance->name);
				echo "(";
				foreach($constructor->arguments as $index => $argument) {
					echo ($index > 0) ? ",\n" : "";
					$this->printName($argument->name);
					echo ":";
					$this->printName($argument->type);
					if($argument->value) {
						echo " = ";
						$this->printOperand($argument->value);
					}
				}
				echo ")";
				if($constructor->body) {
					echo " {<div class='code-block'>\n";
					$this->printOperations($constructor->body->operations);
					echo "</div>\n";
					echo "}</div>\n";
				} else {
					echo ";</div>\n";
				}
				echo "</div>\n";
				
				// print static constructor
				echo "<div class='code-block'>\n";
				echo "<span class='comments'>// static constructor</span>";
				$this->printOperations($static->constructor->body->operations);
				echo "</div>\n";
				echo "}</div>\n";
			} else if($member->object instanceof AVM2Method) {
				$method = $member->object;
				echo "<div>\n";
				$this->printMemberModifiers($member->name, $static);
				echo "function ";
				if($member->type == AVM2ClassMember::MEMBER_GETTER) {
					echo "get ";
				} else if($member->type == AVM2ClassMember::MEMBER_SETTER) {
					echo "set ";
				}
				$this->printName($member->name);
				echo "(";
				foreach($method->arguments as $index => $argument) {
					echo ($index > 0) ? ",\n" : "";
					$this->printName($argument->name);
					echo ":";
					$this->printName($argument->type);
					if($argument->value) {
						echo " = ";
						$this->printOperand($argument->value);
					}
				}
				echo "):";
				$this->printName($method->returnType);
				if($method->body) {
					echo " {<div class='code-block'>\n";
					$this->printOperations($method->body->operations);
					echo "</div>\n";
					echo "}</div>\n";
				} else {
					echo ";</div>\n";
				}
			} else if($member->object instanceof AVM2Variable) {
				$var = $member->object;
				echo "<div>\n";
				$this->printMemberModifiers($member->name, $static);
				echo "var ";
				$this->printName($member->name);
				echo ":";
				$this->printName($var->type);
				if($var->value) {
					echo " = ";
					$this->printOperand($var->value);
				}
				echo ";</div>\n";
			} else if($member->object instanceof AVM2Constant) {
				$const = $member->object;
				echo "<div>\n";
				$this->printMemberModifiers($member->name, $static);
				echo "const ";
				$this->printName($member->name);
				echo ":";
				$this->printName($const->type);
				if($const->value) {
					echo " = ";
					$this->printOperand($const->value);
				}
				echo ";</div>\n";
			}
		}
	}
	
	protected function printMemberModifiers($name, $static) {
		if($name->namespace instanceof AVM2ProtectedNamespace) {
			echo "protected ";
		} else if($name->namespace instanceof AVM2PrivateNamespace) {
			echo "private ";
		} else if($name->namespace instanceof AVM2PackageNamespace) {
			echo "public ";
		}
		if($static) {
			echo "static ";
		}
	}
					
	protected function printOperand($operand) {
		$type = gettype($operand);
		switch($type) {
			case 'boolean': 
				$text = ($operand) ? 'true' : 'false';
				echo "<span class='boolean'>$text</span>"; 
				break;
			case 'double':
				$text = is_nan($operand) ? 'NaN' : (string) $operand;
				echo "<span class='double'>$text</span>"; 
				break;
			case 'integer': 
				$text = (string) $operand;
				echo "<span class='integer'>$text</span>"; 
				break;
			case 'string':
				$text = '"' . htmlspecialchars(addcslashes($operand, "\\\"\n\r\t")) . '"';
				echo "<span class='string'>$text</span>";
				break;
			case 'array':
				echo "[ ";
				foreach($operand as $index => $item) {
					echo ($index > 0) ? ",\n" : "\n";
					$this->printOperand($item);
				}
				echo " ]\n";
				break;
			case 'NULL':
				echo "<span class='null'>null</span>\n";
				break;
			case 'object':
				if($operand instanceof AVM1Undefined || $operand instanceof AVM2Undefined) {
					echo "<span class='undefined'>undefined</span>";
				} else if($operand instanceof AVM1FunctionBody) {
					echo "function() {";
					echo "<div class='code-block'>\n";
					$this->printOperations($operand->operations);
					echo "</div>}";
				} else if($operand instanceof AVM1Register || $operand instanceof AVM2Register) {
					if($operand->name) {
						$text = "(REG_$operand->index, $operand->name)";
					} else {
						$text = "(REG_$operand->index)";
					}
					echo "<span class='register'>$text</span>";
				} else if($operand instanceof AVM2Namespace) {
					echo "<span class='namespace'>$operand->string</span>";
				} else if($operand instanceof AVM2Name) {
					$this->printName($operand);
				}
				break;
		}
	}
	
	protected function printName($name) {
		echo "<span class='name'>";
		if($name instanceof AVM2QName) {
			echo ($name->namespace->string) ? $name->namespace->string . "." : "";
			echo ($name instanceof AVM2QNameA) ? "@" : "";
			echo ($name->string) ? $name->string : "*";
		} else if($name instanceof AVM2RTQName) {
			echo "(RUNTIME NAMESPACE).";
			echo ($name instanceof AVM2RTQNameA) ? "@" : "";
			echo ($name->string) ? $name->string : "*";
		} else if($name instanceof AVM2RTQNameL) {
			echo "(RUNTIME NAMESPACE)";
			echo ($name instanceof AVM2RTQNameLA) ? "@" : "";
			echo "(RUNTIME STRING)";
		} else if($name instanceof AVM2Multiname) {
			if($name->namespaceSet->namespaces) {
				echo "[";
				foreach($name->namespaceSet->namespaces as $index => $namespace) {
					echo ($index > 0) ? "|" : "";
					echo ($namespace->string) ? $namespace->string : "*";
				}
				echo "].";
			}
			echo ($name instanceof AVM2MultinameA) ? "@" : "";
			echo $name->string;
		} else if($name instanceof AVM2MultinameL) {
			if($name->namespaceSet->namespaces) {
				echo "[";
				foreach($name->namespaceSet->namespaces as $index => $namespace) {
					echo ($index > 0) ? "|" : "";
					echo ($namespace->string) ? $namespace->string : "*";
				}
				echo "].";
			}
			echo ($name instanceof AVM2MultinameLA) ? "@" : "";
			echo "(RUNTIME STRING)";
		} else if($name instanceof AVM2GenericName) {
			$this->printName($name->name);
			echo ".<";
			foreach($name->types as $index => $typeName) {
				if($index > 0) {
					echo ", ";
				}
				$this->printName($typeName);
			}
			echo ">";
		} 
		echo "</span>";
	}

	protected function printOperations($operations) {
		foreach($operations as $ip => $op) {
			echo "<div>";
			echo sprintf("<span class='address'>%06d</span> <span class='instruction'>$op->name</span>", $ip);
			for($i = 1, $var = "op1"; isset($op->$var); $i++, $var = "op$i") {
				$operand = $op->$var;
				echo ($i > 1) ? ",\n" : "\n";
				$this->printOperand($operand);
			}
			echo "</div>";
		}
	}
}

?>