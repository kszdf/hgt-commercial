<?php

namespace App\Console\Commands;

use App\Models\CoverAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * 扫描 storage/app/covers/presets/manifest.json，把平台预设封面登记为 CoverAsset（is_preset=true）。
 * 幂等：按 file_path 存在则更新名称/分类，不存在则新建。
 */
class SeedPresetCovers extends Command
{
    protected $signature = 'covers:seed-presets';
    protected $description = '从 presets 目录的 manifest.json 注册平台预设封面';

    public function handle(): int
    {
        $manifestPath = Storage::disk('local')->path('covers/presets/manifest.json');
        if (! is_file($manifestPath)) {
            $this->error("未找到 manifest：$manifestPath");
            $this->line('请先运行 python-pipeline/generate_preset_covers.py 生成预设封面与清单。');
            return self::FAILURE;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest['categories'] ?? null)) {
            $this->error('manifest 格式不正确');
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        foreach ($manifest['categories'] as $cat) {
            $slug = $cat['slug'];
            $label = $cat['label'] ?? $slug;
            foreach ($cat['covers'] as $c) {
                $rel = ltrim($relPath = $c['file'], '/');
                $abs = Storage::disk('local')->path($rel);
                if (! is_file($abs)) {
                    $this->warn("跳过（文件缺失）：$rel");
                    continue;
                }
                $attrs = [
                    'tenant_id' => null,
                    'user_id' => null,
                    'name' => $c['name'] ?? basename($rel),
                    'scene' => null,
                    'category' => $slug,
                    'file_path' => $rel,
                    'preview_path' => $rel,
                    'width' => $c['width'] ?? null,
                    'height' => $c['height'] ?? null,
                    'size' => filesize($abs),
                    'status' => 'ready',
                    'is_preset' => true,
                ];
                $existing = CoverAsset::where('file_path', $rel)->first();
                if ($existing) {
                    $existing->update(array_intersect_key($attrs, array_flip(
                        ['name', 'category', 'width', 'height', 'size']
                    )));
                    $updated++;
                } else {
                    CoverAsset::create($attrs);
                    $created++;
                }
            }
            $this->info("分类 [$label] 已处理");
        }

        $this->info("完成：新建 $created 条，更新 $updated 条预设封面。");
        return self::SUCCESS;
    }
}
