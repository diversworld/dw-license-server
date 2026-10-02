<?php

declare(strict_types=1);
namespace App\Tests\Fixtures;

// Protocol reference copied from contao-issue-service-bundle on 2026-09-27.

/** Ed25519 envelope: base64url(JSON claims).base64url(detached signature). */
final class ContaoLicenseValidator
{
    public function __construct(private readonly string $publicKey, private readonly string $tenant, private readonly string $domain) {}

    /** @return array{enabled: bool, state: string, claims: array<string, mixed>} */
    public function validate(string $token, ?int $now = null): array
    {
        $now ??= time();
        try {
            $parts = explode('.', trim($token));
            if (count($parts) !== 2 || $this->tenant === '' || $this->domain === '') throw new \UnexpectedValueException();
            $claims = json_decode($this->decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($claims)) throw new \UnexpectedValueException();
            $configured = trim($this->publicKey);
            if (str_starts_with($configured, '{')) {
                $keys = json_decode($configured, true, 32, JSON_THROW_ON_ERROR);
                $kid = $claims['kid'] ?? null;
                if ($kid === null) {
                    // Legacy tokens have no kid: identify the trusted key by its signature.
                    $encodedKey = null;
                    foreach ($keys as $candidate) {
                        $decodedKey = base64_decode($candidate, true);
                        $sig = $this->decode($parts[1]);
                        if ($decodedKey !== false && strlen($decodedKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
                            && sodium_crypto_sign_verify_detached($sig, $parts[0], $decodedKey)) { $encodedKey = $candidate; break; }
                    }
                } else {
                    if (!is_string($kid)) throw new \UnexpectedValueException();
                    $encodedKey = $keys[$kid] ?? null;
                }
                if (!is_string($encodedKey)) throw new \UnexpectedValueException();
            } else {
                $encodedKey = $configured;
            }
            $key = base64_decode($encodedKey, true);
            $signature = $this->decode($parts[1]);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || !sodium_crypto_sign_verify_detached($signature, $parts[0], $key)) throw new \UnexpectedValueException();
            if (!is_array($claims) || ($claims['tenant'] ?? null) !== $this->tenant || ($claims['domain'] ?? null) !== strtolower($this->domain)
                || !is_int($claims['issued_at'] ?? null) || !is_int($claims['expires_at'] ?? null)
                || $claims['issued_at'] > $now || $claims['expires_at'] <= $now || $claims['expires_at'] <= $claims['issued_at']
                || !is_array($claims['features'] ?? null) || !in_array('sla', $claims['features'], true)
                || !in_array($claims['mode'] ?? null, ['online', 'offline'], true)
                || ($claims['status'] ?? null) !== 'valid') throw new \UnexpectedValueException();
            $state = 'valid';
            if ($claims['mode'] === 'online') {
                $refresh = $claims['refresh_after'] ?? null;
                if (!is_int($refresh)) throw new \UnexpectedValueException();
                $graceUntil = $claims['grace_until'] ?? $refresh + 30 * 86400;
                if (!is_int($refresh) || !is_int($graceUntil) || $refresh < $claims['issued_at'] || $graceUntil < $refresh || $now >= $graceUntil) throw new \UnexpectedValueException();
                if ($now >= $refresh) $state = 'grace';
            }
            return ['enabled' => true, 'state' => $state, 'claims' => $claims];
        } catch (\Throwable) {
            return ['enabled' => false, 'state' => 'invalid', 'claims' => []];
        }
    }

    private function decode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw new \UnexpectedValueException();
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new \UnexpectedValueException();
        return $decoded;
    }
}
