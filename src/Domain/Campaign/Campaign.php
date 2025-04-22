<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Campaign;

use DateTimeImmutable;

class Campaign
{
    private int $id;
    private int $accountId;
    private string $name;
    private string $subject;
    private string $fromEmail;
    private string $fromName;
    private ?string $replyTo;
    private string $contentHtml;
    private ?string $contentText;
    private string $status;
    private ?DateTimeImmutable $scheduledAt;
    private ?DateTimeImmutable $sentAt;
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt = null;
    private array $lists = [];
    private array $recipients = [];
    private int $totalRecipients = 0;
    private int $sentCount = 0;
    private int $openCount = 0;
    private int $clickCount = 0;
    private int $bounceCount = 0;
    private int $complaintCount = 0;
    private int $unsubscribeCount = 0;

    // סטטוסים אפשריים
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        int $accountId,
        string $name,
        string $subject,
        string $fromEmail,
        string $fromName,
        string $contentHtml,
        ?string $contentText = null,
        ?string $replyTo = null
    ) {
        $this->accountId = $accountId;
        $this->name = $name;
        $this->subject = $subject;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->contentHtml = $contentHtml;
        $this->contentText = $contentText;
        $this->replyTo = $replyTo;
        $this->status = self::STATUS_DRAFT;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $fromEmail): self
    {
        $this->fromEmail = $fromEmail;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function setFromName(string $fromName): self
    {
        $this->fromName = $fromName;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function setReplyTo(?string $replyTo): self
    {
        $this->replyTo = $replyTo;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getContentHtml(): string
    {
        return $this->contentHtml;
    }

    public function setContentHtml(string $contentHtml): self
    {
        $this->contentHtml = $contentHtml;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getContentText(): ?string
    {
        return $this->contentText;
    }

    public function setContentText(?string $contentText): self
    {
        $this->contentText = $contentText;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $allowedStatuses = [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_SENDING,
            self::STATUS_SENT,
            self::STATUS_PAUSED,
            self::STATUS_CANCELED,
            self::STATUS_FAILED,
        ];

        if (!in_array($status, $allowedStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();

        if ($status === self::STATUS_SENT && !$this->sentAt) {
            $this->sentAt = new DateTimeImmutable();
        }

        return $this;
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

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLists(): array
    {
        return $this->lists;
    }

    public function addList(int $listId): self
    {
        if (!in_array($listId, $this->lists)) {
            $this->lists[] = $listId;
        }
        return $this;
    }

    public function removeList(int $listId): self
    {
        $this->lists = array_filter($this->lists, function ($id) use ($listId) {
            return $id !== $listId;
        });
        return $this;
    }

    public function setLists(array $listIds): self
    {
        $this->lists = $listIds;
        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        $this->totalRecipients = count($recipients);
        return $this;
    }

    public function addRecipient(array $recipient): self
    {
        $this->recipients[] = $recipient;
        $this->totalRecipients++;
        return $this;
    }

    public function getTotalRecipients(): int
    {
        return $this->totalRecipients;
    }

    public function setTotalRecipients(int $count): self
    {
        $this->totalRecipients = $count;
        return $this;
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function setSentCount(int $count): self
    {
        $this->sentCount = $count;
        return $this;
    }

    public function incrementSentCount(): self
    {
        $this->sentCount++;
        return $this;
    }

    public function getOpenCount(): int
    {
        return $this->openCount;
    }

    public function setOpenCount(int $count): self
    {
        $this->openCount = $count;
        return $this;
    }

    public function incrementOpenCount(): self
    {
        $this->openCount++;
        return $this;
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function setClickCount(int $count): self
    {
        $this->clickCount = $count;
        return $this;
    }

    public function incrementClickCount(): self
    {
        $this->clickCount++;
        return $this;
    }

    public function getBounceCount(): int
    {
        return $this->bounceCount;
    }

    public function setBounceCount(int $count): self
    {
        $this->bounceCount = $count;
        return $this;
    }

    public function incrementBounceCount(): self
    {
        $this->bounceCount++;
        return $this;
    }

    public function getComplaintCount(): int
    {
        return $this->complaintCount;
    }

    public function setComplaintCount(int $count): self
    {
        $this->complaintCount = $count;
        return $this;
    }

    public function incrementComplaintCount(): self
    {
        $this->complaintCount++;
        return $this;
    }

    public function getUnsubscribeCount(): int
    {
        return $this->unsubscribeCount;
    }

    public function setUnsubscribeCount(int $count): self
    {
        $this->unsubscribeCount = $count;
        return $this;
    }

    public function incrementUnsubscribeCount(): self
    {
        $this->unsubscribeCount++;
        return $this;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED && $this->scheduledAt !== null;
    }

    public function isReadyToSend(): bool
    {
        return ($this->status === self::STATUS_DRAFT || $this->status === self::STATUS_SCHEDULED) && 
               !empty($this->fromEmail) && 
               !empty($this->fromName) && 
               !empty($this->subject) && 
               !empty($this->contentHtml) && 
               $this->totalRecipients > 0;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id ?? null,
            'account_id' => $this->accountId,
            'name' => $this->name,
            'subject' => $this->subject,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'reply_to' => $this->replyTo,
            'content_html' => $this->contentHtml,
            'content_text' => $this->contentText,
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt ? $this->scheduledAt->format('Y-m-d H:i:s') : null,
            'sent_at' => $this->sentAt ? $this->sentAt->format('Y-m-d H:i:s') : null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
            'lists' => $this->lists,
            'total_recipients' => $this->totalRecipients,
            'sent_count' => $this->sentCount,
            'open_count' => $this->openCount,
            'click_count' => $this->clickCount,
            'bounce_count' => $this->bounceCount,
            'complaint_count' => $this->complaintCount,
            'unsubscribe_count' => $this->unsubscribeCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        $campaign = new self(
            $data['account_id'],
            $data['name'],
            $data['subject'],
            $data['from_email'],
            $data['from_name'],
            $data['content_html'],
            $data['content_text'] ?? null,
            $data['reply_to'] ?? null
        );

        if (isset($data['id'])) {
            $campaign->setId($data['id']);
        }

        if (isset($data['status'])) {
            $campaign->setStatus($data['status']);
        }

        if (isset($data['scheduled_at']) && $data['scheduled_at']) {
            $campaign->schedule(new DateTimeImmutable($data['scheduled_at']));
        }

        if (isset($data['sent_at']) && $data['sent_at']) {
            $campaign->setSentAt(new DateTimeImmutable($data['sent_at']));
        }

        if (isset($data['created_at']) && $data['created_at']) {
            $campaign->setCreatedAt(new DateTimeImmutable($data['created_at']));
        }

        if (isset($data['updated_at']) && $data['updated_at']) {
            $campaign->setUpdatedAt(new DateTimeImmutable($data['updated_at']));
        }

        if (isset($data['lists']) && is_array($data['lists'])) {
            $campaign->setLists($data['lists']);
        }

        if (isset($data['total_recipients'])) {
            $campaign->setTotalRecipients((int)$data['total_recipients']);
        }

        if (isset($data['sent_count'])) {
            $campaign->setSentCount((int)$data['sent_count']);
        }

        if (isset($data['open_count'])) {
            $campaign->setOpenCount((int)$data['open_count']);
        }

        if (isset($data['click_count'])) {
            $campaign->setClickCount((int)$data['click_count']);
        }

        if (isset($data['bounce_count'])) {
            $campaign->setBounceCount((int)$data['bounce_count']);
        }

        if (isset($data['complaint_count'])) {
            $campaign->setComplaintCount((int)$data['complaint_count']);
        }

        if (isset($data['unsubscribe_count'])) {
            $campaign->setUnsubscribeCount((int)$data['unsubscribe_count']);
        }

        return $campaign;
    }
} 