<?php

namespace App\Http\Controllers;

use App\Models\VideoJob;
use Illuminate\Http\Request;

/**
 * 人工审核模块：出片渲染完成(status=done)后进入审核队列(publish_status=draft)，
 * 审核员逐条查看视频与机器质检结论，决定通过(approved)或驳回(rejected+理由)。
 * 审核通过是进入批量外发(模块4)的前置条件。
 *
 * 状态机（复用 video_jobs.publish_status）：
 *   draft(刚出片待审) → reviewing(审核中) → approved(通过·可外发) / rejected(驳回)
 * 与 qc_status（机器技术质检）解耦：qc 是技术闸门，review 是业务闸门。
 */
class ReviewController extends Controller
{
    /** 审核队列：列出待审/驳回视频（按最近更新）。 */
    public function index()
    {
        $tenant = request()->user()->tenant;
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->whereIn('publish_status', ['draft', 'reviewing', 'rejected'])
            ->orderByDesc('updated_at')
            ->with('qcReport')
            ->get();

        return view('studio.review', compact('jobs'));
    }

    /** 通过审核 → approved（qc 阻断的视频不允许通过）。 */
    public function approve(VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);

        if ($videoJob->qc_status === 'blocked') {
            return redirect()->back()->with('error', '该视频机器质检判定为「阻断」，不能审核通过，请先处理质检问题。');
        }

        $videoJob->update([
            'publish_status' => 'approved',
            'review_note' => null,
        ]);

        return redirect()->route('studio.review')->with('success', '审核通过，已进入可外发队列。');
    }

    /** 驳回审核 → rejected（必填理由）。 */
    public function reject(Request $request, VideoJob $videoJob)
    {
        $this->authorizeTenant($videoJob);

        $note = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $videoJob->update([
            'publish_status' => 'rejected',
            'review_note' => $note,
        ]);

        return redirect()->route('studio.review')->with('success', '已驳回，原因已记录。');
    }

    private function authorizeTenant(VideoJob $job): void
    {
        if ($job->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }
    }
}
