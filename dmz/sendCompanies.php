<?php

ini_set('log_errors', 'On');
ini_set('error_log', '/home/malin/Desktop/Error_Log/php-error.log');

ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

// THIS PHP FILE SENDS COMPANY INFORMATION TO THE DATABASE VIA CSV FILE

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

$csvFile = 'companies.csv';
$companies = [];

if (($handle = fopen($csvFile, 'r')) !== FALSE) {
    $headers = fgetcsv($handle, 1000, ',');

    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
        $companies[] = array_combine($headers, $data);
    }

    fclose($handle);
} else {
    echo json_encode(['error' => 'Unable to open the CSV file.']);
    exit;
}

$jsonData = json_encode($companies, JSON_PRETTY_PRINT);

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
    $queueName = 'test2';

    $channel->queue_declare($queueName, false, false, false, false);

    $message = new AMQPMessage($jsonData);
    $channel->basic_publish($message, '', $queueName);

    echo " [x] Company data sent to RabbitMQ\n";

    $channel->close();
    $connection->close();

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
