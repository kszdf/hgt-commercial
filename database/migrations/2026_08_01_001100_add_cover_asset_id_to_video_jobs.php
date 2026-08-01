<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->foreignId('cover_asset_id')->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn('cover_asset_id');
        });
    }
};
