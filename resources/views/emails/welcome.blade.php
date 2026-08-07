<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>欢迎使用追梦</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;padding:32px 24px;">
    <div style="background:#ffffff;border-radius:16px;padding:32px 28px;box-shadow:0 4px 20px rgba(15,23,42,.06);">
      <div style="font-size:22px;font-weight:700;color:#0f172a;">欢迎加入追梦 👋</div>
      <p style="font-size:15px;line-height:1.7;color:#475569;">
        您好，您的企业租户「{{ $tenant->name }}」已成功开通，当前处于
        <strong style="color:#2563eb;">7 天免费试用</strong>期，可立即体验短视频智能生产全流程。
      </p>
      <ul style="font-size:14px;line-height:1.9;color:#475569;padding-left:20px;">
        <li>智能选题 / 二次改写（规避违禁词）</li>
        <li>数字人出镜 / 滚动字幕卡一键出片</li>
        <li>智能质检 + 多平台发布跟踪</li>
      </ul>
      <a href="{{ config('app.url') }}/dashboard"
         style="display:inline-block;margin-top:8px;padding:12px 24px;background:#2563eb;color:#fff;border-radius:10px;text-decoration:none;font-size:15px;font-weight:600;">
        进入工作台
      </a>
      <p style="font-size:12px;color:#94a3b8;margin-top:24px;">
        追梦 · 短视频智能生产平台 · 本邮件由系统自动发送，无需回复。
      </p>
    </div>
  </div>
</body>
</html>
