<?php
// Deliberately public deterministic test seed, never a deployment signing key.
$pair = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
$public = sodium_crypto_sign_publickey($pair);
$secret = sodium_crypto_sign_secretkey($pair);
$encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$claims = ['tenant' => 'real-client', 'domain' => 'example.org', 'features' => ['sla'], 'mode' => 'online', 'status' => 'valid', 'issued_at' => 1710000000, 'refresh_after' => 1710000100, 'grace_until' => 1710000300, 'expires_at' => 1710000300];
$vectors = ['description' => 'Public deterministic interoperability vectors; seed is 32 bytes of 0x01.', 'publicKey' => base64_encode($public), 'kid' => substr(hash('sha256', $public), 0, 16), 'vectors' => []];
foreach (['legacy', 'kid', 'offline'] as $name) {
    $payloadClaims = $claims;
    if ($name !== 'legacy') { $payloadClaims['kid'] = $vectors['kid']; }
    if ($name === 'offline') { $payloadClaims['mode'] = 'offline'; unset($payloadClaims['refresh_after'], $payloadClaims['grace_until']); }
    $payload = $encode(json_encode($payloadClaims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $vectors['vectors'][$name] = ['claims' => $payloadClaims, 'token' => $payload.'.'.$encode(sodium_crypto_sign_detached($payload, $secret))];
}
echo json_encode($vectors, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
