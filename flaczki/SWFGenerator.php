<?php

class SWFGenerator {

	protected $destinationFolder;
	protected $destinationFileLists;
	protected $sourceFilePaths;
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
		$this->destinationFolder = isset($config['destination']) ? preg_replace('/\/+$/', '', trim($config['destination'])) : '';
		$this->sourceFilePaths = isset($config['swf-files']) ? array_map('trim', $config['swf-files']) : array();
		$this->updateInterval = isset($config['update-interval']) ? $config['update-interval'] : 0;
		$this->updateInBackground = isset($config['deferred-update']) ? $config['deferred-update'] : false;
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
				case 'f': 
					$targetSWFFile = $value;
					$operation = 'retrieve';
					break;
				case 'export':
					$operation = 'export';
					break;
				case 'update':
					$operation = 'update';
					break;
			}
		}
		
		switch($operation) {
			case 'retrieve':
				$instance->retrieve($targetSWFFile);
				break;
			case 'update':
				// send HTTP response headers to allow fopen() to complete
				flush();
				$instance->update();
				break;
			case 'export':
				$instance->export();
				break;
			default:
				$instance->validate();
				break;
		}
	}
	
	public function retrieve($targetSWFFilePath) {
		// turn off error reporting to ensure error messages don't interfere with redirection
		error_reporting(0);
	
		// see what files there are in the destination folder
		$this->scanDestinationfolder();
		
		// see if any file is out-of-date (or missing)
		$now = time();
		$needUpdate = false;
		$canUpdateInBackground = $this->updateInBackground;
		$maxInterval = 0;
		foreach($this->sourceFilePaths as $swfFilePath) {
			if(isset($this->destinationFileLists[$swfFilePath])) {
				$list = $this->destinationFileLists[$swfFilePath];
				$mostRecentFileName = current($list);
				$creationDate = filectime("{$this->destinationFolder}/{$mostRecentFileName}");
				$interval = $now - $creationDate;
				$maxInterval = max($maxInterval, $interval);
				
				if(filectime($targetSWFFilePath) > $creationDate) {				
					// the source file is newer--update everything
					$needUpdate = true;
				}
			} else {
				$needUpdate = true;
			}
		}
		if(!$needUpdate) {
			if($maxInterval > $this->updateInterval) {
				// at least one file is too old--update everything
				$needUpdate = true;
			}
			
		}
		if($needUpdate) {
			if($maxInterval > $this->maximumStaleInterval) {
				// we don't want to make a visitor wait normally, but the files are just too old
				$canUpdateInBackground = false;
			}
		}
		
		if($needUpdate) {
			$updatingInBackground = false;
			if($canUpdateInBackground) {
				// spawn a server thread to do the update
				$internalUrl = "http://{$_SERVER['HTTP_HOST']}:{$_SERVER['SERVER_PORT']}{$_SERVER['SCRIPT_NAME']}?update";
				$streamContextOptions = array (
					'http' => array (
						'method' => 'GET',
						'timeout' => 1
					)
				);
				$context = stream_context_create($streamContextOptions);
				if($f = fopen($internalUrl, "rb", 0, $context)) {
					fclose($f);
					$updatingInBackground = true;
				}
			} 
			if(!$updatingInBackground) {
				// update now
				$this->update();
			}
		}
		
		$destinationFileList = $this->destinationFileLists[$targetSWFFilePath];
		$mostRecentFileName = ($destinationFileList) ? current($destinationFileList) : null;
		$mostRecentVersion = ($destinationFileList) ? key($destinationFileList) : 0;
		$scriptRoot = dirname($_SERVER["SCRIPT_NAME"]);
		if($scriptRoot == '\\') {
			$scriptRoot = '/';
		}
		if($mostRecentFileName && $mostRecentVersion > 0) {
			$swfFileUrl = "{$scriptRoot}{$this->destinationFolder}/{$mostRecentFileName}";
		} else {
			$swfFileUrl = "{$scriptRoot}{$targetSWFFilePath}";
		}
		$this->redirect($swfFileUrl);
		
		ob_end_clean();
	}
	
	public function update() {
		$this->createDateModules();
		
		// initial the data transfer first
		foreach($this->dataModules as $dataModule) {
			$dataModule->startTransfer();
		}
		
		// parse the SWF files and find the text objects within them
		$this->parseSWFFiles();

		// ask the data modules to apply changes
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
		
		// reassemble the swf files 
		foreach($this->sourceFilePaths as $swfFilePath) {
			/// delete older files
			$lastVersion = 0;
			if($this->destinationFileLists == null) {
				$this->scanDestinationfolder();
			}
			$destinationFileList =& $this->destinationFileLists[$swfFilePath];
			if($destinationFileList) {
				$swfFileKept = 0;
				foreach($destinationFileList as $version => $existingSWFName) {
					if($swfFileKept == 0) {
						// keep one older file around so visitors aren't redirected to a missing file
						// the mostly recent one is the first in the list
						$swfFileKept++;
						$lastVersion = $version;
					} else {
						unlink("{$this->destinationFolder}/{$existingSWFName}");
					}
				}
			} else {
				$destinationFileList = array();
				$this->destinationFileLists[$swfFilePath] =& $destinationFileList;
			}

			// save contents into temporary location first
			$swfFileName = basename($swfFilePath);
			$swfFileExt = strrchr($swfFileName, '.');
			$swfFileExtLen = strlen($swfFileExt);
			$swfFileNamePart = substr($swfFileName, 0, strlen($swfFileName) - $swfFileExtLen);
			$swfFileNamePartLen = strlen($swfFileNamePart);
			$temporaryPath = "{$this->destinationFolder}/{$swfFileNamePart}.00000{$swfFileExt}";
			$output = fopen($temporaryPath, "wb");
			if($output) {
				$swfFile = $this->swfFiles[$swfFilePath];
				$this->swfAssembler->assemble($output, $swfFile);
				fclose($output);
				
				// move the temporary file into final location
				do {
					$lastVersion++;
					$number = sprintf("%0d", $lastVersion);
					$finalSWFFileName = "{$swfFileNamePart}.{$number}{$swfFileExt}";
					$finalPath = "{$this->destinationFolder}/{$finalSWFFileName}";
					$nameConflict = false;
					if(rename($temporaryPath, $finalPath)) {
						$destinationFileList[$lastVersion] = $finalSWFFileName;
						krsort($destinationFileList);
					} else {
						if(file_exists($finalPath)) {
							// race condition--try again
							$nameConflict = true;
						}
					}
				} while($nameConflict);
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
		
		echo "<div class='section-header'>SWF Generation</div>";
		if(file_exists($this->destinationFolder)) {
			$folder = realpath($this->destinationFolder);
			if(is_writable($this->destinationFolder)) {
				echo "<div class='subsection-ok'><b>Destination folder:</b> {$folder}</div>";
			} else {
				$status = 'err';
				$message = "";
				echo "<div class='subsection-err'><b>Destination folder:</b> {$folder} <em>(is not writable)</em></div>";		
			}
		} else {
			echo "<div class='subsection-err' style='align: center'><b>Destination folder:</b> \"{$this->destinationFolder}\" (does not exists)</div>";
		}
		$updateInterval = $this->formatSeconds($this->updateInterval);
		echo "<div class='subsection-ok'><b>Update interval:</b> {$updateInterval}</div>";
		$updateInBackground = ($this->updateInBackground) ? 'yes' : 'no';
		echo "<div class='subsection-ok'><b>Deferred update:</b> {$updateInBackground}</div>";
		$maximumStaleInterval = $this->formatSeconds($this->maximumStaleInterval);
		echo "<div class='subsection-ok'><b>Maximum stale interval:</b> {$maximumStaleInterval}</div>";
		echo "</div>";

		foreach($this->sourceFilePaths as $sourceFilePath) {
			$swfFileName = basename($sourceFilePath);
			echo "<div class='section'>";
			echo "<div class='section-header'>SWF File: $swfFileName</div>";
			if(file_exists($sourceFilePath)) {
				$fullPath = realpath($sourceFilePath);
				if(is_readable($sourceFilePath)) {
					echo "<div class='subsection-ok'><b>Full path:</b> {$fullPath}</div>";
					$filesize = sprintf("%0.1fK", filesize($sourceFilePath) / 1024.0);
					echo "<div class='subsection-ok'><b>File size:</b> {$filesize}</div>";
					$startTime = microtime(true);
					$input = fopen($sourceFilePath, "rb");
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
					echo "<div class='subsection-err'><b>Full path:</b> {$fullPath} <em>(is not readable)</em></div>";
				}
			} else {
					echo "<div class='subsection-err'><b>Full path:</b> \"$sourceFilePath\" <em>(does not exist)</em></div>";
			}
			echo "</div>";
		}
		
		foreach($this->dataModules as $dataModuleName => $dataModule) {
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
		foreach($this->dataModules as $dataModule) {
			if($dataModule->getExportType()) {
				$exportingModules[] = $dataModule;
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
	
	protected function createDateModules() {
		$this->dataModules = array();
		foreach($this->dataModuleConfigs as $dataModuleName => $dataModuleConfig) {
			$dataModuleName = trim($dataModuleName);
			$dataModule = class_exists($dataModuleName, true) ? new $dataModuleName($dataModuleConfig) : null;
			$this->dataModules[$dataModuleName] = $dataModule;
		}
	}
	
	protected function parseSWFFiles() {
		$textObjectLists = array();
		$fontFamilyLists = array();
		$this->swfFiles = array();
		foreach($this->sourceFilePaths as $swfFilePath) {			
			$input = fopen($swfFilePath, "rb");			
			if($input) {
				$this->swfFiles[$swfFilePath] = $swfFile = $this->swfParser->parse($input);
				$textObjectLists[] = $this->textFinder->find($swfFile);
				$fontFamilyLists[] = $this->fontFinder->find($swfFile);
				fclose($input);
			}
		}
		$this->textObjects = call_user_func_array('array_merge', $textObjectLists);
		$this->fontFamilies = call_user_func_array('array_merge', $fontFamilyLists);
		uasort($this->textObjects, array($this, 'compareTextObjectNames'));
	}
	
	protected function scanDestinationFolder() {
		$this->destinationFileLists = array();
		$existingFiles = scandir($this->destinationFolder);
		
		// associate files in the destination folder with source files from which there're created
		foreach($this->sourceFilePaths as $swfFilePath) {
			$swfFileName = basename($swfFilePath);
			$swfFileExt = strrchr($swfFileName, '.');
			$swfFileExtLen = strlen($swfFileExt);
			$swfFileNamePart = substr($swfFileName, 0, strlen($swfFileName) - $swfFileExtLen);
			$swfFileNamePartLen = strlen($swfFileNamePart);
			
			foreach($existingFiles as $existingFile) {
				// see if the beginning and end match
				if(substr($existingFile, 0, $swfFileNamePartLen) == $swfFileNamePart && substr($existingFile, -$swfFileExtLen) == $swfFileExt) {
					if($swfFileName[$swfFileNamePartLen] == '.') {
						// filename should be [filename].#####.swf
						$number = intval(substr($existingFile, $swfFileNamePartLen + 1, -$swfFileExtLen));
						if($number > 0) {
							$destinationFileList =& $this->destinationFileLists[$swfFilePath];
							$destinationFileList[$number] = $existingFile;
						}
					}
				}
			}
		}		
		foreach($this->destinationFileLists as &$destinationFileList) {
			// make the more recent one come first
			krsort($destinationFileList);
		}
	}
	
	protected function redirect($url) {
		header("Location: $url");
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