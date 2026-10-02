<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class LicenseSigner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/license/private.key')] private readonly string $keyPath,
    ) {
    }

    public function sign(array $claims): string
    {
        $secret = $this->secretKey();
        $claims['kid'] = $this->identifier(sodium_crypto_sign_publickey_from_secretkey($secret));
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload.'.'.$this->encode(sodium_crypto_sign_detached($payload, $secret));
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (2 !== count($parts) || strlen($token) > 16384) {
            throw new \DomainException('Invalid token.');
        }
        try {
            $claims = json_decode($this->decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('Invalid claims.', previous: $e);
        }
        if (!is_array($claims)) {
            throw new \DomainException('Invalid claims.');
        }

        $ring = $this->keyring();
        $kid = $claims['kid'] ?? $ring['legacy'];
        if (!is_string($kid) || !isset($ring['publicKeys'][$kid])) {
            throw new \DomainException('Unknown signing key.');
        }
        $key = base64_decode($ring['publicKeys'][$kid], true);
        $signature = $this->decode($parts[1]);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $parts[0], $key)) {
            throw new \DomainException('Invalid signature.');
        }

        return $claims;
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    public function keyId(): string
    {
        return $this->identifier($this->publicKey());
    }

    /** @return array<string, string> Base64-encoded public keys, never secret keys. */
    public function publicKeys(): array
    {
        return $this->keyring()['publicKeys'];
    }

    public function identifier(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    public function keyring(): array
    {
        $path = dirname($this->keyPath).'/keyring.json';
        if (is_file($path)) {
            $ring = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($ring) || !is_string($ring['active'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $ring['active'])
                || !is_array($ring['publicKeys'] ?? null) || !isset($ring['publicKeys'][$ring['active']], $ring['publicKeys'][$ring['legacy'] ?? ''])) {
                throw new \RuntimeException('Invalid signing keyring.');
            }

            return $ring;
        }
        $key = sodium_crypto_sign_publickey_from_secretkey($this->readSecret($this->keyPath));
        $kid = $this->identifier($key);

        return ['active' => $kid, 'legacy' => $kid, 'publicKeys' => [$kid => base64_encode($key)]];
    }

    private function secretKey(): string
    {
        $ring = $this->keyring();
        $path = $ring['active'] === $ring['legacy'] ? $this->keyPath : dirname($this->keyPath).'/keys/'.$ring['active'].'.key';
        $secret = $this->readSecret($path);
        if (base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== $ring['publicKeys'][$ring['active']]) {
            throw new \RuntimeException('Signing key does not match keyring.');
        }

        return $secret;
    }

    private function readSecret(string $path): string
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('Signierschlüssel fehlt. app:license:keys ausführen.');
        }
        $key = base64_decode(trim(file_get_contents($path)), true);
        if (false === $key || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($key)) {
            throw new \RuntimeException('Ungültiger Signierschlüssel.');
        }

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \DomainException('Invalid encoding.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \DomainException('Invalid encoding.');
        }

        return $decoded;
    }
}
