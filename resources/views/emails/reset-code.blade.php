<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>密码重置验证码</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#0f172a;">
    <div style="max-width:460px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;box-shadow:0 8px 24px rgba(15,23,42,.08);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <div style="width:36px;height:36px;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;font-size:18px;display:flex;align-items:center;justify-content:center;">追</div>
            <span style="font-size:18px;font-weight:700;color:#0f172a;">追梦 · 短视频智能生产平台</span>
        </div>
        <p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 16px;">您好，我们收到了您的密码重置请求。请使用以下 6 位验证码完成重置（5 分钟内有效）：</p>
        <div style="text-align:center;font-size:32px;font-weight:800;letter-spacing:8px;color:#4f46e5;background:#eef2ff;border-radius:12px;padding:18px 0;margin:0 0 16px;">{{ $code }}</div>
        <p style="font-size:13px;line-height:1.6;color:#94a3b8;margin:0;">若非本人操作，请忽略本邮件，您的密码不会变更。</p>
    </div>
</body>
</html>
