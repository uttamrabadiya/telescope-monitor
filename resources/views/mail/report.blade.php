<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title>{{ $mail['heading'] }} · {{ $mail['event']['application'] }}</title>
<style>
@media only screen and (max-width:600px){.email-outer{padding:16px 8px!important}.email-padding{padding:24px 18px!important}.email-title{font-size:24px!important}.metric-value{font-size:26px!important}.metric-label{font-size:12px!important}.email-error{font-size:15px!important}}
</style>
</head>
@php($report = $mail['report'])
@php($event = $mail['event'])
<body style="margin:0;padding:0;background:#f3f4f6;color:#111827;font-family:Figtree,'Segoe UI',Arial,sans-serif;line-height:1.5;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ ucfirst($report['kind']) }} report · {{ $report['occurrences'] }} captured occurrences · {{ $report['new_groups'] }} new error groups</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;"><tr><td class="email-outer" align="center" style="padding:32px 16px;">
<!--[if mso]><table role="presentation" width="640"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;text-align:left;">
<tr><td class="email-padding" style="padding:24px 32px;border-bottom:1px solid #e5e7eb;font-size:19px;font-weight:600;">Telescope <span style="color:#4040c8;">Monitor</span></td></tr>
<tr><td class="email-padding" style="padding:30px 32px;">
@if($event['test'])<div style="padding:12px 14px;margin-bottom:24px;background:#efedff;border:1px solid #d6d0ff;border-radius:4px;color:#3333b5;font-size:14px;">Preview report using actual captured data. Automatic delivery settings are unchanged.</div>
@endif

<p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#4040c8;">{{ strtoupper($report['kind']) }} REPORT</p>
<h1 class="email-title" style="font-size:28px;line-height:1.25;letter-spacing:-.025em;margin:0 0 10px;font-weight:600;">{{ $mail['heading'] }}</h1>
<p style="margin:0 0 6px;font-size:15px;color:#626b7a;">{{ $event['application'] }} · {{ implode(', ', $report['environments']) }}</p>
<p style="margin:0 0 24px;font-size:14px;color:#626b7a;">{{ $report['period_label'] }} · {{ $report['timezone'] }}</p>
@if(!$report['complete'])<p style="padding:12px 14px;background:#fff8eb;color:#92400e;font-size:14px;">Partial coverage: monitoring history or retained analytics does not cover the full period. Counts reflect available data.</p>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;margin:0 0 28px;"><tr>
@foreach([['value'=>$report['occurrences'],'label'=>'Occurrences'],['value'=>$report['new_groups'],'label'=>'New error groups'],['value'=>$report['regressions'] ?? '—','label'=>'Regressions']] as $metric)
<td width="33%" valign="top" style="padding:20px 8px 20px 0;"><div class="metric-value" style="font-size:32px;font-weight:600;line-height:1.2;">{{ is_int($metric['value']) ? number_format($metric['value']) : $metric['value'] }}</div><div class="metric-label" style="font-size:14px;color:#626b7a;margin-top:6px;">{{ $metric['label'] }}</div></td>
@endforeach
</tr></table>
<p style="margin:0 0 26px;font-size:14px;color:#626b7a;">
@if(!$report['comparison_available'])Comparison unavailable: incomplete monitoring history.
@elseif($report['change_percent'] !== null)<span style="color:{{ $report['change_percent'] <= 0 ? '#22683a' : '#a51d32' }};font-weight:600;">{{ $report['change_percent'] > 0 ? '+' : '' }}{{ $report['change_percent'] }}%</span> occurrences vs previous {{ $report['kind'] === 'daily' ? 'day' : 'week' }} ({{ number_format($report['previous_occurrences']) }}).
@elseif($report['previous_occurrences'] === 0)Previous {{ $report['kind'] === 'daily' ? 'day' : 'week' }}: 0 captured occurrences. Percentage change unavailable.

@endif

</p>
<h2 style="font-size:19px;font-weight:600;margin:0 0 16px;">{{ $report['kind'] === 'daily' ? 'Captured occurrences' : 'The weekly trend' }}</h2>
@php($max = max(1, ...array_column($report['buckets'], 'count'), ...array_map(fn($b) => $b['previous'] ?? 0, $report['buckets'])))
@if(array_sum(array_column($report['buckets'], 'count')) + array_sum(array_column($report['buckets'], 'previous')) === 0)
<p style="margin:0 0 16px;font-size:14px;color:#626b7a;">No captured occurrences in the available trend data.</p>
@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr>
@foreach($report['buckets'] as $bucket)
@php($height = $bucket['count'] > 0 ? max(2, (int) round($bucket['count'] / $max * 90)) : 0)
<td valign="bottom" align="center" style="padding:0 2px;height:100px;vertical-align:bottom;" title="{{ $bucket['label'] }}: {{ $bucket['count'] }} captured occurrences">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr><td valign="bottom" style="height:100px;vertical-align:bottom;">@if($height)<div style="background:#4040c8;height:{{ $height }}px;font-size:0;line-height:0;">&nbsp;</div>
@endif
</td>
@if($bucket['previous'] !== null)<td valign="bottom" style="height:100px;vertical-align:bottom;padding-left:3px;">@if($bucket['previous'] > 0)<div style="background:#cfd4df;height:{{ max(2, (int) round($bucket['previous'] / $max * 90)) }}px;font-size:0;line-height:0;">&nbsp;</div>
@endif
</td>
@endif

</tr></table>
</td>
@endforeach
</tr><tr>
@foreach($report['buckets'] as $index=>$bucket)<td align="center" style="padding:6px 0;font-size:11px;color:#626b7a;">{{ $report['kind'] === 'weekly' ? $bucket['label'] : ($index % 6 === 0 ? substr($bucket['label'],0,5) : '') }}</td>@endforeach
</tr></table>
@if($report['kind'] === 'weekly' && $report['comparison_available'])<p style="font-size:12px;color:#626b7a;margin:6px 0;"><span style="color:#4040c8;">■</span> This week &nbsp; <span style="color:#626b7a;">■</span> Previous week</p>
@endif
@endif

<p style="margin:6px 0 24px;font-size:12px;color:#626b7a;">{{ $report['kind'] === 'daily' ? 'Hourly' : 'Daily' }} counts in {{ $report['timezone'] }}. Exact counts are included in the plain-text email.</p>
<div style="border-top:1px solid #e5e7eb;padding-top:22px;margin-bottom:24px;">
<h2 style="font-size:19px;font-weight:600;margin:0 0 12px;">Progress &amp; backlog</h2>
<p style="margin:0;font-size:15px;"><strong>{{ $report['resolved'] ?? 'Unavailable' }}</strong> groups resolved · <strong>{{ $report['unresolved'] }}</strong> unresolved now</p>
@if($report['kind'] === 'weekly')<p style="margin:8px 0 0;font-size:14px;color:#626b7a;">{{ $report['aging'] }} unresolved groups first seen over 7 days ago.</p>
@endif

@if(!$report['activity_complete'])<p style="margin:8px 0 0;font-size:13px;color:#92400e;">Resolution and regression totals unavailable: incomplete retained activity history.</p>
@endif

</div>
<h2 style="border-top:1px solid #e5e7eb;padding-top:22px;font-size:19px;font-weight:600;margin:0 0 8px;">{{ $report['kind'] === 'daily' ? 'Needs attention' : 'Prioritize next week' }}</h2>
@forelse($report['top'] as $row)
<div style="padding:16px 0;border-bottom:1px solid #e5e7eb;">
<h3 class="email-error" style="font-size:17px;font-weight:600;margin:0 0 6px;overflow-wrap:anywhere;word-break:break-word;">{{ $row['class'] }}</h3>
<p style="margin:0 0 6px;font-size:14px;color:#626b7a;">{{ $row['status'] }} · {{ $row['environment'] }} · {{ number_format($row['count']) }} occurrences{{ $row['unassigned'] ? ' · Unassigned' : '' }}</p>
@if($report['kind']==='weekly' && $report['occurrences'] > 0)<p style="margin:0 0 6px;font-size:13px;color:#626b7a;">{{ round($row['count'] / $report['occurrences'] * 100) }}% of captured weekly volume
@if($row['regressions']) · {{ $row['regressions'] }} regressions
@endif
</p>
@endif

@if($row['url'])<a href="{{ $row['url'] }}" style="font-size:14px;color:#4040c8;text-decoration:underline;">Investigate →</a>
@endif

</div>
@empty<p style="font-size:14px;color:#626b7a;">No currently unresolved errors with captured occurrences in this period.</p>
@endforelse
@if($report['url'])<p style="margin:28px 0 0;"><a href="{{ $report['url'] }}" style="display:inline-block;background:#4040c8;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;border-radius:4px;padding:12px 20px;">Open monitoring overview</a></p>
@endif

</td></tr>
<tr><td class="email-padding" style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f7f8fa;border-radius:0 0 6px 6px;font-size:12px;color:#626b7a;">
<p style="margin:0 0 8px;">Based on captured exceptions, not total application traffic. Backlog and assignment reflect preparation time.</p>
<p style="margin:0 0 8px;">{{ $report['schedule'] }}</p>
@if($report['preferences_url'])<a href="{{ $report['preferences_url'] }}" style="color:#4040c8;text-decoration:underline;">Manage report preferences</a>
@endif

<p style="margin:8px 0 0;">Sent through your application's mailer. Retries may duplicate a message after uncertain transport acceptance.</p>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
