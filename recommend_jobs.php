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

function sendResponse($channel, $queue, $success, $message, $jobs) {
    $response = [
        'success' => $success,
        'message' => $message,
        'jobs' => $jobs
    ];

    $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        echo "JSON Encoding Error: $jsonError\n";
        echo "Response Data: " . print_r($response, true) . "\n";
        return;
    }

    echo "Sending JSON Response: $jsonResponse\n";

    $msg = new AMQPMessage($jsonResponse);
    $channel->basic_publish($msg, '', $queue);
}

function updateCompanyRatings($mysqli) {
    $sql = "
        UPDATE company_info ci
        JOIN (
            SELECT ur.company_id, AVG(ur.rating) AS avg_rating
            FROM user_reviews ur
            GROUP BY ur.company_id
        ) AS avg_ratings ON ci.id = avg_ratings.company_id
        SET ci.average_rating = IFNULL(avg_ratings.avg_rating, 0)
    ";

    if ($mysqli->query($sql)) {
        echo "Successfully updated average ratings in company_info.\n";
    } else {
        echo "Error updating average ratings: " . $mysqli->error . "\n";
    }
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

    $queueRecommendJobs = 'recommendJobs';
    $queueResponseRecommendJobs = 'responseRecommendJobs';

    $channel->queue_declare($queueRecommendJobs, false, false, false, false);
    $channel->queue_declare($queueResponseRecommendJobs, false, false, false, false);

    echo " [*] Waiting for job recommendation requests. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseRecommendJobs) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'])) {
            echo "Invalid message format\n";
            sendResponse($channel, $queueResponseRecommendJobs, false, "Invalid message format", []);
            return;
        }

        $username = $data['username'];
        echo " [x] Received recommendation request for Username: $username\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseRecommendJobs, false, "Database connection failed", []);
            return;
        }

        updateCompanyRatings($mysqli);

        $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        if (!$user_id) {
            echo "User not found for Username: $username\n";
            sendResponse($channel, $queueResponseRecommendJobs, false, "User not found", []);
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("SELECT jobTitle, location FROM user_preferences WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($preferredJobTitle, $preferredLocation);
        $stmt->fetch();
        $stmt->close();

        if (!$preferredJobTitle || !$preferredLocation) {
            echo "No preferences found for User ID: $user_id\n";
            sendResponse($channel, $queueResponseRecommendJobs, false, "No preferences found", []);
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("
            SELECT tj.id, tj.locations, tj.site, tj.date, tj.url, tj.title, tj.description, tj.company, tj.salary, tj.salary_min, tj.salary_max, tj.salary_type, tj.salary_currency_code, ci.average_rating 
            FROM total_jobs tj
            LEFT JOIN company_info ci ON tj.company LIKE CONCAT('%', ci.name, '%')
            WHERE tj.title LIKE ? AND tj.locations LIKE ?
            ORDER BY ci.average_rating DESC
        ");

        $jobTitleParam = "%$preferredJobTitle%";
        $locationParam = "%$preferredLocation%";
        $stmt->bind_param("ss", $jobTitleParam, $locationParam);
        $stmt->execute();

        $result = $stmt->get_result();
        $recommendedJobs = [];

        while ($row = $result->fetch_assoc()) {
            $recommendedJobs[] = $row;
        }

        $stmt->close();
        $mysqli->close();

        if (!empty($recommendedJobs)) {
            echo "Found " . count($recommendedJobs) . " matching jobs for Username: $username\n";
            sendResponse($channel, $queueResponseRecommendJobs, true, "Recommendations found", $recommendedJobs);
        } else {
            echo "No matching jobs found for Username: $username\n";
            sendResponse($channel, $queueResponseRecommendJobs, false, "No matching jobs found", []);
        }
    };

    $channel->basic_consume($queueRecommendJobs, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
