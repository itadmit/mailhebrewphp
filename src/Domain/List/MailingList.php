<?php

declare(strict_types=1);

namespace MailHebrew\Domain\MailingList;

use DateTimeImmutable;

/**
 * מודל רשימת תפוצה
 */
class MailingList
{
    // סטטוסים אפשריים לרשימת תפוצה
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    
    // מזהה רשימת התפוצה
    private ?int $id = null;
    
    // מזהה החשבון אליו שייכת הרשימה
    private int $accountId;
    
    // שם רשימת התפוצה
    private string $name;
    
    // תיאור רשימת התפוצה
    private ?string $description;
    
    // סטטוס רשימת התפוצה
    private string $status;
    
    // תגים לסיווג רשימת התפוצה
    private array $tags = [];
    
    // מספר הנמענים ברשימה
    private int $recipientCount = 0;
    
    // נתוני יצירה ועדכון
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt = null;
    
    /**
     * בנאי המחלקה
     */
    public function __construct(
        int $accountId,
        string $name,
        ?string $description = null,
        string $status = self::STATUS_ACTIVE,
        array $tags = []
    ) {
        $this->accountId = $accountId;
        $this->name = $name;
        $this->description = $description;
        $this->status = $status;
        $this->tags = $tags;
        $this->createdAt = new DateTimeImmutable();
    }
    
    /**
     * הגדרת מזהה הרשימה
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    /**
     * קבלת מזהה הרשימה
     */
    public function getId(): ?int
    {
        return $this->id;
    }
    
    /**
     * קבלת מזהה החשבון
     */
    public function getAccountId(): int
    {
        return $this->accountId;
    }
    
    /**
     * קבלת שם הרשימה
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * הגדרת שם הרשימה
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת תיאור הרשימה
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    /**
     * הגדרת תיאור הרשימה
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת סטטוס הרשימה
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * הגדרת סטטוס הרשימה
     */
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE])) {
            throw new \InvalidArgumentException('Invalid list status');
        }
        
        $this->status = $status;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * בדיקה האם הרשימה פעילה
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
    
    /**
     * קבלת התגים של הרשימה
     */
    public function getTags(): array
    {
        return $this->tags;
    }
    
    /**
     * הגדרת תגים לרשימה
     */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * הוספת תג לרשימה
     */
    public function addTag(string $tag): self
    {
        if (!in_array($tag, $this->tags)) {
            $this->tags[] = $tag;
            $this->setUpdatedAt();
        }
        
        return $this;
    }
    
    /**
     * הסרת תג מהרשימה
     */
    public function removeTag(string $tag): self
    {
        $key = array_search($tag, $this->tags);
        
        if ($key !== false) {
            unset($this->tags[$key]);
            $this->tags = array_values($this->tags); // סידור מחדש של המערך
            $this->setUpdatedAt();
        }
        
        return $this;
    }
    
    /**
     * קבלת מספר הנמענים ברשימה
     */
    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }
    
    /**
     * הגדרת מספר הנמענים ברשימה
     */
    public function setRecipientCount(int $count): self
    {
        $this->recipientCount = max(0, $count);
        return $this;
    }
    
    /**
     * קבלת תאריך יצירה
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
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
            'account_id' => $this->accountId,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'tags' => $this->tags,
            'recipient_count' => $this->recipientCount,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null
        ];
    }
    
    /**
     * יצירת אובייקט מתוך מערך נתונים ממסד הנתונים
     */
    public static function fromArray(array $data): self
    {
        $list = new self(
            (int)$data['account_id'],
            $data['name'],
            $data['description'] ?? null,
            $data['status'] ?? self::STATUS_ACTIVE,
            isset($data['tags']) && is_string($data['tags']) ? json_decode($data['tags'], true) : []
        );
        
        if (isset($data['id'])) {
            $list->setId((int)$data['id']);
        }
        
        if (isset($data['recipient_count'])) {
            $list->setRecipientCount((int)$data['recipient_count']);
        }
        
        if (isset($data['created_at'])) {
            $list->createdAt = new DateTimeImmutable($data['created_at']);
        }
        
        if (isset($data['updated_at']) && $data['updated_at']) {
            $list->updatedAt = new DateTimeImmutable($data['updated_at']);
        }
        
        return $list;
    }
} 