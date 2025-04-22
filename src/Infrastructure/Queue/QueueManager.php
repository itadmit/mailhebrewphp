<?php

declare(strict_types=1);

namespace MailHebrew\Infrastructure\Queue;

use DateTimeImmutable;
use MailHebrew\Domain\Email\Email;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

class QueueManager
{
    private RedisClient $redis;
    private LoggerInterface $logger;
    private array $queueConfig;

    // שמות תורים ב-Redis
    private const QUEUE_HIGH_PRIORITY = 'mail_queue:high';
    private const QUEUE_NORMAL_PRIORITY = 'mail_queue:normal';
    private const QUEUE_LOW_PRIORITY = 'mail_queue:low';
    private const QUEUE_SCHEDULED = 'mail_queue:scheduled';
    private const QUEUE_FAILED = 'mail_queue:failed';
    private const QUEUE_PROCESSING = 'mail_queue:processing';

    // מפתחות למידע נוסף ב-Redis
    private const KEY_EMAIL_DATA = 'mail:data:';
    private const KEY_EMAIL_STATUS = 'mail:status:';
    private const KEY_STATS = 'mail:stats:';

    public function __construct(
        RedisClient $redis,
        LoggerInterface $logger,
        array $queueConfig
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->queueConfig = $queueConfig;
    }

    /**
     * מוסיף אימייל חדש לתור
     */
    public function enqueue(Email $email, string $priority = 'normal'): bool
    {
        try {
            // בדיקה שהאימייל תקין
            if (!$email->isReady()) {
                throw new \InvalidArgumentException('Email is not ready to be queued');
            }

            // שמירת נתוני האימייל ברדיס
            $emailId = $email->getId();
            $emailData = json_encode($email->toArray());

            if (!$emailData) {
                throw new \RuntimeException('Failed to encode email data');
            }

            // קביעת סטטוס האימייל ל-queued אם הוא לא מתוזמן
            if ($email->getStatus() !== Email::STATUS_SCHEDULED) {
                $email->setStatus(Email::STATUS_QUEUED);
            }

            // בחירת התור המתאים
            $queueKey = $this->getQueueKeyByPriority($priority);

            // שמירת נתוני האימייל
            $this->redis->set(self::KEY_EMAIL_DATA . $emailId, $emailData);
            $this->redis->set(self::KEY_EMAIL_STATUS . $emailId, $email->getStatus());

            // אם האימייל מתוזמן, נשים אותו בתור המתוזמן
            if ($email->getStatus() === Email::STATUS_SCHEDULED && $email->getScheduledAt() !== null) {
                $scheduledTime = $email->getScheduledAt()->getTimestamp();
                $this->redis->zadd(self::QUEUE_SCHEDULED, [$emailId => $scheduledTime]);
                
                $this->logger->info('Email scheduled for later delivery', [
                    'email_id' => $emailId,
                    'scheduled_time' => $email->getScheduledAt()->format('Y-m-d H:i:s'),
                ]);
            } else {
                // אחרת נוסיף לתור הרגיל לפי עדיפות
                $this->redis->rpush($queueKey, [$emailId]);
                
                $this->logger->info('Email added to queue', [
                    'email_id' => $emailId,
                    'queue' => $queueKey,
                ]);
            }

            // עדכון סטטיסטיקות
            $this->redis->hincrby(self::KEY_STATS . 'daily:' . date('Y-m-d'), 'queued', 1);
            $this->redis->hincrby(self::KEY_STATS . 'total', 'queued', 1);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to enqueue email', [
                'email_id' => $email->getId(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * מחזיר את האימייל הבא בתור לשליחה
     */
    public function dequeue(): ?Email
    {
        try {
            // קודם כל, בדיקה אם יש אימיילים מתוזמנים שהגיע זמנם
            $this->moveScheduledEmailsToQueue();

            // ניסיון לקבל אימייל מהתור בעדיפות גבוהה
            $emailId = $this->redis->lpop(self::QUEUE_HIGH_PRIORITY);

            // אם אין אימיילים בעדיפות גבוהה, ניסיון לתור רגיל
            if (!$emailId) {
                $emailId = $this->redis->lpop(self::QUEUE_NORMAL_PRIORITY);
            }

            // אם גם בתור הרגיל אין, ניסיון לתור נמוכה
            if (!$emailId) {
                $emailId = $this->redis->lpop(self::QUEUE_LOW_PRIORITY);
            }

            // אם אין אימיילים כלל, מחזירים null
            if (!$emailId) {
                return null;
            }

            // קבלת נתוני האימייל
            $emailData = $this->redis->get(self::KEY_EMAIL_DATA . $emailId);

            if (!$emailData) {
                $this->logger->error('Email data not found', ['email_id' => $emailId]);
                return null;
            }

            $data = json_decode($emailData, true);

            if (!$data) {
                $this->logger->error('Failed to decode email data', ['email_id' => $emailId]);
                return null;
            }

            // יצירת אובייקט Email
            $email = Email::fromArray($data);

            // עדכון הסטטוס ל-sending
            $email->setStatus(Email::STATUS_SENDING);
            $this->redis->set(self::KEY_EMAIL_STATUS . $emailId, Email::STATUS_SENDING);

            // הוספה לתור 'בטיפול'
            $this->redis->rpush(self::QUEUE_PROCESSING, $emailId);

            // עדכון סטטיסטיקות
            $this->redis->hincrby(self::KEY_STATS . 'daily:' . date('Y-m-d'), 'processing', 1);

            return $email;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dequeue email', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * מעדכן את סטטוס האימייל לאחר ניסיון שליחה
     */
    public function markEmailStatus(Email $email, bool $success): void
    {
        $emailId = $email->getId();

        try {
            // הסרה מהתור 'בטיפול'
            $this->redis->lrem(self::QUEUE_PROCESSING, 0, $emailId);

            // עדכון נתוני האימייל
            $this->redis->set(self::KEY_EMAIL_DATA . $emailId, json_encode($email->toArray()));
            $this->redis->set(self::KEY_EMAIL_STATUS . $emailId, $email->getStatus());

            // עדכון סטטיסטיקות
            if ($success) {
                $this->redis->hincrby(self::KEY_STATS . 'daily:' . date('Y-m-d'), 'sent', 1);
                $this->redis->hincrby(self::KEY_STATS . 'total', 'sent', 1);
            } else {
                // בדיקה אם צריך לנסות שוב או לסמן ככישלון סופי
                if ($email->getSendAttempts() < $this->queueConfig['max_tries']) {
                    // חישוב זמן ההמתנה לניסיון הבא לפי אסטרטגיית backoff אקספוננציאלית
                    $delay = $this->queueConfig['retry_after'] * pow(2, $email->getSendAttempts() - 1);
                    $retryTime = time() + $delay;

                    // הוספה לתור המתוזמן עם זמן הניסיון הבא
                    $this->redis->zadd(self::QUEUE_SCHEDULED, [$emailId => $retryTime]);

                    $this->logger->info('Email scheduled for retry', [
                        'email_id' => $emailId,
                        'retry_attempt' => $email->getSendAttempts(),
                        'retry_after' => $delay,
                        'retry_time' => date('Y-m-d H:i:s', $retryTime),
                    ]);
                } else {
                    // מעבר לתור הכישלונות
                    $this->redis->rpush(self::QUEUE_FAILED, $emailId);
                    $this->redis->hincrby(self::KEY_STATS . 'daily:' . date('Y-m-d'), 'failed', 1);
                    $this->redis->hincrby(self::KEY_STATS . 'total', 'failed', 1);

                    $this->logger->error('Email failed permanently', [
                        'email_id' => $emailId,
                        'attempts' => $email->getSendAttempts(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email status', [
                'email_id' => $emailId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * מעביר אימיילים מתוזמנים שהגיע זמנם לתור הרגיל
     */
    private function moveScheduledEmailsToQueue(): void
    {
        try {
            $now = time();
            
            // קבלת רשימת האימיילים המתוזמנים שהגיע זמנם
            $dueEmails = $this->redis->zrangebyscore(self::QUEUE_SCHEDULED, 0, $now);
            
            if (empty($dueEmails)) {
                return;
            }
            
            foreach ($dueEmails as $emailId) {
                // קבלת סטטוס האימייל הנוכחי
                $status = $this->redis->get(self::KEY_EMAIL_STATUS . $emailId);
                
                // קביעה לאיזה תור האימייל ילך לפי הסטטוס
                $targetQueue = self::QUEUE_NORMAL_PRIORITY;
                
                if ($status === Email::STATUS_FAILED) {
                    // אם האימייל נכשל בעבר וזה ניסיון חוזר
                    $targetQueue = self::QUEUE_HIGH_PRIORITY;
                }
                
                // הוצאה מהתור המתוזמן
                $this->redis->zrem(self::QUEUE_SCHEDULED, $emailId);
                
                // הוספה לתור הרגיל
                $this->redis->rpush($targetQueue, $emailId);
                
                // עדכון הסטטוס ל-queued
                $this->redis->set(self::KEY_EMAIL_STATUS . $emailId, Email::STATUS_QUEUED);
                
                // עדכון האימייל במסד הנתונים (אם קיים)
                $emailData = $this->redis->get(self::KEY_EMAIL_DATA . $emailId);
                
                if ($emailData) {
                    $data = json_decode($emailData, true);
                    
                    if ($data) {
                        $data['status'] = Email::STATUS_QUEUED;
                        $this->redis->set(self::KEY_EMAIL_DATA . $emailId, json_encode($data));
                    }
                }
                
                $this->logger->info('Scheduled email moved to queue', [
                    'email_id' => $emailId,
                    'target_queue' => $targetQueue,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process scheduled emails', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * קבלת מפתח התור לפי רמת העדיפות
     */
    private function getQueueKeyByPriority(string $priority): string
    {
        switch (strtolower($priority)) {
            case 'high':
                return self::QUEUE_HIGH_PRIORITY;
            case 'low':
                return self::QUEUE_LOW_PRIORITY;
            case 'normal':
            default:
                return self::QUEUE_NORMAL_PRIORITY;
        }
    }

    /**
     * קבלת סטטיסטיקות התור
     */
    public function getQueueStats(): array
    {
        try {
            return [
                'high_priority' => $this->redis->llen(self::QUEUE_HIGH_PRIORITY),
                'normal_priority' => $this->redis->llen(self::QUEUE_NORMAL_PRIORITY),
                'low_priority' => $this->redis->llen(self::QUEUE_LOW_PRIORITY),
                'scheduled' => $this->redis->zcard(self::QUEUE_SCHEDULED),
                'processing' => $this->redis->llen(self::QUEUE_PROCESSING),
                'failed' => $this->redis->llen(self::QUEUE_FAILED),
                'today_stats' => $this->redis->hgetall(self::KEY_STATS . 'daily:' . date('Y-m-d')),
                'total_stats' => $this->redis->hgetall(self::KEY_STATS . 'total'),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get queue stats', [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ניקוי אימיילים ישנים מהמערכת
     */
    public function cleanupOldEmails(int $daysToKeep = 30): int
    {
        try {
            $deletedCount = 0;
            $cutoffTime = time() - ($daysToKeep * 86400);

            // רשימת מפתחות לחיפוש
            $scanPattern = self::KEY_EMAIL_DATA . '*';
            $cursor = '0';

            do {
                // סריקה של המפתחות עם דפדוף
                [$cursor, $keys] = $this->redis->scan($cursor, 'MATCH', $scanPattern, 'COUNT', 1000);

                foreach ($keys as $key) {
                    // קבלת מזהה האימייל מהמפתח
                    $emailId = str_replace(self::KEY_EMAIL_DATA, '', $key);
                    
                    // קבלת נתוני האימייל
                    $emailData = $this->redis->get($key);
                    
                    if (!$emailData) {
                        continue;
                    }
                    
                    $data = json_decode($emailData, true);
                    
                    if (!$data || !isset($data['created_at'])) {
                        continue;
                    }
                    
                    // בדיקה אם האימייל ישן מספיק
                    $createTime = strtotime($data['created_at']);
                    
                    if ($createTime && $createTime < $cutoffTime) {
                        // הסרה מכל התורים
                        $this->redis->lrem(self::QUEUE_HIGH_PRIORITY, 0, $emailId);
                        $this->redis->lrem(self::QUEUE_NORMAL_PRIORITY, 0, $emailId);
                        $this->redis->lrem(self::QUEUE_LOW_PRIORITY, 0, $emailId);
                        $this->redis->lrem(self::QUEUE_PROCESSING, 0, $emailId);
                        $this->redis->lrem(self::QUEUE_FAILED, 0, $emailId);
                        $this->redis->zrem(self::QUEUE_SCHEDULED, $emailId);
                        
                        // מחיקת נתוני האימייל והסטטוס
                        $this->redis->del(self::KEY_EMAIL_DATA . $emailId);
                        $this->redis->del(self::KEY_EMAIL_STATUS . $emailId);
                        
                        $deletedCount++;
                    }
                }
            } while ($cursor != '0');

            $this->logger->info('Old emails cleanup completed', [
                'deleted_count' => $deletedCount,
                'days_threshold' => $daysToKeep,
            ]);

            return $deletedCount;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to cleanup old emails', [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }
} 