<?php

require_once __DIR__ . '/jet_api/Careerjet_API.php';

// Mock $_SERVER variables to simulate web server environment
if (php_sapi_name() == 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'CLI';
}

// Initialize CareerJet API with locale 'en_US' (for the United States)
$cjapi = new Careerjet_API('en_US');

// Define search parameters
$search_params = array(
    'keywords' => 'lineman',
    'location' => 'South Plainfield,',
    'affid'    => 'fcd2cacc0c8a6a59d9ea0d1fb45fea12',  // Replace with your CareerJet affiliate ID
    'pagesize' => 2,
    //'sort'     => 'date' // Sort by date to get the latest jobs
);

// Fetch job data from CareerJet API
$result = $cjapi->search($search_params);

if ($result->type == 'JOBS') {
    $jobs = $result->jobs;

    // Print job data
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
