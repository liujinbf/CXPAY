<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Port\EmailSender;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final readonly class SmtpEmailSender implements EmailSender
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName = 'CXPAY 云端'
    ) {
    }

    public function sendVerificationCode(EmailAddress $email, string $code): void
    {
        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $this->host;
            $mailer->Port = $this->port;
            $mailer->SMTPAuth = $this->username !== '';
            $mailer->Username = $this->username;
            $mailer->Password = $this->password;
            $mailer->SMTPSecure = $this->port === 465
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Timeout = 5;
            $mailer->Timelimit = 10;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->fromAddress, $this->fromName);
            $mailer->addAddress($email->display());
            $mailer->Subject = 'CXPAY 云端邮箱验证码';
            $mailer->Body = sprintf('您的验证码是：%s。验证码 10 分钟内有效。', $code);
            $mailer->send();
        } catch (Throwable $exception) {
            throw new RuntimeException('SMTP 邮件投递失败', 0, $exception);
        }
    }
}
