<?php

declare(strict_types=1);

namespace MailHebrew\Api;

use DateTimeImmutable;
use MailHebrew\Domain\Email\Email;
use MailHebrew\Infrastructure\Queue\QueueManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;
use Respect\Validation\Validator as v;
use MailHebrew\Domain\Email\EmailSender;

class EmailController
{
    private QueueManager $queueManager;
    private LoggerInterface $logger;
    private PDO $db;
    private EmailSender $emailSender;

    public function __construct(
        QueueManager $queueManager,
        LoggerInterface $logger,
        PDO $db,
        EmailSender $emailSender
    ) {
        $this->queueManager = $queueManager;
        $this->logger = $logger;
        $this->db = $db;
        $this->emailSender = $emailSender;
    }

    /**
     * שליחת אימייל בודד
     */
    public function send(Request $request, Response $response): Response
    {
        $this->logger->info('Received email send request', [
            'body' => $request->getParsedBody()
        ]);

        try {
            $data = $request->getParsedBody();
            
            $this->logger->info('Validating request data');
            
            // בדיקת שדות חובה
            if (empty($data['to_email'])) {
                $this->logger->error('Missing required field: to_email');
                return $this->jsonResponse($response, ['error' => 'Missing required field: to_email'], 400);
            }

            if (empty($data['subject'])) {
                $this->logger->error('Missing required field: subject');
                return $this->jsonResponse($response, ['error' => 'Missing required field: subject'], 400);
            }

            if (empty($data['content_html']) && empty($data['content_text'])) {
                $this->logger->error('Missing required field: content_html or content_text');
                return $this->jsonResponse($response, ['error' => 'Missing required field: content_html or content_text'], 400);
            }

            $this->logger->info('Creating email object');
            
            // יצירת אובייקט אימייל
            $email = new Email();
            $email->setTo([
                [
                    'email' => $data['to_email'],
                    'name' => $data['to_name'] ?? null
                ]
            ]);
            $email->setSubject($data['subject']);
            $email->setFrom($data['from_email'] ?? 'no-reply@quick-site.co.il');
            $email->setFromName($data['from_name'] ?? 'MailHebrew System');
            $email->setContentHtml($data['content_html'] ?? '');
            $email->setContentText($data['content_text'] ?? '');
            $email->setReplyTo($data['reply_to'] ?? null);
            $email->setTrackingEnabled($data['tracking_enabled'] ?? true);

            $this->logger->info('Email object created', [
                'to' => $email->getTo(),
                'subject' => $email->getSubject(),
                'from' => $email->getFrom()
            ]);

            // שליחת האימייל
            $this->logger->info('Sending email');
            $result = $this->emailSender->send($email);

            if ($result) {
                $this->logger->info('Email sent successfully');
                return $this->jsonResponse($response, ['message' => 'Email sent successfully']);
            } else {
                $this->logger->error('Failed to send email');
                return $this->jsonResponse($response, ['error' => 'Failed to send email'], 500);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while processing email request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * שליחת מספר אימיילים בבת אחת
     */
    public function sendBatch(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // וידוא שדות חובה
        if (!isset($data['emails']) || !is_array($data['emails']) || empty($data['emails'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Emails array is required',
            ], 400);
        }
        
        try {
            // מזהה החשבון (בפועל יתקבל מה-authentication)
            $accountId = $data['account_id'] ?? 1;
            
            $results = [
                'success' => [],
                'failed' => [],
            ];
            
            foreach ($data['emails'] as $emailData) {
                try {
                    // וידוא שדות חובה לכל אימייל
                    if (!$this->validateRequiredEmailFields($emailData)) {
                        $results['failed'][] = [
                            'error' => 'Missing required fields',
                            'data' => $emailData,
                        ];
                        continue;
                    }
                    
                    // יצירת אובייקט אימייל
                    $email = new Email(
                        $emailData['from_email'],
                        $emailData['from_name'],
                        $emailData['to_email'],
                        $emailData['to_name'] ?? null,
                        $emailData['subject'],
                        $emailData['content_html'] ?? null,
                        $emailData['content_text'] ?? null,
                        $emailData['reply_to'] ?? null,
                        $emailData['tracking_enabled'] ?? true
                    );
                    
                    // שמירה למסד הנתונים
                    $this->saveEmailToDatabase($email, $accountId);
                    
                    // הוספה לתור
                    $this->queueManager->enqueue($email);
                    
                    $results['success'][] = [
                        'email_id' => $email->getId(),
                    ];
                    
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'error' => $e->getMessage(),
                        'data' => $emailData,
                    ];
                }
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Batch processing completed',
                'results' => $results,
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process batch emails', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to process batch emails',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * בדיקת סטטוס אימייל
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        $emailId = $args['id'] ?? '';
        
        if (empty($emailId)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Email ID is required',
            ], 400);
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT e.*, 
                       COUNT(DISTINCT ev.id) as event_count
                FROM emails e
                LEFT JOIN email_events ev ON e.id = ev.email_id
                WHERE e.id = :id
                GROUP BY e.id
            ");
            
            $stmt->execute(['id' => $emailId]);
            $email = $stmt->fetch();
            
            if (!$email) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email not found',
                ], 404);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'id' => $email['id'],
                    'status' => $email['status'],
                    'sent_at' => $email['sent_at'],
                    'opened_at' => $email['opened_at'],
                    'clicked_at' => $email['clicked_at'],
                    'event_count' => (int)$email['event_count'],
                ],
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get email status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get email status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * שליחת אימייל בדיקה
     */
    public function sendTest(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // וידוא שדות חובה
        if (!$this->validateRequiredEmailFields($data)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing required fields',
            ], 400);
        }
        
        try {
            // יצירת אובייקט אימייל
            $email = new Email(
                $data['from_email'],
                $data['from_name'],
                $data['to_email'],
                $data['to_name'] ?? null,
                $data['subject'],
                $data['content_html'] ?? null,
                $data['content_text'] ?? null,
                $data['reply_to'] ?? null,
                $data['tracking_enabled'] ?? true
            );
            
            // שליחה מיידית
            $success = $this->emailSender->send($email);
            
            if (!$success) {
                throw new \RuntimeException('Failed to send test email');
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Test email sent successfully',
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send test email', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * וידוא שכל השדות הנדרשים קיימים
     */
    private function validateRequiredEmailFields(array $data): bool
    {
        $requiredFields = [
            'from_email',
            'from_name',
            'to_email',
            'subject',
            'content_html',
        ];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * שמירת האימייל במסד הנתונים
     */
    private function saveEmailToDatabase(Email $email, int $accountId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO emails (
                id, account_id, from_email, from_name, to_email, to_name,
                subject, content_html, content_text, reply_to, tracking_enabled,
                status, created_at
            ) VALUES (
                :id, :account_id, :from_email, :from_name, :to_email, :to_name,
                :subject, :content_html, :content_text, :reply_to, :tracking_enabled,
                :status, :created_at
            )
        ");
        
        $stmt->execute([
            'id' => $email->getId(),
            'account_id' => $accountId,
            'from_email' => $email->getFrom(),
            'from_name' => $email->getFromName(),
            'to_email' => $email->getTo(),
            'to_name' => $email->getToName(),
            'subject' => $email->getSubject(),
            'content_html' => $email->getContentHtml(),
            'content_text' => $email->getContentText(),
            'reply_to' => $email->getReplyTo(),
            'tracking_enabled' => $email->isTrackingEnabled(),
            'status' => $email->getStatus(),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * עדכון סטטוס קמפיין
     */
    private function updateCampaignStatus(int $campaignId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE campaigns
                SET status = 'sending', updated_at = NOW()
                WHERE id = :id AND status != 'sent'
            ");
            
            $stmt->execute(['id' => $campaignId]);
        } catch (\Throwable $e) {
            $this->logger->error('Error updating campaign status', [
                'campaign_id' => $campaignId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * החזרת תשובת JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
} 