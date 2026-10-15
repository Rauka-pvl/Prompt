<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForbiddenWord extends Model
{
    protected $fillable = [
        'room_photo_id',
        'word',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RoomPhoto::class, 'room_photo_id');
    }
}
