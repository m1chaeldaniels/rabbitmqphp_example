<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('login', false, false, false, false);
$channel->queue_declare('responseLogin', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel){
        $data = json_decode($msg->body, true);
        if (!isset($data['username'])) {
        	echo "Invalid message format\n";
		sendMessage($channel, false, "Invalud message format");
		return;
        }

    $username = $data['username'];

    echo ' [x] Received ', $msg->getBody(), "\n";
    
    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
            die("connection failed: " . $mysqli->connect_error);
    }

    $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($hashedPassword);
    $stmt->fetch();
    $stmt->close();

    if ($hashedPassword) {
	
	/*
        $responseData = [
            'username' => $username,
            'password_hash' => $hashedPassword,
	];*/

	echo "Sent hashed password for user: $username \n";
	sendMessage($channel, true, 'User Hashed Password: ' . $hashedPassword);
	
	/*
        $msg = new AMQPMessage(json_encode($responseData));
        $channel->basic_publish(($msg, '', 'responseQueue');
	 */

    } else {
	echo "User $username does not exist \n";
	sendMessage($channel, false, 'User Does Not Exist: ' . $username);
    }

    $mysqli->close();

};



function sendMessage($channel, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message,
    ];
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseLogin'); // Send message to the response queue
}








$channel->basic_consume('login', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();
