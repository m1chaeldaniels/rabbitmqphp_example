<?php

ini_set('log_errors', 'Off');
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $timestamp = "[" . date("d-M-Y H:i:s") . "]";
    $local_error = "$timestamp [MALIN_VM] $errstr in $errfile on line $errline";
    file_put_contents(
        '/home/malin/Desktop/Error_Log/php-error.log',
        $local_error . PHP_EOL,
        FILE_APPEND
    );
    return true;
});

// THIS IS THE PHP FILE THAT FETCHES AND RECEIVES JOBS TO THE DATABASE (This is the development one)

require_once __DIR__ . '/jet_api/Careerjet_API.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}

try {
    $cjapi = new Careerjet_API('en_US');

    $keywords = ['Java Software Engineer', 'PHP Developer'];
    $locations = ['New Jersey', 'California'];

    $allJobs = [];

    foreach ($keywords as $keyword) {
        foreach ($locations as $location) {
            echo "Searching for: $keyword in $location...\n";

            $search_params = array(
                'keywords' => $keyword,
                'location' => $location,
                'affid'    => 'fcd2cacc0c8a6a59d9ea0d1fb45fea12', 
                'pagesize' => 1, 
                'sort'     => 'date' 
            );

            $result = $cjapi->search($search_params);

            if ($result->type == 'JOBS' && !empty($result->jobs)) {
                $jobs = $result->jobs;
                $allJobs[] = [
                    'keyword' => $keyword,
                    'location' => $location,
                    'jobs' => $jobs
                ];
                echo "Jobs found for $keyword in $location.\n";
            } else {
                echo "No jobs found for $keyword in $location.\n";
            }
        }
    }

    if (!empty($allJobs)) {
        $jsonData = json_encode($allJobs, JSON_UNESCAPED_SLASHES);

        $config = getRabbitMQConfig();

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );
        $channel = $connection->channel();

        $queueName = 'test1';
        $channel->queue_declare($queueName, false, false, false, false);

        $message = new AMQPMessage($jsonData);
        $channel->basic_publish($message, '', $queueName);

        echo " [x] Job data sent to RabbitMQ\n";

        $channel->close();
        $connection->close();
    } else {
        echo "No job data to send.\n";
    }

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}

?>
