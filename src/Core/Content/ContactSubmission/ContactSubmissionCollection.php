<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ContactSubmission;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ContactSubmissionEntity>
 */
class ContactSubmissionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ContactSubmissionEntity::class;
    }
}
