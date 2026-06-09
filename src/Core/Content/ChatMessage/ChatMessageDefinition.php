<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatMessage;

use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionDefinition;
use AiCommerceAssistant\Core\Content\ProductReference\ProductReferenceDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ChatMessageDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'aica_chat_message';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ChatMessageCollection::class;
    }

    public function getEntityClass(): string
    {
        return ChatMessageEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),
            (new FkField('session_id', 'sessionId', ChatSessionDefinition::class))->addFlags(new Required(), new ApiAware()),

            (new StringField('role', 'role'))->addFlags(new Required(), new ApiAware()),
            (new IntField('message_index', 'messageIndex'))->addFlags(new Required(), new ApiAware()),
            (new LongTextField('raw_text', 'rawText'))->addFlags(new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new JsonField('rendered_payload', 'renderedPayload'))->addFlags(new ApiAware()),
            (new StringField('message_type', 'messageType'))->addFlags(new ApiAware()),
            (new JsonField('block_kinds', 'blockKinds'))->addFlags(new ApiAware()),

            (new IntField('token_input', 'tokenInput'))->addFlags(new ApiAware()),
            (new IntField('token_output', 'tokenOutput'))->addFlags(new ApiAware()),
            (new IntField('token_total', 'tokenTotal'))->addFlags(new ApiAware()),
            (new IntField('latency_ms', 'latencyMs'))->addFlags(new ApiAware()),
            (new StringField('model_name', 'modelName'))->addFlags(new ApiAware()),
            (new StringField('request_id', 'requestId'))->addFlags(new ApiAware()),

            (new BoolField('contains_product_suggestions', 'containsProductSuggestions'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('contains_info_box', 'containsInfoBox'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('contains_formular', 'containsFormular'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('contains_tool_payload', 'containsToolPayload'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('is_error', 'isError'))->addFlags(new Required(), new ApiAware()),
            (new JsonField('debug_payload', 'debugPayload'))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('session', 'session_id', ChatSessionDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new OneToManyAssociationField('productReferences', ProductReferenceDefinition::class, 'message_id'))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new Required(), new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),
        ]);
    }
}
