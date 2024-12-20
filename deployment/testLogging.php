<?php
ini_set('log_errors', 'On');
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $timestamp = "[" . date("d-M-Y H:i:s") . "]";
    $local_error = "$timestamp [YAMAN_VM] $errstr in $errfile on line $errline";
    file_put_contents(
        '/home/yh36/Desktop/Error_Log/php-error-prod.log',
        $local_error . PHP_EOL,
        FILE_APPEND
    );
    return true;
});

// This file is mainly just for testing if logging works

echo $undefined_variable;

?>
