<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->unsignedBigInteger('player_id');
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->integer('score');
            $table->json('checkpoints');
            $table->timestamp('created_at')->nullable();
            $table->index(['player_id', 'map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('times');
    }
};
