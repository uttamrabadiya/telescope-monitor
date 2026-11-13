<?php

namespace TelescopeMonitor\Integrations;

final class CurlTransport implements HttpTransport
{
    public function request(string $method, array $destination, array $headers, string $body): HttpResult
    {
        if (! function_exists('curl_init')) {
            return new HttpResult(failure: 'curl_unavailable');
        }
        $handle = curl_init();
        $buffer = new ResponseBuffer(max(1024, min(1048576, (int) ($destination['max_response_bytes'] ?? 65536))));
        $responseHeaders = [];
        $raw = [];
        foreach ($headers as $key => $value) {
            $raw[] = $key.': '.$value;
        }
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $destination['url'],
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $raw,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$responseHeaders) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2 && in_array(strtolower($parts[0]), ['retry-after', 'x-ratelimit-reset', 'x-ratelimit-remaining'], true)) {
                        $responseHeaders[strtolower($parts[0])] = trim($parts[1]);
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => fn ($h, $chunk) => $buffer->append($chunk),
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
            } else {
                curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            if ($method === 'POST') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($ok === false) {
                $failure = $buffer->exceeded ? 'response_too_large' : match (curl_errno($handle)) {
                    CURLE_OPERATION_TIMEDOUT => 'timeout',
                    CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR => 'tls',
                    default => 'connection_failed',
                };

                return new HttpResult(failure: $failure);
            }

            return new HttpResult($status, $buffer->body, $responseHeaders);
        } catch (\Throwable) {
            return new HttpResult;
        } finally {
            curl_close($handle);
        }
    }
}
