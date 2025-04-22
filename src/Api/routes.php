<?php
/**
 * MailHebrew API Routes
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/** @var App $app */

// API v1 Group
$app->group('/api/v1', function (RouteCollectorProxy $group) {
    
    // Campaigns
    $group->group('/campaigns', function (RouteCollectorProxy $group) {
        $group->get('', [\MailHebrew\Api\CampaignController::class, 'getAll']);
        $group->post('', [\MailHebrew\Api\CampaignController::class, 'create']);
        $group->get('/{id}', [\MailHebrew\Api\CampaignController::class, 'getOne']);
        $group->put('/{id}', [\MailHebrew\Api\CampaignController::class, 'update']);
        $group->delete('/{id}', [\MailHebrew\Api\CampaignController::class, 'delete']);
        $group->post('/{id}/send', [\MailHebrew\Api\CampaignController::class, 'send']);
        $group->put('/{id}/pause', [\MailHebrew\Api\CampaignController::class, 'pause']);
        $group->put('/{id}/resume', [\MailHebrew\Api\CampaignController::class, 'resume']);
        $group->get('/{id}/status', [\MailHebrew\Api\CampaignController::class, 'getStatus']);
    });
    
    // Emails
    $group->group('/emails', function (RouteCollectorProxy $group) {
        $group->post('', [\MailHebrew\Api\EmailController::class, 'send']);
        $group->post('/batch', [\MailHebrew\Api\EmailController::class, 'sendBatch']);
        $group->get('/{id}', [\MailHebrew\Api\EmailController::class, 'getStatus']);
        $group->post('/test', [\MailHebrew\Api\EmailController::class, 'sendTest']);
    });
    
    // Templates
    $group->group('/templates', function (RouteCollectorProxy $group) {
        $group->get('', [\MailHebrew\Api\TemplateController::class, 'getAll']);
        $group->post('', [\MailHebrew\Api\TemplateController::class, 'create']);
        $group->get('/{id}', [\MailHebrew\Api\TemplateController::class, 'getOne']);
        $group->put('/{id}', [\MailHebrew\Api\TemplateController::class, 'update']);
        $group->delete('/{id}', [\MailHebrew\Api\TemplateController::class, 'delete']);
        $group->post('/{id}/test', [\MailHebrew\Api\TemplateController::class, 'test']);
    });
    
    // Lists
    $group->group('/lists', function (RouteCollectorProxy $group) {
        $group->get('', [\MailHebrew\Api\ListController::class, 'getAll']);
        $group->post('', [\MailHebrew\Api\ListController::class, 'create']);
        $group->get('/{id}', [\MailHebrew\Api\ListController::class, 'getOne']);
        $group->put('/{id}', [\MailHebrew\Api\ListController::class, 'update']);
        $group->delete('/{id}', [\MailHebrew\Api\ListController::class, 'delete']);
        $group->post('/{id}/recipients', [\MailHebrew\Api\ListController::class, 'addRecipients']);
        $group->delete('/{id}/recipients/{email}', [\MailHebrew\Api\ListController::class, 'removeRecipient']);
        $group->get('/{id}/recipients', [\MailHebrew\Api\ListController::class, 'getRecipients']);
    });
    
    // Stats
    $group->group('/stats', function (RouteCollectorProxy $group) {
        $group->get('/campaigns/{id}', [\MailHebrew\Api\StatsController::class, 'getCampaignStats']);
        $group->get('/opens', [\MailHebrew\Api\StatsController::class, 'getOpens']);
        $group->get('/clicks', [\MailHebrew\Api\StatsController::class, 'getClicks']);
        $group->get('/bounces', [\MailHebrew\Api\StatsController::class, 'getBounces']);
        $group->get('/unsubscribes', [\MailHebrew\Api\StatsController::class, 'getUnsubscribes']);
    });
    
    // Webhooks
    $group->group('/webhooks', function (RouteCollectorProxy $group) {
        $group->get('', [\MailHebrew\Api\WebhookController::class, 'getAll']);
        $group->post('', [\MailHebrew\Api\WebhookController::class, 'create']);
        $group->get('/{id}', [\MailHebrew\Api\WebhookController::class, 'getOne']);
        $group->put('/{id}', [\MailHebrew\Api\WebhookController::class, 'update']);
        $group->delete('/{id}', [\MailHebrew\Api\WebhookController::class, 'delete']);
    });
});

// Tracking endpoints (no authentication)
$app->get('/t/o/{id}', [\MailHebrew\Api\TrackingController::class, 'trackOpen']);
$app->get('/t/c/{id}', [\MailHebrew\Api\TrackingController::class, 'trackClick']);
$app->get('/unsubscribe/{id}', [\MailHebrew\Api\TrackingController::class, 'unsubscribe']);

// Webhook receivers
$app->post('/webhooks/bounce', [\MailHebrew\Api\WebhookReceiverController::class, 'handleBounce']);
$app->post('/webhooks/complaint', [\MailHebrew\Api\WebhookReceiverController::class, 'handleComplaint']);
$app->post('/webhooks/delivery', [\MailHebrew\Api\WebhookReceiverController::class, 'handleDelivery']); 