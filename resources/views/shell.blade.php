<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAAIKADAAQAAAABAAAAIAAAAACshmLzAAADQ0lEQVRYCbVXXXLaMBCWaGfIW+kNdIPCS9v0BeUEhRMEThByAuAEDSconCDkBJiX/jxBTxDfoDwm04mVb72WbbBkTIGdEZJ3V7ufdlc/SFGRtF41hHjqCCHbaE0hjMJU8GLa4HeNFoK/FOJiHgQt4u0luU9D659KiDc3QkQ96FqH+6aRfAow4yC4DOnDR14AvOJ/Qzge+CZX48tREHwa+3SdAHjVcoFJyjfxQH6IaFy5olEAcAbnFqsTRM1KqT/SORXdFC1Ec5FC8S7YRyZOAXDOjwo7Cu5zH05uM/OFUQKCdhRTCkAIKrhjcm4oAqAo6fnL8auEeCZfMcUAOCy+apdrq1zeWwDeFOSnD2wqkgjIFFFeC5XbxxZqgTfZ5he+wiD4MicuV7qZFTQKDDkgVo1zL3r0UaR0Ve92ZAizGRNAbnUCmRJA9FALXZZFtxCEqTAbXJNviVD0oPw94++OKAWmuc2NHrDizjbP/+X3Yfpv4bztn0qSXefEq7VhVNMIhPAXj1uO7BOA1xrQwVFuYuXtH9lGDdDFcjDBKG3ZuD1q/etb3oLWv4eo9L8sN/fuRcQzmgAQ32r5+f8zpqrWNJFXbkY0rkCKdgGF6BSk2AiFvTI1km1YacIGWmElzQOUUISCDFeJArZdfcq5dXmQQ61/wE7t2l1wrjligwjI0CkqMCNUe+krR8E5itG1awrGLCMEgOiP/SrpAwBds5wOoJPRes9BREfx5dTljqv9+RGyKulzmQDP9AGArkavoTmUHvAmXAfBxzgCyQHTAR/zKOQF2oAzR9NoCq2E6u9rSV5nHi04omM6Wtl9DrAr5jmdw4xp8bsgvh8IjI+m5Bs1QGTuuC/9VYnU9h7li9gpL6yswLmWpLWC45RA3NhvRw+5XALsvUOWZ82hM0Havpa8qCeI0oAm5QDEtYDwCkWCM1KI8wRp4i2dpIAeEsQwV3AMhbMRbNPzPDtPUgDkkq/Vly6GUDw5weZLd/fqTlOQd4eKV8jOAjz0J6GQV158N2xFwLpilPE2QjEdTbBBOS86J8vOCORdcjTECKq4ZCoT6knMsOo7n2NraS8Aq5idgELjBPwA4wqyRiKHQ9rzZo22POTv+Suv7yMtDTWEQwAAAABJRU5ErkJggg==">
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
