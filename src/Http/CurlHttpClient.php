<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Http;

use ComplyRobin\Runner\Exceptions\NetworkError;
use ComplyRobin\Runner\Runner;

/**
 * Sends requests with curl. HTTPS only (plain HTTP only to localhost, for local testing), no redirects followed,
 * 30 second timeout, answers capped at 2 MB.
 */
final class CurlHttpClient implements HttpClient
{
    private const int MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly int $timeoutSeconds = 30) {}

    public function send(Request $request): Response
    {
        $this->guardScheme($request->url);

        $headers = ['Accept: application/json', 'User-Agent: complyrobin-runner/'.Runner::VERSION];

        foreach ($request->headers as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        $handle = curl_init($request->url);
        $body = '';

        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        if ($request->json !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($request->json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        } elseif (in_array($request->method, ['POST', 'PATCH', 'PUT'], true)) {
            $options[CURLOPT_POSTFIELDS] = '';
        }

        curl_setopt_array($handle, $options);

        $succeeded = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($succeeded === false || $status === 0) {
            throw new NetworkError(parse_url($request->url, PHP_URL_HOST).' could not be reached: '.($error !== '' ? $error : 'no answer').'.');
        }

        return new Response($status, $body);
    }

    private function guardScheme(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            return;
        }

        throw new NetworkError("Refusing to send a token over a connection that isn't HTTPS ({$host}).");
    }
}
