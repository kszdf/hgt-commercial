<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 质检规则表（可版本化、可启停）。
     * type: compliance 合规 / technical 技术 / duration 时长 / content 内容 / asset 素材
     * priority: 1 高危(阻断) 2 中危(告警) 3 提示
     */
    public function up(): void
    {
        Schema::create('qc_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // forbidden_high / no_audio / require_portrait ...
            $table->string('type');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->boolean('enabled')->default(true);
            $table->json('params')->nullable();          // 阈值等，如 {"max_duration_sec":180,"require_silent_audio":true}
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active'); // draft | active
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_rules');
    }
};
