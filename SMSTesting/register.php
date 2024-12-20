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
    if (isset($_POST['uname'], $_POST['pword'], $_POST['email'], $_POST['phone'], $_POST['jobTitle'], $_POST['location'])) {
        $username = $_POST['uname'];
        $password = $_POST['pword']; 
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $jobTitle = $_POST['jobTitle'];
        $location = $_POST['location'];

     
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

       
        $config = getRabbitMQConfig();

        
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

        $channel->queue_declare('webMsg', false, false, false, false);

        
        $messageData = json_encode([
            'username' => $username,
            'password' => $hashedPassword, 
            'email' => $email,
            'phone' => $phone,
            'jobTitle' => $jobTitle,
            'location' => $location
        ]);

        $msg = new AMQPMessage($messageData);

    
        $channel->basic_publish($msg, '', 'webMsg');

      
        $responseQueue = 'responseRegister';
        $channel->queue_declare($responseQueue, false, false, false, false);

        $registrationResponse = waitForResponse($channel, $responseQueue);

     
        $channel->close();
        $connection->close();

     
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
