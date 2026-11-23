<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Generation extends Model
{
    public const SUBMITTING = 'submitting';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /**
     * Order used to stop a late event from moving a generation backwards.
     */
    public const RANK = [
        self::SUBMITTING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charge(): HasOne
    {
        return $this->hasOne(Charge::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    protected function estimatedDisplay(): Attribute
    {
        return Attribute::get(fn () => Money::display($this->estimated_nano));
    }

    protected function chargeDisplay(): Attribute
    {
        return Attribute::get(fn () => $this->charge_nano === null ? null : Money::display($this->charge_nano));
    }
}
