<?php
/**
 * MailHebrew Queue Worker
 * 
 * הרץ באופן רציף ע"י Cron Job: * * * * * php /path/to/queue_worker.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MailHebrew\Domain\Email\Email;
use MailHebrew\Infrastructure\Mail\EmailSender;
use MailHebrew\Infrastructure\Queue\QueueManager;
use MailHebrew\Infrastructure\Tracking\TrackingManager;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;

// טען הגדרות סביבה
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// הכן לוגר
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

$logger = new Logger('queue_worker');
$processor = new UidProcessor();
$handler = new StreamHandler($logDir . '/queue_worker.log', Logger::INFO);
$logger->pushProcessor($processor);
$logger->pushHandler($handler);

$logger->info('Queue worker started');

// הגדרות
$queueConfig = [
    'worker_sleep' => (int)$_ENV['QUEUE_WORKER_SLEEP'],
    'max_tries' => (int)$_ENV['QUEUE_MAX_TRIES'],
    'retry_after' => (int)$_ENV['QUEUE_RETRY_AFTER'],
];

$smtpConfig = [
    'host' => $_ENV['SMTP_HOST'],
    'port' => $_ENV['SMTP_PORT'],
    'secure' => $_ENV['SMTP_SECURE'],
    'username' => $_ENV['SMTP_USERNAME'],
    'password' => $_ENV['SMTP_PASSWORD'],
    'from_email' => $_ENV['SMTP_FROM_EMAIL'],
    'from_name' => $_ENV['SMTP_FROM_NAME'],
];

// חיבור Redis
$redis = new Predis\Client([
    'scheme' => 'tcp',
    'host' => $_ENV['REDIS_HOST'],
    'port' => (int)$_ENV['REDIS_PORT'],
    'password' => $_ENV['REDIS_PASSWORD'] ?: null,
    'database' => (int)$_ENV['REDIS_DB'],
]);

// אתחול מנהל המעקב
$trackingManager = new TrackingManager(
    $_ENV['APP_TRACKING_DOMAIN'],
    $_ENV['APP_URL'],
    $logger
);

// אתחול שולח האימיילים
$emailSender = new EmailSender(
    $smtpConfig,
    $logger,
    $trackingManager
);

// אתחול מנהל התור
$queueManager = new QueueManager(
    $redis,
    $logger,
    $queueConfig
);

// הפעל ניקוי אימיילים ישנים פעם ביום
$today = date('Y-m-d');
$lastCleanupFile = $logDir . '/last_cleanup.txt';

if (!file_exists($lastCleanupFile) || file_get_contents($lastCleanupFile) !== $today) {
    $logger->info('Running daily cleanup');
    $cleanupCount = $queueManager->cleanupOldEmails((int)$_ENV['STORE_TRACKING_DATA_DAYS']);
    $logger->info('Cleanup completed', ['deleted_count' => $cleanupCount]);
    file_put_contents($lastCleanupFile, $today);
}

// החלק הרץ בלולאה - עיבוד האימיילים מהתור
$runningTime = 0;
$maxRunTime = 55; // זמן ריצה מקסימלי בשניות (פחות מדקה, כדי שה-cron יוכל להפעיל שוב)
$emailsProcessed = 0;

$startTime = time();

while ($runningTime < $maxRunTime) {
    // קבל אימייל מהתור
    $email = $queueManager->dequeue();
    
    if (!$email) {
        // אם אין אימיילים, המתן מעט והמשך
        $logger->debug('No emails in queue, waiting...');
        sleep($queueConfig['worker_sleep']);
        $runningTime = time() - $startTime;
        continue;
    }
    
    $logger->info('Processing email', [
        'email_id' => $email->getId(),
        'subject' => $email->getSubject(),
        'to_count' => count($email->getTo()),
    ]);
    
    // ניסיון לשלוח את האימייל
    $success = $emailSender->send($email);
    
    // עדכון הסטטוס בתור
    $queueManager->markEmailStatus($email, $success);
    
    // עדכון סטטיסטיקות
    $emailsProcessed++;
    $runningTime = time() - $startTime;
}

// רשום סיכום
$logger->info('Queue worker finished', [
    'runtime_seconds' => $runningTime,
    'emails_processed' => $emailsProcessed,
]);

$stats = $queueManager->getQueueStats();
$logger->info('Queue statistics', $stats);

exit(0); 