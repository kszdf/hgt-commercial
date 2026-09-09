<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 公众号文章表：AI 出稿 → SEO 优化 → 公众号草稿箱 → 群发。
     *
     * 状态机 draft（已出稿）→ reviewed（人工审核通过，留 reviewed_by/reviewed_at）
     * → published（已群发）。微信公众平台运营规范 3.27 禁止「非真人自动化创作 / 脚本
     * 托管批量连续发布」，故只有 reviewed 状态允许群发，且审核人留痕。
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 64)->nullable()->index();  // 来源会话（对话出稿）
            $table->string('title', 300);
            $table->text('digest')->nullable();                     // 摘要（55~80 字）
            $table->longText('content');                            // 正文纯文本
            $table->longText('content_html')->nullable();           // 正文 HTML（入草稿箱用）
            $table->string('author', 100)->nullable();
            $table->json('tags')->nullable();                       // 话题标签数组
            $table->string('kw_main', 100)->nullable();             // 主关键词
            $table->text('kw_long')->nullable();                    // 长尾词
            $table->string('region', 50)->nullable();               // 地域词
            $table->string('cover_path', 500)->nullable();          // 封面图路径
            $table->string('status', 20)->default('draft');         // draft/reviewed/published/failed
            $table->unsignedBigInteger('reviewed_by')->nullable();  // 审核人
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('seo_score')->default(0);
            $table->json('seo_report')->nullable();                 // SEO 校验明细
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('hit_count')->default(0);       // 违禁词命中数
            $table->string('wechat_media_id', 100)->nullable();     // 草稿 media_id
            $table->string('wechat_article_url', 500)->nullable();  // 群发后的文章链接
            $table->unsignedBigInteger('wechat_account_id')->nullable(); // platform_accounts.id
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'created_at'], 'articles_tenant_status_created_idx');
            $table->index('wechat_media_id', 'articles_wechat_media_id_idx');
        });

        // 文章群发复用 publish_records 落发布记录：该表原为视频设计，video_job_id 非空且外键
        // 指向 video_jobs，文章没有 video_job，这里放宽为空（MySQL 语法，幂等）。
        if (Schema::hasTable('publish_records')) {
            try {
                DB::statement('ALTER TABLE `publish_records` MODIFY `video_job_id` BIGINT UNSIGNED NULL');
            } catch (\Throwable $e) {
                // 已是 nullable 或权限不足时忽略，不阻断建表
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
