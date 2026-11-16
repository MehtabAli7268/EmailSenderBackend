<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends an inquiry over authenticated SMTP. Any failure is thrown for the
 * caller to log, and nothing from here is ever shown to the client.
 */
final class MailService
{
    /** @param array<string,mixed> $config The array returned by config/mail.php */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string,string|bool|null> $inquiry The output of validateInquiry() */
    public function send(string $recipient, array $inquiry): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($recipient), '@example.com')) {
            throw new RuntimeException('Recipient for region ' . $inquiry['region'] . ' is not configured in config/regions.php.');
        }

        $mail = $this->createMailer();
        $mail->addAddress($recipient);

        // The sender stays our own address so SPF and DMARC pass. Replies go to the customer.
        if ($inquiry['email'] !== null) {
            $mail->addReplyTo($inquiry['email'], $inquiry['name']);
        }

        $mail->Subject = '[' . $inquiry['region'] . '] ' . $inquiry['subject'];
        $mail->isHTML(true);
        $mail->Body = $this->renderHtml($inquiry);
        $mail->AltBody = $this->renderText($inquiry);
        $mail->send();
    }

    private function createMailer(): PHPMailer
    {
        $smtp = $this->config['smtp'];
        $from = $this->config['from'];

        if ($smtp['host'] === '' || $smtp['port'] <= 0 || $smtp['username'] === '' || $smtp['password'] === '') {
            throw new RuntimeException('SMTP settings are missing from .env.');
        }
        if (filter_var($from['address'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('MAIL_FROM_ADDRESS is missing or invalid in .env.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = $smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPDebug = 0;
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->XMailer = ' ';

        switch ($smtp['encryption']) {
            case 'ssl':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'tls':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            case 'none':
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                break;
            default:
                throw new RuntimeException('SMTP_ENCRYPTION must be tls, ssl or none.');
        }

        $mail->setFrom($from['address'], $from['name']);

        return $mail;
    }

    /**
     * Label => value for every detail shown in the email, skipping empty optional fields.
     *
     * @param array<string,string|bool|null> $inquiry
     * @return array<string,string>
     */
    private function details(array $inquiry): array
    {
        $details = [
            'Name' => $inquiry['name'],
            'Email' => $inquiry['email'] ?? 'Not provided',
            'Phone' => $inquiry['phone'],
            'Region' => $inquiry['region'],
            'Subject' => $inquiry['subject'],
            'Country' => $inquiry['country'],
            'City' => $inquiry['city'],
            ($inquiry['booking'] ? 'Car type' : 'Service') => $inquiry['service'],
            'Budget' => $inquiry['budget'],
            'Pick-up date' => $inquiry['from'],
            'Return date' => $inquiry['to'],
            'Driver option' => $inquiry['driver'],
        ];

        return array_filter($details, static fn ($value) => $value !== null);
    }

    /** @param array<string,string|bool|null> $inquiry */
    private function renderHtml(array $inquiry): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rows = '';
        foreach ($this->details($inquiry) as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;color:#666666;width:130px;vertical-align:top;">' . $escape($label) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;color:#1a1a1a;word-break:break-word;">' . $escape($value) . '</td>'
                . '</tr>';
        }

        $message = '';
        if ($inquiry['message'] !== null) {
            $message = '<h2 style="margin:24px 0 8px;font-size:15px;color:#1a1a1a;">Message</h2>'
                . '<div style="padding:14px;background:#f7f7f7;border-left:3px solid #c9a227;color:#1a1a1a;line-height:1.5;word-break:break-word;">'
                . nl2br($escape($inquiry['message']), false)
                . '</div>';
        }

        $heading = $inquiry['booking'] ? 'New car booking request' : 'New website enquiry';
        $replyHint = $inquiry['email'] !== null
            ? 'Replying to this email will go directly to the customer.'
            : 'The customer did not give an email address, so please respond by phone or WhatsApp.';

        return '<!doctype html><html><body style="margin:0;padding:24px 12px;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif;font-size:14px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="width:100%;max-width:600px;margin:0 auto;background:#ffffff;border-radius:6px;overflow:hidden;">'
            . '<tr><td style="padding:20px 24px;background:#0a0a0a;color:#c9a227;font-size:18px;font-weight:bold;">' . $escape($heading) . '</td></tr>'
            . '<tr><td style="padding:24px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
            . $message
            . '<p style="margin:24px 0 0;font-size:12px;color:#888888;">' . $escape($replyHint) . ' Received ' . $escape(gmdate('j M Y, H:i')) . ' UTC.</p>'
            . '</td></tr></table></body></html>';
    }

    /** @param array<string,string|bool|null> $inquiry */
    private function renderText(array $inquiry): string
    {
        $text = ($inquiry['booking'] ? 'New car booking request' : 'New website enquiry') . "\n\n";
        foreach ($this->details($inquiry) as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        if ($inquiry['message'] !== null) {
            $text .= "\nMessage:\n" . $inquiry['message'] . "\n";
        }

        return $text;
    }
}
