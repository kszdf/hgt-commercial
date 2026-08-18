<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 爆款复刻 / 参数快照（功能包五）：
 *  - dialogue：出片所用文稿（复刻时一键带回出片页）；
 *  - render_config：出片参数快照（形式/声音/字幕等，JSON）；
 *  - is_hit：用户标记的爆款（数据复盘联动，后续可接播放数据自动推荐）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->text('dialogue')->nullable()->after('title');
            $table->json('render_config')->nullable()->after('dialogue');
            $table->boolean('is_hit')->default(false)->after('render_config');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['dialogue', 'render_config', 'is_hit']);
        });
    }
};
