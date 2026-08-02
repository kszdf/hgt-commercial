<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 平台预设封面没有归属用户，user_id 需可空（保留外键）。
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE cover_assets MODIFY user_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cover_assets MODIFY user_id BIGINT UNSIGNED NOT NULL');
    }
};
