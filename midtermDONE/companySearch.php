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

if (isset($_POST['companyName'])) {
    $name = $_POST['companyName'];
    
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    $channel->queue_declare('companySearch', false, false, false, false);

    $messageData = json_encode([
        'companyName' => $name,
    ]);

    $msg = new AMQPMessage($messageData);

    $channel->basic_publish($msg, '', 'companySearch');

    $responseQueue = 'responseCompanySearch'; 
    $channel->queue_declare($responseQueue, false, false, false, false);

    $responseCompanySearch = waitForResponse($channel, $responseQueue);

    $channel->close();
    $connection->close();

    if ($responseCompanySearch) {
        echo json_encode($responseCompanySearch);
    } else {
        echo json_encode(['error' => 'No response from the server.']);
    }

} else {
    echo json_encode(['error' => 'No data provided for RabbitMQ processing.']);
}

?>
