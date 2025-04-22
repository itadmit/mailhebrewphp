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

// Run app
$app->run(); 