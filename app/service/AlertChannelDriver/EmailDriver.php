<?php

declare(strict_types=1);

namespace app\service\AlertChannelDriver;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * SMTP 邮件通知驱动
 */
class EmailDriver
{
    /**
     * @param array $config {
     *   host, port, username, password, encryption(tls|ssl|''), from_name, from_addr, to_addrs(array)
     * }
     */
    public function send(string $subject, string $bodyText, array $config): bool
    {
        if (empty($config['host']) || empty($config['username']) || empty($config['to_addrs'])) {
            return false;
        }
        if (!class_exists(PHPMailer::class)) {
            error_log('[EmailDriver] PHPMailer 未安装');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = (string)$config['host'];
            $mail->Port       = (int)($config['port'] ?? 465);
            $mail->SMTPAuth   = true;
            $mail->Username   = (string)$config['username'];
            $mail->Password   = (string)$config['password'];

            $enc = strtolower((string)($config['encryption'] ?? 'ssl'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(
                (string)($config['from_addr'] ?? $config['username']),
                (string)($config['from_name'] ?? 'CXPAY 通知')
            );

            $toAddrs = (array)$config['to_addrs'];
            foreach ($toAddrs as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($addr);
                }
            }

            $mail->Subject = $subject;
            if (str_contains($bodyText, '<html') || str_contains($bodyText, '<div')) {
                $mail->Body    = $bodyText;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyText));
            } else {
                $mail->Body    = $this->wrapHtmlCard($subject, $bodyText);
                $mail->AltBody = $bodyText;
            }
            $mail->isHTML(true);

            $mail->Timeout = 8;
            $mail->send();
            return true;
        } catch (MailerException $e) {
            error_log('[EmailDriver] 发送失败: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('[EmailDriver] 异常: ' . $e->getMessage());
            return false;
        }
    }

    private function wrapHtmlCard(string $subject, string $bodyText): string
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $lines = array_filter(explode("\n", $bodyText));
        $contentHtml = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_contains($line, '：') || str_contains($line, ':')) {
                $parts = preg_split('/[：:]/', $line, 2);
                $k = htmlspecialchars(trim($parts[0]), ENT_QUOTES, 'UTF-8');
                $v = htmlspecialchars(trim($parts[1]), ENT_QUOTES, 'UTF-8');
                $contentHtml .= "<div style='display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;'><span style='color:#64748b;font-size:13px;'>{$k}</span><span style='color:#0f172a;font-weight:bold;font-size:13px;font-family:monospace;'>{$v}</span></div>";
            } else {
                $safe = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                $contentHtml .= "<p style='margin:8px 0;color:#334155;font-size:14px;line-height:1.6;'>{$safe}</p>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:20px;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<div style="max-width:540px;margin:20px auto;background:#ffffff;border-radius:16px;box-shadow:0 10px 25px -5px rgba(15,23,42,0.08);overflow:hidden;border:1px solid #e2e8f0;">
    <div style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);padding:24px 28px;color:#ffffff;">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:0.85;font-weight:bold;">CXPAY 通知系统</div>
        <h2 style="margin:6px 0 0 0;font-size:18px;font-weight:800;letter-spacing:-0.5px;">{$safeSubject}</h2>
    </div>
    <div style="padding:28px;">
        {$contentHtml}
    </div>
    <div style="background-color:#f8fafc;padding:16px 28px;border-top:1px solid #f1f5f9;text-align:center;font-size:11px;color:#94a3b8;">
        此邮件由 CXPAY 聚合支付系统自动发送，请勿直接回复。
    </div>
</div>
</body>
</html>
HTML;
    }
}

