<?php

require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSMS($phoneNumber, $message, $carrier = 'tmobile') {
    
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

        
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'rmmyjobs@gmail.com'; 
        $mail->Password = 'tpnb hskw kjjm ztzw'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        
        $mail->setFrom('no-reply@rmmytracker.com', 'RMMY Job Tracker');
        $mail->addAddress($recipient);
        $mail->Subject = ''; 
        $mail->Body = $message;

        $mail->send();
        echo " [x] SMS sent to: $phoneNumber ($carrier)\n";
    } catch (Exception $e) {
        echo " [x] SMS could not be sent. Error: {$mail->ErrorInfo}\n";
    }
}


$testPhone = '2018562600'; 
$testCarrier = 'tmobile'; 
$testSMSMessage = "Hi, this is a test SMS notification from RMMY Job Tracker.";


sendSMS($testPhone, $testSMSMessage, $testCarrier);

?>
