<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function mail_config() {
  return require __DIR__ . '/../config/mail.php';
}

function sendMail($to, $subject, $htmlBody, $textBody = null) {
  $cfg = mail_config();
  $mail = new PHPMailer(true);

  try {
    $mail->isSMTP();
    $mail->Host       = $cfg["host"];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg["username"];
    $mail->Password   = $cfg["password"];
    $mail->Port       = (int)$cfg["port"];

    if ($cfg["secure"] === "tls") {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($cfg["secure"] === "ssl") {
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    }

    $mail->setFrom($cfg["from_email"], $cfg["from_name"]);
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;

    if ($textBody) {
      $mail->AltBody = $textBody;
    } else {
      $mail->AltBody = strip_tags($htmlBody);
    }

    $mail->send();
    return ["success" => true];

  } catch (Exception $e) {
    error_log("Mailer Error: " . $mail->ErrorInfo);
    return ["success" => false, "error" => $mail->ErrorInfo];
  }
}
