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

class EmailController
{
    private QueueManager $queueManager;
    private LoggerInterface $logger;
    private PDO $db;

    public function __construct(
        QueueManager $queueManager,
        LoggerInterface $logger,
        PDO $db
    ) {
        $this->queueManager = $queueManager;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * שליחת אימייל בודד
     */
    public function send(Request $request, Response $response): Response
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
            // מזהה החשבון (בפועל יתקבל מה-authentication)
            $accountId = $data['account_id'] ?? 1;
            
            // יצירת אובייקט אימייל
            $email = new Email(
                $data['from']['email'],
                $data['from']['name'],
                $data['to'],
                $data['subject'],
                $data['html_body'] ?? '',
                $data['text_body'] ?? ''
            );
            
            // הגדרת שדות אופציונליים
            if (isset($data['cc'])) {
                $email->setCc($data['cc']);
            }
            
            if (isset($data['bcc'])) {
                $email->setBcc($data['bcc']);
            }
            
            if (isset($data['track_opens'])) {
                $email->setTrackOpens((bool)$data['track_opens']);
            }
            
            if (isset($data['track_clicks'])) {
                $email->setTrackClicks((bool)$data['track_clicks']);
            }
            
            if (isset($data['tags']) && is_array($data['tags'])) {
                foreach ($data['tags'] as $tag) {
                    $email->addTag($tag);
                }
            }
            
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $email->setMetadata($data['metadata']);
            }
            
            // טיפול בתזמון אם קיים
            if (isset($data['scheduled_at']) && !empty($data['scheduled_at'])) {
                $scheduledAt = new DateTimeImmutable($data['scheduled_at']);
                $email->schedule($scheduledAt);
            }
            
            // שמירת האימייל במסד הנתונים
            $this->saveEmailToDatabase($email, $accountId);
            
            // הוספת האימייל לתור עם עדיפות מתאימה
            $priority = $data['priority'] ?? 'normal';
            $result = $this->queueManager->enqueue($email, $priority);
            
            if (!$result) {
                throw new \RuntimeException('Failed to enqueue email');
            }
            
            // החזרת תשובה
            $responseData = [
                'success' => true,
                'message' => 'Email queued successfully',
                'data' => [
                    'email_id' => $email->getId(),
                    'status' => $email->getStatus(),
                ],
            ];
            
            // אם זה קמפיין, נעדכן את סטטוס הקמפיין
            if (isset($data['campaign_id'])) {
                $this->updateCampaignStatus((int)$data['campaign_id']);
                $responseData['data']['campaign_id'] = (int)$data['campaign_id'];
            }
            
            return $this->jsonResponse($response, $responseData);
        } catch (\Throwable $e) {
            $this->logger->error('Error sending email', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * שליחת מספר אימיילים בבת אחת
     */
    public function sendBatch(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (!isset($data['emails']) || !is_array($data['emails'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing emails array',
            ], 400);
        }
        
        $results = [];
        $totalSuccess = 0;
        $totalFailed = 0;
        
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $data['account_id'] ?? 1;
        
        foreach ($data['emails'] as $emailData) {
            try {
                // וידוא שדות חובה
                if (!$this->validateRequiredEmailFields($emailData)) {
                    $results[] = [
                        'success' => false,
                        'message' => 'Missing required fields',
                        'data' => $emailData,
                    ];
                    $totalFailed++;
                    continue;
                }
                
                // יצירת אובייקט אימייל
                $email = new Email(
                    $emailData['from']['email'],
                    $emailData['from']['name'],
                    $emailData['to'],
                    $emailData['subject'],
                    $emailData['html_body'] ?? '',
                    $emailData['text_body'] ?? ''
                );
                
                // הגדרת שדות אופציונליים
                if (isset($emailData['cc'])) {
                    $email->setCc($emailData['cc']);
                }
                
                if (isset($emailData['bcc'])) {
                    $email->setBcc($emailData['bcc']);
                }
                
                if (isset($emailData['track_opens'])) {
                    $email->setTrackOpens((bool)$emailData['track_opens']);
                }
                
                if (isset($emailData['track_clicks'])) {
                    $email->setTrackClicks((bool)$emailData['track_clicks']);
                }
                
                if (isset($emailData['tags']) && is_array($emailData['tags'])) {
                    foreach ($emailData['tags'] as $tag) {
                        $email->addTag($tag);
                    }
                }
                
                if (isset($emailData['metadata']) && is_array($emailData['metadata'])) {
                    $email->setMetadata($emailData['metadata']);
                }
                
                // טיפול בתזמון אם קיים
                if (isset($emailData['scheduled_at']) && !empty($emailData['scheduled_at'])) {
                    $scheduledAt = new DateTimeImmutable($emailData['scheduled_at']);
                    $email->schedule($scheduledAt);
                }
                
                // שמירת האימייל במסד הנתונים
                $this->saveEmailToDatabase($email, $accountId);
                
                // הוספת האימייל לתור עם עדיפות מתאימה
                $priority = $emailData['priority'] ?? 'normal';
                $result = $this->queueManager->enqueue($email, $priority);
                
                if (!$result) {
                    throw new \RuntimeException('Failed to enqueue email');
                }
                
                // תוצאות חיוביות
                $results[] = [
                    'success' => true,
                    'email_id' => $email->getId(),
                    'status' => $email->getStatus(),
                ];
                
                $totalSuccess++;
                
                // אם זה קמפיין, נעדכן את סטטוס הקמפיין
                if (isset($emailData['campaign_id'])) {
                    $this->updateCampaignStatus((int)$emailData['campaign_id']);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error in batch email', [
                    'exception' => $e->getMessage(),
                ]);
                
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => $emailData,
                ];
                
                $totalFailed++;
            }
        }
        
        return $this->jsonResponse($response, [
            'success' => $totalFailed === 0,
            'message' => "Processed {$totalSuccess} emails successfully, {$totalFailed} failed",
            'results' => $results,
        ]);
    }

    /**
     * בדיקת סטטוס אימייל
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        $emailId = $args['id'];
        
        if (empty($emailId)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Email ID is required',
            ], 400);
        }
        
        try {
            // קבלת סטטוס האימייל ממסד הנתונים
            $stmt = $this->db->prepare("
                SELECT 
                    id, status, sent_at, opened_at, clicked_at, 
                    created_at, updated_at, campaign_id
                FROM emails 
                WHERE id = :id
            ");
            
            $stmt->execute(['id' => $emailId]);
            $email = $stmt->fetch();
            
            if (!$email) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email not found',
                ], 404);
            }
            
            // קבלת אירועים קשורים
            $stmt = $this->db->prepare("
                SELECT event_type, created_at, ip_address
                FROM email_events
                WHERE email_id = :email_id
                ORDER BY created_at DESC
            ");
            
            $stmt->execute(['email_id' => $emailId]);
            $events = $stmt->fetchAll();
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'email' => $email,
                    'events' => $events,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting email status', [
                'exception' => $e->getMessage(),
                'email_id' => $emailId,
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get email status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * שליחת אימייל בדיקה
     */
    public function sendTest(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (!isset($data['to']) || empty($data['to'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Recipient email is required',
            ], 400);
        }
        
        try {
            // מזהה החשבון (בפועל יתקבל מה-authentication)
            $accountId = $data['account_id'] ?? 1;
            
            // בדיקה אם מדובר בבדיקת תבנית
            if (isset($data['template_id'])) {
                // קבלת התבנית ממסד הנתונים
                $stmt = $this->db->prepare("
                    SELECT content_html, content_text
                    FROM templates
                    WHERE id = :id AND account_id = :account_id
                ");
                
                $stmt->execute([
                    'id' => $data['template_id'],
                    'account_id' => $accountId,
                ]);
                
                $template = $stmt->fetch();
                
                if (!$template) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Template not found',
                    ], 404);
                }
                
                // החלפת משתנים בתבנית
                $htmlBody = $template['content_html'];
                $textBody = $template['content_text'];
                
                if (isset($data['variables']) && is_array($data['variables'])) {
                    foreach ($data['variables'] as $key => $value) {
                        $htmlBody = str_replace('{{' . $key . '}}', $value, $htmlBody);
                        $textBody = str_replace('{{' . $key . '}}', $value, $textBody);
                    }
                }
            } else {
                // שימוש בתוכן ישיר
                $htmlBody = $data['html_body'] ?? '';
                $textBody = $data['text_body'] ?? '';
            }
            
            // יצירת אובייקט אימייל
            $email = new Email(
                $data['from']['email'] ?? $_ENV['SMTP_FROM_EMAIL'],
                $data['from']['name'] ?? $_ENV['SMTP_FROM_NAME'],
                is_array($data['to']) ? $data['to'] : [$data['to']],
                $data['subject'] ?? 'Test Email',
                $htmlBody,
                $textBody
            );
            
            // תיוג כאימייל בדיקה
            $email->addTag('test');
            
            // שליחת האימייל בעדיפות גבוהה
            $result = $this->queueManager->enqueue($email, 'high');
            
            if (!$result) {
                throw new \RuntimeException('Failed to enqueue test email');
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Test email queued successfully',
                'data' => [
                    'email_id' => $email->getId(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error sending test email', [
                'exception' => $e->getMessage(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * וידוא שכל השדות הנדרשים קיימים
     */
    private function validateRequiredEmailFields(array $data): bool
    {
        return isset($data['from']) && is_array($data['from']) && 
               isset($data['from']['email']) && 
               isset($data['from']['name']) && 
               isset($data['to']) && 
               isset($data['subject']) && 
               (isset($data['html_body']) || isset($data['text_body']));
    }

    /**
     * שמירת האימייל במסד הנתונים
     */
    private function saveEmailToDatabase(Email $email, int $accountId): void
    {
        try {
            $this->db->beginTransaction();
            
            foreach ($email->getTo() as $recipient) {
                $recipientEmail = is_array($recipient) ? $recipient['email'] : $recipient;
                $recipientName = is_array($recipient) ? ($recipient['name'] ?? '') : '';
                
                // בדיקה אם הנמען כבר קיים במסד הנתונים
                $stmt = $this->db->prepare("
                    SELECT id 
                    FROM recipients 
                    WHERE account_id = :account_id AND email = :email
                ");
                
                $stmt->execute([
                    'account_id' => $accountId,
                    'email' => $recipientEmail,
                ]);
                
                $recipientId = $stmt->fetchColumn();
                
                // אם הנמען לא קיים, נוסיף אותו
                if (!$recipientId) {
                    $stmt = $this->db->prepare("
                        INSERT INTO recipients (account_id, email, name, is_active)
                        VALUES (:account_id, :email, :name, 1)
                    ");
                    
                    $stmt->execute([
                        'account_id' => $accountId,
                        'email' => $recipientEmail,
                        'name' => $recipientName,
                    ]);
                    
                    $recipientId = $this->db->lastInsertId();
                }
                
                // הוספת האימייל לטבלת האימיילים
                $stmt = $this->db->prepare("
                    INSERT INTO emails (
                        id, campaign_id, account_id, recipient_id, 
                        from_email, from_name, to_email, to_name, 
                        subject, content_html, content_text, status,
                        tracking_enabled, metadata
                    )
                    VALUES (
                        :id, :campaign_id, :account_id, :recipient_id,
                        :from_email, :from_name, :to_email, :to_name,
                        :subject, :content_html, :content_text, :status,
                        :tracking_enabled, :metadata
                    )
                ");
                
                $stmt->execute([
                    'id' => $email->getId(),
                    'campaign_id' => $email->getCampaignId(),
                    'account_id' => $accountId,
                    'recipient_id' => $recipientId,
                    'from_email' => $email->getFrom(),
                    'from_name' => $email->getFromName(),
                    'to_email' => $recipientEmail,
                    'to_name' => $recipientName,
                    'subject' => $email->getSubject(),
                    'content_html' => $email->getHtmlBody(),
                    'content_text' => $email->getTextBody(),
                    'status' => $email->getStatus(),
                    'tracking_enabled' => (int)($email->isTrackOpens() || $email->isTrackClicks()),
                    'metadata' => !empty($email->getMetadata()) ? json_encode($email->getMetadata()) : null,
                ]);
            }
            
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
        $payload = json_encode($data);
        
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
} 