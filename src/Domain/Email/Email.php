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
    private string $to;
    private ?string $toName;
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

    public function __construct(
        string $from,
        string $fromName,
        string $to,
        ?string $toName = null,
        string $subject = '',
        ?string $contentHtml = null,
        ?string $contentText = null,
        ?string $replyTo = null,
        bool $trackingEnabled = true
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->from = $from;
        $this->fromName = $fromName;
        $this->to = $to;
        $this->toName = $toName;
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

    public function getTo(): string
    {
        return $this->to;
    }

    public function getToName(): ?string
    {
        return $this->toName;
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
} 