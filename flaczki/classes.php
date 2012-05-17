<?php

function __flaczki_autoload($class_name) {
    include "$class_name.php";
}

spl_autoload_register('__flaczki_autoload');

function trace($s) {
	//echo $s;
}

function dump($v) {
	echo str_replace("=>","&#8658;",str_replace("Array","<font color=\"red\"><b>Array</b></font>",nl2br(str_replace(" "," &nbsp; ",htmlspecialchars(print_r($v,true))))));
}

function backtrace_error_handler($errno, $errstr, $errfile, $errline) {
	static $errtype = array (
               E_ERROR            => 'ERROR',
               E_WARNING        => 'WARNING',
               E_PARSE          => 'PARSING ERROR',
               E_NOTICE         => 'NOTICE',
               E_CORE_ERROR     => 'CORE ERROR',
               E_CORE_WARNING   => 'CORE WARNING',
               E_COMPILE_ERROR  => 'COMPILE ERROR',
               E_COMPILE_WARNING => 'COMPILE WARNING',
               E_USER_ERROR     => 'USER ERROR',
               E_USER_WARNING   => 'USER WARNING',
               E_USER_NOTICE    => 'USER NOTICE',
               E_STRICT         => 'STRICT NOTICE',
               E_RECOVERABLE_ERROR  => 'RECOVERABLE ERROR'
               );
        
        if($errno & error_reporting()) {
		echo "<b>{$errtype[$errno]}</b>: $errstr in $errfile on $errline<br>";		
		$trace = debug_backtrace();
		for($i = 1; $i < count($trace); $i++) {
			$function = $trace[$i]['function'];
			echo "$function()<br>";
		}
	}
}

set_error_handler('backtrace_error_handler');

?>