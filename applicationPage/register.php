<?php

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

function waitForResponse($channel, $responseQueue) {
    $response = null;

    $callback = function ($msg) use (&$response) {
        $response = json_decode($msg->body, true);
    };

    $channel->basic_consume($responseQueue, '', false, true, false, false, $callback);

    while ($channel->is_consuming() && !$response) {
        $channel->wait();
    }

    return $response;
}

try {
    if (isset($_POST['uname'], $_POST['pword'], $_POST['email'], $_POST['jobTitle'], $_POST['location'])) {
        $username = $_POST['uname'];
        $password = $_POST['pword']; // Plaintext password from the client
        $email = $_POST['email'];
        $jobTitle = $_POST['jobTitle'];
        $location = $_POST['location'];

        // Hash the password securely
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Load RabbitMQ config
        $config = getRabbitMQConfig();

        // Establish RabbitMQ connection
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

        $channel->queue_declare('webMsg', false, false, false, false);

        // Prepare the message data with hashed password
        $messageData = json_encode([
            'username' => $username,
            'password' => $hashedPassword, // Send the hashed password
            'email' => $email,
            'jobTitle' => $jobTitle,
            'location' => $location
        ]);

        $msg = new AMQPMessage($messageData);

        // Publish message to RabbitMQ
        $channel->basic_publish($msg, '', 'webMsg');

        // Wait for response
        $responseQueue = 'responseRegister';
        $channel->queue_declare($responseQueue, false, false, false, false);

        $registrationResponse = waitForResponse($channel, $responseQueue);

        // Close connection
        $channel->close();
        $connection->close();

        // Return the response
        if ($registrationResponse) {
            echo json_encode($registrationResponse);
        } else {
            echo json_encode(['error' => 'No response from the server.']);
        }
    } else {
        echo json_encode(['error' => 'No data provided for RabbitMQ processing.']);
    }
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception->getMessage()]);
}

?>
