<?php

declare(strict_types=1);

namespace MailHebrew\Api;

use MailHebrew\Domain\MailingList\MailingList;
use MailHebrew\Domain\MailingList\Recipient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

class ListController
{
    private LoggerInterface $logger;
    private PDO $db;

    public function __construct(
        LoggerInterface $logger,
        PDO $db
    ) {
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * קבלת כל רשימות התפוצה
     */
    public function getAll(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $params['account_id'] ?? 1;
        
        // פרמטרים לסינון
        $status = $params['status'] ?? null;
        $search = $params['search'] ?? null;
        $tag = $params['tag'] ?? null;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $orderBy = $params['order_by'] ?? 'created_at';
        $orderDir = strtoupper($params['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        try {
            // בניית השאילתה
            $query = "
                SELECT l.*,
                       COUNT(DISTINCT lr.recipient_id) as recipient_count
                FROM email_lists l
                LEFT JOIN list_recipients lr ON l.id = lr.list_id
                WHERE l.account_id = :account_id
            ";
            
            $queryParams = ['account_id' => $accountId];
            
            if ($status) {
                $query .= " AND l.is_active = :status";
                $queryParams['status'] = $status === 'active' ? 1 : 0;
            }
            
            if ($search) {
                $query .= " AND (l.name LIKE :search OR l.description LIKE :search)";
                $queryParams['search'] = '%' . $search . '%';
            }
            
            if ($tag) {
                $query .= " AND l.tags LIKE :tag";
                $queryParams['tag'] = '%"' . $tag . '"%';
            }
            
            $query .= " GROUP BY l.id";
            $query .= " ORDER BY l.{$orderBy} {$orderDir}";
            $query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($query);
            
            // חיבור פרמטרים מיוחדים
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            // חיבור שאר הפרמטרים
            foreach ($queryParams as $key => $val) {
                if ($key !== 'limit' && $key !== 'offset') {
                    $stmt->bindValue(":{$key}", $val);
                }
            }
            
            $stmt->execute();
            $lists = $stmt->fetchAll();
            
            // המרת נתונים
            $formattedLists = [];
            foreach ($lists as $listData) {
                // המרת התגים מ-JSON למערך
                if (isset($listData['tags']) && $listData['tags']) {
                    $listData['tags'] = json_decode($listData['tags'], true);
                } else {
                    $listData['tags'] = [];
                }
                
                // המרת סטטוס מבוליאני למחרוזת
                $listData['status'] = $listData['is_active'] == 1 ? 
                    MailingList::STATUS_ACTIVE : MailingList::STATUS_INACTIVE;
                
                $formattedLists[] = $listData;
            }
            
            // ספירת סך כל הרשימות (ללא LIMIT)
            $countQuery = "
                SELECT COUNT(*) 
                FROM email_lists 
                WHERE account_id = :account_id
            ";
            
            $countParams = ['account_id' => $accountId];
            
            if ($status) {
                $countQuery .= " AND is_active = :status";
                $countParams['status'] = $status === 'active' ? 1 : 0;
            }
            
            if ($search) {
                $countQuery .= " AND (name LIKE :search OR description LIKE :search)";
                $countParams['search'] = '%' . $search . '%';
            }
            
            if ($tag) {
                $countQuery .= " AND tags LIKE :tag";
                $countParams['tag'] = '%"' . $tag . '"%';
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
                    'lists' => $formattedLists,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting lists', [
                'exception' => $e->getMessage(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get lists: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * קבלת רשימת תפוצה בודדת לפי ID
     */
    public function getOne(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];

        try {
            // שליפת הרשימה
            $query = "
                SELECT l.*,
                       COUNT(DISTINCT lr.recipient_id) as recipient_count
                FROM email_lists l
                LEFT JOIN list_recipients lr ON l.id = lr.list_id
                WHERE l.id = :id AND l.account_id = :account_id
                GROUP BY l.id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $listData = $stmt->fetch();
            
            if (!$listData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            // המרת התגים מ-JSON למערך
            if (isset($listData['tags']) && $listData['tags']) {
                $listData['tags'] = json_decode($listData['tags'], true);
            } else {
                $listData['tags'] = [];
            }
            
            // המרת סטטוס מבוליאני למחרוזת
            $listData['status'] = $listData['is_active'] == 1 ? 
                MailingList::STATUS_ACTIVE : MailingList::STATUS_INACTIVE;
            
            // שליפת מידע על קמפיינים משויכים
            $campaignsQuery = "
                SELECT c.id, c.name, c.status, c.created_at
                FROM campaigns c
                JOIN campaign_lists cl ON c.id = cl.campaign_id
                WHERE cl.list_id = :list_id
                ORDER BY c.created_at DESC
                LIMIT 5
            ";
            
            $stmt = $this->db->prepare($campaignsQuery);
            $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaigns = $stmt->fetchAll();
            
            // הוספת מידע על קמפיינים
            $listData['recent_campaigns'] = $campaigns;
            $listData['campaign_count'] = count($campaigns);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $listData
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting list', [
                'exception' => $e->getMessage(),
                'list_id' => $listId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get list: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * יצירת רשימת תפוצה חדשה
     */
    public function create(Request $request, Response $response): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        
        // נתוני הרשימה החדשה
        $data = $request->getParsedBody();
        
        // וידוא שהנתונים הבסיסיים קיימים
        if (empty($data['name'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing required field: name'
            ], 400);
        }
        
        try {
            // יצירת אובייקט רשימה
            $status = isset($data['status']) ? 
                (($data['status'] === MailingList::STATUS_ACTIVE) ? MailingList::STATUS_ACTIVE : MailingList::STATUS_INACTIVE) : 
                MailingList::STATUS_ACTIVE;
            
            $list = new MailingList(
                $accountId,
                $data['name'],
                $data['description'] ?? null,
                $status,
                $data['tags'] ?? []
            );
            
            // שמירה במסד הנתונים
            $query = "
                INSERT INTO email_lists (
                    account_id, name, description, is_active, tags, created_at
                ) VALUES (
                    :account_id, :name, :description, :is_active, :tags, :created_at
                )
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':account_id', $list->getAccountId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $list->getName());
            $stmt->bindValue(':description', $list->getDescription());
            $stmt->bindValue(':is_active', $list->isActive() ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':tags', json_encode($list->getTags()));
            $stmt->bindValue(':created_at', $list->getCreatedAt()->format('Y-m-d H:i:s'));
            $stmt->execute();
            
            $listId = (int)$this->db->lastInsertId();
            $list->setId($listId);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $list->toArray(),
                'message' => 'List created successfully'
            ], 201);
        } catch (\Throwable $e) {
            $this->logger->error('Error creating list', [
                'exception' => $e->getMessage(),
                'data' => $data
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to create list: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * עדכון רשימת תפוצה קיימת
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];
        
        // נתוני העדכון
        $data = $request->getParsedBody();
        
        try {
            // בדיקה שהרשימה קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT * FROM email_lists
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $listData = $stmt->fetch();
            
            if (!$listData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            // המרת נתונים ליצירת אובייקט
            if (isset($listData['tags']) && $listData['tags']) {
                $listData['tags'] = json_decode($listData['tags'], true);
            } else {
                $listData['tags'] = [];
            }
            
            // המרת סטטוס מבוליאני למחרוזת
            $listData['status'] = $listData['is_active'] == 1 ? 
                MailingList::STATUS_ACTIVE : MailingList::STATUS_INACTIVE;
                
            // יצירת אובייקט רשימה מהנתונים הקיימים
            $list = MailingList::fromArray([
                'id' => $listData['id'],
                'account_id' => $listData['account_id'],
                'name' => $listData['name'],
                'description' => $listData['description'],
                'status' => $listData['status'],
                'tags' => $listData['tags'],
                'created_at' => $listData['created_at'],
                'updated_at' => $listData['updated_at']
            ]);
            
            // עדכון נתונים לפי הבקשה
            if (isset($data['name'])) {
                $list->setName($data['name']);
            }
            
            if (array_key_exists('description', $data)) {
                $list->setDescription($data['description']);
            }
            
            if (isset($data['status'])) {
                $list->setStatus($data['status']);
            }
            
            if (isset($data['tags'])) {
                $list->setTags($data['tags']);
            }
            
            // עדכון במסד הנתונים
            $query = "
                UPDATE email_lists
                SET name = :name,
                    description = :description,
                    is_active = :is_active,
                    tags = :tags,
                    updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $list->getId(), PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $list->getAccountId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $list->getName());
            $stmt->bindValue(':description', $list->getDescription());
            $stmt->bindValue(':is_active', $list->isActive() ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':tags', json_encode($list->getTags()));
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No changes were made'
                ], 400);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $list->toArray(),
                'message' => 'List updated successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error updating list', [
                'exception' => $e->getMessage(),
                'list_id' => $listId,
                'data' => $data
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update list: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * מחיקת רשימת תפוצה
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];
        
        try {
            // בדיקה שהרשימה קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT id FROM email_lists
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $listData = $stmt->fetch();
            
            if (!$listData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            // בדיקה אם הרשימה משויכת לקמפיינים פעילים
            $campaignQuery = "
                SELECT COUNT(*) FROM campaign_lists cl
                JOIN campaigns c ON cl.campaign_id = c.id
                WHERE cl.list_id = :list_id
                AND c.status IN ('draft', 'scheduled', 'sending', 'paused')
            ";
            
            $stmt = $this->db->prepare($campaignQuery);
            $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignCount = (int)$stmt->fetchColumn();
            
            if ($campaignCount > 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete list: It is associated with ' . $campaignCount . ' active campaign(s)'
                ], 400);
            }
            
            $this->db->beginTransaction();
            
            try {
                // מחיקת הקשרים עם נמענים
                $deleteRecipientsQuery = "
                    DELETE FROM list_recipients
                    WHERE list_id = :list_id
                ";
                
                $stmt = $this->db->prepare($deleteRecipientsQuery);
                $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
                $stmt->execute();
                
                // מחיקת הקשרים עם קמפיינים
                $deleteCampaignLinksQuery = "
                    DELETE FROM campaign_lists
                    WHERE list_id = :list_id
                ";
                
                $stmt = $this->db->prepare($deleteCampaignLinksQuery);
                $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
                $stmt->execute();
                
                // מחיקת הרשימה עצמה
                $deleteListQuery = "
                    DELETE FROM email_lists
                    WHERE id = :id AND account_id = :account_id
                ";
                
                $stmt = $this->db->prepare($deleteListQuery);
                $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    $this->db->rollBack();
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Failed to delete list'
                    ], 500);
                }
                
                $this->db->commit();
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'List deleted successfully'
                ]);
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error deleting list', [
                'exception' => $e->getMessage(),
                'list_id' => $listId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete list: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * קבלת נמענים ברשימת תפוצה
     */
    public function getRecipients(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];
        
        // פרמטרים לסינון
        $params = $request->getQueryParams();
        $status = $params['status'] ?? null;
        $search = $params['search'] ?? null;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $orderBy = $params['order_by'] ?? 'email';
        $orderDir = strtoupper($params['order_dir'] ?? 'ASC') === 'ASC' ? 'ASC' : 'DESC';
        
        try {
            // בדיקה שהרשימה קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT id FROM email_lists
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            // שליפת הנמענים
            $query = "
                SELECT r.*, lr.created_at as subscribed_at
                FROM recipients r
                JOIN list_recipients lr ON r.id = lr.recipient_id
                WHERE lr.list_id = :list_id
            ";
            
            $queryParams = ['list_id' => $listId];
            
            if ($status) {
                if ($status === 'active') {
                    $query .= " AND r.is_active = 1 AND r.unsubscribed = 0 AND r.bounced = 0 AND r.complaint = 0";
                } elseif ($status === 'unsubscribed') {
                    $query .= " AND r.unsubscribed = 1";
                } elseif ($status === 'bounced') {
                    $query .= " AND r.bounced = 1";
                } elseif ($status === 'complained') {
                    $query .= " AND r.complaint = 1";
                } elseif ($status === 'inactive') {
                    $query .= " AND r.is_active = 0";
                }
            }
            
            if ($search) {
                $query .= " AND (r.email LIKE :search OR r.name LIKE :search)";
                $queryParams['search'] = '%' . $search . '%';
            }
            
            $query .= " ORDER BY r.{$orderBy} {$orderDir}";
            $query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($query);
            
            // חיבור פרמטרים מיוחדים
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            // חיבור שאר הפרמטרים
            foreach ($queryParams as $key => $val) {
                if ($key !== 'limit' && $key !== 'offset') {
                    $stmt->bindValue(":{$key}", $val);
                }
            }
            
            $stmt->execute();
            $recipients = $stmt->fetchAll();
            
            // המרת נתונים
            $formattedRecipients = [];
            foreach ($recipients as $recipientData) {
                // המרת מטא-דאטה מ-JSON למערך
                if (isset($recipientData['metadata']) && $recipientData['metadata']) {
                    $recipientData['metadata'] = json_decode($recipientData['metadata'], true);
                } else {
                    $recipientData['metadata'] = [];
                }
                
                // המרת סטטוסים בוליאניים למחרוזות סטטוס
                if ($recipientData['unsubscribed'] == 1) {
                    $recipientData['status'] = Recipient::STATUS_UNSUBSCRIBED;
                } elseif ($recipientData['bounced'] == 1) {
                    $recipientData['status'] = Recipient::STATUS_BOUNCED;
                } elseif ($recipientData['complaint'] == 1) {
                    $recipientData['status'] = Recipient::STATUS_COMPLAINED;
                } elseif ($recipientData['is_active'] == 0) {
                    $recipientData['status'] = 'inactive';
                } else {
                    $recipientData['status'] = Recipient::STATUS_ACTIVE;
                }
                
                $formattedRecipients[] = $recipientData;
            }
            
            // ספירת סך כל הנמענים (ללא LIMIT)
            $countQuery = "
                SELECT COUNT(*) 
                FROM recipients r
                JOIN list_recipients lr ON r.id = lr.recipient_id
                WHERE lr.list_id = :list_id
            ";
            
            $countParams = ['list_id' => $listId];
            
            if ($status) {
                if ($status === 'active') {
                    $countQuery .= " AND r.is_active = 1 AND r.unsubscribed = 0 AND r.bounced = 0 AND r.complaint = 0";
                } elseif ($status === 'unsubscribed') {
                    $countQuery .= " AND r.unsubscribed = 1";
                } elseif ($status === 'bounced') {
                    $countQuery .= " AND r.bounced = 1";
                } elseif ($status === 'complained') {
                    $countQuery .= " AND r.complaint = 1";
                } elseif ($status === 'inactive') {
                    $countQuery .= " AND r.is_active = 0";
                }
            }
            
            if ($search) {
                $countQuery .= " AND (r.email LIKE :search OR r.name LIKE :search)";
                $countParams['search'] = '%' . $search . '%';
            }
            
            $stmt = $this->db->prepare($countQuery);
            
            foreach ($countParams as $key => $val) {
                $stmt->bindValue(":{$key}", $val);
            }
            
            $stmt->execute();
            $totalCount = $stmt->fetchColumn();
            
            // סטטיסטיקות נמענים ברשימה
            $statsQuery = "
                SELECT 
                    COUNT(*) as total_count,
                    SUM(CASE WHEN r.is_active = 1 AND r.unsubscribed = 0 AND r.bounced = 0 AND r.complaint = 0 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN r.unsubscribed = 1 THEN 1 ELSE 0 END) as unsubscribed_count,
                    SUM(CASE WHEN r.bounced = 1 THEN 1 ELSE 0 END) as bounced_count,
                    SUM(CASE WHEN r.complaint = 1 THEN 1 ELSE 0 END) as complained_count,
                    SUM(CASE WHEN r.is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                FROM recipients r
                JOIN list_recipients lr ON r.id = lr.recipient_id
                WHERE lr.list_id = :list_id
            ";
            
            $stmt = $this->db->prepare($statsQuery);
            $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
            $stmt->execute();
            
            $stats = $stmt->fetch();
            
            // החזרת התגובה
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'recipients' => $formattedRecipients,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'stats' => [
                        'total_count' => (int)$stats['total_count'],
                        'active_count' => (int)$stats['active_count'],
                        'unsubscribed_count' => (int)$stats['unsubscribed_count'],
                        'bounced_count' => (int)$stats['bounced_count'],
                        'complained_count' => (int)$stats['complained_count'],
                        'inactive_count' => (int)$stats['inactive_count']
                    ]
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting recipients', [
                'exception' => $e->getMessage(),
                'list_id' => $listId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get recipients: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * הוספת נמענים לרשימת תפוצה
     */
    public function addRecipients(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];
        
        // נתוני הנמענים להוספה
        $data = $request->getParsedBody();
        
        if (empty($data['recipients']) || !is_array($data['recipients'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing or invalid recipients data'
            ], 400);
        }
        
        try {
            // בדיקה שהרשימה קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT id FROM email_lists
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            $this->db->beginTransaction();
            
            try {
                $insertRecipientQuery = "
                    INSERT INTO recipients (
                        account_id, email, name, is_active, metadata, created_at
                    ) VALUES (
                        :account_id, :email, :name, :is_active, :metadata, :created_at
                    )
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        is_active = VALUES(is_active),
                        metadata = VALUES(metadata),
                        updated_at = NOW()
                ";
                
                $insertListRecipientQuery = "
                    INSERT IGNORE INTO list_recipients (
                        list_id, recipient_id, created_at
                    ) VALUES (
                        :list_id, :recipient_id, :created_at
                    )
                ";
                
                $insertRecipientStmt = $this->db->prepare($insertRecipientQuery);
                $insertListRecipientStmt = $this->db->prepare($insertListRecipientQuery);
                
                $addedCount = 0;
                $duplicateCount = 0;
                $errors = [];
                
                foreach ($data['recipients'] as $idx => $recipientData) {
                    // וידוא שיש כתובת אימייל
                    if (empty($recipientData['email'])) {
                        $errors[] = [
                            'index' => $idx,
                            'message' => 'Missing email'
                        ];
                        continue;
                    }
                    
                    // ניקוי כתובת אימייל
                    $email = filter_var(trim($recipientData['email']), FILTER_SANITIZE_EMAIL);
                    
                    // בדיקת תקינות כתובת אימייל
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = [
                            'index' => $idx,
                            'email' => $recipientData['email'],
                            'message' => 'Invalid email address'
                        ];
                        continue;
                    }
                    
                    try {
                        // הכנת נתוני הנמען
                        $name = $recipientData['name'] ?? '';
                        $isActive = isset($recipientData['is_active']) ? (bool)$recipientData['is_active'] : true;
                        $metadata = $recipientData['metadata'] ?? [];
                        
                        // הכנסת הנמען למסד הנתונים או עדכון נמען קיים
                        $insertRecipientStmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                        $insertRecipientStmt->bindValue(':email', $email);
                        $insertRecipientStmt->bindValue(':name', $name);
                        $insertRecipientStmt->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
                        $insertRecipientStmt->bindValue(':metadata', json_encode($metadata));
                        $insertRecipientStmt->bindValue(':created_at', date('Y-m-d H:i:s'));
                        $insertRecipientStmt->execute();
                        
                        // בדיקה אם הנמען חדש או קיים
                        $isNewRecipient = $insertRecipientStmt->rowCount() > 0;
                        
                        // קבלת מזהה הנמען
                        if ($isNewRecipient) {
                            $recipientId = (int)$this->db->lastInsertId();
                        } else {
                            // שליפת מזהה הנמען הקיים
                            $recipientQuery = "
                                SELECT id FROM recipients
                                WHERE account_id = :account_id AND email = :email
                            ";
                            
                            $stmt = $this->db->prepare($recipientQuery);
                            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                            $stmt->bindValue(':email', $email);
                            $stmt->execute();
                            
                            $recipientId = (int)$stmt->fetchColumn();
                        }
                        
                        // בדיקה אם הנמען כבר קיים ברשימה
                        $checkExistsQuery = "
                            SELECT 1 FROM list_recipients
                            WHERE list_id = :list_id AND recipient_id = :recipient_id
                        ";
                        
                        $stmt = $this->db->prepare($checkExistsQuery);
                        $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
                        $stmt->bindValue(':recipient_id', $recipientId, PDO::PARAM_INT);
                        $stmt->execute();
                        
                        $existsInList = $stmt->fetchColumn();
                        
                        if ($existsInList) {
                            $duplicateCount++;
                            continue;
                        }
                        
                        // הוספת הנמען לרשימה
                        $insertListRecipientStmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
                        $insertListRecipientStmt->bindValue(':recipient_id', $recipientId, PDO::PARAM_INT);
                        $insertListRecipientStmt->bindValue(':created_at', date('Y-m-d H:i:s'));
                        $insertListRecipientStmt->execute();
                        
                        $addedCount++;
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'index' => $idx,
                            'email' => $recipientData['email'],
                            'message' => $e->getMessage()
                        ];
                    }
                }
                
                // עדכון מספר הנמענים ברשימה
                $updateListQuery = "
                    UPDATE email_lists
                    SET updated_at = NOW()
                    WHERE id = :id
                ";
                
                $stmt = $this->db->prepare($updateListQuery);
                $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
                $stmt->execute();
                
                $this->db->commit();
                
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => "Recipients added successfully",
                    'data' => [
                        'added_count' => $addedCount,
                        'duplicate_count' => $duplicateCount,
                        'error_count' => count($errors),
                        'errors' => $errors
                    ]
                ]);
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error adding recipients', [
                'exception' => $e->getMessage(),
                'list_id' => $listId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to add recipients: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * הסרת נמען מרשימת תפוצה
     */
    public function removeRecipient(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $listId = (int)$args['id'];
        $email = $args['email'];
        
        try {
            // בדיקה שהרשימה קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT id FROM email_lists
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'List not found'
                ], 404);
            }
            
            // קבלת מזהה הנמען
            $recipientQuery = "
                SELECT id FROM recipients
                WHERE account_id = :account_id AND email = :email
            ";
            
            $stmt = $this->db->prepare($recipientQuery);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email);
            $stmt->execute();
            
            $recipientId = $stmt->fetchColumn();
            
            if (!$recipientId) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Recipient not found'
                ], 404);
            }
            
            // הסרה מהרשימה
            $deleteQuery = "
                DELETE FROM list_recipients
                WHERE list_id = :list_id AND recipient_id = :recipient_id
            ";
            
            $stmt = $this->db->prepare($deleteQuery);
            $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
            $stmt->bindValue(':recipient_id', $recipientId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Recipient not found in this list'
                ], 404);
            }
            
            // עדכון מועד עדכון הרשימה
            $updateListQuery = "
                UPDATE email_lists
                SET updated_at = NOW()
                WHERE id = :id
            ";
            
            $stmt = $this->db->prepare($updateListQuery);
            $stmt->bindValue(':id', $listId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Recipient removed from list successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error removing recipient', [
                'exception' => $e->getMessage(),
                'list_id' => $listId,
                'email' => $email
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to remove recipient: ' . $e->getMessage()
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