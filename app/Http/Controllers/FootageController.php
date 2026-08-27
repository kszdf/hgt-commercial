<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 真人素材精剪：上传真人出镜视频 → 8500 自动去气口/停顿/重复句 → 字幕+封面 → 成品。
 * 素材存 storage/app/footage/{uuid}.mp4（宿主 D:\heygem_data\hgt-commercial\storage\...，
 * 8500 与宿主同机可直接读写该绝对路径）。
 */
class FootageController extends Controller
{
    private function footageDir(): string
    {
        return storage_path('app/footage');
    }

    private function hostPath(string $containerPath): string
    {
        // 容器 /var/www → 宿主 D:\heygem_data\hgt-commercial（docker compose 挂载）
        return str_replace('/var/www', rtrim(base_path(), '/'), $containerPath);
    }

    public function index(Request $request)
    {
        return view('studio.footage', [
            'result' => $request->session()->pull('footage_result'),
        ]);
    }

    public function edit(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,mov,m4v,avi,mkv,webm', 'max:512000'], // ≤500MB
            'language' => ['sometimes', 'string', 'in:zh,auto'],
        ]);

        $dir = $this->footageDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $uuid = Str::uuid()->toString();
        $containerPath = $dir . '/' . $uuid . '.mp4';
        $request->file('file')->move($dir, $uuid . '.mp4');

        try {
            $resp = app(PipelineClient::class)->post('/footage-edit', [
                'file_path' => $this->hostPath($containerPath),
                'language'  => $data['language'] ?? 'zh',
            ], 600);
        } catch (PipelineUnavailableException $e) {
            return back()->with('error', '精剪服务暂时不可用，请确认 8500 已重启加载最新代码（' . $e->getMessage() . '）');
        }

        if (! $resp->successful()) {
            return back()->with('error', '精剪服务返回异常：' . substr((string) $resp->body(), 0, 300));
        }
        $r = $resp->json();
        if (empty($r['ok'])) {
            return back()->with('error', '精剪失败：' . ($r['error'] ?? '未知错误'));
        }

        // 编辑产物文件名（供播放/下载路由安全映射）
        $outName = basename((string) $r['out_mp4']);
        $result = [
            'ok' => true,
            'uuid' => $uuid,
            'out_name' => $outName,
            'cover_name' => $r['cover'] ? basename((string) $r['cover']) : '',
            'duration_before' => $r['duration_before'] ?? null,
            'duration_after' => $r['duration_after'] ?? null,
            'segments_kept' => $r['segments_kept'] ?? null,
            'silences_removed' => $r['silences_removed'] ?? [],
            'dups_removed' => $r['dups_removed'] ?? [],
            'transcript' => $r['transcript'] ?? '',
        ];
        $request->session()->put('footage_result', $result);

        return redirect()->route('studio.footage')->with('success', '精剪完成！');
    }

    /** 播放/下载精剪产物（仅允许 footage 目录内文件）。 */
    public function play(string $file)
    {
        $dir = realpath($this->footageDir());
        $full = realpath($dir . '/' . basename($file));
        if (! $full || strpos($full, $dir) !== 0 || ! is_file($full)) {
            abort(404);
        }
        $mime = str_ends_with($full, '.mp4') ? 'video/mp4' : 'application/octet-stream';
        return response()->file($full, ['Content-Type' => $mime]);
    }
}
