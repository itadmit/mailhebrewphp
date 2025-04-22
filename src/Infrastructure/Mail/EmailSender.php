<?php

declare(strict_types=1);

namespace MailHebrew\Infrastructure\Mail;

use MailHebrew\Domain\Email\Email;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Psr\Log\LoggerInterface;
use MailHebrew\Infrastructure\Tracking\TrackingManager;

class EmailSender
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
        $mail = $this->createMailer();
        
        try {
            // הגדרת השולח
            $mail->setFrom($email->getFrom(), $email->getFromName());
            
            // הגדרת הנמענים
            foreach ($email->getTo() as $recipient) {
                if (is_array($recipient)) {
                    $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
                } else {
                    $mail->addAddress($recipient);
                }
            }
            
            // הגדרת CC
            foreach ($email->getCc() as $cc) {
                if (is_array($cc)) {
                    $mail->addCC($cc['email'], $cc['name'] ?? '');
                } else {
                    $mail->addCC($cc);
                }
            }
            
            // הגדרת BCC
            foreach ($email->getBcc() as $bcc) {
                if (is_array($bcc)) {
                    $mail->addBCC($bcc['email'], $bcc['name'] ?? '');
                } else {
                    $mail->addBCC($bcc);
                }
            }
            
            // הגדרת כותרת
            $mail->Subject = $email->getSubject();
            
            // הכנת תוכן ההודעה עם מעקב פתיחות והקלקות
            $htmlContent = $email->getHtmlBody();
            $textContent = $email->getTextBody();
            
            // הוספת מעקב פתיחות
            if ($email->isTrackOpens()) {
                $htmlContent = $this->trackingManager->addOpenTracking($htmlContent, $email->getId());
            }
            
            // הוספת מעקב הקלקות
            if ($email->isTrackClicks()) {
                $htmlContent = $this->trackingManager->addClickTracking($htmlContent, $email->getId());
            }
            
            // הוספת קישור להסרה מרשימת תפוצה
            $htmlContent = $this->trackingManager->addUnsubscribeLink($htmlContent, $email->getId());
            $textContent = $this->trackingManager->addUnsubscribeText($textContent, $email->getId());
            
            // הגדרת גוף ההודעה
            $mail->isHTML(true);
            $mail->Body = $htmlContent;
            $mail->AltBody = $textContent;
            
            // הוספת קבצים מצורפים
            foreach ($email->getAttachments() as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
            }
            
            // הוספת כותרות (headers) מותאמות אישית
            foreach ($email->getHeaders() as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }
            
            // הוספת Message-ID ייחודי
            $mail->MessageID = '<' . $email->getId() . '@' . parse_url($this->smtpConfig['from_email'], PHP_URL_HOST) . '>';
            
            // הוספת תגיות כחלק מה-X-Headers
            if (!empty($email->getTags())) {
                $mail->addCustomHeader('X-Tags', implode(', ', $email->getTags()));
            }
            
            // שליחת האימייל
            $result = $mail->send();
            
            if ($result) {
                $email->setStatus(Email::STATUS_SENT);
                $this->logger->info('Email sent successfully', [
                    'email_id' => $email->getId(),
                    'to' => $email->getTo(),
                    'subject' => $email->getSubject(),
                ]);
                
                return true;
            } else {
                $email->setStatus(Email::STATUS_FAILED);
                $this->logger->error('Failed to send email', [
                    'email_id' => $email->getId(),
                    'error' => $mail->ErrorInfo,
                ]);
                
                return false;
            }
        } catch (Exception $e) {
            $email->setStatus(Email::STATUS_FAILED);
            $this->logger->error('Exception while sending email', [
                'email_id' => $email->getId(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        } finally {
            // עדכון ניסיון שליחה
            $email->incrementSendAttempts();
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