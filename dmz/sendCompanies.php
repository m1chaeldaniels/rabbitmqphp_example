<?php
require_once __DIR__ . '/vendor/autoload.php'; // Include the Composer autoloader
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Path to the CSV file
$csvFile = 'companies.csv';

// Initialize an empty array to store the data
$companies = [];

// Open the CSV file
if (($handle = fopen($csvFile, 'r')) !== FALSE) {
    // Get the column headers
    $headers = fgetcsv($handle, 1000, ',');

    // Read through each row of the CSV
    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
        // Combine headers with data to create an associative array
        $companies[] = array_combine($headers, $data);
    }

    // Close the file
    fclose($handle);
} else {
    echo json_encode(['error' => 'Unable to open the CSV file.']);
    exit;
}

// Encode the data as JSON
$jsonData = json_encode($companies, JSON_PRETTY_PRINT);



try {
    // Establish connection to RabbitMQ
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue
    $channel->queue_declare('test2', false, false, false, false);

    // Create a new message with the JSON data
    $message = new AMQPMessage($jsonData);

    // Publish the message to the queue
    $channel->basic_publish($message, '', 'test2');

    echo " [x] Job data sent to RabbitMQ\n";

    // Close the channel and the connection
    $channel->close();
    $connection->close();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>
