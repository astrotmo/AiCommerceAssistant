import template from './aica-session-detail.html.twig';
import './aica-session-detail.scss';

const { Criteria } = Shopware.Data;
const { Filter, Mixin } = Shopware;

export default {
    name: 'aica-session-detail',
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        id: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            isLoading: false,
            session: null,
            messages: [],
            productReferences: [],
            contactSubmissions: [],
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        dateFilter() {
            return Filter.getByName('date');
        },

        sessionRepository() {
            return this.repositoryFactory.create('aica_chat_session');
        },

        messageRepository() {
            return this.repositoryFactory.create('aica_chat_message');
        },

        productReferenceRepository() {
            return this.repositoryFactory.create('aica_product_reference');
        },

        contactSubmissionRepository() {
            return this.repositoryFactory.create('aica_contact_submission');
        },

        sessionId() {
            return (this.id || this.$route.params.id || '').toLowerCase();
        },

        participantTitle() {
            if (!this.session) {
                return this.$tc('aica.general.na');
            }

            if (this.session.customer) {
                const firstName = this.session.customer.firstName || '';
                const lastName = this.session.customer.lastName || '';
                const name = `${firstName} ${lastName}`.trim();

                return name || this.session.customer.email || this.$tc('aica.general.customer');
            }

            return this.$tc('aica.general.guest');
        },

        participantSubline() {
            if (!this.session) {
                return this.$tc('aica.general.na');
            }

            if (this.session.customer) {
                return this.session.customer.email || this.$tc('aica.general.na');
            }

            if (this.session.guestFingerprintHash) {
                return `${this.$tc('aica.detail.metadata.guestFingerprint')}: ${this.session.guestFingerprintHash}`;
            }

            return this.$tc('aica.general.na');
        },

        shortSessionId() {
            if (!this.session || !this.session.id) {
                return this.$tc('aica.general.na');
            }

            const value = this.session.id;
            if (value.length <= 14) {
                return value;
            }

            return `${value.substring(0, 14)}...`;
        },

        hasMessageDebugData() {
            return this.messages.some((message) => message.debugPayload != null);
        },

        hasSessionDebugData() {
            if (!this.session) {
                return false;
            }

            return Boolean(
                this.session.debugEnabledSnapshot
                || this.session.storeRawDebugPayloadsSnapshot
                || this.session.rawIp
                || this.session.userAgentRaw,
            );
        },

        canViewDebugData() {
            return this.acl.can('aica_debug.viewer');
        },

        showDebugSection() {
            return this.canViewDebugData && (this.hasSessionDebugData || this.hasMessageDebugData);
        },

        userProductReferences() {
            return this.aggregateReferences(['user', 'contact']);
        },

        assistantProductReferences() {
            return this.aggregateReferences(['assistant', 'tool']);
        },

        otherProductReferences() {
            return this.aggregateReferencesByFilter((source) => !['user', 'contact', 'assistant', 'tool'].includes(source));
        },

        hasProductReferences() {
            return this.productReferences.length > 0;
        },
    },

    watch: {
        sessionId() {
            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            if (!this.sessionId) {
                this.session = null;
                this.messages = [];
                this.productReferences = [];
                this.contactSubmissions = [];

                return;
            }

            this.isLoading = true;

            try {
                const [session, messages, productReferences, contactSubmissions] = await Promise.all([
                    this.loadSession(),
                    this.loadMessages(),
                    this.loadProductReferences(),
                    this.loadContactSubmissions(),
                ]);

                this.session = session;
                this.messages = messages;
                this.productReferences = productReferences;
                this.contactSubmissions = contactSubmissions;
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.detail.errors.loadSession'),
                });

                this.session = null;
                this.messages = [];
                this.productReferences = [];
                this.contactSubmissions = [];
            } finally {
                this.isLoading = false;
            }
        },

        async loadSession() {
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('id', this.sessionId));
            criteria.addAssociation('customer');
            criteria.addAssociation('salesChannel');
            criteria.addAssociation('language');

            const result = await this.sessionRepository.search(criteria, Shopware.Context.api);

            return result.first() || null;
        },

        async loadMessages() {
            return this.loadBySessionPaged(this.messageRepository, (criteria) => {
                criteria.addSorting(Criteria.sort('messageIndex', 'ASC'));
                criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
            });
        },

        async loadProductReferences() {
            return this.loadBySessionPaged(this.productReferenceRepository, (criteria) => {
                criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
            });
        },

        async loadContactSubmissions() {
            return this.loadBySessionPaged(this.contactSubmissionRepository, (criteria) => {
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            });
        },

        // Loads all entities for one session in pages so full conversations stay visible.
        async loadBySessionPaged(repository, configureCriteria, pageSize = 250) {
            const entities = [];
            let page = 1;

            while (true) {
                const criteria = new Criteria(page, pageSize);
                criteria.addFilter(Criteria.equals('sessionId', this.sessionId));
                configureCriteria(criteria);

                const result = await repository.search(criteria, Shopware.Context.api);
                result.forEach((entity) => entities.push(entity));

                if (result.length < pageSize) {
                    break;
                }

                page += 1;
            }

            return entities;
        },

        aggregateReferences(sources) {
            const allowed = new Set(sources.map((source) => String(source).toLowerCase()));

            return this.aggregateReferencesByFilter((source) => allowed.has(source));
        },

        aggregateReferencesByFilter(filter) {
            const entries = new Map();

            this.productReferences.forEach((reference) => {
                const source = String(reference.source || '').toLowerCase();
                if (!filter(source)) {
                    return;
                }

                const key = [
                    source,
                    reference.productId || '',
                    reference.productNumber || '',
                    reference.productName || '',
                    reference.referenceText || '',
                ].join('|');

                if (!entries.has(key)) {
                    entries.set(key, {
                        source,
                        productId: reference.productId || null,
                        productNumber: reference.productNumber || null,
                        productName: reference.productName || null,
                        referenceText: reference.referenceText || null,
                        count: 0,
                    });
                }

                const current = entries.get(key);
                current.count += 1;
            });

            return Array.from(entries.values());
        },

        formatDate(value) {
            if (!value) {
                return this.$tc('aica.general.na');
            }

            return this.dateFilter(value, {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                day: '2-digit',
                month: '2-digit',
                year: '2-digit',
            });
        },

        formatNullableNumber(value) {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                return this.$tc('aica.general.na');
            }

            return value.toLocaleString();
        },

        formatNullableLatency(value) {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                return this.$tc('aica.general.na');
            }

            return `${Math.round(value)}ms`;
        },

        formatBoolean(value) {
            return value ? this.$tc('aica.general.yes') : this.$tc('aica.general.no');
        },

        formatMailStatus(sent, attempted) {
            if (sent) {
                return this.$tc('aica.contactList.status.sent');
            }

            if (attempted) {
                return this.$tc('aica.contactList.status.failed');
            }

            return this.$tc('aica.contactList.status.notAttempted');
        },

        formatReferenceLine(reference) {
            const parts = [];

            if (reference.productName) {
                parts.push(reference.productName);
            }

            if (reference.productNumber) {
                parts.push(reference.productNumber);
            }

            if (reference.productId) {
                parts.push(reference.productId);
            }

            if (parts.length > 0) {
                return parts.join(' | ');
            }

            return reference.referenceText || this.$tc('aica.general.na');
        },

        formatSubmissionSender(submission) {
            const name = (submission.name || '').trim();
            if (name) {
                return `${name} (${submission.email})`;
            }

            return submission.email;
        },

        resolveSalesChannelLabel() {
            if (!this.session) {
                return this.$tc('aica.general.na');
            }

            if (this.session.salesChannel && this.session.salesChannel.name) {
                return this.session.salesChannel.name;
            }

            return this.session.salesChannelId || this.$tc('aica.general.na');
        },

        resolveLanguageLabel() {
            if (!this.session) {
                return this.$tc('aica.general.na');
            }

            if (this.session.language && this.session.language.name) {
                return this.session.language.name;
            }

            return this.session.languageId || this.$tc('aica.general.na');
        },

        goBack() {
            this.$router.push({ name: 'aica.index' });
        },
    },
};
