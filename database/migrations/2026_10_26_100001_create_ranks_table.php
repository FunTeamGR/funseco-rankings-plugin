<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->unsignedBigInteger('player_id')->primary();
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->double('average')->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
