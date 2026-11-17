<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    /** Saved locally, not yet accepted by Pixelforge. Shown to users as "queued". */
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const IN_FLIGHT = [self::PENDING, self::QUEUED, self::PROCESSING];

    /** Statuses only ever move forward along this order. */
    public const RANK = [self::PENDING => 0, self::QUEUED => 1, self::PROCESSING => 2, self::SUCCEEDED => 3, self::FAILED => 3];

    /** We gave up handing the request to Pixelforge (as opposed to Pixelforge failing it). */
    public const ERROR_NOT_SUBMITTED = 'submission_failed';

    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_FLIGHT);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    /** The payload sent to POST /v1/generations. */
    public function providerPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            // Lets a webhook find this row even if it arrives before the create call has returned.
            'metadata' => ['generation_id' => (string) $this->id],
        ];
    }
}
