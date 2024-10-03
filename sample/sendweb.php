<?php
include 'test.php';

require_once __DIR__ . '/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('webMsg', false, false, false, false);

$msg = new AMQPMessage($userY);
$channel->basic_publish($msg, '', 'webMsg');

echo " [x] Sent Web Message'\n";

$channel->close();
$connection->close();
?>
