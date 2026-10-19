<?php
// mail_helper.php – SMTP mailer using PHPMailer

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/phpmailer/src/Exception.php';
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendMail($toEmail, $toName, $subject, $htmlBody, $fromEmail, $fromName) {
    $mail = new PHPMailer(true);

    try {
        // Server settings – CHANGE THESE TO YOUR OWN
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';          // your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'giancarloong78@gmail.com';    // your email
        $mail->Password   = 'ikkk wmez wctd chmi';       // your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or 'tls' on port 587
        $mail->Port       = 587;                       // 465 for SSL, 587 for TLS

        // Uncomment the next line to see SMTP debug output (useful for troubleshooting)
        // $mail->SMTPDebug = 2;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error and also store it in a global for debugging
        $errorMsg = $mail->ErrorInfo;
        error_log("Mailer Error: " . $errorMsg);
        // Optionally, store in a session or a variable to display
        // For now, we'll just return false
        return false;
    }
}