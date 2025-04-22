<?php

namespace App\Infrastructure\Email;

use App\Domain\Email\Email;
use App\Domain\Logger\LoggerInterface;
use App\Domain\Tracking\TrackingManager;
use DateTimeImmutable;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;

class EmailSender
{
    private PHPMailer $mailer;
    private LoggerInterface $logger;
    private TrackingManager $trackingManager;

    public function __construct(
        PHPMailer $mailer,
        LoggerInterface $logger,
        TrackingManager $trackingManager
    ) {
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->trackingManager = $trackingManager;
    }

    public function send(Email $email): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearCustomHeaders();

            $this->mailer->setFrom($email->getFrom(), $email->getFromName());
            $this->mailer->addAddress($email->getTo(), $email->getToName() ?? '');
            
            if ($email->getReplyTo()) {
                $this->mailer->addReplyTo($email->getReplyTo());
            }

            $this->mailer->Subject = $email->getSubject();

            if ($email->getContentHtml()) {
                $this->mailer->isHTML(true);
                $this->mailer->Body = $email->getContentHtml();
                
                if ($email->isTrackingEnabled()) {
                    $this->mailer->Body = $this->trackingManager->addOpenTracking($this->mailer->Body, $email->getId());
                }
            }

            if ($email->getContentText()) {
                $this->mailer->AltBody = $email->getContentText();
            }

            if ($email->isTrackingEnabled()) {
                $this->mailer->addCustomHeader('X-MailHebrew-ID', $email->getId());
            }

            $result = $this->mailer->send();
            
            if ($result) {
                $email->setStatus('sent');
                $email->setSentAt(new DateTimeImmutable());
            } else {
                $email->setStatus('failed');
                $this->logger->error('Failed to send email', [
                    'email_id' => $email->getId(),
                    'error' => $this->mailer->ErrorInfo
                ]);
            }

            return $result;
        } catch (Exception $e) {
            $email->setStatus('failed');
            $this->logger->error('Exception while sending email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
} 