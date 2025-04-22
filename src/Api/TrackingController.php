<?php

declare(strict_types=1);

namespace MailHebrew\Api;

use MailHebrew\Infrastructure\Tracking\TrackingManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use PDO;

class TrackingController
{
    private TrackingManager $trackingManager;
    private LoggerInterface $logger;
    private PDO $db;

    public function __construct(
        TrackingManager $trackingManager,
        LoggerInterface $logger,
        PDO $db
    ) {
        $this->trackingManager = $trackingManager;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * מעקב אחר פתיחת אימייל
     */
    public function trackOpen(Request $request, Response $response, array $args): Response
    {
        $emailId = $args['id'] ?? '';
        
        if (empty($emailId)) {
            return $this->pixelResponse($response);
        }
        
        try {
            // קבלת פרטי בקשה
            $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            $userAgent = $request->getHeaderLine('User-Agent');
            
            // רישום הפתיחה כאירוע למסד הנתונים
            $this->recordOpenEvent($emailId, $ipAddress, $userAgent);
            
            // רישום למערכת המעקב
            $this->trackingManager->recordOpen($emailId, [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error tracking email open', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
        }
        
        // החזרת פיקסל שקוף ב-PNG
        return $this->pixelResponse($response);
    }

    /**
     * מעקב אחר לחיצה על קישור
     */
    public function trackClick(Request $request, Response $response, array $args): Response
    {
        $emailId = $args['id'] ?? '';
        
        if (empty($emailId)) {
            return $this->notFoundResponse($response);
        }
        
        try {
            // פרטי הבקשה
            $params = $request->getQueryParams();
            $url = $params['url'] ?? '';
            $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            $userAgent = $request->getHeaderLine('User-Agent');
            
            if (empty($url)) {
                return $this->notFoundResponse($response);
            }
            
            // רישום הלחיצה כאירוע למסד הנתונים
            $this->recordClickEvent($emailId, $url, $ipAddress, $userAgent);
            
            // רישום למערכת המעקב
            $this->trackingManager->recordClick($emailId, $url, [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
            
            // הפניה ליעד המקורי
            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        } catch (\Throwable $e) {
            $this->logger->error('Error tracking email click', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
            
            return $this->notFoundResponse($response);
        }
    }

    /**
     * טיפול בבקשת הסרה מרשימת תפוצה
     */
    public function unsubscribe(Request $request, Response $response, array $args): Response
    {
        $emailId = $args['id'] ?? '';
        
        if (empty($emailId)) {
            return $this->notFoundResponse($response);
        }
        
        try {
            // פרטי הבקשה
            $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            $userAgent = $request->getHeaderLine('User-Agent');
            
            // שליפת פרטי האימייל
            $email = $this->getEmailDetails($emailId);
            
            if (!$email) {
                return $this->notFoundResponse($response);
            }
            
            // עדכון סטטוס הנמען כמוסר מרשימת התפוצה
            $this->updateRecipientUnsubscribeStatus($email['recipient_id']);
            
            // רישום אירוע ההסרה
            $this->recordUnsubscribeEvent($emailId, $ipAddress, $userAgent);
            
            // רישום למערכת המעקב
            $this->trackingManager->recordUnsubscribe($emailId, [
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
            
            // הצגת עמוד אישור הסרה
            $response->getBody()->write($this->getUnsubscribeConfirmationPage($email));
            
            return $response
                ->withHeader('Content-Type', 'text/html')
                ->withStatus(200);
        } catch (\Throwable $e) {
            $this->logger->error('Error handling unsubscribe', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
            
            return $this->notFoundResponse($response);
        }
    }

    /**
     * רישום אירוע פתיחה למסד הנתונים
     */
    private function recordOpenEvent(string $emailId, string $ipAddress, string $userAgent): void
    {
        try {
            // בדיקה אם האימייל קיים
            $email = $this->getEmailDetails($emailId);
            
            if (!$email) {
                return;
            }
            
            // עדכון נתוני פתיחה בטבלת האימיילים אם עדיין לא נפתח
            if (empty($email['opened_at'])) {
                $stmt = $this->db->prepare("
                    UPDATE emails 
                    SET opened_at = NOW() 
                    WHERE id = :id AND opened_at IS NULL
                ");
                
                $stmt->execute(['id' => $emailId]);
            }
            
            // הוספת אירוע פתיחה לטבלת האירועים
            $stmt = $this->db->prepare("
                INSERT INTO email_events (
                    email_id, event_type, ip_address, user_agent
                )
                VALUES (
                    :email_id, :event_type, :ip_address, :user_agent
                )
            ");
            
            $stmt->execute([
                'email_id' => $emailId,
                'event_type' => 'open',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error recording open event', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * רישום אירוע לחיצה למסד הנתונים
     */
    private function recordClickEvent(string $emailId, string $url, string $ipAddress, string $userAgent): void
    {
        try {
            // בדיקה אם האימייל קיים
            $email = $this->getEmailDetails($emailId);
            
            if (!$email) {
                return;
            }
            
            // עדכון נתוני לחיצה בטבלת האימיילים אם עדיין לא נלחץ
            if (empty($email['clicked_at'])) {
                $stmt = $this->db->prepare("
                    UPDATE emails 
                    SET clicked_at = NOW() 
                    WHERE id = :id AND clicked_at IS NULL
                ");
                
                $stmt->execute(['id' => $emailId]);
            }
            
            // הוספת אירוע לחיצה לטבלת האירועים
            $stmt = $this->db->prepare("
                INSERT INTO email_events (
                    email_id, event_type, event_data, ip_address, user_agent
                )
                VALUES (
                    :email_id, :event_type, :event_data, :ip_address, :user_agent
                )
            ");
            
            $stmt->execute([
                'email_id' => $emailId,
                'event_type' => 'click',
                'event_data' => json_encode(['url' => $url]),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error recording click event', [
                'email_id' => $emailId,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * רישום אירוע הסרה מרשימת תפוצה
     */
    private function recordUnsubscribeEvent(string $emailId, string $ipAddress, string $userAgent): void
    {
        try {
            // הוספת אירוע הסרה לטבלת האירועים
            $stmt = $this->db->prepare("
                INSERT INTO email_events (
                    email_id, event_type, ip_address, user_agent
                )
                VALUES (
                    :email_id, :event_type, :ip_address, :user_agent
                )
            ");
            
            $stmt->execute([
                'email_id' => $emailId,
                'event_type' => 'unsubscribe',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error recording unsubscribe event', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * עדכון סטטוס הסרה של נמען
     */
    private function updateRecipientUnsubscribeStatus(int $recipientId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE recipients 
                SET unsubscribed = 1, updated_at = NOW() 
                WHERE id = :id
            ");
            
            $stmt->execute(['id' => $recipientId]);
        } catch (\Throwable $e) {
            $this->logger->error('Error updating recipient unsubscribe status', [
                'recipient_id' => $recipientId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * קבלת פרטי אימייל לפי מזהה
     */
    private function getEmailDetails(string $emailId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT e.*, r.email as recipient_email, r.name as recipient_name
                FROM emails e
                LEFT JOIN recipients r ON e.recipient_id = r.id
                WHERE e.id = :id
            ");
            
            $stmt->execute(['id' => $emailId]);
            
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            $this->logger->error('Error getting email details', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * החזרת תגובה עם פיקסל שקוף 1x1
     */
    private function pixelResponse(Response $response): Response
    {
        // פיקסל PNG שקוף בקידוד base64
        $transparentPixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        
        $response->getBody()->write($transparentPixel);
        
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withStatus(200);
    }

    /**
     * החזרת דף 404
     */
    private function notFoundResponse(Response $response): Response
    {
        $response->getBody()->write('<html><body><h1>404 Not Found</h1></body></html>');
        
        return $response
            ->withHeader('Content-Type', 'text/html')
            ->withStatus(404);
    }

    /**
     * יצירת עמוד אישור הסרה מרשימת תפוצה
     */
    private function getUnsubscribeConfirmationPage(array $email): string
    {
        $recipientName = htmlspecialchars($email['recipient_name'] ?: $email['recipient_email']);
        $fromName = htmlspecialchars($email['from_name']);
        
        return <<<HTML
        <!DOCTYPE html>
        <html dir="rtl" lang="he">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>אישור הסרה מרשימת תפוצה</title>
            <style>
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    line-height: 1.6;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    text-align: center;
                }
                .container {
                    background-color: #f8f8f8;
                    border-radius: 5px;
                    padding: 20px;
                    margin-top: 30px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #333;
                }
                p {
                    margin: 15px 0;
                    color: #666;
                }
                .success {
                    color: #4CAF50;
                    font-weight: bold;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>הסרה מרשימת התפוצה</h1>
                <p class="success">הוסרת בהצלחה מרשימת התפוצה!</p>
                <p>שלום {$recipientName},</p>
                <p>כתובת האימייל שלך הוסרה בהצלחה מרשימת התפוצה של {$fromName}.</p>
                <p>לא תקבל יותר אימיילים מרשימת תפוצה זו.</p>
                <p>אם ההסרה בוצעה בטעות, אנא צור קשר עם השולח.</p>
            </div>
        </body>
        </html>
        HTML;
    }
} 