<?php

function __flaczki_autoload($class_name) {
    require "$class_name.php";
}

spl_autoload_register('__flaczki_autoload');

function trace($s) {
	//echo $s;
}

function dump($s) {
	echo nl2br(htmlspecialchars(var_dump($s)));
}

function backtrace_error_handler($errno, $errstr, $errfile, $errline) {
	$errorType = array (
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
        
	echo "<b>{$errorType[$errno]}</b>: $errstr in $errfile on $errline<br>";		
	$trace = debug_backtrace();
	for($i = 1; $i < count($trace); $i++) {
		$function = $trace[$i]['function'];
		echo "$function()<br>";
	}	
}

set_error_handler('backtrace_error_handler');

?>