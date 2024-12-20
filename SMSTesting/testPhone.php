<?php

require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSMS($phoneNumber, $message, $carrier = 'tmobile') {
    // Carrier SMTP gateways (you can expand this list)
    $carrierGateways = [
        'verizon' => '@vtext.com',
        'att' => '@txt.att.net',
        'tmobile' => '@tmomail.net',
    ];

    if (!isset($carrierGateways[$carrier])) {
        echo " [x] Carrier not supported for $phoneNumber\n";
        return;
    }

    $recipient = $phoneNumber . $carrierGateways[$carrier];

    try {
        $mail = new PHPMailer(true);

        // SMTP Server Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'rmmyjobs@gmail.com'; // Replace with your Gmail address
        $mail->Password = 'tpnb hskw kjjm ztzw'; // Replace with your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Email-to-SMS Settings
        $mail->setFrom('no-reply@rmmytracker.com', 'RMMY Job Tracker');
        $mail->addAddress($recipient);
        $mail->Subject = ''; // SMS typically doesn't use a subject
        $mail->Body = $message;

        $mail->send();
        echo " [x] SMS sent to: $phoneNumber ($carrier)\n";
    } catch (Exception $e) {
        echo " [x] SMS could not be sent. Error: {$mail->ErrorInfo}\n";
    }
}

// Test data for SMS
$testPhone = '2018562600'; // Replace with a real phone number (digits only)
$testCarrier = 'tmobile'; // Replace with the recipient's carrier ('verizon', 'att', 'tmobile')
$testSMSMessage = "Hi, this is a test SMS notification from RMMY Job Tracker.";

// Send Test SMS
sendSMS($testPhone, $testSMSMessage, $testCarrier);

?>
