<?php

require_once __DIR__ . '/jet_api/Careerjet_API.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Mock $_SERVER variables to simulate web server environment
if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

// Initialize CareerJet API with locale 'en_US' (for the United States)
$cjapi = new Careerjet_API('en_US');

// Define search parameters to show all jobs
$search_params = array(
    'keywords' => 'software developer',
    'location' => 'New Jersey',
    'affid'    => 'fcd2cacc0c8a6a59d9ea0d1fb45fea12',  // Replace with your CareerJet affiliate ID
    'pagesize' => 7, // Adjust the pagesize as needed (max is usually 99)
    'sort'     => 'date' // Sort by date to get the latest jobs
);

// Fetch job data from CareerJet API
$result = $cjapi->search($search_params);
//$jobs = $result->jobs;
//echo json_encode($jobs, JSON_UNESCAPED_SLASHES);

if ($result->type == 'JOBS') {
    $jobs = $result->jobs;

    // Connect to RabbitMQ
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue for job data
    $channel->queue_declare('test1', false, false, false, false);

    // Prepare job data for RabbitMQ
    $message = new AMQPMessage(json_encode($jobs, JSON_UNESCAPED_SLASHES));

    // Publish the job data to the RabbitMQ queue
    $channel->basic_publish($message, '', 'test1');

    echo " [x] Job data sent to RabbitMQ\n";

    // Close connections
    $channel->close();
    $connection->close();
} else {
    echo "Error fetching jobs: " . $result->error . "\n";
}

?>
