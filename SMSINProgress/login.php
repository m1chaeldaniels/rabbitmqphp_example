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

    $callback = function($msg) use (&$response) {
        $response = json_decode($msg->body, true);
    };

    $channel->basic_consume($responseQueue, '', false, true, false, false, $callback);

    while ($channel->is_consuming() && !$response) {
        $channel->wait();
    }

    return $response;
}

try {
    if (isset($_POST['uname'], $_POST['pword'])) {
        $username = $_POST['uname'];
        $plaintextPassword = $_POST['pword'];

     
        $config = getRabbitMQConfig();

        
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

        
        $channel->queue_declare('login', false, false, false, false);

        
        $messageData = json_encode(['username' => $username]);
        $msg = new AMQPMessage($messageData);
        $channel->basic_publish($msg, '', 'login');

     
        $responseQueue = 'responseLogin';
        $channel->queue_declare($responseQueue, false, false, false, false);

        $loginResponse = waitForResponse($channel, $responseQueue);

        
        $channel->close();
        $connection->close();


        if ($loginResponse && $loginResponse['success'] === true) {
            $hashedPassword = $loginResponse['password']; 

            
            if (password_verify($plaintextPassword, $hashedPassword)) {
             
                echo json_encode([
                    'success' => true,
                    'message' => 'Login successful',
                    'username' => $loginResponse['username'],
                    'email' => $loginResponse['email'],
                    'jobTitle' => $loginResponse['jobTitle'],
                    'location' => $loginResponse['location']
                ]);
            } else {
        
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid password'
                ]);
            }
        } else {
            
            echo json_encode([
                'success' => false,
                'message' => 'User not found or server error'
            ]);
        }
    } else {
        echo json_encode(['error' => 'Username and password are required']);
    }
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception->getMessage()]);
}

?>
