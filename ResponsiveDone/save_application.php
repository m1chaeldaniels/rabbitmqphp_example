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
    if (isset($_POST['username'], $_POST['id'])) {
        $username = $_POST['username'];
        $id = $_POST['id'];

        $config = getRabbitMQConfig();

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );

        $channel = $connection->channel();
        $channel->queue_declare('trackAppliedJob', false, false, false, false);

        $messageData = json_encode([
            'username' => $username,
            'id' => $id
        ]);

        $msg = new AMQPMessage($messageData);
        $channel->basic_publish($msg, '', 'trackAppliedJob');

        $responseQueue = 'responseTrackAppliedJob';
        $channel->queue_declare($responseQueue, false, false, false, false);
        $response = waitForResponse($channel, $responseQueue);

        $channel->close();
        $connection->close();

        if ($response) {
            echo json_encode($response);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save application.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
