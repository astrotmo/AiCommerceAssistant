<?php declare(strict_types=1);

namespace AiCommerceAssistant\Storefront\Controller;

use AiCommerceAssistant\Service\ChatTrackingService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ChatTrackingController extends StorefrontController
{
    public function __construct(
        private readonly ChatTrackingService $chatTrackingService,
    ) {
    }

    #[Route(
        path: '/aica/track',
        name: 'frontend.aica.track',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']],
    )]
    public function track(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        if (!$this->isTrustedAjaxRequest($request)) {
            return new JsonResponse(['success' => false, 'error' => 'UNTRUSTED_REQUEST'], 400);
        }

        $payload = $this->readPayload($request);
        if ($payload === null) {
            return new JsonResponse(['success' => false, 'error' => 'INVALID_PAYLOAD'], 400);
        }

        $event = strtolower(trim((string) ($payload['event'] ?? 'message')));

        try {
            if ($event === 'message') {
                $messageId = $this->chatTrackingService->trackMessageEvent($salesChannelContext, $request, $payload);

                return new JsonResponse([
                    'success' => true,
                    'event' => 'message',
                    'messageId' => $messageId,
                ]);
            }

            if ($event === 'contact_form_shown') {
                $this->chatTrackingService->markContactFormShown($salesChannelContext, $request);

                return new JsonResponse([
                    'success' => true,
                    'event' => 'contact_form_shown',
                ]);
            }
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'error' => 'TRACKING_FAILED'], 500);
        }

        return new JsonResponse(['success' => false, 'error' => 'UNSUPPORTED_EVENT'], 400);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(Request $request): ?array
    {
        $content = trim((string) $request->getContent());
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        $payload = $request->request->all();

        return \is_array($payload) && $payload !== [] ? $payload : null;
    }

    private function isTrustedAjaxRequest(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            && $request->headers->get('X-AICA') === '1';
    }
}
