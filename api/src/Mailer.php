<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    private static ?Config $config = null;

    public static function init(Config $config): void
    {
        self::$config = $config;
    }

    public static function notifyAdmin(string $subject, string $bodyHtml): void
    {
        $cfg = self::$config?->get('mail');
        if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['admin_to'])) {
            return;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = (string) $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = (string) ($cfg['username'] ?? '');
            $mail->Password   = (string) ($cfg['password'] ?? '');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = (int) ($cfg['port'] ?? 465);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom((string) $cfg['from'], (string) ($cfg['from_name'] ?? $cfg['from']));
            $mail->addAddress((string) $cfg['admin_to']);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;

            $mail->send();
        } catch (PHPMailerException $e) {
            error_log('[oca-control] Error enviando correo: ' . $mail->ErrorInfo);
        }
    }
}
