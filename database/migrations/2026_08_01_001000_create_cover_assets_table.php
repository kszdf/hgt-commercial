<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('scene')->nullable();
            $table->string('file_path');
            $table->string('preview_path')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('size')->nullable();
            $table->string('status')->default('ready'); // ready
            $table->integer('use_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_assets');
    }
};
