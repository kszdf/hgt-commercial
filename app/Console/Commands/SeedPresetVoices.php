<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantVoice;
use App\Models\User;
use Illuminate\Console\Command;

class SeedPresetVoices extends Command
{
    protected $signature = 'voices:seed-presets {--tenant= : 指定租户 id，缺省补全部}';
    protected $description = '给租户补平台预置标准音色（男/女各一，幂等）';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $query = Tenant::query();
        if ($tenantId) {
            $query->where('id', (int) $tenantId);
        }

        $count = 0;
        foreach ($query->get() as $tenant) {
            $owner = $tenant->users()->orderBy('id')->first();
            $before = TenantVoice::where('tenant_id', $tenant->id)->count();
            TenantVoice::ensurePresetVoices($tenant->id, $owner?->id);
            $after = TenantVoice::where('tenant_id', $tenant->id)->count();
            if ($after > $before) {
                $count++;
                $this->info("租户 #{$tenant->id} 已补预置音色（{$before} → {$after}）");
            }
        }

        $this->info("完成，共为 {$count} 个租户补了预置音色");
        return self::SUCCESS;
    }
}
