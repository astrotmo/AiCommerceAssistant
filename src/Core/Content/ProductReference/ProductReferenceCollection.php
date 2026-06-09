<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ProductReference;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProductReferenceEntity>
 */
class ProductReferenceCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProductReferenceEntity::class;
    }
}
