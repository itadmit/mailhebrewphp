<?php
/**
 * MailHebrew API Entry Point
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Set up dependencies
$containerBuilder = new ContainerBuilder();

// Global middleware
$containerBuilder->addDefinitions([
    'settings' => [
        'displayErrorDetails' => $_ENV['APP_DEBUG'] === 'true',
        'logDir' => __DIR__ . '/../logs',
        'logName' => 'app',
        'db' => [
            'host' => $_ENV['DB_HOST'],
            'port' => $_ENV['DB_PORT'],
            'database' => $_ENV['DB_DATABASE'],
            'username' => $_ENV['DB_USERNAME'],
            'password' => $_ENV['DB_PASSWORD'],
        ],
        'redis' => [
            'host' => $_ENV['REDIS_HOST'],
            'port' => $_ENV['REDIS_PORT'],
            'password' => $_ENV['REDIS_PASSWORD'],
            'database' => $_ENV['REDIS_DB'],
        ],
        'smtp' => [
            'host' => $_ENV['SMTP_HOST'],
            'port' => $_ENV['SMTP_PORT'],
            'secure' => $_ENV['SMTP_SECURE'],
            'username' => $_ENV['SMTP_USERNAME'],
            'password' => $_ENV['SMTP_PASSWORD'],
            'from_email' => $_ENV['SMTP_FROM_EMAIL'],
            'from_name' => $_ENV['SMTP_FROM_NAME'],
        ],
        'app' => [
            'url' => $_ENV['APP_URL'],
            'tracking_domain' => $_ENV['APP_TRACKING_DOMAIN'],
            'secret' => $_ENV['APP_SECRET'],
        ],
        'queue' => [
            'worker_sleep' => (int)$_ENV['QUEUE_WORKER_SLEEP'],
            'max_tries' => (int)$_ENV['QUEUE_MAX_TRIES'],
            'retry_after' => (int)$_ENV['QUEUE_RETRY_AFTER'],
        ],
        'tracking' => [
            'enable_open_tracking' => $_ENV['ENABLE_OPEN_TRACKING'] === 'true',
            'enable_click_tracking' => $_ENV['ENABLE_CLICK_TRACKING'] === 'true',
            'store_tracking_data_days' => (int)$_ENV['STORE_TRACKING_DATA_DAYS'],
        ],
    ],
    'logger' => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        
        $loggerSettings = $settings['logger'] ?? [];
        $loggerName = $loggerSettings['name'] ?? $settings['logName'];
        
        $logger = new Logger($loggerName);
        
        $logDir = $settings['logDir'];
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $filename = $logDir . '/' . $loggerName . '.log';
        $level = $settings['displayErrorDetails'] ? Logger::DEBUG : Logger::INFO;
        
        $handler = new StreamHandler($filename, $level);
        $processor = new UidProcessor();
        
        $logger->pushProcessor($processor);
        $logger->pushHandler($handler);
        
        return $logger;
    },
    'db' => function (ContainerInterface $c) {
        $settings = $c->get('settings')['db'];
        
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $settings['host'],
            $settings['port'],
            $settings['database']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $pdo = new PDO($dsn, $settings['username'], $settings['password'], $options);
            return $pdo;
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    },
    'redis' => function (ContainerInterface $c) {
        $settings = $c->get('settings')['redis'];
        
        $redis = new \Predis\Client([
            'scheme' => 'tcp',
            'host' => $settings['host'],
            'port' => $settings['port'],
            'password' => $settings['password'] ?: null,
            'database' => $settings['database'],
        ]);
        
        return $redis;
    },
    'queueConfig' => function (ContainerInterface $c) {
        return $c->get('settings')['queue'];
    },
    \MailHebrew\Infrastructure\Queue\QueueManager::class => function (ContainerInterface $c) {
        $redis = $c->get('redis');
        $logger = $c->get('logger');
        $queueConfig = $c->get('queueConfig');
        
        return new \MailHebrew\Infrastructure\Queue\QueueManager(
            $redis,
            $logger,
            $queueConfig
        );
    },
    \MailHebrew\Api\EmailController::class => function (ContainerInterface $c) {
        $queueManager = $c->get(\MailHebrew\Infrastructure\Queue\QueueManager::class);
        $logger = $c->get('logger');
        $db = $c->get('db');
        $emailSender = $c->get(\MailHebrew\Infrastructure\Mail\EmailSender::class);
        
        return new \MailHebrew\Api\EmailController(
            $queueManager,
            $logger,
            $db,
            $emailSender
        );
    },
    \MailHebrew\Api\CampaignController::class => function (ContainerInterface $c) {
        $queueManager = $c->get(\MailHebrew\Infrastructure\Queue\QueueManager::class);
        $logger = $c->get('logger');
        $db = $c->get('db');
        
        return new \MailHebrew\Api\CampaignController(
            $queueManager,
            $logger,
            $db
        );
    },
    \MailHebrew\Infrastructure\Tracking\TrackingManager::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $logger = $c->get('logger');
        
        return new \MailHebrew\Infrastructure\Tracking\TrackingManager(
            $settings['app']['tracking_domain'],
            $settings['app']['url'],
            $logger
        );
    },
    \MailHebrew\Domain\Email\EmailSender::class => function (ContainerInterface $c) {
        $smtpConfig = $c->get('settings')['smtp'];
        $logger = $c->get('logger');
        $trackingManager = $c->get(\MailHebrew\Infrastructure\Tracking\TrackingManager::class);
        
        return new \MailHebrew\Infrastructure\Mail\EmailSender(
            $smtpConfig,
            $logger,
            $trackingManager
        );
    },
    TrackingController::class => function (ContainerInterface $c) {
        return new TrackingController(
            $c->get(\MailHebrew\Infrastructure\Tracking\TrackingManager::class),
            $c->get(LoggerInterface::class),
            $c->get('db')
        );
    },
    TrackingManager::class => function (ContainerInterface $c) {
        return new TrackingManager(
            $c->get('settings')['app']['url'],
            $c->get('logger')
        );
    },
]);

// Create container
$container = $containerBuilder->build();

// Create app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(
    $_ENV['APP_DEBUG'] === 'true',
    true,
    true,
    $container->get('logger')
);

// Add routes
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([
        'name' => 'MailHebrew API',
        'version' => '1.0.0',
        'status' => 'running',
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Add API Routes
require __DIR__ . '/../src/Api/routes.php';

// נתיבי מעקב
$app->get('/track/open/{id}', [TrackingController::class, 'trackOpen']);
$app->get('/track/click/{id}', [TrackingController::class, 'trackClick']);
$app->get('/unsubscribe/{id}', [TrackingController::class, 'unsubscribe']);

// Run app
$app->run(); 