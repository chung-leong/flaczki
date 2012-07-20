<?php

class SWFImageUpdaterFolder extends SWFImageUpdater {

	protected $folderPath;
	protected $canUseGD;

	public function __construct($folderPath) {
		$this->folderPath = $folderPath;
		$this->canUseGD = function_exists('imagepng');
	}

	public function update($assets) {
		$dir = opendir($this->folderPath);
		while($file = readdir($dir)) {
			if(preg_match('/(\.jpg|\.jpeg|\.png|\.gif)$/i', $file, $m)) {
				$extension = $m[1];
				$namePart = substr($file, 0, - strlen($extension));
				
				// get the data
				$path = "{$this->folderPath}/{$file}";
				$data = file_get_contents($path);
				
				foreach($assets->images as $image) {
					if($image->name == $namePart) {
						$image->data = $data;
						$image->changed = true;
					}
				}
			}
		}
		closedir($dir);	
	}

	public function getImageNames() {
		$imageNames = array();
		$dir = opendir($this->folderPath);
		while($file = readdir($dir)) {
			if(preg_match('/(\.jpg|\.jpeg|\.png|\.gif)$/i', $file, $m)) {
				$extension = $m[1];
				$namePart = substr($file, 0, - strlen($extension));
				$imageNames[] = $namePart;
			}
		}
		closedir($dir);	
		return $imageNames;
	}
}

?>