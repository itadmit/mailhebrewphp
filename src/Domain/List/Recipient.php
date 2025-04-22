<?php

declare(strict_types=1);

namespace MailHebrew\Domain\MailingList;

use DateTimeImmutable;

/**
 * מודל נמען ברשימת תפוצה
 */
class Recipient
{
    // סטטוסים אפשריים לנמען
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_COMPLAINED = 'complained';
    
    // מזהה הנמען
    private ?int $id = null;
    
    // כתובת המייל (המזהה העיקרי)
    private string $email;
    
    // שם פרטי
    private ?string $firstName;
    
    // שם משפחה
    private ?string $lastName;
    
    // מזהה רשימת התפוצה
    private int $listId;
    
    // סטטוס הנמען
    private string $status;
    
    // נתונים נוספים (JSON)
    private array $customFields = [];
    
    // תאריך הרשמה
    private DateTimeImmutable $subscribedAt;
    
    // תאריך הסרה מרשימת התפוצה
    private ?DateTimeImmutable $unsubscribedAt = null;
    
    // תאריך עדכון אחרון
    private ?DateTimeImmutable $updatedAt = null;
    
    /**
     * בנאי המחלקה
     */
    public function __construct(
        string $email,
        int $listId,
        ?string $firstName = null,
        ?string $lastName = null,
        string $status = self::STATUS_ACTIVE,
        array $customFields = []
    ) {
        $this->email = $email;
        $this->listId = $listId;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->status = $status;
        $this->customFields = $customFields;
        $this->subscribedAt = new DateTimeImmutable();
    }
    
    /**
     * הגדרת מזהה הנמען
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    /**
     * קבלת מזהה הנמען
     */
    public function getId(): ?int
    {
        return $this->id;
    }
    
    /**
     * קבלת כתובת המייל
     */
    public function getEmail(): string
    {
        return $this->email;
    }
    
    /**
     * הגדרת כתובת המייל
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת השם הפרטי
     */
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }
    
    /**
     * הגדרת השם הפרטי
     */
    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת שם המשפחה
     */
    public function getLastName(): ?string
    {
        return $this->lastName;
    }
    
    /**
     * הגדרת שם המשפחה
     */
    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת השם המלא
     */
    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }
    
    /**
     * קבלת מזהה רשימת התפוצה
     */
    public function getListId(): int
    {
        return $this->listId;
    }
    
    /**
     * הגדרת מזהה רשימת התפוצה
     */
    public function setListId(int $listId): self
    {
        $this->listId = $listId;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת סטטוס הנמען
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * הגדרת סטטוס הנמען
     */
    public function setStatus(string $status): self
    {
        $validStatuses = [
            self::STATUS_ACTIVE,
            self::STATUS_UNSUBSCRIBED,
            self::STATUS_BOUNCED,
            self::STATUS_COMPLAINED
        ];
        
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException('Invalid recipient status');
        }
        
        $oldStatus = $this->status;
        $this->status = $status;
        
        // עדכון תאריך הסרה אם הסטטוס השתנה להסרה מרשימת התפוצה
        if ($oldStatus !== self::STATUS_UNSUBSCRIBED && $status === self::STATUS_UNSUBSCRIBED) {
            $this->unsubscribedAt = new DateTimeImmutable();
        }
        
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * בדיקה האם הנמען פעיל
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
    
    /**
     * קבלת נתונים נוספים
     */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }
    
    /**
     * הגדרת נתונים נוספים
     */
    public function setCustomFields(array $customFields): self
    {
        $this->customFields = $customFields;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת ערך שדה מותאם אישית
     */
    public function getCustomField(string $key)
    {
        return $this->customFields[$key] ?? null;
    }
    
    /**
     * הגדרת ערך שדה מותאם אישית
     */
    public function setCustomField(string $key, $value): self
    {
        $this->customFields[$key] = $value;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * הסרת שדה מותאם אישית
     */
    public function removeCustomField(string $key): self
    {
        if (isset($this->customFields[$key])) {
            unset($this->customFields[$key]);
            $this->setUpdatedAt();
        }
        
        return $this;
    }
    
    /**
     * קבלת תאריך הרשמה
     */
    public function getSubscribedAt(): DateTimeImmutable
    {
        return $this->subscribedAt;
    }
    
    /**
     * קבלת תאריך הסרה מרשימת התפוצה
     */
    public function getUnsubscribedAt(): ?DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }
    
    /**
     * קבלת תאריך עדכון אחרון
     */
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
    
    /**
     * עדכון תאריך עדכון
     */
    private function setUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
    
    /**
     * המרה למערך
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
            'list_id' => $this->listId,
            'status' => $this->status,
            'custom_fields' => $this->customFields,
            'subscribed_at' => $this->subscribedAt->format('Y-m-d H:i:s'),
            'unsubscribed_at' => $this->unsubscribedAt ? $this->unsubscribedAt->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null
        ];
    }
    
    /**
     * יצירת אובייקט מתוך מערך נתונים ממסד הנתונים
     */
    public static function fromArray(array $data): self
    {
        $recipient = new self(
            $data['email'],
            (int)$data['list_id'],
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['status'] ?? self::STATUS_ACTIVE,
            isset($data['custom_fields']) && is_string($data['custom_fields']) ? 
                json_decode($data['custom_fields'], true) : 
                ($data['custom_fields'] ?? [])
        );
        
        if (isset($data['id'])) {
            $recipient->setId((int)$data['id']);
        }
        
        if (isset($data['subscribed_at'])) {
            $recipient->subscribedAt = new DateTimeImmutable($data['subscribed_at']);
        }
        
        if (isset($data['unsubscribed_at']) && $data['unsubscribed_at']) {
            $recipient->unsubscribedAt = new DateTimeImmutable($data['unsubscribed_at']);
        }
        
        if (isset($data['updated_at']) && $data['updated_at']) {
            $recipient->updatedAt = new DateTimeImmutable($data['updated_at']);
        }
        
        return $recipient;
    }
} 