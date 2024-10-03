<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$mysqli = new mysqli("localhost", "testUser", "12345", "testdb");

// Check MySQL connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}


$connection = new AMQPStreamConnection('localhost', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('your_queue', false, false, false, false);

$sql = "SELECT name FROM students";
$result = $mysqli->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $messageBody = $row['name']; // Modify as needed
        $msg = new AMQPMessage($messageBody);
        $channel->basic_publish($msg, '', 'your_queue');
        echo "Sent: $messageBody\n";
    }
} else {
    echo "No results found.";
}

// Clean up
$mysqli->close();
$channel->close();
$connection->close();
?>
