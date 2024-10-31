<?php

// ** THIS IS REDUNDANT CODE // IT IS NOT USED ****

require_once __DIR__ . '/jet_api/Careerjet_API.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

$cjapi = new Careerjet_API('en_US');

//  search parameters
$locations = ['South Plainfield', 'New York', 'New Jersey'];
$keywords = 'software engineer'; 
$affid = 'fcd2cacc0c8a6a59d9ea0d1fb45fea12'; 
$pagesize = 1; // Adjust as needed
$sort = 'date'; 

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('test1', false, false, false, false);

foreach ($locations as $location) {
    $search_params = array(
        'keywords' => $keywords,
        'location' => $location,
        'affid'    => $affid,
        'pagesize' => $pagesize,
        'sort'     => $sort
    );

    $result = $cjapi->search($search_params);

    if ($result->type == 'JOBS') {
        $jobs = $result->jobs;

        $message = new AMQPMessage(json_encode($jobs, JSON_UNESCAPED_SLASHES));

        $channel->basic_publish($message, '', 'test1');

        echo " [x] Job data for location '{$location}' sent to RabbitMQ\n";
    } else {
        echo "Error fetching jobs for location '{$location}': " . $result->error . "\n";
    }
}

$channel->close();
$connection->close();

?>
