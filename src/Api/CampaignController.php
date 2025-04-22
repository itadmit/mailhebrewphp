<?php

declare(strict_types=1);

namespace MailHebrew\Api;

use DateTimeImmutable;
use MailHebrew\Domain\Campaign\Campaign;
use MailHebrew\Domain\Email\Email;
use MailHebrew\Infrastructure\Queue\QueueManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

class CampaignController
{
    private LoggerInterface $logger;
    private PDO $db;
    private QueueManager $queueManager;

    public function __construct(
        LoggerInterface $logger,
        PDO $db,
        QueueManager $queueManager
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->queueManager = $queueManager;
    }

    /**
     * קבלת כל הקמפיינים (עם אפשרות לסינון)
     */
    public function getAll(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $params['account_id'] ?? 1;
        
        // פרמטרים לסינון
        $status = $params['status'] ?? null;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $orderBy = $params['order_by'] ?? 'created_at';
        $orderDir = strtoupper($params['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        try {
            // בניית השאילתה
            $query = "
                SELECT 
                    c.*,
                    COUNT(DISTINCT cl.list_id) AS list_count,
                    COALESCE(SUM(
                        (SELECT COUNT(*) FROM list_recipients lr WHERE lr.list_id = cl.list_id)
                    ), 0) AS recipient_count 
                FROM campaigns c
                LEFT JOIN campaign_lists cl ON c.id = cl.campaign_id
                WHERE c.account_id = :account_id
            ";
            
            $params = ['account_id' => $accountId];
            
            if ($status) {
                $query .= " AND c.status = :status";
                $params['status'] = $status;
            }
            
            $query .= " GROUP BY c.id";
            $query .= " ORDER BY c.{$orderBy} {$orderDir}";
            $query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($query);
            
            // חיבור פרמטרים מיוחדים
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            // חיבור שאר הפרמטרים
            foreach ($params as $key => $val) {
                if ($key !== 'limit' && $key !== 'offset') {
                    $stmt->bindValue(":{$key}", $val);
                }
            }
            
            $stmt->execute();
            $campaigns = $stmt->fetchAll();
            
            // ספירת סך כל הקמפיינים (ללא LIMIT)
            $countQuery = "
                SELECT COUNT(*) 
                FROM campaigns 
                WHERE account_id = :account_id
            ";
            
            $countParams = ['account_id' => $accountId];
            
            if ($status) {
                $countQuery .= " AND status = :status";
                $countParams['status'] = $status;
            }
            
            $stmt = $this->db->prepare($countQuery);
            
            foreach ($countParams as $key => $val) {
                $stmt->bindValue(":{$key}", $val);
            }
            
            $stmt->execute();
            $totalCount = $stmt->fetchColumn();
            
            // החזרת התגובה
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'campaigns' => $campaigns,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting campaigns', [
                'exception' => $e->getMessage(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get campaigns: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * קבלת קמפיין בודד לפי ID
     */
    public function getOne(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];

        try {
            // שליפת הקמפיין
            $query = "
                SELECT * FROM campaigns
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            // שליפת הרשימות שמקושרות לקמפיין
            $listsQuery = "
                SELECT l.id, l.name, COUNT(lr.email) as recipient_count
                FROM lists l
                JOIN campaign_lists cl ON l.id = cl.list_id
                LEFT JOIN list_recipients lr ON l.id = lr.list_id
                WHERE cl.campaign_id = :campaign_id
                GROUP BY l.id
            ";
            
            $stmt = $this->db->prepare($listsQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            $lists = $stmt->fetchAll();
            
            // שליפת מדדי המעקב
            $statsQuery = "
                SELECT 
                    COUNT(CASE WHEN event_type = 'send' THEN 1 END) AS sent_count,
                    COUNT(CASE WHEN event_type = 'open' THEN 1 END) AS open_count,
                    COUNT(CASE WHEN event_type = 'click' THEN 1 END) AS click_count,
                    COUNT(CASE WHEN event_type = 'bounce' THEN 1 END) AS bounce_count,
                    COUNT(CASE WHEN event_type = 'complaint' THEN 1 END) AS complaint_count,
                    COUNT(DISTINCT CASE WHEN event_type = 'unsubscribe' THEN recipient_email END) AS unsubscribe_count
                FROM tracking_events
                WHERE campaign_id = :campaign_id
            ";
            
            $stmt = $this->db->prepare($statsQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            $stats = $stmt->fetch();
            
            // מיזוג המידע
            $campaign = array_merge($campaignData, [
                'lists' => $lists,
                'stats' => $stats
            ]);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $campaign
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * יצירת קמפיין חדש
     */
    public function create(Request $request, Response $response): Response
    {
        $this->logger->info('Received campaign create request', [
            'body' => $request->getParsedBody()
        ]);

        try {
            $data = $request->getParsedBody();
            
            $this->logger->info('Validating campaign data');
            
            // בדיקת שדות חובה
            if (empty($data['name'])) {
                $this->logger->error('Missing required field: name');
                return $this->jsonResponse($response, ['error' => 'Missing required field: name'], 400);
            }

            if (empty($data['subject'])) {
                $this->logger->error('Missing required field: subject');
                return $this->jsonResponse($response, ['error' => 'Missing required field: subject'], 400);
            }

            if (empty($data['content_html']) && empty($data['content_text'])) {
                $this->logger->error('Missing required field: content_html or content_text');
                return $this->jsonResponse($response, ['error' => 'Missing required field: content_html or content_text'], 400);
            }

            if (empty($data['list_id'])) {
                $this->logger->error('Missing required field: list_id');
                return $this->jsonResponse($response, ['error' => 'Missing required field: list_id'], 400);
            }

            $this->logger->info('Creating campaign object');
            
            // יצירת אובייקט קמפיין
            $campaign = new Campaign();
            $campaign->setName($data['name']);
            $campaign->setSubject($data['subject']);
            $campaign->setContentHtml($data['content_html'] ?? '');
            $campaign->setContentText($data['content_text'] ?? '');
            $campaign->setListId($data['list_id']);
            $campaign->setFromEmail($data['from_email'] ?? 'no-reply@quick-site.co.il');
            $campaign->setFromName($data['from_name'] ?? 'MailHebrew System');
            $campaign->setReplyTo($data['reply_to'] ?? null);
            $campaign->setTrackingEnabled($data['tracking_enabled'] ?? true);
            $campaign->setScheduledAt($data['scheduled_at'] ?? null);

            $this->logger->info('Campaign object created', [
                'name' => $campaign->getName(),
                'subject' => $campaign->getSubject(),
                'list_id' => $campaign->getListId()
            ]);

            // שמירת הקמפיין במסד הנתונים
            $this->logger->info('Saving campaign to database');
            $result = $this->campaignRepository->save($campaign);

            if ($result) {
                $this->logger->info('Campaign saved successfully', [
                    'campaign_id' => $campaign->getId()
                ]);
                return $this->jsonResponse($response, [
                    'message' => 'Campaign created successfully',
                    'campaign_id' => $campaign->getId()
                ]);
            } else {
                $this->logger->error('Failed to save campaign');
                return $this->jsonResponse($response, ['error' => 'Failed to create campaign'], 500);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while processing campaign request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }
    
    /**
     * עדכון קמפיין קיים
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        // נתוני העדכון
        $data = $request->getParsedBody();
        
        try {
            // בדיקה שהקמפיין קיים ושייך לחשבון
            $checkQuery = "
                SELECT id, status FROM campaigns
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            // בדיקה שהקמפיין לא נשלח כבר
            if ($campaignData['status'] === Campaign::STATUS_SENT) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot update a campaign that has already been sent'
                ], 400);
            }
            
            // בניית השאילתה לעדכון
            $updates = [];
            $params = [];
            
            $allowedFields = [
                'name', 'subject', 'from_email', 'from_name', 'reply_to',
                'content_html', 'content_text', 'status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }
            
            if (!empty($updates)) {
                $this->db->beginTransaction();
                
                // עדכון הקמפיין
                $query = "
                    UPDATE campaigns
                    SET " . implode(', ', $updates) . ",
                        updated_at = NOW()
                    WHERE id = :id AND account_id = :account_id
                ";
                
                $stmt = $this->db->prepare($query);
                
                foreach ($params as $param => $value) {
                    $stmt->bindValue($param, $value);
                }
                
                $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->execute();
                
                // טיפול ברשימות התפוצה אם יש שינוי
                if (isset($data['lists']) && is_array($data['lists'])) {
                    // מחיקת הרשימות הקיימות
                    $deleteListsQuery = "
                        DELETE FROM campaign_lists 
                        WHERE campaign_id = :campaign_id
                    ";
                    
                    $stmt = $this->db->prepare($deleteListsQuery);
                    $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    // הוספת הרשימות החדשות
                    $insertListQuery = "
                        INSERT INTO campaign_lists (campaign_id, list_id)
                        VALUES (:campaign_id, :list_id)
                    ";
                    
                    $insertListStmt = $this->db->prepare($insertListQuery);
                    
                    foreach ($data['lists'] as $listId) {
                        $insertListStmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
                        $insertListStmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
                        $insertListStmt->execute();
                    }
                }
                
                $this->db->commit();
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'Campaign updated successfully'
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No updates provided'
                ], 400);
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            $this->logger->error('Error updating campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * שליחת קמפיין
     */
    public function send(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        try {
            // בדיקה שהקמפיין קיים, שייך לחשבון ולא נשלח כבר
            $checkQuery = "
                SELECT c.*, COUNT(cl.list_id) as list_count
                FROM campaigns c
                LEFT JOIN campaign_lists cl ON c.id = cl.campaign_id
                WHERE c.id = :id AND c.account_id = :account_id
                GROUP BY c.id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            if ($campaignData['status'] === Campaign::STATUS_SENT) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign has already been sent'
                ], 400);
            }
            
            if ($campaignData['list_count'] === 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign has no recipient lists'
                ], 400);
            }
            
            // שליפת נמענים ממאגד של כל רשימות התפוצה
            $recipientsQuery = "
                SELECT DISTINCT lr.email, lr.first_name, lr.last_name
                FROM list_recipients lr
                JOIN campaign_lists cl ON lr.list_id = cl.list_id
                WHERE cl.campaign_id = :campaign_id
                AND lr.status = 'active'
            ";
            
            $stmt = $this->db->prepare($recipientsQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            $recipients = $stmt->fetchAll();
            
            if (empty($recipients)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No active recipients found in the campaign lists'
                ], 400);
            }
            
            // יצירת עבודות התור לשליחת המיילים
            $emailSendJobs = [];
            $batchId = uniqid('batch_', true);
            
            foreach ($recipients as $recipient) {
                // יצירת אובייקט Email
                $email = new Email();
                $email->setSubject($campaignData['subject']);
                $email->setSenderEmail($campaignData['from_email']);
                $email->setSenderName($campaignData['from_name']);
                $email->setRecipientEmail($recipient['email']);
                $email->setRecipientName($recipient['first_name'] . ' ' . $recipient['last_name']);
                
                if ($campaignData['reply_to']) {
                    $email->setReplyTo($campaignData['reply_to']);
                }
                
                // עיבוד התוכן עם נתונים אישיים
                $personalizedHtml = $this->personalizeContent(
                    $campaignData['content_html'],
                    $recipient
                );
                $email->setHtmlBody($personalizedHtml);
                
                if ($campaignData['content_text']) {
                    $personalizedText = $this->personalizeContent(
                        $campaignData['content_text'],
                        $recipient
                    );
                    $email->setTextBody($personalizedText);
                }
                
                // הוספת מידע לצורך מעקב
                $email->addParam('campaign_id', $campaignId);
                $email->addParam('batch_id', $batchId);
                
                // הוספה לתור
                $this->queueManager->addToQueue('email_send', [
                    'email' => $email->toArray(),
                    'campaign_id' => $campaignId,
                    'tracking_enabled' => true
                ]);
                
                // תיעוד אירוע שליחה
                $trackingQuery = "
                    INSERT INTO tracking_events (
                        campaign_id, recipient_email, event_type, occurred_at,
                        event_data, batch_id
                    ) VALUES (
                        :campaign_id, :recipient_email, 'queued', NOW(),
                        :event_data, :batch_id
                    )
                ";
                
                $stmt = $this->db->prepare($trackingQuery);
                $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
                $stmt->bindValue(':recipient_email', $recipient['email']);
                $stmt->bindValue(':event_data', json_encode([
                    'recipient_name' => $recipient['first_name'] . ' ' . $recipient['last_name']
                ]));
                $stmt->bindValue(':batch_id', $batchId);
                $stmt->execute();
            }
            
            // עדכון סטטוס הקמפיין ל"בתהליך שליחה"
            $updateQuery = "
                UPDATE campaigns
                SET status = :status, sent_at = NOW()
                WHERE id = :id
            ";
            
            $stmt = $this->db->prepare($updateQuery);
            $stmt->bindValue(':status', Campaign::STATUS_SENDING, PDO::PARAM_STR);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Campaign sending initiated',
                'data' => [
                    'recipient_count' => count($recipients),
                    'batch_id' => $batchId
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error sending campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to send campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * מחיקת קמפיין
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        try {
            // בדיקה שהקמפיין קיים ושייך לחשבון
            $checkQuery = "
                SELECT id, status FROM campaigns
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            // בדיקה שהקמפיין לא נשלח כבר
            if ($campaignData['status'] === Campaign::STATUS_SENT || 
                $campaignData['status'] === Campaign::STATUS_SENDING) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete a campaign that has been sent or is in the process of sending'
                ], 400);
            }
            
            $this->db->beginTransaction();
            
            // מחיקת הקשרים עם רשימות תפוצה
            $deleteLinksQuery = "
                DELETE FROM campaign_lists
                WHERE campaign_id = :campaign_id
            ";
            
            $stmt = $this->db->prepare($deleteLinksQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            // מחיקת הקמפיין עצמו
            $deleteQuery = "
                DELETE FROM campaigns
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($deleteQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $this->db->commit();
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Campaign deleted successfully'
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            
            $this->logger->error('Error deleting campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * החלפת פרמטרים בתוכן החזר המייל עם מידע אישי
     */
    private function personalizeContent(string $content, array $recipientData): string
    {
        $patterns = [
            '/\{\{first_name\}\}/' => $recipientData['first_name'] ?? '',
            '/\{\{last_name\}\}/' => $recipientData['last_name'] ?? '',
            '/\{\{email\}\}/' => $recipientData['email'] ?? '',
            '/\{\{full_name\}\}/' => trim(($recipientData['first_name'] ?? '') . ' ' . ($recipientData['last_name'] ?? '')),
        ];
        
        return preg_replace(array_keys($patterns), array_values($patterns), $content);
    }
    
    /**
     * השהיית קמפיין בתהליך שליחה
     */
    public function pause(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        try {
            // בדיקה שהקמפיין קיים, שייך לחשבון ובתהליך שליחה
            $checkQuery = "
                SELECT id, status 
                FROM campaigns
                WHERE id = :id 
                AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            if ($campaignData['status'] !== Campaign::STATUS_SENDING) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Only campaigns in sending status can be paused'
                ], 400);
            }
            
            // עדכון סטטוס הקמפיין
            $updateQuery = "
                UPDATE campaigns
                SET status = :status
                WHERE id = :id
            ";
            
            $stmt = $this->db->prepare($updateQuery);
            $stmt->bindValue(':status', Campaign::STATUS_PAUSED, PDO::PARAM_STR);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            // שליחת פקודה למערכת התור להשהות עבודות שליחה הקשורות לקמפיין זה
            $this->queueManager->pauseJobs('email_send', [
                'campaign_id' => $campaignId
            ]);
            
            // תיעוד אירוע השהייה
            $this->logger->info('Campaign paused', [
                'campaign_id' => $campaignId,
                'account_id' => $accountId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Campaign paused successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error pausing campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to pause campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * המשך שליחה של קמפיין מושהה
     */
    public function resume(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        try {
            // בדיקה שהקמפיין קיים, שייך לחשבון ובסטטוס מושהה
            $checkQuery = "
                SELECT id, status 
                FROM campaigns
                WHERE id = :id 
                AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            if ($campaignData['status'] !== Campaign::STATUS_PAUSED) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Only paused campaigns can be resumed'
                ], 400);
            }
            
            // עדכון סטטוס הקמפיין
            $updateQuery = "
                UPDATE campaigns
                SET status = :status
                WHERE id = :id
            ";
            
            $stmt = $this->db->prepare($updateQuery);
            $stmt->bindValue(':status', Campaign::STATUS_SENDING, PDO::PARAM_STR);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            // שליחת פקודה למערכת התור להמשיך עבודות שליחה הקשורות לקמפיין זה
            $this->queueManager->resumeJobs('email_send', [
                'campaign_id' => $campaignId
            ]);
            
            // תיעוד אירוע המשך שליחה
            $this->logger->info('Campaign resumed', [
                'campaign_id' => $campaignId,
                'account_id' => $accountId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Campaign resumed successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error resuming campaign', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to resume campaign: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * קבלת סטטוס מפורט של קמפיין
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $campaignId = (int)$args['id'];
        
        try {
            // בדיקה שהקמפיין קיים ושייך לחשבון
            $checkQuery = "
                SELECT c.*, COUNT(cl.list_id) as list_count 
                FROM campaigns c
                LEFT JOIN campaign_lists cl ON c.id = cl.campaign_id
                WHERE c.id = :id AND c.account_id = :account_id
                GROUP BY c.id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignData = $stmt->fetch();
            
            if (!$campaignData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }
            
            // שליפת סטטיסטיקות שליחה
            $statsQuery = "
                SELECT
                    COUNT(DISTINCT recipient_email) as total_recipients,
                    SUM(CASE WHEN event_type = 'queued' THEN 1 ELSE 0 END) as queued_count,
                    SUM(CASE WHEN event_type = 'send' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) as open_count,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) as click_count,
                    SUM(CASE WHEN event_type = 'bounce' THEN 1 ELSE 0 END) as bounce_count,
                    SUM(CASE WHEN event_type = 'complaint' THEN 1 ELSE 0 END) as complaint_count,
                    SUM(CASE WHEN event_type = 'unsubscribe' THEN 1 ELSE 0 END) as unsubscribe_count
                FROM tracking_events
                WHERE campaign_id = :campaign_id
            ";
            
            $stmt = $this->db->prepare($statsQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            $stats = $stmt->fetch();
            
            // שליפת מידע על אירועי שליחה אחרונים
            $recentEventsQuery = "
                SELECT 
                    event_type, 
                    recipient_email, 
                    occurred_at,
                    event_data
                FROM tracking_events
                WHERE campaign_id = :campaign_id
                ORDER BY occurred_at DESC
                LIMIT 20
            ";
            
            $stmt = $this->db->prepare($recentEventsQuery);
            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->execute();
            
            $recentEvents = $stmt->fetchAll();
            
            // חישוב אחוזים אם יש מיילים שנשלחו
            $percentages = [
                'open_rate' => 0,
                'click_rate' => 0,
                'bounce_rate' => 0,
                'complaint_rate' => 0,
                'unsubscribe_rate' => 0
            ];
            
            if ($stats['sent_count'] > 0) {
                $percentages = [
                    'open_rate' => round(($stats['open_count'] / $stats['sent_count']) * 100, 2),
                    'click_rate' => round(($stats['click_count'] / $stats['sent_count']) * 100, 2),
                    'bounce_rate' => round(($stats['bounce_count'] / $stats['sent_count']) * 100, 2),
                    'complaint_rate' => round(($stats['complaint_count'] / $stats['sent_count']) * 100, 2),
                    'unsubscribe_rate' => round(($stats['unsubscribe_count'] / $stats['sent_count']) * 100, 2)
                ];
            }
            
            // מספר מיילים שעדיין לא נשלחו (בהמתנה בתור)
            $pendingCount = 0;
            if ($campaignData['status'] === Campaign::STATUS_SENDING || 
                $campaignData['status'] === Campaign::STATUS_PAUSED) {
                $pendingCount = $stats['total_recipients'] - $stats['sent_count'];
                if ($pendingCount < 0) $pendingCount = 0;
            }
            
            // הכנת המידע לתשובה
            $statusData = [
                'campaign' => [
                    'id' => $campaignData['id'],
                    'name' => $campaignData['name'],
                    'status' => $campaignData['status'],
                    'subject' => $campaignData['subject'],
                    'created_at' => $campaignData['created_at'],
                    'sent_at' => $campaignData['sent_at'],
                    'list_count' => $campaignData['list_count']
                ],
                'progress' => [
                    'total_recipients' => $stats['total_recipients'] ?: 0,
                    'sent' => $stats['sent_count'] ?: 0,
                    'pending' => $pendingCount,
                    'percent_complete' => $stats['total_recipients'] > 0 
                        ? round(($stats['sent_count'] / $stats['total_recipients']) * 100, 1) 
                        : 0
                ],
                'engagement' => array_merge($stats, $percentages),
                'recent_events' => $recentEvents
            ];
            
            // אם הקמפיין בתהליך שליחה, נוסיף מידע על קצב השליחה
            if ($campaignData['status'] === Campaign::STATUS_SENDING) {
                // חישוב קצב השליחה לשעה האחרונה
                $hourlyRateQuery = "
                    SELECT COUNT(*) as count_last_hour
                    FROM tracking_events
                    WHERE campaign_id = :campaign_id
                    AND event_type = 'send'
                    AND occurred_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ";
                
                $stmt = $this->db->prepare($hourlyRateQuery);
                $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
                $stmt->execute();
                
                $hourlyRate = $stmt->fetchColumn();
                
                // הוספת זמן משוער לסיום
                $statusData['progress']['sending_rate_per_hour'] = (int)$hourlyRate;
                
                if ($hourlyRate > 0 && $pendingCount > 0) {
                    $estimatedHoursRemaining = $pendingCount / $hourlyRate;
                    $statusData['progress']['estimated_time_remaining'] = round($estimatedHoursRemaining, 1);
                }
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $statusData
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting campaign status', [
                'exception' => $e->getMessage(),
                'campaign_id' => $campaignId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get campaign status: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * JSON Response Helper
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
} 