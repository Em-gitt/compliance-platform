<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    use Auditable;

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_PROCESSING_FAILED = 'processing_failed';

    /** Status badge shown in the document detail (FR-003). */
    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_PROCESSED => 'Processed',
        self::STATUS_PROCESSING_FAILED => 'Processing Failed',
    ];

    public const MESSAGE_DAMAGED_FILE = 'The file could not be read. It may be damaged or password-protected.';

    public const MESSAGE_NO_TEXT = 'No text could be extracted from this document.';

    /** Shown in the document detail when OCR fails or produces no text (CMP-26). */
    public const MESSAGE_OCR_FAILED = 'The scanned document could not be read (OCR failed). Upload a clearer scan or a text-based PDF.';

    /** Shown when a scanned PDF exceeds 100 pages (CMP-26). */
    public const MESSAGE_SCANNED_PAGE_LIMIT = 'Scanned documents can have up to 100 pages.';

    /** Shown in the document detail when FastAPI returns 502 EMBEDDING_FAILED (CMP-29). */
    public const MESSAGE_INDEXING_FAILED = 'The document text could not be indexed for the AI assistant.';

    protected $guarded = [];

    /**
     * Keep failure_reason limited to the exact user-facing messages defined in CMP-25 and CMP-26.
     */
    public static function extractionFailureMessage(string $message): string
    {
        return in_array($message, [self::MESSAGE_DAMAGED_FILE, self::MESSAGE_NO_TEXT, self::MESSAGE_OCR_FAILED, self::MESSAGE_SCANNED_PAGE_LIMIT], true)
            ? $message
            : self::MESSAGE_DAMAGED_FILE;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'is_current' => 'boolean',
            'ocr_used' => 'boolean',
            'ocr_confidence' => 'float',
            'uploaded_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'chunk_count' => 'integer',
            'page_count' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
