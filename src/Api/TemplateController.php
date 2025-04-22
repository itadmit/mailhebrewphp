<?php

declare(strict_types=1);

namespace MailHebrew\Api;

use MailHebrew\Domain\Template\Template;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

class TemplateController
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
     * קבלת כל התבניות
     */
    public function getAll(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $params['account_id'] ?? 1;
        
        // פרמטרים לסינון
        $status = $params['status'] ?? null;
        $type = $params['type'] ?? null;
        $category = $params['category'] ?? null;
        $tag = $params['tag'] ?? null;
        $search = $params['search'] ?? null;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $orderBy = $params['order_by'] ?? 'created_at';
        $orderDir = strtoupper($params['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        try {
            // בניית השאילתה
            $query = "
                SELECT * FROM templates
                WHERE account_id = :account_id
            ";
            
            $queryParams = ['account_id' => $accountId];
            
            if ($status) {
                $query .= " AND status = :status";
                $queryParams['status'] = $status;
            }
            
            if ($type) {
                $query .= " AND type = :type";
                $queryParams['type'] = $type;
            }
            
            if ($category) {
                $query .= " AND category = :category";
                $queryParams['category'] = $category;
            }
            
            if ($tag) {
                $query .= " AND tags LIKE :tag";
                $queryParams['tag'] = '%"' . $tag . '"%';
            }
            
            if ($search) {
                $query .= " AND (name LIKE :search OR description LIKE :search)";
                $queryParams['search'] = '%' . $search . '%';
            }
            
            $query .= " ORDER BY {$orderBy} {$orderDir}";
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
            $templates = $stmt->fetchAll();
            
            // המרת נתונים
            $formattedTemplates = [];
            foreach ($templates as $templateData) {
                // המרת התגים מ-JSON למערך
                if (isset($templateData['tags']) && $templateData['tags']) {
                    $templateData['tags'] = json_decode($templateData['tags'], true);
                } else {
                    $templateData['tags'] = [];
                }
                
                $formattedTemplates[] = $templateData;
            }
            
            // ספירת סך כל התבניות (ללא LIMIT)
            $countQuery = "
                SELECT COUNT(*) 
                FROM templates 
                WHERE account_id = :account_id
            ";
            
            $countParams = ['account_id' => $accountId];
            
            if ($status) {
                $countQuery .= " AND status = :status";
                $countParams['status'] = $status;
            }
            
            if ($type) {
                $countQuery .= " AND type = :type";
                $countParams['type'] = $type;
            }
            
            if ($category) {
                $countQuery .= " AND category = :category";
                $countParams['category'] = $category;
            }
            
            if ($tag) {
                $countQuery .= " AND tags LIKE :tag";
                $countParams['tag'] = '%"' . $tag . '"%';
            }
            
            if ($search) {
                $countQuery .= " AND (name LIKE :search OR description LIKE :search)";
                $countParams['search'] = '%' . $search . '%';
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
                    'templates' => $formattedTemplates,
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting templates', [
                'exception' => $e->getMessage(),
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get templates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * קבלת תבנית בודדת לפי ID
     */
    public function getOne(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $templateId = (int)$args['id'];

        try {
            // שליפת התבנית
            $query = "
                SELECT * FROM templates
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $templateData = $stmt->fetch();
            
            if (!$templateData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Template not found'
                ], 404);
            }
            
            // המרת התגים מ-JSON למערך
            if (isset($templateData['tags']) && $templateData['tags']) {
                $templateData['tags'] = json_decode($templateData['tags'], true);
            } else {
                $templateData['tags'] = [];
            }
            
            // ספירת שימושים
            $usageQuery = "
                SELECT COUNT(*) FROM campaigns
                WHERE template_id = :template_id
            ";
            
            $stmt = $this->db->prepare($usageQuery);
            $stmt->bindValue(':template_id', $templateId, PDO::PARAM_INT);
            $stmt->execute();
            
            $usageCount = (int)$stmt->fetchColumn();
            
            // הוספת מידע על שימוש
            $templateData['usage_count'] = $usageCount;
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $templateData
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting template', [
                'exception' => $e->getMessage(),
                'template_id' => $templateId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * יצירת תבנית חדשה
     */
    public function create(Request $request, Response $response): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        
        // נתוני התבנית החדשה
        $data = $request->getParsedBody();
        
        // וידוא שהנתונים הבסיסיים קיימים
        if (empty($data['name'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing required field: name'
            ], 400);
        }
        
        if (empty($data['content_html'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Missing required field: content_html'
            ], 400);
        }
        
        try {
            // אם נדרש להפוך את התבנית הזו לברירת מחדל, נבטל את ברירת המחדל הקיימת
            $isDefault = isset($data['is_default']) && $data['is_default'];
            
            if ($isDefault) {
                $resetDefaultQuery = "
                    UPDATE templates 
                    SET is_default = 0
                    WHERE account_id = :account_id AND type = :type AND is_default = 1
                ";
                
                $stmt = $this->db->prepare($resetDefaultQuery);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->bindValue(':type', $data['type'] ?? Template::TYPE_EMAIL);
                $stmt->execute();
            }
            
            // יצירת אובייקט תבנית
            $template = new Template(
                $accountId,
                $data['name'],
                $data['content_html'],
                $data['type'] ?? Template::TYPE_EMAIL,
                $data['status'] ?? Template::STATUS_DRAFT,
                $data['description'] ?? null,
                $data['category'] ?? null,
                $data['content_text'] ?? null,
                $data['default_subject'] ?? null,
                $data['tags'] ?? []
            );
            
            if ($isDefault) {
                $template->setIsDefault(true);
            }
            
            // שמירה במסד הנתונים
            $query = "
                INSERT INTO templates (
                    account_id, name, description, category, type, status, 
                    content_html, content_text, default_subject, tags, is_default, 
                    created_at
                ) VALUES (
                    :account_id, :name, :description, :category, :type, :status, 
                    :content_html, :content_text, :default_subject, :tags, :is_default, 
                    :created_at
                )
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':account_id', $template->getAccountId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $template->getName());
            $stmt->bindValue(':description', $template->getDescription());
            $stmt->bindValue(':category', $template->getCategory());
            $stmt->bindValue(':type', $template->getType());
            $stmt->bindValue(':status', $template->getStatus());
            $stmt->bindValue(':content_html', $template->getContentHtml());
            $stmt->bindValue(':content_text', $template->getContentText());
            $stmt->bindValue(':default_subject', $template->getDefaultSubject());
            $stmt->bindValue(':tags', json_encode($template->getTags()));
            $stmt->bindValue(':is_default', $template->isDefault(), PDO::PARAM_INT);
            $stmt->bindValue(':created_at', $template->getCreatedAt()->format('Y-m-d H:i:s'));
            $stmt->execute();
            
            $templateId = (int)$this->db->lastInsertId();
            $template->setId($templateId);
            
            // יצירת צילום מסך של התבנית (לצרכי דגימה לא מימשנו פונקציה זו)
            // אפשר להוסיף כאן קריאה לשירות של צילום מסך
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $template->toArray(),
                'message' => 'Template created successfully'
            ], 201);
        } catch (\Throwable $e) {
            $this->logger->error('Error creating template', [
                'exception' => $e->getMessage(),
                'data' => $data
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to create template: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * עדכון תבנית קיימת
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $templateId = (int)$args['id'];
        
        // נתוני העדכון
        $data = $request->getParsedBody();
        
        try {
            // בדיקה שהתבנית קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT * FROM templates
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $templateData = $stmt->fetch();
            
            if (!$templateData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Template not found'
                ], 404);
            }
            
            // המרת נתונים ליצירת אובייקט
            if (isset($templateData['tags']) && $templateData['tags']) {
                $templateData['tags'] = json_decode($templateData['tags'], true);
            } else {
                $templateData['tags'] = [];
            }
            
            // יצירת אובייקט ועדכון הנתונים החדשים
            $template = Template::fromArray($templateData);
            
            if (isset($data['name'])) {
                $template->setName($data['name']);
            }
            
            if (array_key_exists('description', $data)) {
                $template->setDescription($data['description']);
            }
            
            if (isset($data['category'])) {
                $template->setCategory($data['category']);
            }
            
            if (isset($data['type'])) {
                $template->setType($data['type']);
            }
            
            if (isset($data['status'])) {
                $template->setStatus($data['status']);
            }
            
            if (isset($data['content_html'])) {
                $template->setContentHtml($data['content_html']);
            }
            
            if (array_key_exists('content_text', $data)) {
                $template->setContentText($data['content_text']);
            }
            
            if (array_key_exists('default_subject', $data)) {
                $template->setDefaultSubject($data['default_subject']);
            }
            
            if (isset($data['tags'])) {
                $template->setTags($data['tags']);
            }
            
            // טיפול בהגדרת ברירת מחדל
            $isDefault = isset($data['is_default']) && $data['is_default'];
            
            if ($isDefault && !$template->isDefault()) {
                // אם נדרש להפוך את התבנית הזו לברירת מחדל, נבטל את ברירת המחדל הקיימת
                $resetDefaultQuery = "
                    UPDATE templates 
                    SET is_default = 0
                    WHERE account_id = :account_id AND type = :type AND is_default = 1 AND id != :id
                ";
                
                $stmt = $this->db->prepare($resetDefaultQuery);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->bindValue(':type', $template->getType());
                $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                $stmt->execute();
                
                $template->setIsDefault(true);
            } elseif (isset($data['is_default']) && !$data['is_default']) {
                $template->setIsDefault(false);
            }
            
            // עדכון במסד הנתונים
            $query = "
                UPDATE templates
                SET name = :name,
                    description = :description,
                    category = :category,
                    type = :type,
                    status = :status,
                    content_html = :content_html,
                    content_text = :content_text,
                    default_subject = :default_subject,
                    tags = :tags,
                    is_default = :is_default,
                    updated_at = NOW()
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $template->getId(), PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $template->getAccountId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $template->getName());
            $stmt->bindValue(':description', $template->getDescription());
            $stmt->bindValue(':category', $template->getCategory());
            $stmt->bindValue(':type', $template->getType());
            $stmt->bindValue(':status', $template->getStatus());
            $stmt->bindValue(':content_html', $template->getContentHtml());
            $stmt->bindValue(':content_text', $template->getContentText());
            $stmt->bindValue(':default_subject', $template->getDefaultSubject());
            $stmt->bindValue(':tags', json_encode($template->getTags()));
            $stmt->bindValue(':is_default', $template->isDefault(), PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No changes were made'
                ], 400);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $template->toArray(),
                'message' => 'Template updated successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error updating template', [
                'exception' => $e->getMessage(),
                'template_id' => $templateId,
                'data' => $data
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update template: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * מחיקת תבנית
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $templateId = (int)$args['id'];
        
        try {
            // בדיקה שהתבנית קיימת ושייכת לחשבון
            $checkQuery = "
                SELECT id, is_default FROM templates
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($checkQuery);
            $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $templateData = $stmt->fetch();
            
            if (!$templateData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Template not found'
                ], 404);
            }
            
            // אם התבנית מוגדרת כברירת מחדל, לא ניתן למחוק אותה
            if ($templateData['is_default']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete default template. Please set another template as default first.'
                ], 400);
            }
            
            // בדיקה אם התבנית משויכת לקמפיינים פעילים
            $campaignQuery = "
                SELECT COUNT(*) FROM campaigns
                WHERE template_id = :template_id AND status IN ('draft', 'scheduled', 'sending')
            ";
            
            $stmt = $this->db->prepare($campaignQuery);
            $stmt->bindValue(':template_id', $templateId, PDO::PARAM_INT);
            $stmt->execute();
            
            $campaignCount = (int)$stmt->fetchColumn();
            
            if ($campaignCount > 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete template: It is associated with ' . $campaignCount . ' active campaign(s)'
                ], 400);
            }
            
            // מחיקת התבנית
            $deleteTemplateQuery = "
                DELETE FROM templates
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($deleteTemplateQuery);
            $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to delete template'
                ], 500);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Template deleted successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error deleting template', [
                'exception' => $e->getMessage(),
                'template_id' => $templateId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete template: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * שכפול תבנית קיימת
     */
    public function duplicate(Request $request, Response $response, array $args): Response
    {
        // מזהה החשבון (בפועל יתקבל מה-authentication)
        $accountId = $request->getQueryParams()['account_id'] ?? 1;
        $templateId = (int)$args['id'];
        
        try {
            // שליפת התבנית המקורית
            $query = "
                SELECT * FROM templates
                WHERE id = :id AND account_id = :account_id
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':id', $templateId, PDO::PARAM_INT);
            $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            $stmt->execute();
            
            $templateData = $stmt->fetch();
            
            if (!$templateData) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Template not found'
                ], 404);
            }
            
            // המרת תגים מ-JSON למערך
            if (isset($templateData['tags']) && $templateData['tags']) {
                $templateData['tags'] = json_decode($templateData['tags'], true);
            } else {
                $templateData['tags'] = [];
            }
            
            // יצירת תבנית חדשה מבוססת על המקורית
            $newName = $templateData['name'] . ' (Copy)';
            
            // בדיקה אם כבר קיימת תבנית עם אותו שם
            $copyNumber = 1;
            while (true) {
                $checkNameQuery = "
                    SELECT COUNT(*) FROM templates
                    WHERE account_id = :account_id AND name = :name
                ";
                
                $stmt = $this->db->prepare($checkNameQuery);
                $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
                $stmt->bindValue(':name', $newName);
                $stmt->execute();
                
                if ((int)$stmt->fetchColumn() === 0) {
                    break;
                }
                
                $copyNumber++;
                $newName = $templateData['name'] . ' (Copy ' . $copyNumber . ')';
            }
            
            // יצירת אובייקט התבנית החדשה
            $template = new Template(
                $accountId,
                $newName,
                $templateData['content_html'],
                $templateData['type'],
                Template::STATUS_DRAFT, // תמיד נתחיל כטיוטה
                $templateData['description'],
                $templateData['category'],
                $templateData['content_text'],
                $templateData['default_subject'],
                $templateData['tags']
            );
            
            // שמירה במסד הנתונים
            $insertQuery = "
                INSERT INTO templates (
                    account_id, name, description, category, type, status, 
                    content_html, content_text, default_subject, tags, 
                    created_at
                ) VALUES (
                    :account_id, :name, :description, :category, :type, :status, 
                    :content_html, :content_text, :default_subject, :tags, 
                    :created_at
                )
            ";
            
            $stmt = $this->db->prepare($insertQuery);
            $stmt->bindValue(':account_id', $template->getAccountId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $template->getName());
            $stmt->bindValue(':description', $template->getDescription());
            $stmt->bindValue(':category', $template->getCategory());
            $stmt->bindValue(':type', $template->getType());
            $stmt->bindValue(':status', $template->getStatus());
            $stmt->bindValue(':content_html', $template->getContentHtml());
            $stmt->bindValue(':content_text', $template->getContentText());
            $stmt->bindValue(':default_subject', $template->getDefaultSubject());
            $stmt->bindValue(':tags', json_encode($template->getTags()));
            $stmt->bindValue(':created_at', $template->getCreatedAt()->format('Y-m-d H:i:s'));
            $stmt->execute();
            
            $newTemplateId = (int)$this->db->lastInsertId();
            $template->setId($newTemplateId);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $template->toArray(),
                'message' => 'Template duplicated successfully'
            ], 201);
        } catch (\Throwable $e) {
            $this->logger->error('Error duplicating template', [
                'exception' => $e->getMessage(),
                'template_id' => $templateId
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to duplicate template: ' . $e->getMessage()
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