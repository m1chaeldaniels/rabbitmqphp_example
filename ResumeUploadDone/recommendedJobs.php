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
    if (isset($_POST['username'])) {
        $username = $_POST['username'];

   
        $config = getRabbitMQConfig();

       
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

      
        $channel->queue_declare('recommendJobs', false, false, false, false);

        $messageData = json_encode([
            'username' => $username
        ]);

        $msg = new AMQPMessage($messageData);

     
        $channel->basic_publish($msg, '', 'recommendJobs');

       
        $responseQueue = 'responseRecommendJobs'; 
        $channel->queue_declare($responseQueue, false, false, false, false);

      
        $recommendedResponse = waitForResponse($channel, $responseQueue);

       
        $channel->close();
        $connection->close();

        
        if ($recommendedResponse) {
            echo json_encode($recommendedResponse);
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