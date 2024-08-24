<?php
// config/mailer.php
require_once __DIR__ . '/../env.php';
loadEnv(__DIR__ . '/../.env');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Try composer first, then manual include
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // manual includes (no composer)
    require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
}

/**
 * Send an email via SMTP (Gmail-ready).
 *
 * @return bool true if sent
 */
function sendEmailSMTP(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    $host   = $_ENV['SMTP_HOST']        ?? '';
    $port   = (int)($_ENV['SMTP_PORT']  ?? 587);
    $secure = $_ENV['SMTP_SECURE']      ?? 'tls';      // 'tls' or 'ssl'
    $user   = $_ENV['SMTP_USER']        ?? '';
    $pass   = $_ENV['SMTP_PASS']        ?? '';
    $from   = $_ENV['SMTP_FROM']        ?? $user;
    $name   = $_ENV['SMTP_FROM_NAME']   ?? 'Zicbot';

    if (!$host || !$user || !$pass || !$from) {
        error_log('SMTP not configured: missing env values.');
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($from, $name);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('SMTP send error: ' . $e->getMessage());
        return false;
    }
}
