<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="data:,">
    <title>Telescope Monitor</title>
    @foreach($entry['css'] ?? [] as $stylesheet)
    <link rel="stylesheet" href="{{ $base }}/assets/{{ $build }}/{{ $stylesheet }}">
    @endforeach
    <script type="module" src="{{ $base }}/assets/{{ $build }}/{{ $entry['file'] }}" @if($nonce) nonce="{{ $nonce }}" @endif></script>
</head>
<body>
    <div id="exception-monitor-app" data-base="{{ $base }}" data-api="{{ $base }}/api/v1"><p role="status">Loading Telescope Monitor…</p></div>
    <noscript>Enable JavaScript to use this dashboard.</noscript>
</body>
</html>
