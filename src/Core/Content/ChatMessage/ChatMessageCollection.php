<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatMessage;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ChatMessageEntity>
 */
class ChatMessageCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ChatMessageEntity::class;
    }
}
