<?php

// Development-only: render synthetic examples locally; no mail or HTTP transport is invoked.
require dirname(__DIR__).'/phpstan-bootstrap.php';

$directory = $argv[1] ?? sys_get_temp_dir().'/telescope-monitor-showcase';
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
$envelope = json_decode(file_get_contents(dirname(__DIR__).'/docs/examples/email-notification.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($directory.'/email.html', view('telescope-monitor::mail.exception', [
    'mail' => app(\TelescopeMonitor\Alerts\EmailPresentation::class)->data($envelope),
])->render());

// A deliberately small offline Block Kit illustration, not a Slack client renderer.
$payload = json_decode(file_get_contents(dirname(__DIR__).'/docs/slack-notification-example.json'), true, 512, JSON_THROW_ON_ERROR);
$escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$renderText = function (array $text) use ($escape): string {
    $value = $escape($text['text']);
    if ($text['type'] === 'mrkdwn') {
        $value = preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', $value);
        $value = preg_replace('/`([^`]+)`/', '<code>$1</code>', $value);
    }

    return nl2br($value);
};
$html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Slack alert · offline illustration</title><style>body{margin:0;background:#f3f4f6;color:#1d1c1d;font:16px/1.5 system-ui,sans-serif;padding:32px}main{max-width:680px;margin:auto;background:white;border:1px solid #ddd;border-radius:8px;padding:28px}h1{font-size:20px;margin:0 0 24px}section{margin:20px 0}.fields{display:grid;grid-template-columns:1fr 1fr;gap:18px}button{background:white;border:1px solid #888;border-radius:4px;padding:9px 15px;font:600 14px system-ui}code{background:#f5f5f5;color:#a52551;padding:2px 4px;font-size:14px}footer{color:#626b7a;font-size:12px;margin-top:24px}.label{max-width:738px;margin:0 auto 12px;color:#626b7a;font-size:13px}</style><p class="label">Slack notification · offline illustration from the example Block Kit payload</p><main>';
foreach ($payload['blocks'] as $block) {
    $html .= match ($block['type']) {
        'header' => '<h1>'.$renderText($block['text']).'</h1>',
        'section' => isset($block['fields']) ? '<section class="fields">'.implode('', array_map(fn ($field) => '<div>'.$renderText($field).'</div>', $block['fields'])).'</section>' : '<section>'.$renderText($block['text']).'</section>',
        'actions' => '<section><button>'.$escape($block['elements'][0]['text']['text']).'</button></section>',
        'context' => '<footer>'.$renderText($block['elements'][0]).'</footer>',
        default => '',
    };
}
file_put_contents($directory.'/slack.html', $html.'</main></html>');
echo "Rendered email.html and slack.html in {$directory}; no notifications sent.\n";
