<?php

namespace TelescopeMonitor\Integrations;

final class CurlTransport implements HttpTransport
{
    public function request(string $method, array $destination, array $headers, string $body): HttpResult
    {
        if (! function_exists('curl_init')) {
            return new HttpResult;
        }
        $handle = curl_init();
        $resultBody = '';
        $responseHeaders = [];
        $raw = [];
        foreach ($headers as $key => $value) {
            $raw[] = $key.': '.$value;
        }
        $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
        try {
            curl_setopt_array($handle, [CURLOPT_URL => $destination['url'], CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $raw, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,  CURLOPT_PROXY => '', CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_RESOLVE => [$destination['host'].':'.$destination['port'].':'.$ip], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && in_array(strtolower($parts[0]), ['retry-after', 'x-ratelimit-reset', 'x-ratelimit-remaining'], true)) {
                    $responseHeaders[strtolower($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => function ($h, $chunk) use (&$resultBody) {
                if (strlen($resultBody) + strlen($chunk) > 65536) {
                    return 0;
                }$resultBody .= $chunk;

                return strlen($chunk);
            }]);
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

            return $ok === false ? new HttpResult : new HttpResult($status, $resultBody, $responseHeaders);
        } catch (\Throwable) {
            return new HttpResult;
        } finally {
            curl_close($handle);
        }
    }
}
