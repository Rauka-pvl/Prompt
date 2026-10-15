<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Room extends Model
{
    protected $fillable = [
        'key',
        'name',
        'plays_count',
    ];

    protected function casts(): array
    {
        return [
            'plays_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RoomPhoto::class)->orderBy('sort')->orderBy('id');
    }

    public function activePhoto(): HasOne
    {
        return $this->hasOne(RoomPhoto::class)->where('is_active', true);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function latestRound(): HasOne
    {
        return $this->hasOne(Round::class)->latestOfMany();
    }

    public function currentRound(): ?Round
    {
        $round = $this->latestRound;

        if ($round && $round->isOpen()) {
            return $round;
        }

        return null;
    }

    public function cancelOpenRounds(): void
    {
        $this->rounds()
            ->whereIn('status', [Round::STATUS_PLAYING, Round::STATUS_PROMPTED])
            ->update(['status' => Round::STATUS_CANCELLED]);
    }
}
