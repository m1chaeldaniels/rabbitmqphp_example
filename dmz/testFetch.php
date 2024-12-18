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

// This file is to test whether I can receive jobs from the API

require_once __DIR__ . '/jet_api/Careerjet_API.php';

if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

// Initialize CareerJet API 
$cjapi = new Careerjet_API('en_US');

//  search parameters
$search_params = array(
    'keywords' => 'Java Software Engineer',
    'location' => 'New Jersey',
    'affid'    => 'fcd2cacc0c8a6a59d9ea0d1fb45fea12',  //  affiliate ID
    'pagesize' => 1, 
    'sort'     => 'date' 
);

// Fetch job data from CareerJet API
$result = $cjapi->search($search_params);

if ($result->type == 'JOBS') {
    $jobs = $result->jobs;

    echo "Got " . $result->hits . " jobs:\n\n";
    
    foreach ($jobs as $job) {
        echo "URL: " . $job->url . "\n";
        echo "TITLE: " . $job->title . "\n";
        echo "LOCATION: " . $job->locations . "\n";
        echo "COMPANY: " . $job->company . "\n";
        echo "SALARY: " . $job->salary . "\n";
        echo "DATE: " . $job->date . "\n";
        echo "DESCRIPTION: " . $job->description . "\n";
        echo "-----------------------------\n";
    }
} else {
    echo "Error fetching jobs: " . $result->error . "\n";
}

?>
