<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ChatSession;

use AiCommerceAssistant\Core\Content\ChatMessage\ChatMessageDefinition;
use AiCommerceAssistant\Core\Content\ContactSubmission\ContactSubmissionDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

class ChatSessionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'aica_chat_session';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ChatSessionCollection::class;
    }

    public function getEntityClass(): string
    {
        return ChatSessionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),

            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new ApiAware()),
            (new FkField('language_id', 'languageId', LanguageDefinition::class))->addFlags(new ApiAware()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new ApiAware()),

            (new StringField('storefront_session_identifier', 'storefrontSessionIdentifier'))
                ->addFlags(new Required(), new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('guest_fingerprint_hash', 'guestFingerprintHash'))->addFlags(new ApiAware()),
            (new StringField('hashed_ip', 'hashedIp'))->addFlags(new ApiAware()),
            (new StringField('hashed_user_agent', 'hashedUserAgent'))->addFlags(new ApiAware()),
            (new StringField('raw_ip', 'rawIp'))->addFlags(new ApiAware()),
            (new StringField('user_agent_raw', 'userAgentRaw'))->addFlags(new ApiAware()),
            (new StringField('model_name', 'modelName'))->addFlags(new ApiAware()),

            (new IntField('total_input_tokens', 'totalInputTokens'))->addFlags(new ApiAware()),
            (new IntField('total_output_tokens', 'totalOutputTokens'))->addFlags(new ApiAware()),
            (new IntField('total_tokens', 'totalTokens'))->addFlags(new ApiAware()),
            (new IntField('total_message_count', 'totalMessageCount'))->addFlags(new Required(), new ApiAware()),

            (new DateTimeField('first_message_at', 'firstMessageAt'))->addFlags(new ApiAware()),
            (new DateTimeField('last_message_at', 'lastMessageAt'))->addFlags(new ApiAware()),
            (new FloatField('average_response_latency_ms', 'averageResponseLatencyMs'))->addFlags(new ApiAware()),
            (new IntField('response_latency_sample_count', 'responseLatencySampleCount'))->addFlags(new Required(), new ApiAware()),

            (new BoolField('contact_form_shown', 'contactFormShown'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('contact_form_submitted', 'contactFormSubmitted'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('debug_enabled_snapshot', 'debugEnabledSnapshot'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('store_raw_debug_payloads_snapshot', 'storeRawDebugPayloadsSnapshot'))->addFlags(new Required(), new ApiAware()),

            (new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('language', 'language_id', LanguageDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),

            (new OneToManyAssociationField('messages', ChatMessageDefinition::class, 'session_id'))->addFlags(new ApiAware()),
            (new OneToManyAssociationField('contactSubmissions', ContactSubmissionDefinition::class, 'session_id'))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new Required(), new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),
        ]);
    }
}
