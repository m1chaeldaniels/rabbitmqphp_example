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
    if (isset($_POST['session_token'])) {
        $sessionToken = $_POST['session_token'];

       
        $config = getRabbitMQConfig();

       
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

      
        $channel->queue_declare('logoutQueue', false, false, false, false);

       
        $messageData = json_encode([
            'session_token' => $sessionToken
        ]);

        $msg = new AMQPMessage($messageData);

 
        $channel->basic_publish($msg, '', 'logoutQueue');

        
        $responseQueue = 'responseLogout';
        $channel->queue_declare($responseQueue, false, false, false, false);

       
        $response = waitForResponse($channel, $responseQueue);

        
        $channel->close();
        $connection->close();

        
        if ($response && $response['success']) {
            echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Logout failed.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No session token provided.']);
    }
} catch (Throwable $exception) {
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}

?>