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
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload.'.'.$this->encode(sodium_crypto_sign_detached($payload, $this->secretKey()));
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (2 !== count($parts) || strlen($token) > 16384) {
            throw new \DomainException('Invalid token.');
        }
        $signature = $this->decode($parts[1]);
        if (SODIUM_CRYPTO_SIGN_BYTES !== strlen($signature) || !sodium_crypto_sign_verify_detached($signature, $parts[0], $this->publicKey())) {
            throw new \DomainException('Invalid signature.');
        }
        try {
            $claims = json_decode($this->decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('Invalid claims.', previous: $e);
        }
        if (!is_array($claims)) {
            throw new \DomainException('Invalid claims.');
        }

        return $claims;
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    private function secretKey(): string
    {
        if (!is_readable($this->keyPath)) {
            throw new \RuntimeException('Signierschlüssel fehlt. app:license:keys ausführen.');
        }
        $key = base64_decode(trim(file_get_contents($this->keyPath)), true);
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
