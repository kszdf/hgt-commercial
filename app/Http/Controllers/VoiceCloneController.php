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
        $tenant = $this->studioTenant(request());
        $voices = TenantVoice::where('tenant_id', $tenant->id)
            ->orderBy('gender')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
        $maleCount = $voices->where('gender', 'male')->where('status', 'ready')->count();
        $femaleCount = $voices->where('gender', 'female')->where('status', 'ready')->count();

        // 官方音色库（按性别分组）+ 该租户已添加的 voice_id 集合（前端标记"已添加"）
        $official = TenantVoice::OFFICIAL_VOICES;
        $addedIds = TenantVoice::officialAddedVoiceIds($tenant->id);
        $officialData = [];
        foreach ($official as $gender => $list) {
            $officialData[$gender] = collect($list)->map(function ($v) use ($addedIds) {
                return [
                    'voice_id' => $v['voice_id'],
                    'name' => $v['name'],
                    'desc' => $v['desc'],
                    'added' => in_array($v['voice_id'], $addedIds, true),
                ];
            })->values();
        }

        return view('studio.voices', compact('voices', 'maleCount', 'femaleCount', 'officialData'));
    }

    /** 添加官方音色到租户声音库（自助添加，可删除）。 */
    public function addOfficial(Request $request)
    {
        $tenant = $this->studioTenant(request());
        $voiceId = trim((string) $request->input('voice_id'));

        $voice = TenantVoice::addOfficialVoice($tenant->id, $request->user()?->id, $voiceId);
        if (! $voice) {
            return redirect()->route('studio.voices')->with('error', '未找到该官方音色。');
        }
        $name = $voice->name;
        return redirect()->route('studio.voices')->with('success', '已添加官方音色「' . $name . '」到声音库。');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file'   => ['required', 'file', 'mimes:wav,mp3,m4a,aac,flac,ogg', 'max:30720'], // ≤30MB
            'name'   => ['nullable', 'string', 'max:60'],
            'gender' => ['required', 'in:male,female'],
        ]);

        $user = $request->user();
        $tenant = $this->studioTenant(request());
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

        // 防御：老租户可能无预置音色，克隆前先补上（保证每个性别始终有官方音色兜底）
        TenantVoice::ensurePresetVoices($tenant->id, $user->id);

        // 克隆音不自动设默认：预置音色已保证每个性别有声，克隆音仅加入备选
        $isDefault = TenantVoice::where('tenant_id', $tenant->id)
            ->where('gender', $data['gender'])
            ->where('status', 'ready')
            ->where('is_default', true)
            ->doesntExist();

        TenantVoice::create([
            'tenant_id'  => $tenant->id,
            'user_id'    => $user->id,
            'name'       => $data['name'] ?: $file->getClientOriginalName(),
            'gender'     => $data['gender'],
            'voice_id'   => $r['voice_id'],
            'model'      => $r['model'] ?? 'cosyvoice-v3-plus',
            'status'     => 'ready',
            'is_default' => $isDefault,   // 仅当该性别无任何默认时才自动设为默认
        ]);

        return redirect()->route('studio.voices')->with('success', '声音克隆成功，已加入你的声音库。');
    }

    public function setDefault(TenantVoice $voice)
    {
        $tenant = $this->studioTenant(request());
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
        $tenant = $this->studioTenant(request());
        if ($voice->tenant_id !== $tenant->id) {
            abort(403);
        }
        // 平台预置音色不可删除（保证租户始终有声可用）；仅可设默认/取消默认
        if ($voice->is_preset) {
            return redirect()->route('studio.voices')->with('error', '平台预置音色不可删除，你可以克隆自己的声音后切换默认。');
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
