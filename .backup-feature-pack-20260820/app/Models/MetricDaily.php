<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricDaily extends Model
{
    protected $table = 'metrics_daily';

    protected $fillable = [
        'tenant_id', 'video_job_id', 'platform', 'metric_date',
        'views', 'shares', 'comments', 'likes',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'views' => 'integer',
        'shares' => 'integer',
        'comments' => 'integer',
        'likes' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function videoJob(): BelongsTo
    {
        return $this->belongsTo(VideoJob::class);
    }
}
