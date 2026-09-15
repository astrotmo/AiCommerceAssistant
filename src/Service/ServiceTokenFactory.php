<?php declare(strict_types=1);

namespace AiCommerceAssistant\Service;

final class ServiceTokenFactory
{
    private const TOKEN_TTL_SECONDS = 60;

    public function __construct(
        private readonly string $secret,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/', $secret) !== 1) {
            throw new \InvalidArgumentException(
                'AICA_BACKEND_SECRET must contain 64 lowercase hex characters.'
            );
        }
    }

    public function create(): string
    {
        $now = time();

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => 'aica-shopware',
            'aud' => 'aica-backend',
            'sub' => 'shopware-proxy',
            'token_use' => 'service',
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
        ];

        $encodedHeader = $this->base64UrlEncode(
            json_encode($header, JSON_THROW_ON_ERROR)
        );

        $encodedPayload = $this->base64UrlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $signature = hash_hmac(
            'sha256',
            $signingInput,
            $this->secret,
            true
        );

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}