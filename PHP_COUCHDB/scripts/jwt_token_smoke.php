<?php

declare(strict_types=1);

use App\Auth\AuthException;
use App\Auth\JwtTokenService;

require dirname(__DIR__) . '/vendor/autoload.php';

$secret = random_bytes(48);
$tokens = new JwtTokenService($secret, 'jwt-smoke', 'jwt-smoke-api', 300);
$issued = $tokens->issue([
    '_id' => 'staff:jwt-smoke',
    'type' => 'staff',
    'active' => true,
]);
$claims = $tokens->verify($issued['access_token']);
if (($claims['sub'] ?? null) !== 'staff:jwt-smoke' || $issued['token_type'] !== 'Bearer') {
    throw new RuntimeException('JWT issue/verify smoke test failed.');
}

$tampered = substr($issued['access_token'], 0, -1) . ($issued['access_token'][-1] === 'a' ? 'b' : 'a');
try {
    $tokens->verify($tampered);
    throw new RuntimeException('Tampered JWT was accepted.');
} catch (AuthException) {
    // Expected: signature validation rejects modified tokens.
}

$wrongIssuer = new JwtTokenService($secret, 'other-issuer', 'jwt-smoke-api', 300);
try {
    $wrongIssuer->verify($issued['access_token']);
    throw new RuntimeException('JWT with the wrong issuer was accepted.');
} catch (AuthException) {
    // Expected: issuer validation rejects tokens for another service.
}

echo "JWT issue, verification, tamper rejection, and issuer validation passed.\n";
