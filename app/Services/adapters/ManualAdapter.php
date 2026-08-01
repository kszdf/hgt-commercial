<?php

namespace App\Services\adapters;

use App\Services\PlatformAdapter;
use App\Models\PlatformAccount;
use App\Models\VideoJob;

/**
 * 手动录入适配器。
 *
 * 适用于：平台尚未授权、或需人工补录的历史数据。
 * isReady 始终为 true（手动录入无需 OAuth）；fetchDaily 返回空（无自动拉取能力）。
 */
class ManualAdapter extends PlatformAdapter
{
    public function __construct(private string $platform = 'manual') {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function isReady(PlatformAccount $account): bool
    {
        // 手动录入不依赖授权
        return true;
    }

    public function fetchDaily(PlatformAccount $account, VideoJob $job, string $start, string $end): array
    {
        // 手动适配器无自动拉取能力，交由人工在表单录入
        return [];
    }
}
