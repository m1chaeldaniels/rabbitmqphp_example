<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('companySearch', false, false, false, false);
$channel->queue_declare('responseCompanySearch', false, false, false, false);

echo " [*] Waiting for company search messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    echo ' [x] Received ', $msg->getBody(), "\n";

    $data = json_decode($msg->getBody(), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['companyName'])) {
        echo "Error decoding JSON or missing 'companyName' field\n";
        sendResponse($channel, false, "Invalid message format");
        return;
    }

    $searchTerm = $data['companyName'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo "Database connection failed: " . $mysqli->connect_error . "\n";
        sendResponse($channel, false, "Database connection failed");
        return;
    }

    $sql = "SELECT * FROM company_info WHERE name LIKE CONCAT('%', ?, '%')";
    $stmt = $mysqli->prepare($sql);

    if ($stmt === false) {
        echo "Error preparing statement: " . $mysqli->error . "\n";
        sendResponse($channel, false, "Error preparing statement");
        $mysqli->close();
        return;
    }

    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();

    $companies = [];

    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $stmt->close();
    $mysqli->close();

    if (!empty($companies)) {
        sendResponse($channel, true, "Matching companies found", $companies);
    } else {
        sendResponse($channel, false, "No matching companies found");
    }
};

function sendResponse($channel, $success, $message, $companies = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'companies' => $companies,
    ];

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseCompanySearch');
}

$channel->basic_consume('companySearch', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
