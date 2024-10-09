<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

$mysqli = new mysqli("localhost", "testUser", "12345", "testdb");

// Check MySQL connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('reg_queue', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) {
	$data = json_decode($msg->body, true);
	$username = $data['username'];
	$password = $data['password'];

	echo ' [x] Received ', $msg->getBody(), "\n";
	
	$stmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
	$stmt->bind_param("s", $username);
	$stmt->execute();
	$stmt->bind_result($count);
	$stmt->fetch();
	$stmt->close();

	if ($count === 0) {
		$stmt = $mysqli->prepare("INSERT INTO users(username, password_hash) VALUES (?, ?)");
	        $stmt->bind_param("ss", $username, $password);
        	$stmt->execute();
		$stmt->close();
		echo "inserted user: $username \n";

	} else {
		echo "User $username already exists \n";
	}
};
	$mysqli->close();

	$channel->basic_consume('reg_queue', '', false, false, false, false, $callback);

	try {
	    $channel->consume();
	} catch (\Throwable $exception) {
	echo $exception->getMessage();
	}	

	$channel->close();
	$connection->close();

?>
