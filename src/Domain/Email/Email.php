<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Email;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

class Email
{
    private string $id;
    private ?int $campaignId;
    private string $from;
    private string $fromName;
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private string $subject;
    private string $htmlBody;
    private string $textBody;
    private array $attachments = [];
    private array $headers = [];
    private array $tags = [];
    private array $metadata = [];
    private bool $trackOpens;
    private bool $trackClicks;
    private string $status;
    private int $sendAttempts = 0;
    private ?DateTimeImmutable $lastAttemptAt = null;
    private ?DateTimeImmutable $sentAt = null;
    private ?DateTimeImmutable $scheduledAt = null;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt = null;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SCHEDULED = 'scheduled';

    public function __construct(
        string $from,
        string $fromName,
        array $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        ?int $campaignId = null
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->from = $from;
        $this->fromName = $fromName;
        $this->to = $to;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody ?: strip_tags($htmlBody);
        $this->campaignId = $campaignId;
        $this->status = self::STATUS_DRAFT;
        $this->trackOpens = true;
        $this->trackClicks = true;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
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
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function setCc(array $cc): self
    {
        $this->cc = $cc;
        return $this;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function setBcc(array $bcc): self
    {
        $this->bcc = $bcc;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function getTextBody(): string
    {
        return $this->textBody;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function addAttachment(string $path, string $name = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name ?? basename($path),
        ];
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function addTag(string $tag): self
    {
        if (!in_array($tag, $this->tags)) {
            $this->tags[] = $tag;
        }
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function isTrackOpens(): bool
    {
        return $this->trackOpens;
    }

    public function setTrackOpens(bool $trackOpens): self
    {
        $this->trackOpens = $trackOpens;
        return $this;
    }

    public function isTrackClicks(): bool
    {
        return $this->trackClicks;
    }

    public function setTrackClicks(bool $trackClicks): self
    {
        $this->trackClicks = $trackClicks;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        
        if ($status === self::STATUS_SENT) {
            $this->sentAt = new DateTimeImmutable();
        }
        
        return $this;
    }

    public function getSendAttempts(): int
    {
        return $this->sendAttempts;
    }

    public function incrementSendAttempts(): self
    {
        $this->sendAttempts++;
        $this->lastAttemptAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function schedule(DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        $this->status = self::STATUS_SCHEDULED;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isReady(): bool
    {
        return !empty($this->from) && !empty($this->to) && !empty($this->subject) && 
               (!empty($this->htmlBody) || !empty($this->textBody));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaignId,
            'from' => $this->from,
            'from_name' => $this->fromName,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'html_body' => $this->htmlBody,
            'text_body' => $this->textBody,
            'attachments' => $this->attachments,
            'headers' => $this->headers,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'track_opens' => $this->trackOpens,
            'track_clicks' => $this->trackClicks,
            'status' => $this->status,
            'send_attempts' => $this->sendAttempts,
            'last_attempt_at' => $this->lastAttemptAt ? $this->lastAttemptAt->format('Y-m-d H:i:s') : null,
            'sent_at' => $this->sentAt ? $this->sentAt->format('Y-m-d H:i:s') : null,
            'scheduled_at' => $this->scheduledAt ? $this->scheduledAt->format('Y-m-d H:i:s') : null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
        ];
    }

    public static function fromArray(array $data): self
    {
        $email = new self(
            $data['from'],
            $data['from_name'],
            $data['to'],
            $data['subject'],
            $data['html_body'],
            $data['text_body'] ?? '',
            $data['campaign_id'] ?? null
        );

        if (isset($data['id'])) {
            $email->id = $data['id'];
        }

        if (isset($data['cc'])) {
            $email->cc = $data['cc'];
        }

        if (isset($data['bcc'])) {
            $email->bcc = $data['bcc'];
        }

        if (isset($data['attachments'])) {
            $email->attachments = $data['attachments'];
        }

        if (isset($data['headers'])) {
            $email->headers = $data['headers'];
        }

        if (isset($data['tags'])) {
            $email->tags = $data['tags'];
        }

        if (isset($data['metadata'])) {
            $email->metadata = $data['metadata'];
        }

        if (isset($data['track_opens'])) {
            $email->trackOpens = (bool) $data['track_opens'];
        }

        if (isset($data['track_clicks'])) {
            $email->trackClicks = (bool) $data['track_clicks'];
        }

        if (isset($data['status'])) {
            $email->status = $data['status'];
        }

        if (isset($data['send_attempts'])) {
            $email->sendAttempts = (int) $data['send_attempts'];
        }

        if (isset($data['last_attempt_at']) && $data['last_attempt_at']) {
            $email->lastAttemptAt = new DateTimeImmutable($data['last_attempt_at']);
        }

        if (isset($data['sent_at']) && $data['sent_at']) {
            $email->sentAt = new DateTimeImmutable($data['sent_at']);
        }

        if (isset($data['scheduled_at']) && $data['scheduled_at']) {
            $email->scheduledAt = new DateTimeImmutable($data['scheduled_at']);
        }

        if (isset($data['created_at'])) {
            $email->createdAt = new DateTimeImmutable($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at']) {
            $email->updatedAt = new DateTimeImmutable($data['updated_at']);
        }

        return $email;
    }
} 