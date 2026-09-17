<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send a single email. Returns [true, null] on success or [false, errorMessage] on failure.
 *
 * @param array $attachments Optional list of files to attach, each:
 *                           ['path' => absolute path on disk, 'name' => name shown to the recipient]
 */
function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): array {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)));

        foreach ($attachments as $att) {
            if (!empty($att['path']) && is_file($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        $mail->send();
        return [true, null];
    } catch (PHPMailerException $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}
