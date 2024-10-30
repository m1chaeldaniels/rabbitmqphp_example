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

if (isset($_POST['companyName']) && isset($_POST['username']) && isset($_POST['rating']) && isset($_POST['reviewText'])) {
    $name = $_POST['companyName'];
    $username = $_POST['username'];
    $rating = $_POST['rating'];
    $reviewText = $_POST['reviewText'];

    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    $channel->queue_declare('submitReview', false, false, false, false);

    $messageData = json_encode([
        'companyName' => $name,
        'username' => $username,
        'rating' => $rating,
        'reviewText' => $reviewText
    ]);

    $msg = new AMQPMessage($messageData);

    $channel->basic_publish($msg, '', 'submitReview');

    $responseQueue = 'responseSubmitReview'; 
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
