<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>试用即将结束</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;padding:32px 24px;">
    <div style="background:#ffffff;border-radius:16px;padding:32px 28px;box-shadow:0 4px 20px rgba(15,23,42,.06);">
      <div style="font-size:20px;font-weight:700;color:#0f172a;">免费试用即将结束</div>
      <p style="font-size:15px;line-height:1.7;color:#475569;">
        您好，您的企业租户「{{ $tenant->name }}」的免费试用还剩
        <strong style="color:#d97706;">{{ $daysLeft }} 天</strong>。
        试用期结束后若未升级，将无法继续生成视频。
      </p>
      <p style="font-size:14px;line-height:1.7;color:#475569;">
        升级专业版 / 企业版即可解锁更高额度与不限量出片能力，支撑您的短视频矩阵持续产出。
      </p>
      <a href="{{ config('app.url') }}/admin/billing"
         style="display:inline-block;margin-top:8px;padding:12px 24px;background:#2563eb;color:#fff;border-radius:10px;text-decoration:none;font-size:15px;font-weight:600;">
        立即升级
      </a>
      <p style="font-size:12px;color:#94a3b8;margin-top:24px;">
        追梦 · 短视频智能生产平台 · 本邮件由系统自动发送，无需回复。
      </p>
    </div>
  </div>
</body>
</html>
