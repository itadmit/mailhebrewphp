<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Tracking;

use DateTimeImmutable;

/**
 * מודל לאירועי מעקב של מיילים
 */
class TrackingEvent
{
    // סוגי אירועים
    public const TYPE_QUEUED = 'queued';
    public const TYPE_SEND = 'send';
    public const TYPE_OPEN = 'open';
    public const TYPE_CLICK = 'click';
    public const TYPE_BOUNCE = 'bounce';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_UNSUBSCRIBE = 'unsubscribe';
    
    // מזהה האירוע
    private ?int $id = null;
    
    // מזהה הקמפיין
    private ?int $campaignId;
    
    // כתובת המייל של הנמען
    private string $recipientEmail;
    
    // סוג האירוע
    private string $eventType;
    
    // מזהה ייחודי לקבוצת שליחה
    private ?string $batchId;
    
    // תאריך התרחשות האירוע
    private DateTimeImmutable $occurredAt;
    
    // נתונים נוספים בפורמט JSON
    private array $eventData = [];
    
    // כתובת IP של הנמען
    private ?string $ipAddress = null;
    
    // נתוני דפדפן של הנמען
    private ?string $userAgent = null;
    
    // מזהה ייחודי של האירוע (לפי צורך)
    private ?string $eventId = null;
    
    /**
     * בנאי המחלקה
     */
    public function __construct(
        string $recipientEmail,
        string $eventType,
        ?int $campaignId = null,
        ?string $batchId = null,
        ?array $eventData = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ) {
        $this->recipientEmail = $recipientEmail;
        $this->setEventType($eventType);
        $this->campaignId = $campaignId;
        $this->batchId = $batchId;
        $this->eventData = $eventData ?? [];
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->occurredAt = new DateTimeImmutable();
        
        // יצירת מזהה אירוע ייחודי אם לא סופק
        $this->eventId = uniqid('evt_', true);
    }
    
    /**
     * הגדרת מזהה האירוע
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    /**
     * קבלת מזהה האירוע
     */
    public function getId(): ?int
    {
        return $this->id;
    }
    
    /**
     * קבלת מזהה הקמפיין
     */
    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }
    
    /**
     * הגדרת מזהה הקמפיין
     */
    public function setCampaignId(?int $campaignId): self
    {
        $this->campaignId = $campaignId;
        return $this;
    }
    
    /**
     * קבלת כתובת המייל של הנמען
     */
    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }
    
    /**
     * הגדרת כתובת המייל של הנמען
     */
    public function setRecipientEmail(string $recipientEmail): self
    {
        $this->recipientEmail = $recipientEmail;
        return $this;
    }
    
    /**
     * קבלת סוג האירוע
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }
    
    /**
     * הגדרת סוג האירוע
     */
    public function setEventType(string $eventType): self
    {
        $validEventTypes = [
            self::TYPE_QUEUED,
            self::TYPE_SEND,
            self::TYPE_OPEN,
            self::TYPE_CLICK,
            self::TYPE_BOUNCE,
            self::TYPE_COMPLAINT,
            self::TYPE_UNSUBSCRIBE
        ];
        
        if (!in_array($eventType, $validEventTypes)) {
            throw new \InvalidArgumentException('Invalid event type: ' . $eventType);
        }
        
        $this->eventType = $eventType;
        return $this;
    }
    
    /**
     * קבלת מזהה קבוצת השליחה
     */
    public function getBatchId(): ?string
    {
        return $this->batchId;
    }
    
    /**
     * הגדרת מזהה קבוצת השליחה
     */
    public function setBatchId(?string $batchId): self
    {
        $this->batchId = $batchId;
        return $this;
    }
    
    /**
     * קבלת תאריך התרחשות האירוע
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
    
    /**
     * הגדרת תאריך התרחשות האירוע
     */
    public function setOccurredAt(DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }
    
    /**
     * קבלת נתוני האירוע
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }
    
    /**
     * הגדרת נתוני האירוע
     */
    public function setEventData(array $eventData): self
    {
        $this->eventData = $eventData;
        return $this;
    }
    
    /**
     * הוספת פרמטר לנתוני האירוע
     */
    public function addEventData(string $key, $value): self
    {
        $this->eventData[$key] = $value;
        return $this;
    }
    
    /**
     * קבלת כתובת IP
     */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
    
    /**
     * הגדרת כתובת IP
     */
    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }
    
    /**
     * קבלת נתוני דפדפן
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
    
    /**
     * הגדרת נתוני דפדפן
     */
    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }
    
    /**
     * קבלת מזהה ייחודי של האירוע
     */
    public function getEventId(): ?string
    {
        return $this->eventId;
    }
    
    /**
     * הגדרת מזהה ייחודי של האירוע
     */
    public function setEventId(string $eventId): self
    {
        $this->eventId = $eventId;
        return $this;
    }
    
    /**
     * המרה למערך
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaignId,
            'recipient_email' => $this->recipientEmail,
            'event_type' => $this->eventType,
            'batch_id' => $this->batchId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'event_data' => $this->eventData,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'event_id' => $this->eventId
        ];
    }
    
    /**
     * יצירת אובייקט מתוך מערך נתונים ממסד הנתונים
     */
    public static function fromArray(array $data): self
    {
        $eventData = [];
        if (isset($data['event_data'])) {
            if (is_string($data['event_data'])) {
                $eventData = json_decode($data['event_data'], true) ?: [];
            } elseif (is_array($data['event_data'])) {
                $eventData = $data['event_data'];
            }
        }
        
        $event = new self(
            $data['recipient_email'],
            $data['event_type'],
            isset($data['campaign_id']) ? (int)$data['campaign_id'] : null,
            $data['batch_id'] ?? null,
            $eventData,
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null
        );
        
        if (isset($data['id'])) {
            $event->setId((int)$data['id']);
        }
        
        if (isset($data['event_id'])) {
            $event->setEventId($data['event_id']);
        }
        
        if (isset($data['occurred_at'])) {
            $event->setOccurredAt(new DateTimeImmutable($data['occurred_at']));
        }
        
        return $event;
    }
} 