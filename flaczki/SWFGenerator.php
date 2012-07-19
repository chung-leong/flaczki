<?php

class SWFGenerator {

	protected $swfFileMappings;
	protected $updateInterval;
	protected $updateInBackground;
	protected $maximumStaleInterval;
	protected $dataModuleConfigs;
	
	protected $persistentDataPath;
	protected $persistentData;
	protected $persistentDataString;
	
	public function __construct($config) {
		$this->swfFileMappings = isset($config['swf-files']) ? $config['swf-files'] : array();
		$this->updateInterval = isset($config['update-interval']) ? $config['update-interval'] : 0;
		$this->updateInBackground = isset($config['background-update']) ? $config['deferred-update'] : false;
		$this->maximumStaleInterval = isset($config['maximum-stale-interval']) ? $config['maximum-stale-interval'] : 86400;
		$this->dataModuleConfigs = isset($config['data-modules']) ? $config['data-modules'] : array();
	}
	
	public static function run($config) {
		// create an instance of this class and run its main function
		$instance = new self($config);

		// determine what should happen
		$operation = null;
		if(isset($_GET['update'])) {
			$operation = 'update';
		} else if(isset($_GET['test'])) {
			$operation = 'validate';
		} else if(isset($_GET['export'])) {
			$operation = 'export';
		}
		
		switch($operation) {
			case 'update':
				$instance->update($parameter == 'force');
				break;
			case 'export':
				$instance->export();
				break;
			case 'validate':
				$instance->validate();
				break;
			default:
				$instance->runModuleSpecificOperation($_GET);
				break;
		}
		
		if($instance->persistentData !== null) {
			$instance->savePersistentData();
		}
	}
	
	public function update($forceUpdate) {
		// turn off error reporting since the output should be Javascript
		error_reporting(E_ALL);
		
		// set expiration date
		$now = time();
		$expirationDate = date("r", $now + $this->updateInterval);
		header("Pragma: public");
		header("Cache-Control: maxage=$this->updateInterval");
		header("Expires: $expirationDate");
		
		// send HTTP response headers to allow fopen() to complete		
		flush();
	
		// see if any file is out-of-date (or missing)
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
		
		if(!$needUpdate) {
			$this->stopAdditionalOutput();
			return true;
		}
		
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
				$this->stopAdditionalOutput();
				return true;
			}
		}
		
		$dataModules = $this->createDateModules();
				
		if(!$mustUpdate) {
			// update only if remote data source(s) has changed		
			$sourcesChanged  = false;
			foreach($dataModules as $dataModule) {
				if($dataModule->checkChanges()) {
					$sourcesChanged = true;
					break;
				}
			}
			if(!$sourcesChanged) {
				// just touch the files and get out of here
				foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
					touch($swfDestinationFilePath);
				}
				$this->stopAdditionalOutput();
				return true;
			}			
		}
		
				
		// initial the data transfer first 
		foreach($dataModules as $dataModule) {
			if(!$dataModule->startTransfer()) {
				$this->stopAdditionalOutput();
				return false;
			}
		}
		$transferFinished = false;
		
		$temporaryFiles = array();
		foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
			// see if a temporary file exists already 
			$swfTempFilePath = "$swfDestinationFilePath.new";
			$fileConflict = file_exists($swfTempFilePath);
			if($fileConflict) {
				$modificationTime = filemtime($swfTempFilePath);
				if($modificationTime + 60 < $now) {
					// file is older than a minute--probably garbage
					if(unlink($swfTempFilePath)) {
						$fileConflict = false;
					}
				}
			}

			// delete old file if there's one
			$swfOldFilePath = "$swfDestinationFilePath.old";
			if(file_exists($swfOldFilePath)) {
				unlink($swfOldFilePath);
			}
				
			if(!$fileConflict) {
				// parse the SWF file and find the text objects within it
				$input = fopen($swfSourceFilePath, "rb");
				if($input) {
					$assetFinder = new SWFAssetFinder; 
					$swfParser = new SWFParser;
					$swfFile = $swfParser->parse($input, $assetFinder->getRequiredTags());
					fclose($input);
					$assets = $assetFinder->find($swfFile);
					
					// finish transfering the data 
					if(!$transferFinished) {
						foreach($dataModules as $dataModule) {
							$dataModule->finishTransfer();
						}
						$transferFinished = true;
					}
				
					// ask the data modules to apply changes
					foreach($dataModules as $dataModule) {
						$dataModule->update($assets);
					}
					
					// transfer changes to SWF tags
					$assetUpdater = new SWFAssetUpdater;
					$fileChanged = $assetUpdater->update($assets);
					
					if($fileChanged || !file_exists($swfDestinationFilePath)) {
						// assemble the files
						$swfTempFilePath = "$swfDestinationFilePath.new";
						
						// open the file in append mode, just in case of the race condition where 
						// where temporary files are created right after the call to checkTemporaryFiles()
						$output = fopen($swfTempFilePath, "a");
						if($output && ftell($output) == 0) {
							$swfAssembler = new SWFAssembler;
							$swfAssembler->assemble($output, $swfFile, true);
							fclose($output);
							$temporaryFiles[$swfDestinationFilePath] = $swfTempFilePath;
						}
					} else {
						touch($swfDestinationFilePath);
					}
				}
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
		$this->stopAdditionalOutput();
		return true;
	}
	
	protected function createDateModules() {
		if($this->persistentData === null) {
			$this->loadPersistentData();
		}
		$dataModules = array();				
		foreach($this->dataModuleConfigs as $dataModuleConfig) {
			$dataModuleName = isset($dataModuleConfig['name']) ? trim($dataModuleConfig['name']) : null;
			$dataModule = class_exists($dataModuleName, true) ? new $dataModuleName($dataModuleConfig, $this->persistentData) : null;
			$dataModules[] = $dataModule;
		}
		return $dataModules;
	}
	
	protected function loadPersistentData() {
		$dir = session_save_path();
		if(!$dir) {
			$dir = sys_get_temp_dir();
		}
		$this->persistentDataPath = preg_replace('/\/$/', '', str_replace('\\', '/', $dir)) . '/flaczki';
		if(file_exists($this->persistentDataPath)) {
			$this->persistentDataString = file_get_contents($this->persistentDataPath);
			$this->persistentData = unserialize($this->persistentDataString);
		} else {
			$this->persistentDataString = 'a:0:{}';
			$this->persistentData = array();
		}
	}
	
	protected function savePersistentData() {
		$newPersistentDataString = serialize($this->persistentData);
		if($this->persistentDataString != $newPersistentDataString) {
			file_put_contents($this->persistentDataPath, $newPersistentDataString);
		}
	}
	
	public function validate() {
		// turn off error reporting since some operations might fail
		error_reporting(0);
				
		echo "<html><head><title>Flaczki SWF Generator</title>";
		echo "<style> BODY { font-family: sans-serif; font-size: 1em; } .section { border: 1px solid #333333; margin: 1em 1em 1em 1em; background-color: #EEEEEE; } .section-header { border: 1px inset #999999; background-color: #333333; color: #FFFFFF; padding: 0px 4px 0px 4px; font-size: 1.5em; font-weight: bold; margin: 3px 3px 3px 3px; } .subsection-ok { border: 1px inset #CCCCCC; background-color: #DDFFDD; padding: 1px 4px 1px 4px; margin: 3px 3px 3px 3px; } .subsection-err { border: 1px inset #CCCCCC; background-color: #FFDDDD; padding: 1px 4px 1px 4px; margin: 3px 3px 3px 3px; } </style>";
		echo "</head><body>";
		
		$dataModules = $this->createDateModules();
		$requiredExtensions = array('PCRE', 'XML', 'Zlib');
		foreach($dataModules as $dataModule) {
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
					if($input) {
						$assetFinder = new SWFAssetFinder;
						$swfParser = new SWFParser;
						$swfFile = $swfParser->parse($input, $assetFinder->getRequiredTags());
					} else {
						$swfFile = null;
					}
					$endTime = microtime(true);
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
						
						$assets = $assetFinder->find($swfFile);
						$textObjectCount = count($assets->textObjects);
						$textObjectNames = array();
						foreach($assets->textObjects as $textObject) {
							$textObjectNames[] = $textObject->name;
						}
						natsort($textObjectNames);
						$textObjectNames = implode(', ', $textObjectNames);
						
						if($textObjectCount > 0) {
							echo "<div class='subsection-ok'><b>TLF text objects ($textObjectCount):</b> $textObjectNames</div>";
						} else {
							echo "<div class='subsection-err'><b>TLF text objects ($textObjectCount):</b></div>";
						}
						
						$fontCount = count($assets->fontFamilies);
						$fontDescriptions = array();
						foreach($assets->fontFamilies as $fontFamily) {
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
						
						$imageCount = count($assets->images);
						$imageDescriptions = array();
						foreach($assets->images as $image) {
							$imageDescriptions[] = $image->name;
						}
						$imageDescriptions = implode(', ', $imageDescriptions);
						echo "<div class='subsection-ok'><b>Images ($imageCount):</b> $imageDescriptions</div>";
						
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
		
		foreach($dataModules as $index => $dataModule) {
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
		$dataModules = $this->createDateModules();
		$exportingModules = array();
		$exportingModuleHash = array();
		foreach($dataModules as $dataModule) {
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
			$textObjects = array();
			$fontFamilies = array();
			$images = array();
			foreach($this->swfFileMappings as $swfSourceFilePath => $swfDestinationFilePath) {
				$input = fopen($swfSourceFilePath, "rb");
				if($input) {
					$assetFinder = new SWFAssetFinder; 
					$swfParser = new SWFParser;
					$swfFile = $swfParser->parse($input, $assetFinder->getRequiredTags());
					fclose($input);
					$assets = $assetFinder->find($swfFile);
				}
			}
			
			$output = fopen("php://output", "wb");
			if(count($exportingModules) == 1) {
				// just send the single file
				$exportingModule = $exportingModules[0];
				$mimeType = $exportingModule->getExportType();
				$fileName = $exportingModule->getExportFileName();
				header("Content-type: $mimeType");
				header("Content-Disposition: attachment; filename=\"$fileName\"");
				$exportingModule->export($output, $assets);
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
					$exportingModule->export($output, $assets);
				}
				StreamZipArchive::close($zipPath);
			}
			$this->stopAdditionalOutput();
		}
	}
	
	public function runModuleSpecificOperation($parameters) {
		$dataModules = $this->createDateModules();
		$handled = false;
		foreach($dataModules as $dataModule) {
			if($dataModule->runModuleSpecificOperation($parameters)) {
				$handled = true;
				$this->stopAdditionalOutput();
				break;
			}
		}
		if(!$handled) {
			echo "<h1>Unknown Operation</h1>";
			echo "<p>URL should contain one of the following GET variables:</p>";
			echo "<p><i><a href=\"?update\">update</a></i> - Perform an update</p>";
			echo "<p><i><a href=\"?test\">test</a></i> - Test to see if configuration is correct</p>";
			echo "<p><i><a href=\"?export\">export</a></i> - Export text in SWF file(s)</p>";
			
			foreach($dataModules as $dataModule) {
				$parameters = $dataModule->getModuleSpecificParameters();
				foreach($parameters as $name => $description) {
					echo "<p><i><a href=\"?$name\">$name</a></i> - $description</p>";
				}
			}
		}
	}	
	
	protected function stopAdditionalOutput() {
		ob_start();
		register_shutdown_function('ob_end_clean');
	}
		
	protected function formatSeconds($s) {
		$text = "";
		if($s > 3600) {
			$h = $s / 3600;
			$s = $s % 3600;
			$text .= "$h hour" . (($h > 1) ? 's ' : ' ');
		}
		if($s > 60) {
			$m = $s / 60;
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