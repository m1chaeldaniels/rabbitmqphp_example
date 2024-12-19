<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}


$config = getRabbitMQConfig();


$connection = new AMQPStreamConnection(
    $config['host'],
    $config['port'],
    $config['username'],
    $config['password'],
    $config['vhost']
);

$channel = $connection->channel();


$channel->queue_declare('alertQueue', false, false, false, false);

echo " [*] Waiting for notifications. To exit press CTRL+C\n";


$callback = function ($msg) {
    $data = json_decode($msg->body, true);

    if (!isset($data['usersToNotify']) || !is_array($data['usersToNotify'])) {
        echo "Invalid message format\n";
        return;
    }

   
    foreach ($data['usersToNotify'] as $user) {
        if (!isset($user['username'], $user['email'])) {
            echo "Invalid user data\n";
            continue;
        }

        $username = $user['username'];
        $email = $user['email'];

      
        $subject = "New Matching Job Alerts";
        $body = "Hello $username,\n\nYou have new job matches based on your preferences!\nLog in to your account to see the details.\n\nBest,\nRMMY Job Tracker Team";

       
        sendEmail($email, $subject, $body);
    }
};


function sendEmail($recipientEmail, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'rmmyjobs@gmail.com';
        $mail->Password = 'tpnb hskw kjjm ztzw';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        
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