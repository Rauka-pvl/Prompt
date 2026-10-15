<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class RoomPhoto extends Model
{
    protected $fillable = [
        'room_id',
        'path',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function forbiddenWords(): HasMany
    {
        return $this->hasMany(ForbiddenWord::class)->orderBy('id');
    }

    /**
     * Alias for scoped route binding on /photos/{photo}/words/{word}.
     */
    public function words(): HasMany
    {
        return $this->hasMany(ForbiddenWord::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function url(): string
    {
        return '/storage/'.$this->path;
    }

    public function absolutePath(): string
    {
        return Storage::disk('public')->path($this->path);
    }
}
