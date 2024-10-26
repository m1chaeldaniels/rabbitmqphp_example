<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('test1', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) {
    echo ' [x] Received ', $msg->getBody(), "\n";

    $jobsData = json_decode($msg->getBody(), true);

    if (!is_array($jobsData)) {
        echo "Invalid data format.\n";
        return;
    }

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // Create insert statement
    $stmt = $mysqli->prepare("
        INSERT INTO total_jobs (locations, site, date, url, title, description, company, salary, salary_min, salary_max, salary_type, salary_currency_code)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error . "\n";
        $mysqli->close();
        return;
    }

    // insert the jobs into the table
    foreach ($jobsData as $job) {
        $locations = $job['locations'] ?? '';
        $site = $job['site'] ?? '';
        $date = isset($job['date']) ? date('Y-m-d H:i:s', strtotime($job['date'])) : null;
        $url = $job['url'] ?? '';
        $title = $job['title'] ?? '';
        $description = $job['description'] ?? '';
        $company = $job['company'] ?? '';
        $salary = $job['salary'] ?? '';
        $salary_min = $job['salary_min'] ?? '';
        $salary_max = $job['salary_max'] ?? '';
        $salary_type = $job['salary_type'] ?? '';
        $salary_currency_code = $job['salary_currency_code'] ?? '';

        $stmt->bind_param("ssssssssssss", $locations, $site, $date, $url, $title, $description, $company, $salary, $salary_min, $salary_max, $salary_type, $salary_currency_code);

        if ($stmt->execute()) {
            echo "Job inserted: $title\n";
        } else {
            echo "Failed to insert job: " . $stmt->error . "\n";
        }
    }

    $stmt->close();
    $mysqli->close();
};

$channel->basic_consume('test1', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
