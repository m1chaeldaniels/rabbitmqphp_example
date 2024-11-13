<?php

// THIS PHP FIND SENDS COMPANY INFORMATION TO THE DATABASE VIA CSV FILE

require_once __DIR__ . '/vendor/autoload.php'; 
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

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
    $connection = new AMQPStreamConnection('172.29.29.174', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    $channel->queue_declare('test2', false, false, false, false);

    $message = new AMQPMessage($jsonData);

    $channel->basic_publish($message, '', 'test2');

    echo " [x] Job data sent to RabbitMQ\n";

    $channel->close();
    $connection->close();

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>
