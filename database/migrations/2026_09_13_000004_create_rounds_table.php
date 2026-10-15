<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_photo_id')->constrained('room_photos')->cascadeOnDelete();
            $table->text('prompt_raw')->nullable();
            $table->text('prompt_clean')->nullable();
            $table->string('generated_path')->nullable();
            $table->decimal('similarity', 5, 2)->nullable();
            $table->string('status', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};
