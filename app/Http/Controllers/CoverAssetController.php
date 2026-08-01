<?php

namespace App\Http\Controllers;

use App\Models\CoverAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

/**
 * 封面素材管理：上传（格式/大小校验）、预览、删除、重新上传，租户隔离。
 * 图片走纯 Laravel 处理（容器内存储 + Response::file 直出），不依赖宿主 8500。
 */
class CoverAssetController extends Controller
{
    private function tenantId(): int
    {
        return Auth::user()->tenant_id;
    }

    public function index()
    {
        $assets = CoverAsset::where('tenant_id', $this->tenantId())->latest()->get();
        return view('studio.covers', compact('assets'));
    }

    public function coversJson()
    {
        $assets = CoverAsset::where('tenant_id', $this->tenantId())
            ->latest()
            ->get(['id', 'name', 'scene', 'file_path', 'width', 'height', 'status']);
        return response()->json([
            'ok' => true,
            'covers' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'scene' => $a->scene,
                'preview' => route('studio.covers.preview', $a),
                'width' => $a->width,
                'height' => $a->height,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'name' => ['nullable', 'string', 'max:60'],
            'scene' => ['nullable', 'string', 'max:40'],
        ]);

        $ext = strtolower($request->file('file')->getClientOriginalExtension() ?: 'jpg');
        $rel = 'covers/' . $tenant->id . '/cv_' . uniqid() . '.' . $ext;
        $request->file('file')->storeAs(dirname($rel), basename($rel), 'local');
        $abs = Storage::disk('local')->path($rel);

        [$w, $h] = @getimagesize($abs) ?: [null, null];

        $asset = CoverAsset::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => $request->input('name') ?: ($request->file('file')->getClientOriginalName() ?: '封面'),
            'scene' => $request->input('scene') ?: null,
            'file_path' => $rel,
            'preview_path' => $rel,
            'width' => $w,
            'height' => $h,
            'size' => filesize($abs),
            'status' => 'ready',
        ]);

        return redirect()->route('studio.covers')->with('success', '封面已上传：' . $asset->name);
    }

    public function preview(CoverAsset $coverAsset)
    {
        abort_if($coverAsset->tenant_id !== $this->tenantId(), 403);
        $path = Storage::disk('local')->path(ltrim($coverAsset->file_path, '/'));
        abort_if(! is_file($path), 404);
        return Response::file($path);
    }

    public function destroy(CoverAsset $coverAsset)
    {
        abort_if($coverAsset->tenant_id !== $this->tenantId(), 403);
        $path = Storage::disk('local')->path(ltrim($coverAsset->file_path, '/'));
        if (is_file($path)) {
            @unlink($path);
        }
        $coverAsset->delete();
        return redirect()->route('studio.covers')->with('success', '封面已删除');
    }

    public function reupload(Request $request, CoverAsset $coverAsset)
    {
        abort_if($coverAsset->tenant_id !== $this->tenantId(), 403);
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $ext = strtolower($request->file('file')->getClientOriginalExtension() ?: 'jpg');
        $rel = 'covers/' . $coverAsset->tenant_id . '/cv_' . uniqid() . '.' . $ext;
        $request->file('file')->storeAs(dirname($rel), basename($rel), 'local');
        $abs = Storage::disk('local')->path($rel);
        [$w, $h] = @getimagesize($abs) ?: [null, null];

        $coverAsset->update([
            'file_path' => $rel,
            'preview_path' => $rel,
            'width' => $w,
            'height' => $h,
            'size' => filesize($abs),
            'status' => 'ready',
        ]);

        return redirect()->route('studio.covers')->with('success', '封面已重新上传：' . $coverAsset->name);
    }
}
