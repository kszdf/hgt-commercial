<?php

namespace App\Models;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoJob extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'job_id', 'mode', 'title', 'status',
        'qc_status', 'publish_status', 'review_note', 'cover_asset_id', 'batch_id',
        'heartbeat_at', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function qcReport(): BelongsTo
    {
        return $this->belongsTo(QcReport::class, 'id', 'video_job_id');
    }

    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(CoverAsset::class, 'cover_asset_id');
    }

    /** 渲染完成（done）且待质检。 */
    public function isRendered(): bool
    {
        return $this->status === 'done';
    }

    /** 质检通过/告警，可进入人工审核。 */
    public function canReview(): bool
    {
        return in_array($this->qc_status, ['passed', 'warned'], true);
    }

    /** 审核通过，可外发。 */
    public function canPublish(): bool
    {
        return $this->publish_status === 'approved';
    }

    /** 待人工审核（含已驳回可重审）。 */
    public function isPendingReview(): bool
    {
        return in_array($this->publish_status, ['draft', 'reviewing', 'rejected'], true);
    }

    public function isApproved(): bool
    {
        return $this->publish_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->publish_status === 'rejected';
    }

    /**
     * 按 8500 返回的状态 JSON 推进本地记录（纯函数，不发起网络请求）。
     * 本地已到终态时不回退；远端为 done/failed 时更新本地，避免重复写入。
     */
    public function applyPipelineStatus(array $json): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        $remote = $json['status'] ?? null;
        if (! in_array($remote, ['done', 'failed'], true)) {
            return false;
        }
        $this->update(['status' => $remote]);
        // 渲染完成即进入「待人工审核」初始态（draft），与状态端点逻辑一致
        if ($remote === 'done' && is_null($this->publish_status)) {
            $this->update(['publish_status' => 'draft']);
        }
        return true;
    }

    /**
     * 主动查询 8500 真实状态并回写本地（服务端兜底同步用）。
     * 8500 不可达 / 超时 / 非终态时返回 false，保持 queued 不变，不抛异常。
     */
    public function syncFromPipeline(): bool
    {
        if ($this->status !== 'queued' || empty($this->job_id)) {
            return false;
        }
        try {
            $resp = app(PipelineClient::class)->get('/status/' . $this->job_id, 15);
        } catch (PipelineUnavailableException $e) {
            return false;
        }
        if (! $resp->successful()) {
            return false;
        }
        return $this->applyPipelineStatus($resp->json());
    }

    /** 是否已到达终态（不再参与并发闸计数）。 */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /**
     * 客户端心跳：更新 heartbeat_at，但刻意不改动 updated_at（避免干扰列表排序与缓存）。
     * 通过查询构造器直接更新，绕过模型的 timestamps 自动维护。
     */
    public function touchHeartbeat(): void
    {
        if (! $this->exists) {
            return;
        }
        static::whereKey($this->getKey())->update(['heartbeat_at' => now()]);
        $this->heartbeat_at = now();
    }

    /**
     * 计算幂等去重键：同一租户 + 同一模式 + 同一文案 + 同一标题 视为一次提交。
     * 用于拦截「关页面前重复点击」造成的重复出片任务。
     */
    public static function computeDedupeKey(int $tenantId, string $mode, string $dialogue, ?string $title): string
    {
        return md5($tenantId . '|' . $mode . '|' . $dialogue . '|' . (string) $title);
    }

    /**
     * 查找 60 秒内同键的活跃（queued）重复任务。
     * 命中说明是短时间内重复提交，可直接复用已有任务，避免重复占用并发槽位。
     */
    public static function findDuplicate(int $tenantId, string $key): ?self
    {
        return static::where('tenant_id', $tenantId)
            ->where('dedupe_key', $key)
            ->where('status', 'queued')
            ->where('created_at', '>=', now()->subSeconds(60))
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * 回收孤儿任务：长时间无心跳且仍停留在 queued（客户端断开 / 提交后未获任务标识 / 8500 侧丢失）。
     * 标记 failed 并释放并发槽位，附系统回收原因。返回是否真正执行了回收。
     */
    public function releaseIfStale(int $staleSec): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        // 有效最后活跃时间 = 心跳时间（优先）；从未心跳则退化为创建时间
        $last = $this->heartbeat_at ?? $this->created_at;
        if ($last && $last->copy()->addSeconds($staleSec)->isFuture()) {
            return false; // 仍在有效期内，不回收
        }
        $reason = $this->job_id
            ? '任务超时未推进，已自动回收'
            : '提交后未获得任务标识，已自动回收';
        $this->update([
            'status' => 'failed',
            'review_note' => ($this->review_note ? $this->review_note . '；' : '') . '[系统]' . $reason,
        ]);
        return true;
    }
}
