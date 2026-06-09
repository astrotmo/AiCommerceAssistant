<?php declare(strict_types=1);

namespace AiCommerceAssistant\Controller\Api;

use AiCommerceAssistant\Service\ChatAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ChatAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ChatAnalyticsService $chatAnalyticsService,
    ) {
    }

    #[Route(
        path: '/api/_action/aica/dashboard/overview',
        name: 'api.action.aica.dashboard.overview',
        methods: ['POST'],
        defaults: [
            '_acl' => [
                'aica_chat_session:read',
                'aica_contact_submission:read',
                'aica_product_reference:read',
            ],
        ],
    )]
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveDateRange($request);

        $overview = $this->chatAnalyticsService->getOverview($from, $to);

        return new JsonResponse([
            'success' => true,
            'data' => $overview,
        ]);
    }

    #[Route(
        path: '/api/_action/aica/dashboard/export/sessions',
        name: 'api.action.aica.dashboard.export_sessions',
        methods: ['POST'],
        defaults: ['_acl' => ['aica_chat_session:read', 'customer:read']],
    )]
    public function exportSessions(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $csv = $this->chatAnalyticsService->exportSessionsCsv($from, $to);

        return $this->buildCsvResponse('aica-sessions', $from, $to, $csv);
    }

    #[Route(
        path: '/api/_action/aica/dashboard/export/contacts',
        name: 'api.action.aica.dashboard.export_contacts',
        methods: ['POST'],
        defaults: ['_acl' => ['aica_contact_submission:read', 'customer:read']],
    )]
    public function exportContacts(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $csv = $this->chatAnalyticsService->exportContactsCsv($from, $to);

        return $this->buildCsvResponse('aica-contacts', $from, $to, $csv);
    }

    #[Route(
        path: '/api/_action/aica/dashboard/export/messages',
        name: 'api.action.aica.dashboard.export_messages',
        methods: ['POST'],
        defaults: ['_acl' => ['aica_chat_message:read']],
    )]
    public function exportMessages(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $csv = $this->chatAnalyticsService->exportMessagesCsv($from, $to);

        return $this->buildCsvResponse('aica-messages', $from, $to, $csv);
    }

    #[Route(
        path: '/api/_action/aica/dashboard/delete/sessions',
        name: 'api.action.aica.dashboard.delete_sessions',
        methods: ['POST'],
        defaults: [
            '_acl' => [
                'aica_chat_session:delete',
                'aica_chat_message:delete',
                'aica_product_reference:delete',
            ],
        ],
    )]
    public function deleteSessions(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveDateRange($request);

        $result = $this->chatAnalyticsService->deleteSessionsByRange($from, $to);

        return new JsonResponse([
            'success' => true,
            'data' => $result,
        ]);
    }

    #[Route(
        path: '/api/_action/aica/dashboard/delete/contacts',
        name: 'api.action.aica.dashboard.delete_contacts',
        methods: ['POST'],
        defaults: ['_acl' => ['aica_contact_submission:delete']],
    )]
    public function deleteContacts(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveDateRange($request);

        $result = $this->chatAnalyticsService->deleteContactsByRange($from, $to);

        return new JsonResponse([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $payload = $this->readPayload($request);

        $fromRaw = $payload['from'] ?? null;
        $toRaw = $payload['to'] ?? null;

        $from = $this->parseDateValue($fromRaw, false);
        $to = $this->parseDateValue($toRaw, true);

        if ($from === null || $to === null) {
            $to = new \DateTimeImmutable();
            $from = $to->sub(new \DateInterval('P30D'))->setTime(0, 0, 0);
            $to = $to->setTime(23, 59, 59);
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        $query = $request->query->all();

        return \is_array($query) ? $query : [];
    }

    private function parseDateValue(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }

        if (!str_contains($raw, 'T')) {
            return $date->setTime(
                $endOfDay ? 23 : 0,
                $endOfDay ? 59 : 0,
                $endOfDay ? 59 : 0,
            );
        }

        return $date;
    }

    private function buildCsvResponse(
        string $namePrefix,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $csv,
    ): Response {
        $safeName = sprintf(
            '%s-%s-to-%s.csv',
            $namePrefix,
            $from->format('Ymd'),
            $to->format('Ymd'),
        );

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $safeName));

        return $response;
    }
}
