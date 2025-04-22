<?php

declare(strict_types=1);

namespace MailHebrew\Infrastructure\Mail;

use MailHebrew\Domain\Email\Email;
use MailHebrew\Domain\Email\EmailSender as EmailSenderInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Psr\Log\LoggerInterface;
use MailHebrew\Infrastructure\Tracking\TrackingManager;

class EmailSender implements EmailSenderInterface
{
    private array $smtpConfig;
    private LoggerInterface $logger;
    private TrackingManager $trackingManager;

    public function __construct(
        array $smtpConfig,
        LoggerInterface $logger,
        TrackingManager $trackingManager
    ) {
        $this->smtpConfig = $smtpConfig;
        $this->logger = $logger;
        $this->trackingManager = $trackingManager;
    }

    public function send(Email $email): bool
    {
        $this->logger->info('Starting email send process', [
            'email_id' => $email->getId(),
            'to' => $email->getTo(),
            'subject' => $email->getSubject()
        ]);

        try {
            $mail = new PHPMailer(true);
            
            $this->logger->info('Configuring SMTP settings', [
                'host' => $this->smtpConfig['host'],
                'port' => $this->smtpConfig['port'],
                'username' => $this->smtpConfig['username']
            ]);

            // הגדרת SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpConfig['username'];
            $mail->Password = $this->smtpConfig['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpConfig['port'];
            $mail->CharSet = 'UTF-8';

            // הגדרת השולח
            $mail->setFrom($email->getFrom(), $email->getFromName());
            
            $this->logger->info('Setting recipients', [
                'to' => $email->getTo(),
                'cc' => $email->getCc(),
                'bcc' => $email->getBcc()
            ]);

            // הגדרת נמענים
            foreach ($email->getTo() as $recipient) {
                $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
            }

            // הגדרת CC
            foreach ($email->getCc() as $cc) {
                $mail->addCC($cc['email'], $cc['name'] ?? '');
            }

            // הגדרת BCC
            foreach ($email->getBcc() as $bcc) {
                $mail->addBCC($bcc['email'], $bcc['name'] ?? '');
            }

            // הגדרת נושא ותוכן
            $mail->Subject = $email->getSubject();
            
            $this->logger->info('Processing email content', [
                'has_html' => !empty($email->getContentHtml()),
                'has_text' => !empty($email->getContentText())
            ]);

            // עיבוד תוכן HTML
            $htmlContent = $email->getContentHtml();
            if ($email->isTrackingEnabled()) {
                $this->logger->info('Adding tracking to HTML content');
                $htmlContent = $this->trackingManager->addOpenTracking($htmlContent, $email->getId());
                $htmlContent = $this->trackingManager->addClickTracking($htmlContent, $email->getId());
            }

            $mail->isHTML(true);
            $mail->Body = $htmlContent;
            $mail->AltBody = $email->getContentText();

            // הוספת קבצים מצורפים
            if (!empty($email->getAttachments())) {
                $this->logger->info('Adding attachments', [
                    'count' => count($email->getAttachments())
                ]);
                foreach ($email->getAttachments() as $attachment) {
                    $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                }
            }

            // הוספת headers מותאמים אישית
            if (!empty($email->getHeaders())) {
                $this->logger->info('Adding custom headers', [
                    'headers' => $email->getHeaders()
                ]);
                foreach ($email->getHeaders() as $name => $value) {
                    $mail->addCustomHeader($name, $value);
                }
            }

            $this->logger->info('Sending email');
            $result = $mail->send();

            if ($result) {
                $this->logger->info('Email sent successfully', [
                    'email_id' => $email->getId()
                ]);
                return true;
            } else {
                $this->logger->error('Failed to send email', [
                    'email_id' => $email->getId(),
                    'error' => $mail->ErrorInfo
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while sending email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        
        // הגדרות SMTP
        $mail->isSMTP();
        $mail->Host = $this->smtpConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->smtpConfig['username'];
        $mail->Password = $this->smtpConfig['password'];
        $mail->SMTPSecure = $this->smtpConfig['secure'];
        $mail->Port = (int)$this->smtpConfig['port'];
        
        // הגדרות נוספות
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->XMailer = 'MailHebrew Mailer';
        
        // הגדרת DKIM אם יש
        if (!empty($this->smtpConfig['dkim_domain']) && !empty($this->smtpConfig['dkim_private_key']) && !empty($this->smtpConfig['dkim_selector'])) {
            $mail->DKIM_domain = $this->smtpConfig['dkim_domain'];
            $mail->DKIM_private = $this->smtpConfig['dkim_private_key'];
            $mail->DKIM_selector = $this->smtpConfig['dkim_selector'];
            $mail->DKIM_identity = $this->smtpConfig['from_email'] ?? null;
        }
        
        return $mail;
    }
} 