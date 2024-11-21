#!/usr/bin/php
<?php

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
    // Load RabbitMQ configuration
    $config = getRabbitMQConfig();

    // RabbitMQ connection settings
    $connection = new AMQPStreamConnection(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password'],
        $config['vhost']
    );
    $channel = $connection->channel();

    // Declare queue
    $queueTest = 'test1';
    $channel->queue_declare($queueTest, false, false, false, false);

    // Test message
    $testMessage = 'Hello Rudys!';
    $msg = new AMQPMessage($testMessage);
    $channel->basic_publish($msg, '', $queueTest);

    echo " [x] Sent '$testMessage'\n";

    // Close channel and connection
    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
