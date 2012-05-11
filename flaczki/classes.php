<?php

function __flaczki_autoload($class_name) {
    require "$class_name.php";
}

spl_autoload_register('__flaczki_autoload');

?>