<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Round extends Model
{
    public const STATUS_PLAYING = 'playing';

    public const STATUS_PROMPTED = 'prompted';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'room_id',
        'room_photo_id',
        'prompt_raw',
        'prompt_clean',
        'generated_path',
        'similarity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RoomPhoto::class, 'room_photo_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PLAYING, self::STATUS_PROMPTED], true);
    }

    public function generatedUrl(): ?string
    {
        if (! $this->generated_path) {
            return null;
        }

        return '/storage/'.$this->generated_path;
    }

    public function generatedAbsolutePath(): ?string
    {
        if (! $this->generated_path) {
            return null;
        }

        return Storage::disk('public')->path($this->generated_path);
    }
}
