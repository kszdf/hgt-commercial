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
        'heartbeat_at', 'dedupe_key', 'dialogue', 'render_config', 'is_hit',
        'last_pipeline_step', 'step_changed_at', 'last_progress', 'progress_changed_at',
        'failed_reason', 'failed_at', 'pipeline_error',
    ];

    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
            'step_changed_at' => 'datetime',
            'progress_changed_at' => 'datetime',
            'failed_at' => 'datetime',
            'render_config' => 'array',
            'is_hit' => 'boolean',
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
        // 成片时长（秒）：8500 /status 返回的 duration 字段（浮点）。用于试用累计总时长计量。
        $duration = null;
        if (isset($json['duration']) && is_numeric($json['duration'])) {
            $duration = (float) $json['duration'];
        }
        $update = ['status' => $remote];
        if ($duration !== null) {
            $update['duration_sec'] = $duration;
        }
        // 失败：结构化记录原因与原始错误，便于前端明确提示 + 管理员溯源
        if ($remote === 'failed') {
            $err = $json['error'] ?? null;
            $update['failed_reason'] = self::classifyError($err, $json['step'] ?? null);
            $update['failed_at'] = now();
            if ($err !== null) {
                $update['pipeline_error'] = is_string($err) ? $err : json_encode($err, JSON_UNESCAPED_UNICODE);
            }
        }
        $this->update($update);
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

    /**
     * 记录 8500 真实阶段推进（看门狗每次轮询调用）。
     * 仅当阶段发生变化时更新 step_changed_at，作为「最近一次真实进展」的基线：
     *  - 首次观测（last_pipeline_step 为空）：以创建时间为基线，使老任务/历史卡死任务
     *    立即纳入检测（避免新字段上线前创建的任务永远查不到进度）。
     *  - 阶段变化：刷新为当前时间。
     * 通过查询构造器直接更新，绕过 updated_at 自动维护（避免干扰列表排序与缓存）。
     */
    public function recordStep(?string $step): void
    {
        if (! $step || $this->last_pipeline_step === $step) {
            return;
        }
        $baseline = $this->last_pipeline_step === null
            ? ($this->created_at ?? now())   // 首次观测：进度基线 = 创建时间（历史任务同样适用）
            : now();
        static::whereKey($this->getKey())->update([
            'last_pipeline_step' => $step,
            'step_changed_at'    => $baseline,
        ]);
        $this->last_pipeline_step = $step;
        $this->step_changed_at = $baseline;
    }

    /**
     * 结构化标记任务失败（看门狗 / 控制器共用）。
     * 仅当任务尚未到达终态时生效；失败幂等（重复调用不重复写）。
     * 失败原因分类写入 failed_reason，原始错误写入 pipeline_error。
     */
    public function markFailed(string $reason, ?string $error = null, ?string $detail = null): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        $note = $detail ?: self::failedReasonLabel($reason);
        $update = [
            'status'        => 'failed',
            'failed_reason' => $reason,
            'failed_at'     => now(),
        ];
        if ($error !== null) {
            $update['pipeline_error'] = $error;
        }
        $update['review_note'] = ($this->review_note ? $this->review_note . '；' : '') . '[系统]' . $note;
        $this->update($update);
        return true;
    }

    /** 失败原因中文标签（前端展示 / 日志 / 列表溯源）。 */
    public static function failedReasonLabel(string $reason): string
    {
        return match ($reason) {
            'timeout'             => '出片超时（长时间无进展）',
            'service_unavailable' => '出片服务异常（持续不可达）',
            'resource'            => '系统资源不足（磁盘/显存/内存）',
            'format'              => '素材或格式问题',
            'job_lost'            => '出片任务丢失（服务侧已无记录）',
            default               => '出片失败（原因未知）',
        };
    }

    /**
     * 从 8500 返回的错误文本推断失败原因分类。
     * 超时/硬上限 → timeout；资源类（磁盘/显存/内存）→ resource；格式/编码/分辨率 → format；其余 → unknown。
     */
    public static function classifyError(?string $error, ?string $step): string
    {
        if ($error) {
            $e = mb_strtolower($error);
            if (preg_match('/(timeout|timed out|超时|硬上限|超过.*秒|时间限制|time limit)/u', $e)) {
                return 'timeout';
            }
            if (preg_match('/(no space|disk|磁盘|空间不足|vram|cuda|out of memory|memoryerror|内存|显存)/u', $e)) {
                return 'resource';
            }
            if (preg_match('/(format|codec|resolution|unsupported|invalid|编码|格式|分辨率|不支持)/u', $e)) {
                return 'format';
            }
        }
        return 'unknown';
    }

    /** 距最近一次阶段推进的秒数（无记录则用创建时间）。 */
    public function secondsSinceProgress(): int
    {
        $base = $this->step_changed_at ?? $this->created_at;
        if (! $base) {
            return 0;
        }
        return max(0, (int) now()->diffInSeconds($base));
    }
}
