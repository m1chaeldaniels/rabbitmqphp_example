<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include Composer's autoloader
require 'vendor/autoload.php';
// Load SMTP configuration
// $smtpConfig = include('/var/www/sample/config/smtp_config.php'); // Adjust this path as needed

$mail = new PHPMailer(true);

try {
    // SMTP configuration
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'rmmyjobtracker@gmail.com';
    $mail->Password = 'iezd aevj ckku zvkh';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Set email format to HTML or plain text
    $mail->isHTML(true);
    
    // Set sender information
    $mail->setFrom('rmmyjobtracker@gmail.com', 'RMMYJobTracker');
    
    
    // Set recipient email (use your email for testing)
    $recipientEmail = 'yamanhannineh@gmail.com'; // Replace with your email
    $mail->addAddress($recipientEmail);

    // Set email subject and body
    $mail->Subject = 'Test Email from PHP using Gmail SMTP';
    $mail->Body    = '<p>This is a test email sent using Gmail SMTP settings in PHPMailer.</p>';

    // Send the email
    $mail->send();
    echo "Email sent successfully to $recipientEmail!\n";
} catch (Exception $e) {
    echo "Email could not be sent. Error: {$mail->ErrorInfo}\n";
}

?>