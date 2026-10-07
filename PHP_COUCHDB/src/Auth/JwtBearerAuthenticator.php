<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Response;

final class JwtBearerAuthenticator
{
    public function __construct(
        private readonly JwtTokenService $tokens,
        private readonly AccountRepository $accounts,
    ) {
    }

    /** @return array<string, mixed>|Response */
    public function account(): array|Response
    {
        $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = trim((string) $value);
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+([A-Za-z0-9_.-]+)$/i', $header, $matches) !== 1) {
            return self::json(['error' => 'A Bearer access token is required.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        try {
            $claims = $this->tokens->verify($matches[1]);
            $account = $this->accounts->byTokenSubject((string) $claims['sub']);
        } catch (AuthException) {
            return self::json(['error' => 'Invalid or expired access token.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if ($account === null || ($account['active'] ?? false) !== true
            || (($account['type'] ?? null) === 'customer' && ($account['auth']['requires_password_reset'] ?? false) === true)) {
            return self::json(['error' => 'Invalid or expired access token.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        return $account;
    }

    /** @param array<string, mixed> $body
     *  @param array<string, string> $headers
     */
    private static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            'application/json; charset=utf-8',
            $headers,
        );
    }
}
