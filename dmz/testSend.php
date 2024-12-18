<?php

ini_set('log_errors', 'Off');
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $timestamp = "[" . date("d-M-Y H:i:s") . "]";
    $local_error = "$timestamp [MALIN_VM] $errstr in $errfile on line $errline";
    file_put_contents(
        '/home/malin/Desktop/Error_Log/php-error.log',
        $local_error . PHP_EOL,
        FILE_APPEND
    );
    return true;
});

// This file is mainly just for testing communication to RabbitMQ

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}

try {
    $config = getRabbitMQConfig();

    $connection = new AMQPStreamConnection(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password'],
        $config['vhost']
    );
    $channel = $connection->channel();

    $queueTest = 'test1';
    $channel->queue_declare($queueTest, false, false, false, false);

    $testMessage = 'Hello Rudys!';
    $msg = new AMQPMessage($testMessage);
    $channel->basic_publish($msg, '', $queueTest);

    echo " [x] Sent '$testMessage'\n";

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
