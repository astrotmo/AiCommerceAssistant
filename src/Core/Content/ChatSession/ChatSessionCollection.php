<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatSession;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ChatSessionEntity>
 */
class ChatSessionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ChatSessionEntity::class;
    }
}
