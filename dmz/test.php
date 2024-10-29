<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


$test = 'Hello Rudys!';

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('test1', false, false, false, false);

$msg = new AMQPMessage($test);
$channel->basic_publish($msg, '', 'test1');

echo " [x] Sent 'Hello World!'\n";

$channel->close();
$connection->close();

?>