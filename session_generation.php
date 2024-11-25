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

function sendMessage($channel, $queue, $success, $message, $email, $jobTitle, $location, $username, $hasMatchingJobs) {
    $response = [
        'success' => $success,
        'message' => $message,
        'email' => $email,
        'jobTitle' => $jobTitle,
        'location' => $location,
        'username' => $username,
        'alerts' => $hasMatchingJobs,
    ];
    echo "Message: $message | Email: $email | Job Title: $jobTitle | Location: $location | Username: $username | Has Matching Jobs: $hasMatchingJobs\n";
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', $queue);
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

    $channel->queue_declare('sessionTokenQueue', false, false, false, false);
    $channel->queue_declare('responseSessionToken', false, false, false, false);

    echo " [*] Waiting for session token messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'], $data['session_token'], $data['token_expiry'])) {
            sendMessage($channel, 'responseSessionToken', false, 'Invalid message format', null, null, null, null, null);
            return;
        }

        $username = $data['username'];
        $sessionToken = $data['session_token'];
        $tokenExpiry = $data['token_expiry'];

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            sendMessage($channel, 'responseSessionToken', false, 'Database connection failed', null, null, null, null, null);
            return;
        }

        // Update session token
        $stmt = $mysqli->prepare("UPDATE users SET session_token = ?, token_expiry = ? WHERE username = ?");
        $stmt->bind_param("sis", $sessionToken, $tokenExpiry, $username);

        if ($stmt->execute()) {
            $stmt = $mysqli->prepare("SELECT id, email, username, last_login FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($userId, $email, $username, $lastLogin);
            $stmt->fetch();
            $stmt->close();

            if ($userId) {
                $stmt = $mysqli->prepare("SELECT jobTitle, location FROM user_preferences WHERE user_id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->bind_result($jobTitle, $location);
                $stmt->fetch();
                $stmt->close();

                $jobsQuery = "
                    SELECT COUNT(*) AS match_count
                    FROM total_jobs tj
                    WHERE tj.title LIKE CONCAT('%', ?, '%')
                    AND tj.locations LIKE CONCAT('%', ?, '%')
                    AND UNIX_TIMESTAMP(tj.jobAddedTime) > ?
                ";
                $jobsStmt = $mysqli->prepare($jobsQuery);

                if ($jobsStmt) {
                    $jobsStmt->bind_param("ssi", $jobTitle, $location, $lastLogin);
                    $jobsStmt->execute();
                    $jobsStmt->bind_result($matchCount);
                    $jobsStmt->fetch();
                    $jobsStmt->close();

                    $hasMatchingJobs = $matchCount > 0;

                    $newLoginTime = time();
                    $updateStmt = $mysqli->prepare("UPDATE users SET last_login = ? WHERE id = ?");
                    $updateStmt->bind_param("ii", $newLoginTime, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();

                    sendMessage($channel, 'responseSessionToken', true, 'Session token stored and last login updated', $email, $jobTitle, $location, $username, $hasMatchingJobs);

                    echo "Updated last login for user: $username \n";

                } else {
                    sendMessage($channel, 'responseSessionToken', false, 'Error preparing jobs query', $email, $jobTitle, $location, $username, false);
                }

            } else {
                sendMessage($channel, 'responseSessionToken', false, 'User not found', null, null, null, null, false);
            }
        } else {
            sendMessage($channel, 'responseSessionToken', false, 'Failed to store session token', null, null, null, null, false);
        }

        $mysqli->close();
    };

    $channel->basic_consume('sessionTokenQueue', '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
