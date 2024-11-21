<?php

// THIS IS THE PHP FILE THAT FETCHES AND RECIEVES JOBS TO THE DATABASE

require_once __DIR__ . '/jet_api/Careerjet_API.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

// Initialize CareerJet API 
$cjapi = new Careerjet_API('en_US');

// search parameters 
$search_params = array(
    'keywords' => 'Java Software Engineer',
    'location' => 'New Jersey',
    'affid'    => 'fcd2cacc0c8a6a59d9ea0d1fb45fea12',  // affiliate ID
    'pagesize' => 1, // Adjust the pagesize
    'sort'     => 'date' 
);

// Fetch job data from CareerJet API
$result = $cjapi->search($search_params);
//$jobs = $result->jobs;
//echo json_encode($jobs, JSON_UNESCAPED_SLASHES);

if ($result->type == 'JOBS') {
    $jobs = $result->jobs;

    $connection = new AMQPStreamConnection('10.147.17.214', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    $channel->queue_declare('test1', false, false, false, false);

    $message = new AMQPMessage(json_encode($jobs, JSON_UNESCAPED_SLASHES));

    $channel->basic_publish($message, '', 'test1');

    echo " [x] Job data sent to RabbitMQ\n";

    $channel->close();
    $connection->close();
} else {
    echo "Error fetching jobs: " . $result->error . "\n";
}

?>
