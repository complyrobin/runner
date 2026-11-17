<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Support;

/**
 * Makes text safe to print or to send to ComplyRobin: known secrets and anything shaped like a key or token are
 * replaced, and the text is cut to a length. ComplyRobin refuses reports that look like they carry a key, so this
 * also keeps reports acceptable.
 */
final class Redact
{
    private const string REPLACEMENT = '[redacted]';

    /** @var list<string> */
    private const array PATTERNS = [
        '/\b(?:sk|rk|pk)-[A-Za-z0-9_-]{8,}/',                 // OpenAI / Anthropic style keys
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}/',     // Stripe style keys
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{8,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{8,}/',
        '/\bcrr_[A-Za-z0-9]{8,}/',                            // ComplyRobin runner tokens
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.?[A-Za-z0-9_-]*/', // JWTs
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s',
    ];

    /**
     * @param  list<string>  $secrets  exact values to remove first (the new key, tokens in use)
     */
    public static function text(string $text, int $maxLength = 500, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, self::REPLACEMENT, $text);
            }
        }

        $text = (string) preg_replace(self::PATTERNS, self::REPLACEMENT, $text);
        $text = (string) preg_replace_callback('/[A-Za-z0-9+\/=_-]{32,}/', static fn (array $run): string => self::looksRandom($run[0]) ? self::REPLACEMENT : $run[0], $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)).'…';
        }

        return $text;
    }

    /**
     * A long run mixing upper case, lower case and digits, as in base64 secrets. Lower-case ids such as UUIDs stay.
     */
    private static function looksRandom(string $run): bool
    {
        return preg_match('/[a-z]/', $run) === 1 && preg_match('/[A-Z]/', $run) === 1 && preg_match('/\d/', $run) === 1;
    }
}
