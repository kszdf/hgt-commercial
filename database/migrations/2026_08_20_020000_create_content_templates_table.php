<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 话术模板市场（功能包四）：财税垂类话术模板。
 *
 *  - tenant_id 为空 = 平台级模板（所有租户可见，超管维护）；
 *  - tenant_id 非空 = 租户私有模板（"我的模板"）；
 *  - type: hook(留资钩子) / opening(爆款开头) / avoidance(避坑清单) / ending(结尾引导) / angle(选题角度)。
 * 模板内容按合规标准编写（不承诺避税、不诱导虚开），use_count 统计使用热度。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title', 60);
            $table->text('content');
            $table->json('tags')->nullable();
            $table->string('status', 10)->default('active'); // active | disabled
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_templates');
    }
};
