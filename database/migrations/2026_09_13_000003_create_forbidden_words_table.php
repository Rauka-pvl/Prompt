<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forbidden_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_photo_id')->constrained('room_photos')->cascadeOnDelete();
            $table->string('word');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forbidden_words');
    }
};
