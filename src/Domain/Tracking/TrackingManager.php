<?php

namespace App\Domain\Tracking;

use Psr\Log\LoggerInterface;
use Exception;

class TrackingManager
{
    private string $baseUrl;
    private LoggerInterface $logger;

    public function __construct(string $baseUrl, LoggerInterface $logger)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    public function addOpenTracking(string $html, string $emailId): string
    {
        $trackingPixel = sprintf(
            '<img src="%s/tracking/open/%s" width="1" height="1" alt="" style="display:none" />',
            $this->baseUrl,
            $emailId
        );

        return $html . $trackingPixel;
    }

    public function addClickTracking(string $html, string $emailId): string
    {
        return preg_replace_callback(
            '/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/i',
            function($matches) use ($emailId) {
                $url = $matches[2];
                $trackingUrl = sprintf(
                    '%s/tracking/click/%s?url=%s',
                    $this->baseUrl,
                    $emailId,
                    urlencode($url)
                );
                return str_replace($url, $trackingUrl, $matches[0]);
            },
            $html
        );
    }

    public function trackOpen(string $emailId): void
    {
        try {
            $this->logger->info('Email opened', ['email_id' => $emailId]);
            // TODO: Update email status in database
        } catch (Exception $e) {
            $this->logger->error('Failed to track email open', [
                'email_id' => $emailId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function trackClick(string $emailId, string $url): void
    {
        try {
            $this->logger->info('Email link clicked', [
                'email_id' => $emailId,
                'url' => $url
            ]);
            // TODO: Update email status in database
        } catch (Exception $e) {
            $this->logger->error('Failed to track email click', [
                'email_id' => $emailId,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function addUnsubscribeLink(string $html, string $emailId): string
    {
        $unsubscribeUrl = sprintf(
            '%s/unsubscribe/%s',
            $this->baseUrl,
            $emailId
        );

        return str_replace(
            '{unsubscribe_url}',
            $unsubscribeUrl,
            $html
        );
    }

    public function recordUnsubscribe(string $emailId, array $metadata = []): void
    {
        try {
            $this->logger->info('Email unsubscribed', [
                'email_id' => $emailId,
                'metadata' => $metadata
            ]);
            // TODO: Update email status in database
        } catch (Exception $e) {
            $this->logger->error('Failed to record unsubscribe', [
                'email_id' => $emailId,
                'metadata' => $metadata,
                'error' => $e->getMessage()
            ]);
        }
    }
} 