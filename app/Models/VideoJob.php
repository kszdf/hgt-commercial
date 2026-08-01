<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoJob extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'job_id', 'mode', 'title', 'status',
        'qc_status', 'publish_status', 'review_note', 'cover_asset_id',
    ];

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
}
