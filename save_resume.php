<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}

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

    $queue = 'resumeQueue';
    $channel->queue_declare($queue, false, false, false, false);

    echo " [*] Waiting for resume upload messages. To exit press CTRL+C\n";

    $callback = function ($msg) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'], $data['file_name'], $data['file_content'])) {
            echo "Invalid message format\n";
            return;
        }

        $username = $data['username'];
        $fileName = $data['file_name'];
        $fileData = base64_decode($data['file_content']);

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            return;
        }

        $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        if (!$userId) {
            echo "User not found for Username: $username\n";
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("INSERT INTO user_resumes (user_id, filename, file_data) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $fileName, $fileData);

        if ($stmt->execute()) {
            echo "Resume saved for User ID: $userId\n";
        } else {
            echo "Failed to save resume\n";
        }

        $stmt->close();
        $mysqli->close();
    };

    $channel->basic_consume($queue, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
