<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load SMTP configuration
// $smtpConfig = include('/var/www/your_project/config/smtp_config.php'); // Adjust path if needed

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// Declare the notification queue
$channel->queue_declare('alertQueue', false, false, false, false);

echo " [*] Waiting for notifications. To exit press CTRL+C\n";

$callback = function ($msg) {
    $data = json_decode($msg->body, true);

    if (!isset($data['usersToNotify']) || !is_array($data['usersToNotify'])) {
        echo "Invalid message format\n";
        return;
    }

    // Loop through all users in the message
    foreach ($data['usersToNotify'] as $user) {
        if (!isset($user['username'], $user['email'])) {
            echo "Invalid user data\n";
            continue;
        }

        $username = $user['username'];
        $email = $user['email'];

        // Prepare a general notification email
        $subject = "New Matching Job Alerts";
        $body = "Hello $username,\n\nYou have new job matches based on your preferences!\nLog in to your account to see the details.\n\nBest,\nRMMY Job Tracker Team";

        // Send the email using PHPMailer
        sendEmail($email, $subject, $body);
    }
};

function sendEmail($recipientEmail, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'rmmyjobtracker@gmail.com';
        $mail->Password = 'iezd aevj ckku zvkh'; // Ensure this is replaced with a more secure method
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Email settings
        $mail->setFrom('no-reply@rmmytracker.com', 'RMMYJobTracker');
        $mail->addAddress($recipientEmail);

        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        echo " [x] Email sent to: $recipientEmail\n";
    } catch (Exception $e) {
        echo " [x] Email could not be sent. Error: {$mail->ErrorInfo}\n";
    }
}

$channel->basic_consume('alertQueue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>