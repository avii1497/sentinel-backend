<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';

function sendMail($to, $subject, $body)
{
    $mail = new PHPMailer(true);

    try {
        $cfg = require __DIR__ . '/config/mail.php';

        // =============================
        // SMTP SETTINGS
        // =============================
        $mail->isSMTP();
        $mail->Host       = $cfg["host"];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg["username"];
        $mail->Password   = $cfg["password"];
        $mail->SMTPSecure = $cfg["secure"] === "ssl"
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$cfg["port"];

        // =============================
        // EMAIL SETTINGS
        // =============================
        $mail->setFrom($cfg["from_email"], $cfg["from_name"]);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = nl2br($body);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
