<?php declare(strict_types=1);

namespace AiCommerceAssistant\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class ChatSessionResolver
{
    public const SESSION_KEY = 'aica.session_id';

    public function resolveStorefrontSessionIdentifier(SalesChannelContext $context, ?Request $request): string
    {
        if ($request === null || !$request->hasSession()) {
            return hash('sha256', $context->getToken());
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            try {
                $session->start();
            } catch (\Throwable) {
                return hash('sha256', $context->getToken());
            }
        }

        $sessionId = $session->get(self::SESSION_KEY);
        if (\is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        $newSessionId = $this->randomHex(16);
        $session->set(self::SESSION_KEY, $newSessionId);

        return $newSessionId;
    }

    private function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable) {
            return bin2hex(hash('sha256', uniqid('', true), true));
        }
    }
}
