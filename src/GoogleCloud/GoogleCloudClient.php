<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\GoogleCloud;

use Closure;
use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Exceptions\ConfigurationError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The Google Cloud IAM calls the runner needs: list, make, disable and delete a service account's keys.
 *
 * The runner's own access is either an access token (from google-github-actions/auth with token_format:
 * access_token, so no long-lived key is needed) or the key file of an admin service account, which the runner
 * trades for an access token once per run. That trade signs a JWT, so it needs PHP's openssl extension.
 */
final class GoogleCloudClient
{
    public const string BASE_URL = 'https://iam.googleapis.com/v1';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  string|null  $accessToken  a ready access token
     * @param  string|null  $credentials  an admin service account's key file (JSON), used when there is no access token
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly HttpClient $http,
        private ?string $accessToken,
        private readonly ?string $credentials = null,
        ?Closure $clock = null,
        private readonly string $baseUrl = self::BASE_URL,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * POST /projects/-/serviceAccounts/{email}/keys. Returns the key's resource name and its key file.
     *
     * @return array{name: string, key_file: string}
     */
    public function createKey(string $serviceAccountEmail): array
    {
        $json = $this->expect($this->send('POST', self::keysPath($serviceAccountEmail), [
            'privateKeyType' => 'TYPE_GOOGLE_CREDENTIALS_FILE',
            'keyAlgorithm' => 'KEY_ALG_RSA_2048',
        ]));

        $keyFile = is_string($json['privateKeyData'] ?? null) ? base64_decode($json['privateKeyData'], true) : false;

        if (! is_string($json['name'] ?? null) || ! is_string($keyFile) || $keyFile === '') {
            throw new ApiError('Google Cloud', 200, 'The answer had no new key in it.');
        }

        return ['name' => $json['name'], 'key_file' => $keyFile];
    }

    /**
     * The service account's own keys (not the ones Google manages).
     *
     * @return list<array{name: string, disabled: bool}>
     */
    public function listKeys(string $serviceAccountEmail): array
    {
        $json = $this->expect($this->send('GET', self::keysPath($serviceAccountEmail).'?keyTypes=USER_MANAGED'));
        $keys = [];

        foreach (is_array($json['keys'] ?? null) ? $json['keys'] : [] as $key) {
            if (is_array($key) && is_string($key['name'] ?? null)) {
                $keys[] = ['name' => $key['name'], 'disabled' => ($key['disabled'] ?? false) === true];
            }
        }

        return $keys;
    }

    /**
     * POST /{name}:disable. The key stops working at once but can be enabled again in Google Cloud.
     */
    public function disableKey(string $name): void
    {
        $this->expect($this->send('POST', '/'.self::path($name).':disable', []));
    }

    public function deleteKey(string $name): void
    {
        $this->expect($this->send('DELETE', '/'.self::path($name)));
    }

    /**
     * Proves the access works: a key file is traded for a token; a token is looked up.
     */
    public function check(): void
    {
        if ($this->credentials !== null && $this->accessToken === null) {
            $this->token();

            return;
        }

        $response = $this->http->send(new Request(
            'POST',
            'https://oauth2.googleapis.com/tokeninfo',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            body: http_build_query(['access_token' => $this->token()]),
        ));

        if (! $response->successful()) {
            throw new ApiError('Google Cloud', $response->status, 'The access token was refused.');
        }
    }

    /**
     * Calls another Google Cloud API (such as Secret Manager) with the same access. Only Google's own *.googleapis.com
     * addresses are allowed, so the token can't be sent anywhere else.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function call(string $method, string $url, ?array $json = null): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! str_ends_with($host, '.googleapis.com')) {
            throw new InvalidArgumentException('The runner only sends its Google Cloud access to Google\'s own addresses.');
        }

        return $this->expect($this->http->send(new Request($method, $url, ['Authorization' => 'Bearer '.$this->token()], $json)));
    }

    /**
     * Proves a new key file works: Google trades it for a token. A brand-new key can take a few seconds before Google
     * accepts it, so the runner tries again for a while before giving up.
     */
    public function verifyKeyFile(string $keyFile): void
    {
        (new self($this->http, null, $keyFile, $this->clock, $this->baseUrl))->token();
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $file = json_decode((string) $this->credentials, true);

        if (! is_array($file) || ($file['type'] ?? null) !== 'service_account' || ! is_string($file['client_email'] ?? null) || ! is_string($file['private_key'] ?? null)) {
            throw new ConfigurationError('GOOGLE_CLOUD_CREDENTIALS is not a service account key file. Use the JSON key file of a service account, or set GOOGLE_CLOUD_ACCESS_TOKEN instead.');
        }

        if (! function_exists('openssl_sign')) {
            throw new ConfigurationError('Using GOOGLE_CLOUD_CREDENTIALS needs PHP\'s openssl extension. Install it, or set GOOGLE_CLOUD_ACCESS_TOKEN instead.');
        }

        $now = ($this->clock)()->getTimestamp();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'] + (is_string($file['private_key_id'] ?? null) ? ['kid' => $file['private_key_id']] : []);
        $claims = ['iss' => $file['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600];
        $unsigned = self::base64url((string) json_encode($header)).'.'.self::base64url((string) json_encode($claims));

        if (! openssl_sign($unsigned, $signature, $file['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new ConfigurationError('The private key in GOOGLE_CLOUD_CREDENTIALS could not be used.');
        }

        // Always Google's own token address, whatever the key file says, so the signed request can't be sent elsewhere.
        $response = $this->http->send(new Request(
            'POST',
            self::TOKEN_URL,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            body: http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.self::base64url($signature)]),
        ));

        $token = $response->json()['access_token'] ?? null;

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $detail = $response->json()['error_description'] ?? '';

            throw new ApiError('Google Cloud', $response->status, 'The service account key in GOOGLE_CLOUD_CREDENTIALS was refused. '.(is_string($detail) ? $detail : ''));
        }

        return $this->accessToken = $token;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, ?array $json = null): Response
    {
        return $this->http->send(new Request($method, rtrim($this->baseUrl, '/').$path, ['Authorization' => 'Bearer '.$this->token()], $json));
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(Response $response): array
    {
        if (! $response->successful()) {
            throw ApiError::from('Google Cloud', $response);
        }

        return $response->json();
    }

    private static function keysPath(string $serviceAccountEmail): string
    {
        return '/projects/-/serviceAccounts/'.rawurlencode($serviceAccountEmail).'/keys';
    }

    /**
     * A resource name such as projects/p/serviceAccounts/sa@p.iam.gserviceaccount.com/keys/abc, each part escaped.
     */
    private static function path(string $name): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $name)));
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'access' => '[hidden]'];
    }
}
