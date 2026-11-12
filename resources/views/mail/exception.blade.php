<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title>{{ $mail['heading'] }} · {{ $mail['event']['application'] }}</title>
<style>
@media only screen and (max-width:600px){.email-outer{padding:16px 8px!important}.email-padding{padding:24px 18px!important}.email-brand{font-size:16px!important}.email-action{font-size:13px!important;padding:10px 12px!important}.email-title{font-size:24px!important}.email-metadata th{width:110px!important}.email-stack{font-size:12px!important}}
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;color:#111827;font-family:Figtree,'Segoe UI',Arial,sans-serif;line-height:1.5;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $mail['heading'] }} in {{ $mail['event']['application'] }} · {{ $mail['event']['environment'] }} · {{ $mail['event']['message'] }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;"><tr><td class="email-outer" align="center" style="padding:32px 16px;">
<!--[if mso]><table role="presentation" width="640"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;text-align:left;">
<tr><td class="email-padding" style="padding:24px 32px;border-bottom:1px solid #e5e7eb;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td class="email-brand" style="font-size:19px;font-weight:600;">Telescope <span style="color:#4040c8;">Monitor</span></td><td align="right">@if($mail['url'])<a class="email-action" href="{{ $mail['url'] }}" style="display:inline-block;background:#4040c8;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:4px;padding:10px 16px;">View {{ $mail['event']['test'] ? 'setup' : 'error' }}</a>@endif</td></tr></table>
</td></tr>
<tr><td class="email-padding" style="padding:30px 32px;">
@if($mail['event']['test'])
<div style="padding:12px 14px;margin-bottom:24px;background:#efedff;border:1px solid #d6d0ff;border-radius:4px;color:#3333b5;font-size:14px;">Synthetic delivery test. This email does not represent a real incident or enable an alert rule.</div>
@endif
<p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#4040c8;word-break:break-all;">{{ $mail['reference'] }}</p>
<h1 class="email-title" style="font-size:28px;line-height:1.25;letter-spacing:-.025em;margin:0 0 10px;font-weight:600;">{{ $mail['heading'] }}</h1>
<p style="margin:0 0 24px;font-size:15px;color:#626b7a;">{{ $mail['event']['application'] }} · {{ $mail['event']['environment'] }}</p>
<h2 style="font-size:18px;line-height:1.5;margin:0 0 8px;word-break:break-all;font-weight:600;">{{ $mail['event']['class'] }}</h2>
<p style="font-size:16px;margin:0 0 24px;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;">{{ $mail['event']['message'] }}</p>
<table class="email-metadata" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
<tr><th scope="row" style="width:140px;text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">Application</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;word-break:break-word;">{{ $mail['event']['application'] }}</td></tr>
<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">Environment</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ $mail['event']['environment'] }}</td></tr>
<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">Occurred</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ $mail['occurred'] }}</td></tr>
<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">Count at alert</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ $mail['event']['count'] }} captured {{ (string)$mail['event']['count'] === '1' ? 'occurrence' : 'occurrences' }}</td></tr>
@if($mail['context']['status']??null)<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">State at preparation</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ ucfirst($mail['context']['status']) }}</td></tr>@endif
@if($mail['first_seen'])<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">First seen</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ $mail['first_seen'] }}</td></tr>@endif
@if($mail['last_seen'])<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">Last seen</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;">{{ $mail['last_seen'] }}</td></tr>@endif
@if($mail['context']['release']??null)<tr><th scope="row" style="text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;">Release</th><td style="padding:10px 0;border-top:1px solid #e5e7eb;word-break:break-all;">{{ $mail['context']['release'] }}</td></tr>@endif
</table>
@if($mail['event']['file'])
<h2 style="font-size:18px;margin:28px 0 12px;font-weight:600;">Exception location</h2>
<div style="background:#f7f8fa;border:1px solid #e5e7eb;border-radius:4px;padding:14px;font:13px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all;">{{ $mail['event']['file'] }}:{{ $mail['event']['line'] }}</div>
@endif
@if($mail['context']['execution']??[])
<h2 style="font-size:18px;margin:28px 0 12px;font-weight:600;">Retained execution context</h2>
<table class="email-metadata" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
@foreach($mail['context']['execution'] as $key=>$value)<tr><th scope="row" style="width:140px;text-align:left;font-weight:400;color:#626b7a;padding:10px 0;border-top:1px solid #e5e7eb;vertical-align:top;">{{ ['method'=>'Method','path_template'=>'Route','route_name'=>'Route name','job_class'=>'Job','command'=>'Command'][$key]??$key }}</th><td style="padding:10px 0 10px 12px;border-top:1px solid #e5e7eb;word-break:break-all;">{{ $value }}</td></tr>@endforeach
</table>
@endif
@if($mail['context']['frames']??[])
<h2 style="font-size:18px;margin:28px 0 12px;font-weight:600;">Application stack</h2>
<div style="background:#f7f8fa;border:1px solid #e5e7eb;border-radius:4px;padding:4px 14px;">
@foreach($mail['context']['frames'] as $frame)<div class="email-stack" style="padding:12px 0;font:13px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all;@if(!$loop->last)border-bottom:1px solid #e5e7eb;@endif"><strong style="font-weight:600;color:#374151;">{{ $frame['class'] }}{{ $frame['function'] ? '::'.$frame['function'].'()' : '' }}</strong><br><span style="color:#626b7a;">{{ $frame['file'] }}:{{ $frame['line'] }}</span></div>@endforeach
</div>
@if($mail['context']['omitted_frames']??0)<p style="font-size:13px;color:#626b7a;margin:10px 0 0;">{{ $mail['context']['omitted_frames'] }} additional retained frames omitted from this email. Open the monitor for the complete retained evidence.</p>@endif
@elseif(!$mail['event']['test'])
<p style="font-size:13px;color:#626b7a;margin:20px 0 0;">Detailed stack evidence is unavailable, expired or excluded by the current email/privacy policy.</p>
@endif
@if($mail['context']['tags']??[])
<h2 style="font-size:18px;margin:28px 0 12px;font-weight:600;">Tags</h2>
@foreach($mail['context']['tags'] as $key=>$value)<span style="display:inline-block;margin:0 6px 8px 0;padding:5px 9px;border:1px solid #e5e7eb;border-radius:4px;background:#f7f8fa;color:#374151;font-size:13px;word-break:break-all;">{{ $key }}: {{ $value }}</span>@endforeach
@endif
@if($mail['url'])<p style="margin:28px 0 0;"><a href="{{ $mail['url'] }}" style="color:#4040c8;text-decoration:underline;font-size:15px;font-weight:600;">Open full investigation →</a></p>@endif
</td></tr>
<tr><td class="email-padding" style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f7f8fa;border-radius:0 0 6px 6px;font-size:12px;color:#626b7a;">
<p style="margin:0 0 8px;">Sent by Telescope Monitor using your application's configured mailer.</p>
@if($mail['context']['occurrence_id']??null)<p style="margin:0 0 4px;word-break:break-all;">Occurrence: {{ $mail['context']['occurrence_id'] }}</p>@endif
<p style="margin:0;word-break:break-all;">Delivery: {{ $mail['event']['delivery_id']??'Unavailable' }}</p>
<p style="margin:8px 0 0;">Retries may duplicate a message after uncertain provider acceptance.</p>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
