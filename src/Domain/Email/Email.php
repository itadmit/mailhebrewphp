<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Email;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

class Email
{
    private string $id;
    private string $from;
    private string $fromName;
    private array $to;
    private array $toNames;
    private string $subject;
    private ?string $contentHtml;
    private ?string $contentText;
    private ?string $replyTo;
    private bool $trackingEnabled;
    private string $status;
    private ?DateTimeImmutable $sentAt;
    private ?DateTimeImmutable $openedAt;
    private ?DateTimeImmutable $clickedAt;
    private array $metadata;
    private array $cc;
    private array $bcc;
    private array $attachments;
    private array $headers;
    private array $tags;

    public function __construct(
        string $from,
        string $fromName,
        $to,
        $toName = null,
        string $subject = '',
        ?string $contentHtml = null,
        ?string $contentText = null,
        ?string $replyTo = null,
        bool $trackingEnabled = true
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->from = $from;
        $this->fromName = $fromName;
        $this->to = is_array($to) ? $to : [$to];
        $this->toNames = is_array($toName) ? $toName : [$toName];
        $this->subject = $subject;
        $this->contentHtml = $contentHtml;
        $this->contentText = $contentText;
        $this->replyTo = $replyTo;
        $this->trackingEnabled = $trackingEnabled;
        $this->status = 'draft';
        $this->sentAt = null;
        $this->openedAt = null;
        $this->clickedAt = null;
        $this->metadata = [];
        $this->cc = [];
        $this->bcc = [];
        $this->attachments = [];
        $this->headers = [];
        $this->tags = [];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getTo(): array
    {
        $recipients = [];
        foreach ($this->to as $index => $email) {
            $recipients[] = [
                'email' => $email,
                'name' => $this->toNames[$index] ?? null
            ];
        }
        return $recipients;
    }

    public function getToName(): ?string
    {
        return $this->toNames[0] ?? null;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContentHtml(): ?string
    {
        return $this->contentHtml;
    }

    public function getContentText(): ?string
    {
        return $this->contentText;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function isTrackingEnabled(): bool
    {
        return $this->trackingEnabled;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): void
    {
        $this->sentAt = $sentAt;
    }

    public function getOpenedAt(): ?DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?DateTimeImmutable $openedAt): void
    {
        $this->openedAt = $openedAt;
    }

    public function getClickedAt(): ?DateTimeImmutable
    {
        return $this->clickedAt;
    }

    public function setClickedAt(?DateTimeImmutable $clickedAt): void
    {
        $this->clickedAt = $clickedAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function isReady(): bool
    {
        return !empty($this->from) &&
               !empty($this->fromName) &&
               !empty($this->to) &&
               !empty($this->subject) &&
               (!empty($this->contentHtml) || !empty($this->contentText));
    }

    public function getHtmlBody(): ?string
    {
        return $this->contentHtml;
    }

    public function getTextBody(): ?string
    {
        return $this->contentText;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function addCc(string $email, ?string $name = null): void
    {
        $this->cc[] = ['email' => $email, 'name' => $name];
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function addBcc(string $email, ?string $name = null): void
    {
        $this->bcc[] = ['email' => $email, 'name' => $name];
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function addAttachment(string $path, ?string $name = null): void
    {
        $this->attachments[] = ['path' => $path, 'name' => $name];
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function addHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function addTag(string $tag): void
    {
        $this->tags[] = $tag;
    }

    public function isTrackOpens(): bool
    {
        return $this->trackingEnabled;
    }

    public function isTrackClicks(): bool
    {
        return $this->trackingEnabled;
    }

    public function incrementSendAttempts(): void
    {
        // TODO: Implement send attempts tracking
    }
} 