<?php

class SWFGenerator {

	protected $swfFileMappings;
	protected $updateInterval;
	protected $updateInBackground;
	protected $maximumStaleInterval;
	protected $dataModuleConfigs;
	protected $dataModules;

	protected $swfParser;
	protected $swfAssembler;
	protected $textFinder;
	protected $fontFinder;

	protected $swfFiles;
	protected $textObjects;
	protected $fontFamilies;
	
	public function __construct($config) {
		$this->swfFileMappings = isset($config['swf-files']) ? $config['swf-files'] : array();
		$this->updateInterval = isset($config['update-interval']) ? $config['update-interval'] : 0;
		$this->updateInBackground = isset($config['background-update']) ? $config['deferred-update'] : false;
		$this->maximumStaleInterval = isset($config['maximum-stale-interval']) ? $config['maximum-stale-interval'] : 86400;
		$this->dataModuleConfigs = isset($config['data-modules']) ? $config['data-modules'] : array();
				
		$this->swfParser = new SWFTextObjectParser;
		$this->swfAssembler = new SWFTextObjectAssembler;
		$this->textFinder = new SWFTextObjectFinder;
		$this->fontFinder = new SWFFontFinder;
	}
	
	public static function run($config) {
		// create an instance of this class and run its main function
		$instance = new self($config);

		// determine what should happen
		$operation = null;
		foreach($_GET as $name => $value) {
			switch($name) {
				case 'export':
					$operation = 'export';
					break;
				case 'update':
					$operation = 'update';
					$parameter = $value;
					break;
				default:
					$operation = 'runModuleSpecific';
					$parameters[$name] = $value;
			}
		}
		
		switch($operation) {
			case 'update':
				$instance->update($parameter == 'force');
				break;
			case 'export':
				$instance->export();
				break;
			case 'runModuleSpecific':
				$instance->runModuleSpecificOperation($parameters);
				break;
			default:
				$instance->validate();
				break;
		}
	}
	
	public function update($forceUpdate) {
		// turn off error reporting since the output should be Javascript
		error_reporting(0);
		
		// send HTTP response headers to allow fopen() to complete
		flush();
	
		// see if any file is out-of-date (or missing)
		$now = time();
		if($forceUpdate) {
			$needUpdate = true;
			$mustUpdate = false;
			$canShowOlderVersion = false;
		} else {
			$needUpdate = false;
			$mustUpdate = false;
			$canShowOlderVersion = $this->updateInBackground;
			foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
				if(file_exists($swfDestinationFilePath)) {
					$sourceCreationDate = filemtime($swfSourceFilePath);
					$destinationCreationDate = filemtime($swfDestinationFilePath);
					if($destinationCreationDate < $sourceCreationDate) {
						// the source file is newer--update
						$needUpdate = true;
						$mustUpdate = true;
						$canShowOlderVersion = false;
					}
					if($destinationCreationDate + $this->updateInterval < $now) {
						// file is older than update interval--might need to check if upda
						$needUpdate = true;
						if($destinationCreationDate + $this->maximumStaleInterval < $now) {
							$canShowOlderVersion = false;
						}
					}
				} else {
					// a destination file is missing--must update
					$needUpdate = true;
					$mustUpdate = true;
					$canShowOlderVersion = false;
				}
			}
		}
		
		if($needUpdate) {
			if($canShowOlderVersion) {
				// spawn a server thread to do the update
				// the visitor will see an older version in the mean time
				$internalUrl = "http://{$_SERVER['HTTP_HOST']}:{$_SERVER['SERVER_PORT']}{$_SERVER['SCRIPT_NAME']}?update=force";
				$streamContextOptions = array (
					'http' => array (
						'method' => 'GET',
						'timeout' => 1
					)
				);
				$context = stream_context_create($streamContextOptions);
				if($f = fopen($internalUrl, "rb", 0, $context)) {
					fclose($f);
					$needUpdate = false;
				}
			}
			
			if($needUpdate) {
				$this->createDateModules();
						
				// see if remote data source(s) have changed		
				if($mustUpdate || $this->checkChanges()) {
					// initial the data transfer first 
					// continue the update process even when an error occurs if there're files missing
					if($this->startTransfer() || $mustUpdate) {
						// check paths where files will be saved temporarily
						if($this->checkTemporaryFiles()) {
							// parse the SWF files and find the text objects within them
							$this->parseSWFFiles();

							// ask the data modules to apply changes
							$this->applyChanges();
						
							// assemble the files
							$this->assembleSWFFiles();
						}
					}
				}
			}
		}
	}
	
	protected function createDateModules() {
		$this->dataModules = array();
		foreach($this->dataModuleConfigs as $index => $dataModuleConfig) {
			$dataModuleName = isset($dataModuleConfig['name']) ? trim($dataModuleConfig['name']) : null;
			$dataModule = class_exists($dataModuleName, true) ? new $dataModuleName($dataModuleConfig) : null;
			$this->dataModules[$index] = $dataModule;
		}
	}
	
	protected function checkChanges() {
		foreach($this->dataModules as $dataModule) {
			if($dataModule->checkChanges()) {
				return true;
			}
		}
		return false;
	}
	
	protected function startTransfer() {
		$successful = true;
		foreach($this->dataModules as $dataModule) {
			if(!$dataModule->startTransfer()) {
				$successful = false;
			}
		}
		return $successful;
	}
	
	protected function parseSWFFiles() {
		$textObjectLists = array();
		$fontFamilyLists = array();
		$this->swfFiles = array();
		foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
			$input = fopen($swfSourceFilePath, "rb");			
			if($input) {
				$this->swfFiles[$swfSourceFilePath] = $swfFile = $this->swfParser->parse($input);
				$textObjectLists[] = $this->textFinder->find($swfFile);
				$fontFamilyLists[] = $this->fontFinder->find($swfFile);
				fclose($input);
			}
		}
		$this->textObjects = call_user_func_array('array_merge', $textObjectLists);
		$this->fontFamilies = call_user_func_array('array_merge', $fontFamilyLists);
		uasort($this->textObjects, array($this, 'compareTextObjectNames'));
	}
	
	protected function checkTemporaryFiles() {
		foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
			$swfTempFilePath = "$swfDestinationFilePath.new";
			if(file_exists($swfTempFilePath)) {
				$modificationTime = filemtime($swfTempFilePath);
				if($modificationTime + 60 < $now) {
					// file is older than a minute--probably garbage
					unlink($swfTempFilePath);
				} else {
					return false;
				}
			}
			
			// delete old files
			$swfOldFilePath = "$swfDestinationFilePath.old";
			if(file_exists($swfOldFilePath)) {
				unlink($swfOldFilePath);
			}
		}				
		return true;
	}
	
	protected function applyChanges() {
		foreach($this->dataModules as $dataModule) {
			$changes = $dataModule->updateText($this->textObjects, $this->fontFamilies);
			$this->textFinder->replace($changes);
			
			// remove the ones that's been changed from the list
			if($changes) {
				foreach($this->textObjects as $index => $textObject) {
					if(in_array($textObject, $changes)) {
						unset($this->textObjects[$index]);
					}
				}
			}
		}
	}
	
	protected function assembleSWFFiles() {
		$temporaryFiles = array();
		foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
			$swfTempFilePath = "$swfDestinationFilePath.new";
			
			// open the file in append mode, just in case of the race condition where 
			// where temporary files are created right after the call to checkTemporaryFiles()
			$output = fopen($swfTempFilePath, "a");
			if($output && ftell($output) == 0) {
				$swfFile = $this->swfFiles[$swfSourceFilePath];
				$this->swfAssembler->assemble($output, $swfFile);
				fclose($output);
				$temporaryFiles[$swfDestinationFilePath] = $swfTempFilePath;
			}
		}

		// move the temporary files into the final location		
		foreach($temporaryFiles as $swfDestinationFilePath => $swfTempFilePath) {
			// try to delete the old file
			if(!file_exists($swfDestinationFilePath) || unlink($swfDestinationFilePath)) {
				rename($swfTempFilePath, $swfDestinationFilePath);
			} else {
				// doesn't work--perhaps it's being accessed by the web-server (memory-mapped on Win32?)
				// try renaming it instead
				$swfOldFilePath = "$swfDestinationFilePath.old";
				if(rename($swfDestinationFilePath, $swfOldFilePath)) {
					rename($swfTempFilePath, $swfDestinationFilePath);
				}
			}
		}		
	}
	
	public function validate() {
		// turn off error reporting since some operations might fail
		error_reporting(0);
				
		echo "<html><head><title>Flaczki SWF Generator</title>";
		echo "<style> BODY { font-family: sans-serif; font-size: 1em; } .section { border: 1px solid #333333; margin: 1em 1em 1em 1em; background-color: #EEEEEE; } .section-header { border: 1px inset #999999; background-color: #333333; color: #FFFFFF; padding: 0px 4px 0px 4px; font-size: 1.5em; font-weight: bold; margin: 3px 3px 3px 3px; } .subsection-ok { border: 1px inset #CCCCCC; background-color: #DDFFDD; padding: 1px 4px 1px 4px; margin: 3px 3px 3px 3px; } .subsection-err { border: 1px inset #CCCCCC; background-color: #FFDDDD; padding: 1px 4px 1px 4px; margin: 3px 3px 3px 3px; } </style>";
		echo "</head><body>";
		
		$this->createDateModules();
		$requiredExtensions = array('PCRE', 'XML', 'Zlib');
		foreach($this->dataModules as $dataModule) {
			if($dataModule instanceof SWFGeneratorDataModule) {
				$requiredExtensions = array_merge($requiredExtensions, $dataModule->getRequiredPHPExtensions());
			}
		}
		$requiredExtensions = array_unique($requiredExtensions);
		sort($requiredExtensions);
		
		echo "<div class='section'>";
		echo "<div class='section-header'>PHP Extensions</div>";
		$extensions = get_loaded_extensions();
		foreach($requiredExtensions as $ext) {
			if(in_array(strtolower($ext), $extensions)) {
				echo "<div class='subsection-ok'><b>$ext:</b> active</div>";
			} else {
				echo "<div class='subsection-err'><b>$ext:</b> missing</div>";
			}
		}
		echo "</div>";
		echo "<div class='section'>";
		
		echo "<div class='section-header'>Update Options</div>";
		$updateInterval = $this->formatSeconds($this->updateInterval);
		echo "<div class='subsection-ok'><b>Update interval:</b> {$updateInterval}</div>";
		$updateInBackground = ($this->updateInBackground) ? 'yes' : 'no';
		echo "<div class='subsection-ok'><b>Background update:</b> {$updateInBackground}</div>";
		$maximumStaleInterval = $this->formatSeconds($this->maximumStaleInterval);
		echo "<div class='subsection-ok'><b>Maximum stale interval:</b> {$maximumStaleInterval}</div>";
		echo "</div>";

		foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
			$swfSourceFileName = basename($swfSourceFilePath);
			$swfDestinationFileName = basename($swfDestinationFilePath);
			echo "<div class='section'>";
			echo "<div class='section-header'>SWF Generation: $swfSourceFileName &rarr; $swfDestinationFileName </div>";
			if(file_exists($swfSourceFilePath)) {
				$fullPath = realpath($swfSourceFilePath);
				if(is_readable($swfSourceFilePath)) {
					echo "<div class='subsection-ok'><b>Source file:</b> {$fullPath}</div>";
					$filesize = sprintf("%0.1fK", filesize($swfSourceFilePath) / 1024.0);
					echo "<div class='subsection-ok'><b>File size:</b> {$filesize}</div>";
					$startTime = microtime(true);
					$input = fopen($swfSourceFilePath, "rb");
					$swfFile = ($input) ? $this->swfParser->parse($input) : null;
					if($swfFile) {
						if($swfFile->version >= 11) {
							echo "<div class='subsection-ok'><b>Flash version:</b> {$swfFile->version}</div>";
						} else {
							echo "<div class='subsection-err'><b>Flash version:</b> {$swfFile->version}</div>";
						}					
						$width = ($swfFile->frameSize->right - $swfFile->frameSize->left) / 20;
						$height = ($swfFile->frameSize->bottom - $swfFile->frameSize->top) / 20;						
						echo "<div class='subsection-ok'><b>Dimension:</b> {$width}x{$height}</div>";
						$compressed = ($swfFile->compressed) ? 'yes' : 'no';
						echo "<div class='subsection-ok'><b>Compressed:</b> $compressed</div>";
						
						$textObjects = $this->textFinder->find($swfFile);
						$textObjectCount = count($textObjects);
						$textObjectNames = array();
						foreach($textObjects as $textObject) {
							$textObjectNames[] = $textObject->name;
						}
						natsort($textObjectNames);
						$textObjectNames = implode(', ', $textObjectNames);
						
						if($textObjectCount > 0) {
							echo "<div class='subsection-ok'><b>TLF text objects ($textObjectCount):</b> $textObjectNames</div>";
						} else {
							echo "<div class='subsection-err'><b>TLF text objects ($textObjectCount):</b></div>";
						}
						
						$fontFamilies = $this->fontFinder->find($swfFile);
						$fontCount = count($fontFamilies);
						$fontDescriptions = array();
						foreach($fontFamilies as $fontFamily) {
							$fontDescription = $fontFamily->name;
							$fontStyles = array();
							if($fontFamily->normal) $fontStyles[] = 'normal';
							if($fontFamily->bold) $fontStyles[] = 'bold';
							if($fontFamily->italic) $fontStyles[] = 'italic';
							if($fontFamily->boldItalic) $fontStyles[] = 'bold-italic';
							$fontDescription .= " (" . implode('/', $fontStyles) . ")";
							$fontDescriptions[] = $fontDescription;
						}
						$fontDescriptions = implode(', ', $fontDescriptions);
						echo "<div class='subsection-ok'><b>Embedded fonts ($fontCount):</b> $fontDescriptions</div>";
						
						$endTime = microtime(true);
						$duration = sprintf("%0.4f", $endTime - $startTime);
						echo "<div class='subsection-ok'><b>Process time: </b> $duration second(s)</div>";						
					} else {
						echo "<div class='subsection-err' style='align: center'><em>(errors encountered reading file)</em></div>";
					}
				} else {
					echo "<div class='subsection-err'><b>Source file:</b> {$fullPath} <em>(is not readable)</em></div>";
				}
			} else {
					echo "<div class='subsection-err'><b>Source file:</b> \"{$swfSourceFilePath}\" <em>(does not exists)</em></div>";
			}
			if(file_exists($swfDestinationFilePath)) {
				$fullPath = realpath($swfDestinationFilePath);
				if(is_writable($swfDestinationFilePath)) {
				
					echo "<div class='subsection-ok'><b>Destination file:</b> {$fullPath}</div>";
				} else {
					echo "<div class='subsection-err'><b>Destination file:</b> {$fullPath} <em>(is not writable)</em></div>";
				}
			} else {
				$swfDestinationFolderPath = dirname($swfDestinationFilePath);
				if(file_exists($swfDestinationFolderPath)) {
					$fullPath = realpath($swfDestinationFolderPath) . DIRECTORY_SEPARATOR . basename($swfDestinationFilePath);
					if(is_writable($swfDestinationFolderPath)) {
						echo "<div class='subsection-ok'><b>Destination file:</b> {$fullPath}</div>";
					} else {
						echo "<div class='subsection-err'><b>Destination file:</b> {$fullPath} <em>(directory is not writable)</em></div>";
					}
				} else {
					echo "<div class='subsection-err'><b>Destination file:</b> \"{$swfDestinationFilePath}\" <em>(directory does not exists)</em></div>";
				}
			}
			echo "</div>";
		}
		
		foreach($this->dataModules as $index => $dataModule) {
			$dataModuleConfig = $this->dataModuleConfigs[$index];
			$dataModuleName = $dataModuleConfig['name'];
			echo "<div class='section'>";
			echo "<div class='section-header'>Data Module: $dataModuleName</div>";			
			if($dataModule) {
				if($dataModule instanceof SWFGeneratorDataModule) {
					flush();
					$dataModule->validate();
				} else {
					echo "<div class='subsection-err' style='text-align: center'><em>(class is not inherited from SWFGeneratorDataModule)</em></div>";
				}
			} else {
					echo "<div class='subsection-err' style='text-align: center'><em>(unknown or mistyped module name)</em></div>";
			}
			echo "</div>";
		}
				
		echo "</body>";
	}
	
	public function export() {
		//  turn off error reporting to ensure error messages don't interfere with download
		error_reporting(0);
		
		// see which modules has exportable contents
		$this->createDateModules();
		$exportingModules = array();
		$exportingModuleHash = array();
		foreach($this->dataModules as $dataModule) {
			$className = get_class($dataModule);
			// if a module is used multiple times, export from only the first instance
			if(!isset($exportingModuleHash[$className])) {
				if($dataModule->getExportType()) {
					$exportingModules[] = $dataModule;
				}
				$exportingModuleHash[$className] = true;
			}
		}
				
		if(count($exportingModules) == 0) {
			echo "<p>Not exportable contents available</p>";
		} else {
			$this->parseSWFFiles();
			$output = fopen("php://output", "wb");
			if(count($exportingModules) == 1) {
				// just send the single file
				$exportingModule = $exportingModules[0];
				$mimeType = $exportingModule->getExportType();
				$fileName = $exportingModule->getExportFileName();
				header("Content-type: $mimeType");
				header("Content-Disposition: attachment; filename=\"$fileName\"");
				$exportingModule->export($output, $this->textObjects, $this->fontFamilies);
			} else {
				// put the files into a zip archive
				$mimeType = "application/zip";
				$fileName = "export.zip";
				header("Content-type: $mimeType");
				header("Content-Disposition: attachment; filename=\"$fileName\"");
				$zipPath = StreamZipArchive::create($output);
				foreach($exportingModules as $exportingModule) {
					$name = $exportingModule->getExportFileName();
					$stream = fopen("$zipPath/$name", "wb");
					$exportingModule->export($stream, $this->textObjects, $this->fontFamilies);
				}
				StreamZipArchive::close($zipPath);
			}
		}
	}
	
	public function runModuleSpecificOperation($parameters) {
		$this->createDateModules();
		foreach($this->dataModules as $dataModule) {
			if($dataModule->runModuleSpecificOperation($parameters)) {
				break;
			}
		}
	}	
		
	protected function compareTextObjectNames($a, $b) {
		return strnatcasecmp($a->name, $b->name);
	}
	
	protected function formatSeconds($s) {
		$text = "";
		if($s > 3600) {
			$h = $s / 3600;
			$s = $s % 3600;
			$text .= "$h hour" . (($h > 1) ? 's ' : ' ');
		}
		if($s > 60) {
			$m - $s / 60;
			$s = $s % 60;
			$text .= "$m minute" . (($m > 1) ? 's ' : ' ');
		}
		if($s > 0) {
			$text .= "$s second" . (($s > 1) ? 's ' : ' ');
		}
		return $text;
	}
}

?>