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

        $mail->setFrom('no-reply@rmmytracker.com', 'RMMY Job Tracker');
        $mail->addAddress($recipientEmail);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        echo " [x] Email sent to: $recipientEmail\n";
    } catch (Exception $e) {
        echo " [x] Email could not be sent. Error: {$mail->ErrorInfo}\n";
    }
}

function sendSMS($phoneNumber, $message) {
    
    $carrierGateways = [
        'tmobile' => '@tmomail.net',
        'verizon' => '@vtext.com',
        'att' => '@txt.att.net',
        'sprint' => '@messaging.sprintpcs.com',
        'uscellular' => '@email.uscc.net',
        'cricket' => '@sms.cricketwireless.net',
        'boost' => '@sms.myboostmobile.com',
    ];

    foreach ($carrierGateways as $carrier => $gateway) {
        $recipient = $phoneNumber . $gateway;
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'rmmyjobs@gmail.com';
            $mail->Password = 'tpnb hskw kjjm ztzw';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('no-reply@rmmytracker.com', 'RMMY Job Tracker');
            $mail->addAddress($recipient);
        //    $mail->Subject = '';
            $mail->Body = $message;

            $mail->send();
            echo " [x] SMS sent to: $phoneNumber via $carrier\n";
            return; 
        } catch (Exception $e) {
            echo " [x] SMS via $carrier failed for $phoneNumber. Error: {$mail->ErrorInfo}\n";
        }
    }

    echo " [x] SMS could not be sent to $phoneNumber. All carrier attempts failed.\n";
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
        if (!isset($user['username'], $user['email'], $user['phone_number'])) {
            echo "Invalid user data\n";
            continue;
        }

        $username = $user['username'];
        $email = $user['email'];
        $phoneNumber = $user['phone_number'];

        $subject = "New Matching Job Alerts";
        $body = "Hello $username,\n\nYou have new job matches based on your preferences!\nLog in to your account to see the details.\n\nBest,\nRMMY Job Tracker Team";

        sendEmail($email, $subject, $body);
        sendSMS($phoneNumber, "Hi $username, you have new job matches! Log in to your account to see the details.");
    }
};

$channel->basic_consume('alertQueue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>
