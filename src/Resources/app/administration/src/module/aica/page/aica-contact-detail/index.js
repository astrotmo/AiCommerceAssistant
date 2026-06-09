import template from './aica-contact-detail.html.twig';
import './aica-contact-detail.scss';

const { Criteria } = Shopware.Data;
const { Filter, Mixin } = Shopware;

export default {
    name: 'aica-contact-detail',
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
            submission: null,
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

        contactRepository() {
            return this.repositoryFactory.create('aica_contact_submission');
        },

        submissionId() {
            return (this.id || this.$route.params.id || '').toLowerCase();
        },

        canOpenSession() {
            return Boolean(this.submission && this.submission.sessionId);
        },

        customerLabel() {
            if (!this.submission || !this.submission.customer) {
                return this.$tc('aica.general.guest');
            }

            const firstName = this.submission.customer.firstName || '';
            const lastName = this.submission.customer.lastName || '';
            const fullName = `${firstName} ${lastName}`.trim();

            return fullName || this.submission.customer.email || this.submission.customer.id;
        },
    },

    watch: {
        submissionId() {
            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            if (!this.submissionId) {
                this.submission = null;

                return;
            }

            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('id', this.submissionId));
                criteria.addAssociation('customer');
                criteria.addAssociation('session');
                criteria.addAssociation('session.customer');

                const result = await this.contactRepository.search(criteria, Shopware.Context.api);
                this.submission = result.first() || null;
            } catch {
                this.submission = null;
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.contactDetail.errors.loadSubmission'),
                });
            } finally {
                this.isLoading = false;
            }
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

        statusVariant(sent, attempted) {
            if (sent) {
                return 'success';
            }

            if (attempted) {
                return 'danger';
            }

            return 'neutral';
        },

        statusLabel(sent, attempted) {
            if (sent) {
                return this.$tc('aica.contactList.status.sent');
            }

            if (attempted) {
                return this.$tc('aica.contactList.status.failed');
            }

            return this.$tc('aica.contactList.status.notAttempted');
        },

        toSession() {
            if (!this.submission || !this.submission.sessionId) {
                return;
            }

            this.$router.push({
                name: 'aica.detail',
                params: {
                    id: this.submission.sessionId,
                },
            });
        },

        goBack() {
            this.$router.push({ name: 'aica.contact' });
        },
    },
};
