<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Template;

use DateTimeImmutable;

/**
 * מודל תבנית HTML לשליחת מיילים
 */
class Template
{
    // סוגי תבניות
    public const TYPE_EMAIL = 'email';
    public const TYPE_PAGE = 'page';
    
    // סטטוסים אפשריים לתבנית
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    
    // מזהה התבנית
    private ?int $id = null;
    
    // מזהה החשבון
    private int $accountId;
    
    // שם התבנית
    private string $name;
    
    // תיאור התבנית
    private ?string $description;
    
    // קטגוריה של התבנית
    private ?string $category;
    
    // סוג התבנית (מייל או דף נחיתה)
    private string $type;
    
    // סטטוס התבנית
    private string $status;
    
    // תוכן ה-HTML של התבנית
    private string $contentHtml;
    
    // תוכן טקסט פשוט (חלופה)
    private ?string $contentText;
    
    // נושא ברירת מחדל (לתבניות מייל)
    private ?string $defaultSubject;
    
    // תגים לסיווג התבנית
    private array $tags = [];
    
    // האם התבנית היא ברירת מחדל
    private bool $isDefault = false;
    
    // צילום מסך מוקטן של התבנית
    private ?string $thumbnail = null;
    
    // נתוני יצירה ועדכון
    private DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt = null;
    
    /**
     * בנאי המחלקה
     */
    public function __construct(
        int $accountId,
        string $name,
        string $contentHtml,
        string $type = self::TYPE_EMAIL,
        string $status = self::STATUS_DRAFT,
        ?string $description = null,
        ?string $category = null,
        ?string $contentText = null,
        ?string $defaultSubject = null,
        array $tags = []
    ) {
        $this->accountId = $accountId;
        $this->name = $name;
        $this->contentHtml = $contentHtml;
        $this->type = $type;
        $this->status = $status;
        $this->description = $description;
        $this->category = $category;
        $this->contentText = $contentText;
        $this->defaultSubject = $defaultSubject;
        $this->tags = $tags;
        $this->createdAt = new DateTimeImmutable();
    }
    
    /**
     * הגדרת מזהה התבנית
     */
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    /**
     * קבלת מזהה התבנית
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
     * קבלת שם התבנית
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * הגדרת שם התבנית
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת תיאור התבנית
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    /**
     * הגדרת תיאור התבנית
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת קטגוריית התבנית
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }
    
    /**
     * הגדרת קטגוריית התבנית
     */
    public function setCategory(?string $category): self
    {
        $this->category = $category;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת סוג התבנית
     */
    public function getType(): string
    {
        return $this->type;
    }
    
    /**
     * הגדרת סוג התבנית
     */
    public function setType(string $type): self
    {
        if (!in_array($type, [self::TYPE_EMAIL, self::TYPE_PAGE])) {
            throw new \InvalidArgumentException('Invalid template type');
        }
        
        $this->type = $type;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת סטטוס התבנית
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    
    /**
     * הגדרת סטטוס התבנית
     */
    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED])) {
            throw new \InvalidArgumentException('Invalid template status');
        }
        
        $this->status = $status;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת תוכן ה-HTML של התבנית
     */
    public function getContentHtml(): string
    {
        return $this->contentHtml;
    }
    
    /**
     * הגדרת תוכן ה-HTML של התבנית
     */
    public function setContentHtml(string $contentHtml): self
    {
        $this->contentHtml = $contentHtml;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת תוכן טקסט של התבנית
     */
    public function getContentText(): ?string
    {
        return $this->contentText;
    }
    
    /**
     * הגדרת תוכן טקסט של התבנית
     */
    public function setContentText(?string $contentText): self
    {
        $this->contentText = $contentText;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת נושא ברירת מחדל
     */
    public function getDefaultSubject(): ?string
    {
        return $this->defaultSubject;
    }
    
    /**
     * הגדרת נושא ברירת מחדל
     */
    public function setDefaultSubject(?string $defaultSubject): self
    {
        $this->defaultSubject = $defaultSubject;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת התגים של התבנית
     */
    public function getTags(): array
    {
        return $this->tags;
    }
    
    /**
     * הגדרת תגים לתבנית
     */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * בדיקה האם התבנית היא ברירת מחדל
     */
    public function isDefault(): bool
    {
        return $this->isDefault;
    }
    
    /**
     * הגדרת תבנית כברירת מחדל
     */
    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        $this->setUpdatedAt();
        return $this;
    }
    
    /**
     * קבלת התמונה המוקטנת
     */
    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }
    
    /**
     * הגדרת התמונה המוקטנת
     */
    public function setThumbnail(?string $thumbnail): self
    {
        $this->thumbnail = $thumbnail;
        $this->setUpdatedAt();
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
            'category' => $this->category,
            'type' => $this->type,
            'status' => $this->status,
            'content_html' => $this->contentHtml,
            'content_text' => $this->contentText,
            'default_subject' => $this->defaultSubject,
            'tags' => $this->tags,
            'is_default' => $this->isDefault,
            'thumbnail' => $this->thumbnail,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null
        ];
    }
    
    /**
     * יצירת אובייקט מתוך מערך נתונים ממסד הנתונים
     */
    public static function fromArray(array $data): self
    {
        $template = new self(
            (int)$data['account_id'],
            $data['name'],
            $data['content_html'],
            $data['type'] ?? self::TYPE_EMAIL,
            $data['status'] ?? self::STATUS_DRAFT,
            $data['description'] ?? null,
            $data['category'] ?? null,
            $data['content_text'] ?? null,
            $data['default_subject'] ?? null,
            isset($data['tags']) && is_string($data['tags']) ? json_decode($data['tags'], true) : ($data['tags'] ?? [])
        );
        
        if (isset($data['id'])) {
            $template->setId((int)$data['id']);
        }
        
        if (isset($data['is_default'])) {
            $template->setIsDefault((bool)$data['is_default']);
        }
        
        if (isset($data['thumbnail'])) {
            $template->setThumbnail($data['thumbnail']);
        }
        
        if (isset($data['created_at'])) {
            $template->createdAt = new DateTimeImmutable($data['created_at']);
        }
        
        if (isset($data['updated_at']) && $data['updated_at']) {
            $template->updatedAt = new DateTimeImmutable($data['updated_at']);
        }
        
        return $template;
    }
} 