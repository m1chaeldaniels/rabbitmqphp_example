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


function generateSessionToken() {
    return bin2hex(random_bytes(32)); 
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

if (isset($_POST['uname'])) {
    $username = $_POST['uname'];

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

 
        $sessionToken = generateSessionToken();
        $tokenExpiry = time() + 3600; 

      
        $channel->queue_declare('sessionTokenQueue', false, false, false, false);


        $messageData = json_encode([
            'username' => $username,
            'session_token' => $sessionToken,
            'token_expiry' => $tokenExpiry
        ]);

        $msg = new AMQPMessage($messageData);

       
        $channel->basic_publish($msg, '', 'sessionTokenQueue');

   
        $responseQueue = 'responseSessionToken';
        $channel->queue_declare($responseQueue, false, false, false, false);

     
        $response = waitForResponse($channel, $responseQueue);

    
        $channel->close();
        $connection->close();

        
        if ($response && $response['success']) {
            echo json_encode([
                'success' => $response['success'],
                'session_token' => $sessionToken,
                'token_expiry' => $tokenExpiry,
                'message' => $response['message'],
                'email' => $response['email'],
                'phone' => $response['phone'],
                'jobTitle' => $response['jobTitle'],
                'location' => $response['location'],
                'username' => $response['username'],
                'alerts' => $response['alerts']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to store session token in the database.']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'No username provided.']);
}

?>