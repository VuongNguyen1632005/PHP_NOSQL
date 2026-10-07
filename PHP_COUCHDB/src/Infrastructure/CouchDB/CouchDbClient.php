<?php

declare(strict_types=1);

namespace App\Infrastructure\CouchDB;

use RuntimeException;

final class CouchDbClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $requestTimeoutSeconds = 30,
    ) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('COUCHDB_URL must be a valid HTTP or HTTPS URL.');
        }
        if ($username === '' || $password === '') {
            throw new RuntimeException('CouchDB credentials are required.');
        }
    }

    /** @param array<string, mixed>|list<mixed>|null $payload */
    public function request(string $method, string $path, ?array $payload = null): CouchDbResponse
    {
        $curl = curl_init(rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'));
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the CouchDB HTTP client.');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $encodedPayload = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($body)) {
            throw new RuntimeException('CouchDB request failed: ' . ($curlError !== '' ? $curlError : 'no response'));
        }

        return new CouchDbResponse($statusCode, $body);
    }
}
