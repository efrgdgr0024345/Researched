<?php
declare(strict_types=1);

namespace Researched;

final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 20,
        private readonly string $userAgent = 'ResearchedIdeaWorkbench/0.1'
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        return $this->request(
            'POST',
            $url,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $headers
        );
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $headers = array_merge([
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ], $headers);

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $body, $headers);
        }

        return $this->requestWithStreams($method, $url, $body, $headers);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function requestWithCurl(string $method, string $url, ?string $body, array $headers): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialise HTTP client.');
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $this->parseHeaders($rawHeaders),
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function requestWithStreams(string $method, string $url, ?string $body, array $headers): array
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $lastError = error_get_last();
            throw new \RuntimeException('HTTP request failed: ' . ($lastError['message'] ?? 'unknown error'));
        }

        $rawHeaderLines = $http_response_header ?? [];
        $status = 0;
        foreach ($rawHeaderLines as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $this->parseHeaders(implode("\r\n", $rawHeaderLines)),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
        return $headers;
    }
}
