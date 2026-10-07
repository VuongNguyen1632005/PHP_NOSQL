<?php

declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtTokenService
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $ttlSeconds = 900,
    ) {
        if (strlen($secret) < 32) throw new AuthException('JWT_SECRET must contain at least 32 bytes.');
        if (trim($issuer) === '' || trim($audience) === '') throw new AuthException('JWT issuer and audience are required.');
        if ($ttlSeconds < 60 || $ttlSeconds > 3600) throw new AuthException('JWT_TTL_SECONDS must be between 60 and 3600.');
    }

    /** @param array<string, mixed> $account
     *  @return array{access_token:string,token_type:string,expires_in:int}
     */
    public function issue(array $account): array
    {
        $subject = $account['_id'] ?? null;
        $type = $account['type'] ?? null;
        if (!is_string($subject) || !in_array($type, ['customer', 'staff'], true)
            || ($account['active'] ?? false) !== true) {
            throw new AuthException('An active account is required to issue an access token.');
        }

        $now = time();
        $token = JWT::encode([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $subject,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttlSeconds,
        ], $this->secret, self::ALGORITHM);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $this->ttlSeconds];
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        if (strlen($token) > 8192 || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new AuthException('Invalid or expired access token.');
        }

        try {
            $claims = (array) JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable) {
            throw new AuthException('Invalid or expired access token.');
        }

        $now = time();
        $audience = $claims['aud'] ?? null;
        $validAudience = $audience === $this->audience
            || (is_array($audience) && in_array($this->audience, $audience, true));
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $subject = $claims['sub'] ?? null;
        if (($claims['iss'] ?? null) !== $this->issuer || !$validAudience
            || !is_int($issuedAt) || $issuedAt > $now + 30
            || !is_int($expiresAt) || $expiresAt <= $issuedAt || $expiresAt - $issuedAt > $this->ttlSeconds
            || !is_string($subject) || preg_match('/^(customer|staff):[A-Za-z0-9:_-]{1,220}$/', $subject) !== 1
            || !is_string($claims['jti'] ?? null) || preg_match('/^[a-f0-9]{32}$/', $claims['jti']) !== 1) {
            throw new AuthException('Invalid or expired access token.');
        }

        return $claims;
    }
}
