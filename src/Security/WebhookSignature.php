<?php
namespace App\Security;
use Symfony\Component\Uid\Uuid;
final class WebhookSignature
{
    public static function sign(string $body, string $eventId, int $timestamp, #[\SensitiveParameter] string $secret): string
    { return 'v1='.hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.$body, $secret); }
    public static function verify(string $body, string $eventId, string $timestamp, string $signature, #[\SensitiveParameter] string $secret, int $now, int $window = 300): bool
    {
        if (!ctype_digit($timestamp) || strlen($timestamp) > 12 || abs($now - (int) $timestamp) > $window || !Uuid::isValid($eventId) || strlen($body) > 65536 || !preg_match('/^v1=[a-f0-9]{64}$/D', $signature)) { return false; }
        try { $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) { return false; }
        return is_array($payload) && ($payload['id'] ?? null) === $eventId && ($payload['version'] ?? null) === 1 && hash_equals(self::sign($body, $eventId, (int) $timestamp, $secret), $signature);
    }
}
