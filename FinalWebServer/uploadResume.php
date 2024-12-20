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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['resume'], $_POST['username'])) {
    try {
        $username = $_POST['username'];
        $resume = $_FILES['resume'];

        
        if ($resume['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error.');
        }

        
        $fileContent = file_get_contents($resume['tmp_name']);
        $fileName = $resume['name'];

     
        $config = getRabbitMQConfig();
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

       
        $channel->queue_declare('resumeQueue', false, false, false, false);

     
        $messageData = json_encode([
            'username' => $username,
            'file_name' => $fileName,
            'file_content' => base64_encode($fileContent) 
        ]);
        $msg = new AMQPMessage($messageData);
        $channel->basic_publish($msg, '', 'resumeQueue');

        
        $channel->close();
        $connection->close();

        echo json_encode(['success' => true, 'message' => 'Resume uploaded successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}

?>
