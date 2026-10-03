<?php
namespace App\Service;
final class WebhookTargets
{
    public static function validate(string $url): void
    {
        $parts = parse_url($url); $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['port'] ?? 443) !== 443) || strlen($url) > 2048
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}$/Di', $host) || preg_match('/(?:^|\.)(?:localhost|local|internal)$/i', $host)) { throw new \DomainException('webhook.invalid_url'); }
    }
}
