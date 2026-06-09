<?php declare(strict_types=1);

namespace AiCommerceAssistant\Core\Content\ContactSubmission;

use AiCommerceAssistant\Core\Content\ChatSession\ChatSessionDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ContactSubmissionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'aica_contact_submission';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return ContactSubmissionCollection::class;
    }

    public function getEntityClass(): string
    {
        return ContactSubmissionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),

            (new FkField('session_id', 'sessionId', ChatSessionDefinition::class))->addFlags(new ApiAware()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new ApiAware()),

            (new StringField('email', 'email'))->addFlags(new Required(), new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('name', 'name'))->addFlags(new ApiAware()),
            (new StringField('phone', 'phone'))->addFlags(new ApiAware()),
            (new StringField('company', 'company'))->addFlags(new ApiAware()),
            (new LongTextField('message_text', 'messageText'))->addFlags(new Required(), new ApiAware(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('product_reference_text', 'productReferenceText'))->addFlags(new ApiAware()),
            (new StringField('source_context', 'sourceContext'))->addFlags(new ApiAware()),
            (new StringField('mail_target_address', 'mailTargetAddress'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('confirmation_mail_attempted', 'confirmationMailAttempted'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('confirmation_mail_sent', 'confirmationMailSent'))->addFlags(new Required(), new ApiAware()),
            (new LongTextField('confirmation_mail_error', 'confirmationMailError'))->addFlags(new ApiAware()),
            (new BoolField('internal_notification_mail_attempted', 'internalNotificationMailAttempted'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('internal_notification_mail_sent', 'internalNotificationMailSent'))->addFlags(new Required(), new ApiAware()),
            (new LongTextField('internal_notification_mail_error', 'internalNotificationMailError'))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('session', 'session_id', ChatSessionDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false))->addFlags(new ApiAware()),

            (new CreatedAtField())->addFlags(new Required(), new ApiAware()),
            (new UpdatedAtField())->addFlags(new ApiAware()),
        ]);
    }
}
