<?php

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class OtpMailer
{
    /**
     * Build (but do not send) the verification email.
     */
    public function buildMessage(string $email, string $name, string $code): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) config('mail.mailers.smtp.host');
        $mail->Port = (int) config('mail.mailers.smtp.port', 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) config('mail.mailers.smtp.username');
        $mail->Password = (string) config('mail.mailers.smtp.password');
        $mail->SMTPSecure = $this->encryption();
        $mail->Timeout = 10;

        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($email, $name);

        $mail->Subject = 'Your BIGKAS-AI verification code';
        $mail->Body = "Hi {$name},\n\nYour BIGKAS-AI verification code is: {$code}\n\n"
            . "It expires in 30 minutes. If you did not create this account, ignore this email.\n";
        $mail->AltBody = $mail->Body;

        return $mail;
    }

    /**
     * Send the verification code email. Throws on SMTP failure.
     */
    public function sendCode(string $email, string $name, string $code): void
    {
        try {
            $this->buildMessage($email, $name, $code)->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException('Could not send verification email.', 0, $e);
        }
    }

    private function encryption(): string
    {
        return match (config('mail.mailers.smtp.encryption', 'tls')) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
    }
}
