<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ProductReference;

use AiCommerceAssistant\Core\Content\ChatMessage\ChatMessageDefinition;
use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductReferenceDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'aica_product_reference';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ProductReferenceCollection::class;
    }

    public function getEntityClass(): string
    {
        return ProductReferenceEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),

            (new FkField('session_id', 'sessionId', ChatSessionDefinition::class))->addFlags(new ApiAware()),
            (new FkField('message_id', 'messageId', ChatMessageDefinition::class))->addFlags(new ApiAware()),

            (new StringField('source', 'source'))->addFlags(new Required(), new ApiAware()),
            (new StringField('product_id', 'productId'))->addFlags(new ApiAware()),
            (new StringField('product_number', 'productNumber'))
                ->addFlags(new ApiAware(), new SearchRanking(SearchRanking::MIDDLE_SEARCH_RANKING)),
            (new StringField('product_name', 'productName'))
                ->addFlags(new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('reference_text', 'referenceText'))
                ->addFlags(new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),

            (new ManyToOneAssociationField('session', 'session_id', ChatSessionDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('message', 'message_id', ChatMessageDefinition::class, 'id', false))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new Required(), new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),
        ]);
    }
}
