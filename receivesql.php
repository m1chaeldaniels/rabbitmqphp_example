<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('webMsg', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg){
	$data = json_decode($msg->body, true);
	if (!isset($data['username'], $data['password'])) {
	echo "Invalid message format\n";
	return;
    	}

    $username = $data['username'];
    $password = $data['password'];

    echo ' [x] Received ', $msg->getBody(), "\n";

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
	    die("connection failed: " . $mysqli->connect_error);
    }
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        $stmt = $mysqli->prepare("INSERT INTO users(username, password_hash) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $password);

        if ($stmt->execute()) {
            echo "Inserted user: $username \n";
        } else {
            echo "Failed to insert user: " . $stmt->error . "\n";
        }
        $stmt->close();
    } else {
        echo "User $username already exists \n";
    }


};

$channel->basic_consume('webMsg', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$mysqli->close();
$channel->close();
$connection->close();
