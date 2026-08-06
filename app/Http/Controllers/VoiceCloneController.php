<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\TenantVoice;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 声音克隆：租户上传参考音频 → 经 8500 /clone_voice 调 CosyVoice 克隆 → 存 voice_id。
 * 支持多男声/多女声，可设默认。出片时从本租户声音库自由选择。
 */
class VoiceCloneController extends Controller
{
    private function pipelineUrl(): string
    {
        return env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
    }

    public function index()
    {
        $tenant = request()->user()->tenant;
        $voices = TenantVoice::where('tenant_id', $tenant->id)
            ->orderBy('gender')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
        $maleCount = $voices->where('gender', 'male')->where('status', 'ready')->count();
        $femaleCount = $voices->where('gender', 'female')->where('status', 'ready')->count();
        return view('studio.voices', compact('voices', 'maleCount', 'femaleCount'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file'   => ['required', 'file', 'mimes:wav,mp3,m4a,aac,flac,ogg', 'max:30720'], // ≤30MB
            'name'   => ['nullable', 'string', 'max:60'],
            'gender' => ['required', 'in:male,female'],
        ]);

        $user = $request->user();
        $tenant = $user->tenant;
        $file = $request->file('file');

        $b64 = base64_encode(file_get_contents($file->getRealPath()));

        try {
            $resp = app(PipelineClient::class)->post('/clone_voice', [
                'audio_b64' => $b64,
                'name'      => $data['name'] ?: $file->getClientOriginalName(),
                'gender'    => $data['gender'],
            ], 120);
        } catch (PipelineUnavailableException $e) {
            return redirect()->back()->with('error', '克隆服务暂时不可用，请稍后重试（' . $e->getMessage() . '）');
        }

        if (! $resp->successful()) {
            Log::error('VOICE_CLONE_FAIL', ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 500)]);
            return redirect()->back()->with('error', '克隆失败：' . ($resp->json('error') ?? '服务不可用'));
        }
        $r = $resp->json();
        if (empty($r['voice_id'])) {
            return redirect()->back()->with('error', '克隆未返回 voice_id');
        }

        $isFirst = TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', $data['gender'])
            ->where('status', 'ready')
            ->doesntExist();

        TenantVoice::create([
            'tenant_id'  => $tenant->id,
            'user_id'    => $user->id,
            'name'       => $data['name'] ?: $file->getClientOriginalName(),
            'gender'     => $data['gender'],
            'voice_id'   => $r['voice_id'],
            'model'      => $r['model'] ?? 'cosyvoice-v3-plus',
            'status'     => 'ready',
            'is_default' => $isFirst,   // 同性别首个自动设为默认
        ]);

        return redirect()->route('studio.voices')->with('success', '声音克隆成功，已加入你的声音库。');
    }

    public function setDefault(TenantVoice $voice)
    {
        $tenant = request()->user()->tenant;
        if ($voice->tenant_id !== $tenant->id) {
            abort(403);
        }
        // 同租户同性别其他音色取消默认
        TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', $voice->gender)
            ->where('id', '!=', $voice->id)
            ->update(['is_default' => false]);
        $voice->update(['is_default' => true]);
        return redirect()->route('studio.voices')->with('success', '已将「' . $voice->name . '」设为默认' . ($voice->gender === 'male' ? '男声' : '女声') . '。');
    }

    public function destroy(TenantVoice $voice)
    {
        $tenant = request()->user()->tenant;
        if ($voice->tenant_id !== $tenant->id) {
            abort(403);
        }
        $gender = $voice->gender;
        $wasDefault = $voice->is_default;
        $voice->delete();

        // 若删掉的是默认，且同性别还有余下音色，把最新的设为默认
        if ($wasDefault) {
            $next = TenantVoice::where('tenant_id', $tenant->id)
                ->where('gender', $gender)
                ->where('status', 'ready')
                ->orderByDesc('created_at')
                ->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }
        return redirect()->route('studio.voices')->with('success', '声音已删除。');
    }
}
