<?php

declare(strict_types=1);

namespace MailHebrew\Infrastructure\Tracking;

use DOMDocument;
use DOMXPath;
use Psr\Log\LoggerInterface;

class TrackingManager
{
    private string $trackingDomain;
    private string $appUrl;
    private LoggerInterface $logger;
    
    public function __construct(
        string $trackingDomain,
        string $appUrl,
        LoggerInterface $logger
    ) {
        $this->trackingDomain = $trackingDomain;
        $this->appUrl = $appUrl;
        $this->logger = $logger;
    }
    
    /**
     * מוסיף מעקב פתיחות להודעת HTML
     */
    public function addOpenTracking(string $htmlContent, string $emailId): string
    {
        // בדיקה האם לא מדובר בהודעת HTML ריקה
        if (empty($htmlContent)) {
            return $htmlContent;
        }
        
        try {
            // בניית URL מעקב
            $trackingUrl = $this->buildTrackingUrl('o', $emailId);
            
            // יצירת תג האימג' לפיקסל המעקב
            $trackingPixel = '<img src="' . htmlspecialchars($trackingUrl) . '" alt="" width="1" height="1" border="0" style="height:1px !important;width:1px !important;border-width:0 !important;margin:0 !important;padding:0 !important;" />';
            
            // ניסיון להוסיף את פיקסל המעקב לסוף תג ה-body
            if (stripos($htmlContent, '</body>') !== false) {
                $htmlContent = str_ireplace('</body>', $trackingPixel . '</body>', $htmlContent);
            } else {
                // אם אין תג body, פשוט נוסיף בסוף ההודעה
                $htmlContent .= $trackingPixel;
            }
            
            return $htmlContent;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add open tracking', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            return $htmlContent;
        }
    }
    
    /**
     * מוסיף מעקב הקלקות להודעת HTML
     */
    public function addClickTracking(string $htmlContent, string $emailId): string
    {
        if (empty($htmlContent)) {
            return $htmlContent;
        }
        
        try {
            // שימוש ב-DOMDocument לניתוח ה-HTML
            $dom = new DOMDocument();
            
            // ניסיון לטעון את ה-HTML עם סבלנות כלפי תגים שלא נסגרו כראוי
            @$dom->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            // מציאת כל הקישורים
            $xpath = new DOMXPath($dom);
            $links = $xpath->query('//a[@href]');
            
            // העתקת הקישורים וטיפול בהם
            foreach ($links as $link) {
                $originalUrl = $link->getAttribute('href');
                
                // אם זה כבר קישור מעקב או קישור מיוחד, לא נשנה אותו
                if (strpos($originalUrl, $this->trackingDomain) !== false || 
                    strpos($originalUrl, 'mailto:') === 0 ||
                    strpos($originalUrl, 'tel:') === 0 ||
                    strpos($originalUrl, 'sms:') === 0 ||
                    strpos($originalUrl, 'javascript:') === 0 ||
                    strpos($originalUrl, '#') === 0) {
                    continue;
                }
                
                // יצירת לינק מעקב
                $trackingUrl = $this->buildTrackingUrl('c', $emailId, ['url' => $originalUrl]);
                
                // החלפת הקישור המקורי בקישור מעקב
                $link->setAttribute('href', $trackingUrl);
                
                // הוספת מאפיין data-original-url לשמירת הלינק המקורי
                $link->setAttribute('data-original-url', $originalUrl);
            }
            
            // שמירת ה-HTML המעודכן
            $trackedHtml = $dom->saveHTML();
            
            // אם לא הצלחנו לטפל ב-HTML, נחזיר את המקורי
            if (empty($trackedHtml)) {
                return $htmlContent;
            }
            
            return $trackedHtml;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add click tracking', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            return $htmlContent;
        }
    }
    
    /**
     * מוסיף קישור להסרה מרשימת תפוצה להודעת HTML
     */
    public function addUnsubscribeLink(string $htmlContent, string $emailId): string
    {
        if (empty($htmlContent)) {
            return $htmlContent;
        }
        
        try {
            // בניית URL הסרה מרשימת תפוצה
            $unsubscribeUrl = $this->appUrl . '/unsubscribe/' . $emailId;
            
            // טקסט התחתית שיכיל את לינק ההסרה
            $unsubscribeText = '<div style="text-align:center;font-size:12px;color:#777;margin-top:20px;padding:10px;">אם אינך מעוניין לקבל אימיילים נוספים, <a href="' . 
                htmlspecialchars($unsubscribeUrl) . '" style="color:#777;text-decoration:underline;">לחץ כאן להסרה מרשימת התפוצה</a>.</div>';
            
            // בדיקה אם יש כבר לינק הסרה (למניעת כפילות)
            if (stripos($htmlContent, 'unsubscribe') !== false || stripos($htmlContent, 'הסרה') !== false) {
                // הנחה שיש כבר קישור הסרה, לא נוסיף
                return $htmlContent;
            }
            
            // ניסיון להוסיף את הקישור לפני סגירת תג ה-body
            if (stripos($htmlContent, '</body>') !== false) {
                $htmlContent = str_ireplace('</body>', $unsubscribeText . '</body>', $htmlContent);
            } else {
                // אם אין תג body, פשוט נוסיף בסוף ההודעה
                $htmlContent .= $unsubscribeText;
            }
            
            return $htmlContent;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add unsubscribe link', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            return $htmlContent;
        }
    }
    
    /**
     * מוסיף טקסט הסרה להודעת טקסט פשוטה
     */
    public function addUnsubscribeText(string $textContent, string $emailId): string
    {
        if (empty($textContent)) {
            return $textContent;
        }
        
        // בניית URL הסרה
        $unsubscribeUrl = $this->appUrl . '/unsubscribe/' . $emailId;
        
        // טקסט ההסרה
        $unsubscribeText = "\n\n------------------------\n" .
            "אם אינך מעוניין לקבל אימיילים נוספים, בקר בכתובת הבאה להסרה מרשימת התפוצה:\n" .
            $unsubscribeUrl;
        
        // בדיקה שהטקסט לא קיים כבר
        if (stripos($textContent, 'unsubscribe') === false && stripos($textContent, 'הסרה') === false) {
            $textContent .= $unsubscribeText;
        }
        
        return $textContent;
    }
    
    /**
     * בונה URL מעקב
     */
    private function buildTrackingUrl(string $type, string $emailId, array $params = []): string
    {
        $url = 'https://' . $this->trackingDomain . '/t/' . $type . '/' . $emailId;
        
        // הוספת פרמטרים נוספים
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }
        
        return $url;
    }
    
    /**
     * שומר אירוע פתיחה למסד הנתונים
     */
    public function recordOpen(string $emailId, array $metadata = []): bool
    {
        try {
            // בשלב זה נרשום רק ללוג, בהמשך נוסיף שמירה במסד נתונים
            $this->logger->info('Email open tracked', [
                'email_id' => $emailId,
                'timestamp' => date('Y-m-d H:i:s'),
                'metadata' => $metadata,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record email open', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * שומר אירוע הקלקה למסד הנתונים
     */
    public function recordClick(string $emailId, string $url, array $metadata = []): bool
    {
        try {
            // בשלב זה נרשום רק ללוג, בהמשך נוסיף שמירה במסד נתונים
            $this->logger->info('Email click tracked', [
                'email_id' => $emailId,
                'url' => $url,
                'timestamp' => date('Y-m-d H:i:s'),
                'metadata' => $metadata,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record email click', [
                'email_id' => $emailId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * שומר אירוע הסרה מרשימת תפוצה
     */
    public function recordUnsubscribe(string $emailId, array $metadata = []): bool
    {
        try {
            // בשלב זה נרשום רק ללוג, בהמשך נוסיף שמירה במסד נתונים
            $this->logger->info('Unsubscribe recorded', [
                'email_id' => $emailId,
                'timestamp' => date('Y-m-d H:i:s'),
                'metadata' => $metadata,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record unsubscribe', [
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
} 