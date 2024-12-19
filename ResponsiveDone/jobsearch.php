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
    if (isset($_POST['title']) && isset($_POST['location'])) {
        $title = $_POST['title'];
        $location = $_POST['location']; 

       
        $config = getRabbitMQConfig();

       
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

  
        $channel->queue_declare('jobSearch', false, false, false, false);

        $messageData = json_encode([
            'jobTitle' => $title,
            'location' => $location,
        ]);

        $msg = new AMQPMessage($messageData);

       
        $channel->basic_publish($msg, '', 'jobSearch');

      
        $responseQueue = 'responseJobSearch'; 
        $channel->queue_declare($responseQueue, false, false, false, false);

      
        $jobSearchResponse = waitForResponse($channel, $responseQueue);

       
        $channel->close();
        $connection->close();

        
        if ($jobSearchResponse) {
            echo json_encode($jobSearchResponse);
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