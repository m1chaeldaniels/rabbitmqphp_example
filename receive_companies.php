<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('test2', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) {
    echo ' [x] Received ', $msg->getBody(), "\n";

    $data = json_decode($msg->getBody(), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error decoding JSON: ", json_last_error_msg(), "\n";
        return;
    }

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo "Database connection failed: " . $mysqli->connect_error . "\n";
        return;
    }

    $sql = "INSERT INTO company_info (name, industry, headquarters, employee_size, website_link, linkedin, company_description) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);

    if ($stmt === false) {
        echo "Error preparing statement: " . $mysqli->error . "\n";
        $mysqli->close();
        return;
    }

    foreach ($data as $company) {
        if (!isset($company['Name'], $company['Industry'], $company['Headquarters'], $company['Employee Size'], $company['Website Link'], $company['LinkedIn'], $company['Description'])) {
            echo "Incomplete data for company entry\n";
            continue;
        }

        if (isCompanyExists($mysqli, $company['Name'])) {
            echo "Company already exists: " . $company['Name'] . "\n";
            continue;
        }

        $stmt->bind_param(
            "sssssss",
            $company['Name'],
            $company['Industry'],
            $company['Headquarters'],
            $company['Employee Size'],
            $company['Website Link'],
            $company['LinkedIn'],
            $company['Description']
        );

        // Execute the statement
        if ($stmt->execute()) {
            echo "Company info added: " . $company['Name'] . "\n";
        } else {
            echo "Failed to add company info: " . $company['Name'] . "\n";
        }
    }

    $stmt->close();
    $mysqli->close();
};

//check if a company  exists
function isCompanyExists($mysqli, $companyName) {
    $sql = "SELECT 1 FROM company_info WHERE name = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt === false) {
        echo "Error preparing select statement: " . $mysqli->error . "\n";
        return false;
    }

    $stmt->bind_param("s", $companyName);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

$channel->basic_consume('test2', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
