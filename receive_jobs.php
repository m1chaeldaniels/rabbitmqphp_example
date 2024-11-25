#!/usr/bin/php
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

try {
    $config = getRabbitMQConfig();

    $connection = new AMQPStreamConnection(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password'],
        $config['vhost']
    );
    $channel = $connection->channel();

    $queueTest1 = 'test1';
    $queueAlert = 'alertQueue';

    $channel->queue_declare($queueTest1, false, false, false, false);
    $channel->queue_declare($queueAlert, false, false, false, false);

    echo " [*] Waiting for messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueAlert) {
        echo ' [x] Received ', $msg->getBody(), "\n";

        $jobsData = json_decode($msg->getBody(), true);

        if (!is_array($jobsData)) {
            echo "Invalid data format.\n";
            return;
        }

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            return;
        }

        $stmt = $mysqli->prepare("
            INSERT INTO total_jobs (locations, site, date, url, title, description, company, salary, salary_min, salary_max, salary_type, salary_currency_code, jobAddedTime)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error . "\n";
            $mysqli->close();
            return;
        }

        $usersToNotify = []; 

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
            $jobAddedTime = date('Y-m-d H:i:s'); 

            $checkStmt = $mysqli->prepare("
                SELECT id FROM total_jobs 
                WHERE title = ? AND locations = ? AND company = ? AND date = ?
            ");
            $checkStmt->bind_param("ssss", $title, $locations, $company, $date);
            $checkStmt->execute();
            $checkStmt->store_result();

            if ($checkStmt->num_rows > 0) {
                echo "Duplicate job detected: $title at $locations for $company on $date\n";
                $checkStmt->close();
                continue; 
            }
            $checkStmt->close();

            $stmt->bind_param("sssssssssssss", $locations, $site, $date, $url, $title, $description, $company, $salary, $salary_min, $salary_max, $salary_type, $salary_currency_code, $jobAddedTime);

            if ($stmt->execute()) {
                echo "Job inserted: $title | Added at: $jobAddedTime\n";

                $alertQuery = "
                    SELECT u.username, u.email, up.jobTitle, up.location
                    FROM user_preferences up
                    JOIN users u ON up.user_id = u.id
                    WHERE ? LIKE CONCAT('%', up.jobTitle, '%')
                    AND ? LIKE CONCAT('%', up.location, '%')
                ";
                $alertStmt = $mysqli->prepare($alertQuery);

                if ($alertStmt) {
                    $alertStmt->bind_param("ss", $title, $locations);
                    $alertStmt->execute();
                    $result = $alertStmt->get_result();

                    while ($row = $result->fetch_assoc()) {
                        $username = $row['username'];
                        $email = $row['email'];
                        $usersToNotify[] = ['username' => $username, 'email' => $email];
                        echo "User matched: $username ($email) for job title: $title in location: $locations\n";
                    }

                    $alertStmt->close();
                } else {
                    echo "Failed to prepare alert query: (" . $mysqli->errno . ") " . $mysqli->error . "\n";
                }
            } else {
                echo "Failed to insert job: " . $stmt->error . "\n";
            }
        }

        if (!empty($usersToNotify)) {
            $messageBody = json_encode(["usersToNotify" => $usersToNotify]);
            $message = new AMQPMessage($messageBody);

            $channel->basic_publish($message, '', $queueAlert);
            echo " [x] Sent alert to alertQueue: $messageBody\n";
        }

        $stmt->close();
        $mysqli->close();
    };

    $channel->basic_consume($queueTest1, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
