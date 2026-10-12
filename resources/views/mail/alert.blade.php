<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
    <tr><td style="padding:20px 24px;border-bottom:3px solid #f59e0b;">
        <div style="font-size:13px;font-weight:600;letter-spacing:.04em;color:#b45309;">JOBPILOT AI</div>
        <div style="font-size:20px;font-weight:700;margin-top:4px;">{{ $heading }}</div>
    </td></tr>
    <tr><td style="padding:20px 24px;font-size:15px;line-height:1.55;">
        @foreach ($lines as $line)
            <p style="margin:0 0 12px;">{{ $line }}</p>
        @endforeach
        @if (! empty($items))
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
                @foreach ($items as $item)
                    <tr><td style="padding:10px 0;border-top:1px solid #e2e8f0;">
                        @if (! empty($item['url']))
                            <a href="{{ $item['url'] }}" style="color:#0f172a;font-weight:600;text-decoration:none;">{{ $item['title'] }}</a>
                        @else
                            <span style="font-weight:600;">{{ $item['title'] }}</span>
                        @endif
                        @if (! empty($item['meta']))<div style="font-size:13px;color:#64748b;margin-top:2px;">{{ $item['meta'] }}</div>@endif
                    </td></tr>
                @endforeach
            </table>
        @endif
        @if ($actionUrl)
            <p style="margin:20px 0 4px;">
                <a href="{{ $actionUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">{{ $actionLabel ?? 'Open in JobPilot' }}</a>
            </p>
        @endif
    </td></tr>
    <tr><td style="padding:14px 24px;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;">
        You are receiving this because e-mail alerts are enabled in your private JobPilot AI installation. Nothing was submitted on your behalf.
    </td></tr>
</table>
</body>
</html>
