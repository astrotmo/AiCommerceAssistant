<?php declare(strict_types=1);

/**
 * Controller to provide chat context token for AICA - AI Commerce Assistant.
 *
 * @package    AiCommerceAssistant
 * @author     Paul Nöth
*/

namespace AiCommerceAssistant\Storefront\Controller;

use AiCommerceAssistant\Service\ChatSessionResolver;
use AiCommerceAssistant\Service\ChatTrackingService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;


/**
 * Class ChatContextController
 *
 * This controller provides an endpoint to generate a context token for the AICA - AI Commerce Assistant.
 * The token is a JWT that includes information about the customer and sales channel.
 *
 * @author Paul Nöth
 * @package AiCommerceAssistant\Storefront\Controller
 */
class ChatContextController extends StorefrontController
{
    private const TOKEN_TTL_SECONDS = 60;

    private readonly ChatSessionResolver $chatSessionResolver;

    private readonly ?ChatTrackingService $chatTrackingService;

    /**
     * Constructor to initialize the ChatContextController with necessary dependencies.
     *
     * @param string $chatAuthSecret The secret key used to sign the JWT tokens.
     */
    public function __construct(
        private readonly string $chatAuthSecret,
        private readonly RequestStack $requestStack,
        ?ChatSessionResolver $chatSessionResolver = null,
        ?ChatTrackingService $chatTrackingService = null,
    )
    {
        $this->chatSessionResolver = $chatSessionResolver ?? new ChatSessionResolver();
        $this->chatTrackingService = $chatTrackingService;
    }

    #[Route(
        path: '/aica/context-token',
        name: 'frontend.aica.context_token',
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']],
        methods: ['GET']
    )]

    /**
     * Generates and returns a context token for the AICA - AI Commerce Assistant.
     * 
     * @param SalesChannelContext $context The sales channel context containing customer and sales channel information.
     * @return JsonResponse A JSON response containing the generated context token.
     */
    public function contextToken(SalesChannelContext $context): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && !$this->isTrustedAjaxRequest($request)) {
            return new JsonResponse(['error' => 'UNTRUSTED_REQUEST'], 400);
        }

        // Secret shared with Python backend (set it as env var in Shopware container)
        $secret = trim($this->chatAuthSecret);
        if ($secret === '') {
            return new JsonResponse(['error' => 'CHAT_AUTH_SECRET not configured'], 500);
        }

        $now = time();

        $storefrontSessionIdentifier = $this->chatSessionResolver->resolveStorefrontSessionIdentifier($context, $request);

        $payload = [
            'sid' => $storefrontSessionIdentifier,
            'salesChannelId' => $context->getSalesChannelId(),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
            'jti' => $this->randomHex(8),
        ];

        if ($this->chatTrackingService !== null) {
            try {
                $this->chatTrackingService->touchSession(
                    $context,
                    $request,
                    $storefrontSessionIdentifier,
                );
            } catch (\Throwable) {
                // Session tracking must never block token creation.
            }
        }

        $token = $this->signToken($payload, $secret);

        $response = new JsonResponse(['token' => $token]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Signs the given payload to create a JWT token.
     *
     * @param array $payload The payload data to include in the token.
     * @param string $secret The secret key used to sign the token.
     * @return string The signed JWT token.
     */
    private function signToken(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $h = $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $sig = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        $s = $this->base64UrlEncode($sig);

        return $h . '.' . $p . '.' . $s;
    }

    /**
     * Encodes data with base64 URL-safe encoding.
     *
     * @param string $data The data to encode.
     * @return string The base64 URL-encoded string.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable) {
            return bin2hex(hash('sha256', uniqid('', true), true));
        }
    }

    private function isTrustedAjaxRequest(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            && $request->headers->get('X-AICA') === '1';
    }
}
