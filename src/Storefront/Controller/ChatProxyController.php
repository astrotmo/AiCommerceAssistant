<?php declare(strict_types=1);

namespace AiCommerceAssistant\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Same-origin JSON proxy between the storefront widget and the configured AI backend.
 *
 * After trimming outer whitespace, the browser payload is forwarded after checking
 * that it decodes to a JSON object/array. The proxy adds JSON accept/content headers
 * and `X-AICA-Proxy: 1`, but forwards no browser cookies or authentication headers.
 * Upstream status, body, and the first Content-Type value are returned unchanged and
 * marked `no-store`.
 */
class ChatProxyController extends StorefrontController
{
    private const DEFAULT_BACKEND_CHAT_PATH = '/chat';

    private const UPSTREAM_TIMEOUT_SECONDS = 60.0;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/aica/chat',
        name: 'frontend.aica.chat',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']],
    )]
    /**
     * @return Response Upstream response, or JSON `{error: string}` for proxy failures
     */
    public function chat(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $rawPayload = trim((string) $request->getContent());
        if ($rawPayload === '' || !\is_array(json_decode($rawPayload, true))) {
            return new JsonResponse(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $backendUrl = $this->resolveBackendUrl($salesChannelContext->getSalesChannelId());
        if ($backendUrl === null) {
            $this->logger->error('AICA proxy has no backend URL configured.', [
                'route' => '/aica/chat',
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]);

            return new JsonResponse(['error' => 'CHAT_BACKEND_NOT_CONFIGURED'], 500);
        }

        $this->logger->info('AICA proxy forwarding request.', [
            'route' => '/aica/chat',
            'backendUrl' => $backendUrl,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        try {
            $upstreamResponse = $this->httpClient->request('POST', $backendUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-AICA-Proxy' => '1',
                ],
                'body' => $rawPayload,
                'timeout' => self::UPSTREAM_TIMEOUT_SECONDS,
                'max_duration' => self::UPSTREAM_TIMEOUT_SECONDS,
            ]);

            $statusCode = $upstreamResponse->getStatusCode();
            $upstreamBody = $upstreamResponse->getContent(false);
            $upstreamHeaders = $upstreamResponse->getHeaders(false);
            $contentType = $upstreamHeaders['content-type'][0] ?? 'application/json';

            if ($statusCode >= 400) {
                $this->logger->warning('AICA proxy received non-success backend response.', [
                    'route' => '/aica/chat',
                    'backendUrl' => $backendUrl,
                    'statusCode' => $statusCode,
                ]);
            }

            $response = new Response($upstreamBody, $statusCode);
            $response->headers->set('Content-Type', $contentType);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (TimeoutExceptionInterface $exception) {
            $this->logger->error('AICA proxy request timed out.', [
                'route' => '/aica/chat',
                'backendUrl' => $backendUrl,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse(['error' => 'CHAT_BACKEND_TIMEOUT'], 504);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('AICA proxy transport failure.', [
                'route' => '/aica/chat',
                'backendUrl' => $backendUrl,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse(['error' => 'CHAT_BACKEND_UNAVAILABLE'], 502);
        } catch (\Throwable $exception) {
            $this->logger->error('AICA proxy unexpected forwarding failure.', [
                'route' => '/aica/chat',
                'backendUrl' => $backendUrl,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse(['error' => 'CHAT_PROXY_FAILED'], 500);
        }
    }

    private function resolveBackendUrl(?string $salesChannelId): ?string
    {
        $configuredUrl = $this->readConfigValue('AiCommerceAssistant.config.chatUrl', $salesChannelId);
        if ($configuredUrl === '') {
            return null;
        }

        return $this->normalizeBackendUrl($configuredUrl);
    }

    private function readConfigValue(string $key, ?string $salesChannelId): string
    {
        $salesChannelValue = $this->systemConfigService->get($key, $salesChannelId);
        if (\is_string($salesChannelValue) && trim($salesChannelValue) !== '') {
            return trim($salesChannelValue);
        }

        $globalValue = $this->systemConfigService->get($key);

        return \is_string($globalValue) ? trim($globalValue) : '';
    }

    private function normalizeBackendUrl(string $configuredUrl): ?string
    {
        // A bare host gets HTTP; a base URL with no path gets the backend's `/chat` path.
        $candidate = trim($configuredUrl);
        if ($candidate === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $candidate)) {
            $candidate = 'http://' . ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);
        if (!\is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $authority .= ':' . (string) $parts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            $path = self::DEFAULT_BACKEND_CHAT_PATH;
        }

        $normalized = $scheme . '://' . $authority . $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }
}
