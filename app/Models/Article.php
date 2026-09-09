<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 公众号文章（AI 出稿 → SEO 优化 → 公众号草稿箱 → 群发）。
 *
 * 状态机：draft（已出稿）→ reviewed（人工审核通过）→ published（已群发）。
 * 微信公众平台运营规范 3.27：禁止非真人自动化创作与脚本托管批量连续发布，
 * 故仅 reviewed 状态可群发，且审核人 / 审核时间必须留痕（canPublish()）。
 */
class Article extends Model
{
    use SoftDeletes;

    protected $table = 'articles';

    /** 状态中文名（列表徽标 / 筛选 chips 同源）。 */
    public const STATUS_LABELS = [
        'draft'     => '待审核',
        'reviewed'  => '已审核',
        'published' => '已发表',
        'failed'    => '失败',
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'session_id',
        'title', 'digest', 'content', 'content_html',
        'author', 'tags',
        'kw_main', 'kw_long', 'region',
        'cover_path', 'status', 'reviewed_by', 'reviewed_at',
        'seo_score', 'seo_report', 'word_count', 'hit_count',
        'wechat_media_id', 'wechat_article_url', 'wechat_account_id',
        'published_at', 'meta',
    ];

    protected $casts = [
        'tags'        => 'array',
        'seo_report'  => 'array',
        'meta'        => 'array',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'seo_score'   => 'integer',
        'word_count'  => 'integer',
        'hit_count'   => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 审核人（human-in-the-loop 留痕）。 */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** 目标公众号账号（platform_accounts.id）。 */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class, 'wechat_account_id');
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** 关键词搜索：标题 + 正文 LIKE。 */
    public function scopeSearch($query, $keyword)
    {
        $kw = trim((string) $keyword);
        if ($kw === '') {
            return $query;
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('content', 'like', $like);
        });
    }

    public function scopeOfStatus($query, $status)
    {
        $st = trim((string) $status);
        if ($st === '') {
            return $query;
        }
        return $query->where('status', $st);
    }

    /** 摘要截取（无 digest 时用正文兜底）。 */
    public function excerpt(int $n = 80): string
    {
        $text = trim((string) ($this->digest ?: $this->content));
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        return mb_strlen($text) > $n ? mb_substr($text, 0, $n) . '…' : $text;
    }

    /**
     * 是否可群发：仅人工审核通过（reviewed）放行。
     * 微信 3.27 合规红线——未审核不允许自动群发。
     */
    public function canPublish(): bool
    {
        return $this->status === 'reviewed';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** SEO 得分档位：high(≥80 绿) / mid(60~79 黄) / low(<60 红)。 */
    public function seoLevel(): string
    {
        $score = (int) $this->seo_score;
        if ($score >= 80) {
            return 'high';
        }
        return $score >= 60 ? 'mid' : 'low';
    }
}
