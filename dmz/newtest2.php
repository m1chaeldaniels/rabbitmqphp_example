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

// Define search parameters
$locations = ['South Plainfield', 'New York', 'New Jersey']; // Add more locations here
$keywords = 'software engineer'; // Define the keywords once
$affid = 'fcd2cacc0c8a6a59d9ea0d1fb45fea12'; // Replace with your CareerJet affiliate ID
$pagesize = 1; // Adjust as needed
$sort = 'date'; // Sort by date to get the latest jobs

// Connect to RabbitMQ
$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// Declare the queue for job data
$channel->queue_declare('test1', false, false, false, false);

// Loop through each location and fetch job data
foreach ($locations as $location) {
    // Set search parameters for the current location
    $search_params = array(
        'keywords' => $keywords,
        'location' => $location,
        'affid'    => $affid,
        'pagesize' => $pagesize,
        'sort'     => $sort
    );

    // Fetch job data from CareerJet API
    $result = $cjapi->search($search_params);

    // Check if the response contains job data
    if ($result->type == 'JOBS') {
        $jobs = $result->jobs;

        // Prepare job data for RabbitMQ
        $message = new AMQPMessage(json_encode($jobs, JSON_UNESCAPED_SLASHES));

        // Publish the job data to the RabbitMQ queue
        $channel->basic_publish($message, '', 'test1');

        echo " [x] Job data for location '{$location}' sent to RabbitMQ\n";
    } else {
        echo "Error fetching jobs for location '{$location}': " . $result->error . "\n";
    }
}

// Close connections
$channel->close();
$connection->close();

?>
