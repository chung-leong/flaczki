<?php

class StreamWrapperStaticStorage {

	private static $records = array();
	private static $counter = 0;

	public static function add($protocol, $record) {
		$index = ++self::$counter;
		$path = "$protocol://$index";
		self::$records[$path] = $record;
		return $path;
	}
	
	public static function get($path) {
		return isset(self::$records[$path]) ? self::$records[$path] : null;
	}
	
	public static function remove($path) {
		unset(self::$records[$path]);
	}
}

?>