
<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// Declare queues for job search
$channel->queue_declare('jobSearch', false, false, false, false);
$channel->queue_declare('responseJobSearch', false, false, false, false);

echo " [*] Waiting for job search requests. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    $data = json_decode($msg->body, true);

    if (!isset($data['jobTitle'], $data['location'])) {
        echo "Invalid message format\n";
        sendResponse($channel, false, "Invalid message format", []);
        return;
    }

    $jobTitle = $data['jobTitle'];
    $location = $data['location'];
    echo " [x] Received search request for Job Title: $jobTitle, Location: $location\n";

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo "Database connection failed: " . $mysqli->connect_error . "\n";
        sendResponse($channel, false, "Database connection failed", []);
        return;
    }

    // Search the total_jobs table for matching jobs
    $stmt = $mysqli->prepare("
        SELECT id, locations, site, date, url, title, description, company, salary, salary_min, salary_max, salary_type, salary_currency_code 
        FROM total_jobs 
        WHERE title LIKE ? AND locations LIKE ?
    ");

    $jobTitleParam = "%$jobTitle%";
    $locationParam = "%$location%";
    $stmt->bind_param("ss", $jobTitleParam, $locationParam);
    $stmt->execute();

    $result = $stmt->get_result();
    $jobs = [];

    // Fetch matching jobs and store in an array
    while ($row = $result->fetch_assoc()) {
        // Ensure URL slashes are not escaped
        $row['url'] = stripslashes($row['url']);
        $jobs[] = $row;
    }

    $stmt->close();
    $mysqli->close();

    if (!empty($jobs)) {
        echo "Found " . count($jobs) . " matching jobs\n";
        sendResponse($channel, true, "Jobs found", $jobs);
    } else {
        echo "No matching jobs found\n";
        sendResponse($channel, false, "No matching jobs found", []);
    }
};

function sendResponse($channel, $success, $message, $jobs) {
    foreach ($jobs as &$job) {
        if (isset($job['url'])) {
            $job['url'] = str_replace('\/', '/', $job['url']);
        }
    }

    $response = [
        'success' => $success,
        'message' => $message,
        'jobs' => $jobs
    ];

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseJobSearch');
}

$channel->basic_consume('jobSearch', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
