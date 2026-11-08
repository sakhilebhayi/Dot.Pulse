<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $digest['subject'] }}</title>
</head>
<body style="margin:0;padding:0;background:#EEF0F5;color:#0B0E1A;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF0F5;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
<tr><td style="background:#1F3CFF;color:#FFFFFF;padding:20px 24px;font-size:20px;font-weight:700;letter-spacing:-0.02em;">Dot.Pulse</td></tr>
<tr><td style="background:#FFFFFF;padding:24px;">
<p style="margin:0 0 4px 0;font-size:20px;font-weight:700;letter-spacing:-0.02em;">{{ $digest['subject'] }}</p>
<p style="margin:0 0 20px 0;color:#4B5266;">{{ $digest['intro'] }}</p>
@foreach ($digest['items'] as $item)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px 0;border-top:2px solid #0B0E1A;">
<tr><td style="padding:12px 0 0 0;">
<p style="margin:0;font-family:Menlo,Consolas,monospace;font-size:12px;color:#4B5266;text-transform:uppercase;">{{ $item['kind_label'] }}@if ($item['place']) &middot; {{ $item['place'] }}@endif</p>
<p style="margin:4px 0 4px 0;font-size:17px;font-weight:700;"><a href="{{ $item['url'] }}" style="color:#1F3CFF;text-decoration:none;">{{ $item['title'] }}</a></p>
@if ($item['from'])<p style="margin:0 0 4px 0;color:#4B5266;">From {{ $item['from'] }}</p>@endif
@if ($item['excerpt'])<p style="margin:0 0 8px 0;">{{ \Illuminate\Support\Str::limit($item['excerpt'], 160) }}</p>@endif
<p style="margin:0;padding:6px 10px;background:#FFF6BF;border-left:4px solid #FFD400;color:#0B0E1A;"><strong>Why you are seeing this:</strong> {{ $item['reason'] }}</p>
</td></tr>
</table>
@endforeach
@if ($digest['more'] > 0)
<p style="margin:0 0 16px 0;color:#4B5266;">{{ $digest['more'] }} more {{ $digest['more'] === 1 ? 'match is' : 'matches are' }} waiting on the Floor.</p>
@endif
<p style="margin:20px 0 0 0;"><a href="{{ $digest['floor_url'] }}" style="display:inline-block;background:#1F3CFF;color:#FFFFFF;text-decoration:none;font-weight:700;padding:10px 18px;">Open the Floor</a></p>
</td></tr>
<tr><td style="padding:16px 24px;color:#4B5266;font-size:13px;">
You get this because you asked for a digest. It is only sent when there is something real in it.<br>
<a href="{{ $digest['manage_url'] }}" style="color:#1F3CFF;">Change how often</a> &middot; <a href="{{ $digest['unsubscribe_url'] }}" style="color:#1F3CFF;">Stop the digest</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
