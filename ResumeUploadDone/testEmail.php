<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Twilio\Rest\Client;

require 'vendor/autoload.php';

$recipientPhoneNumber = '+12018562600'; 
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'rmmyjobs@gmail.com';
    $mail->Password = 'tpnb hskw kjjm ztzw';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->isHTML(true);

    $mail->setFrom('rmmyjobtracker@gmail.com', 'RMMYJobTracker');

    $recipientEmail = 'yamanhannineh@gmail.com'; 
    $mail->addAddress($recipientEmail);

    $mail->Subject = 'Test Email from PHP using Gmail SMTP';
    $mail->Body    = '<p>This is a test email sent using Gmail SMTP settings in PHPMailer.</p>';

    $mail->send();
    echo "Email sent successfully to $recipientEmail!\n";

    $twilio = new Client($twilioSid, $twilioAuthToken);

    $message = $twilio->messages->create(
        $recipientPhoneNumber, 
        [
            'from' => $twilioPhoneNumber, 
            'body' => 'This is a test SMS sent using Twilio API.'
        ]
    );

    echo "SMS sent successfully to $recipientPhoneNumber!\n";

} catch (Exception $e) {
    echo "Email or SMS could not be sent. Error: {$e->getMessage()}\n";
}

?>
