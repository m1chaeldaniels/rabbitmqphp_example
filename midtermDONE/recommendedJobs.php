<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

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

if (isset($_POST['username'])) {
    $username = $_POST['username'];

    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
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

?>