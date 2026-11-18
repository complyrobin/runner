<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AWS Signature Version 4: signs a request so AWS knows it comes from the runner's credentials. The secret never
 * leaves the runner; only the signature is sent.
 */
final class SignatureV4
{
    /**
     * Returns the headers to add: X-Amz-Date, X-Amz-Security-Token when there is one, and Authorization. Every
     * header passed in is signed, with Host taken from the address.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function headers(
        AwsCredentials $credentials,
        string $method,
        string $url,
        string $body,
        array $headers,
        string $region,
        string $service,
        DateTimeImmutable $now,
    ): array {
        $time = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $date = $time->format('Ymd');

        $added = ['X-Amz-Date' => $amzDate];

        if ($credentials->sessionToken !== null) {
            $added['X-Amz-Security-Token'] = $credentials->sessionToken;
        }

        $parts = parse_url($url);
        $signed = ['host' => (string) ($parts['host'] ?? '')];

        foreach ([...$headers, ...$added] as $name => $value) {
            $signed[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }

        ksort($signed, SORT_STRING);

        $canonicalHeaders = '';

        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }

        $signedHeaders = implode(';', array_keys($signed));
        $scope = "{$date}/{$region}/{$service}/aws4_request";

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath((string) ($parts['path'] ?? '/')),
            self::canonicalQuery((string) ($parts['query'] ?? '')),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $body),
        ]);

        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$credentials->secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $signature = hash_hmac('sha256', $stringToSign, $key);

        return [
            ...$added,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$credentials->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }
}
