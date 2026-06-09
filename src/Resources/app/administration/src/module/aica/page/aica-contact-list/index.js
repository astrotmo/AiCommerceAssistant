import template from './aica-contact-list.html.twig';
import './aica-contact-list.scss';

const { Criteria } = Shopware.Data;
const { Filter, Utils, Mixin } = Shopware;

function compactUuid(value) {
    return String(value || '').replace(/-/g, '').trim().toLowerCase();
}

export default {
    name: 'aica-contact-list',
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            submissions: [],
            isLoading: false,
            page: 1,
            limit: 25,
            total: 0,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            filters: {
                searchTerm: '',
                dateFrom: null,
                dateTo: null,
                hasSession: 'all',
                internalStatus: 'all',
                confirmationStatus: 'all',
            },
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

        hasSessionOptions() {
            return [
                { value: 'all', label: this.$tc('aica.contactList.filters.hasSessionAll') },
                { value: 'yes', label: this.$tc('aica.contactList.filters.hasSessionYes') },
                { value: 'no', label: this.$tc('aica.contactList.filters.hasSessionNo') },
            ];
        },

        statusOptions() {
            return [
                { value: 'all', label: this.$tc('aica.contactList.filters.statusAll') },
                { value: 'sent', label: this.$tc('aica.contactList.filters.statusSent') },
                { value: 'failed', label: this.$tc('aica.contactList.filters.statusFailed') },
                { value: 'notAttempted', label: this.$tc('aica.contactList.filters.statusNotAttempted') },
            ];
        },

        columns() {
            return [
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: 'aica.contactList.columns.createdAt',
                    sortable: true,
                    width: '180px',
                    primary: true,
                },
                {
                    property: 'sender',
                    dataIndex: 'sender',
                    label: 'aica.contactList.columns.sender',
                    sortable: false,
                    width: '260px',
                },
                {
                    property: 'customer',
                    dataIndex: 'customer',
                    label: 'aica.contactList.columns.customer',
                    sortable: false,
                    width: '220px',
                },
                {
                    property: 'sessionId',
                    dataIndex: 'sessionId',
                    label: 'aica.contactList.columns.session',
                    sortable: false,
                    width: '180px',
                },
                {
                    property: 'internalNotificationMailSent',
                    dataIndex: 'internalNotificationMailSent',
                    label: 'aica.contactList.columns.internalStatus',
                    sortable: true,
                    width: '170px',
                },
                {
                    property: 'confirmationMailSent',
                    dataIndex: 'confirmationMailSent',
                    label: 'aica.contactList.columns.confirmationStatus',
                    sortable: true,
                    width: '180px',
                },
                {
                    property: 'mailTargetAddress',
                    dataIndex: 'mailTargetAddress',
                    label: 'aica.contactList.columns.target',
                    sortable: false,
                    width: '220px',
                },
            ];
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            await this.getList();
        },

        async getList() {
            this.isLoading = true;

            try {
                const criteria = this.buildCriteria();
                const result = await this.contactRepository.search(criteria, Shopware.Context.api);

                this.submissions = result;
                this.total = result.total;
                this.page = result.criteria.page;
                this.limit = result.criteria.limit;
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.contactList.errors.loadSubmissions'),
                });

                this.submissions = [];
                this.total = 0;
            } finally {
                this.isLoading = false;
            }
        },

        buildCriteria() {
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('customer');
            criteria.addAssociation('session');
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            if (this.sortBy !== 'createdAt') {
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            }

            const dateFrom = this.normalizeDateBoundary(this.filters.dateFrom, false);
            const dateTo = this.normalizeDateBoundary(this.filters.dateTo, true);
            if (dateFrom || dateTo) {
                const range = {};

                if (dateFrom) {
                    range.gte = dateFrom;
                }
                if (dateTo) {
                    range.lte = dateTo;
                }

                criteria.addFilter(Criteria.range('createdAt', range));
            }

            if (this.filters.hasSession === 'yes') {
                criteria.addFilter(Criteria.not('AND', [Criteria.equals('sessionId', null)]));
            } else if (this.filters.hasSession === 'no') {
                criteria.addFilter(Criteria.equals('sessionId', null));
            }

            this.applyStatusFilter(criteria, this.filters.internalStatus, 'internalNotificationMailAttempted', 'internalNotificationMailSent');
            this.applyStatusFilter(criteria, this.filters.confirmationStatus, 'confirmationMailAttempted', 'confirmationMailSent');

            const term = this.normalizeTerm(this.filters.searchTerm);
            if (term) {
                const searchFilters = [
                    Criteria.contains('email', term),
                    Criteria.contains('name', term),
                    Criteria.contains('messageText', term),
                    Criteria.contains('productReferenceText', term),
                    Criteria.contains('sourceContext', term),
                    Criteria.contains('mailTargetAddress', term),
                    Criteria.contains('customer.firstName', term),
                    Criteria.contains('customer.lastName', term),
                    Criteria.contains('customer.email', term),
                ];

                const compact = compactUuid(term);
                if (Utils.types.isString(compact) && compact.length === 32) {
                    searchFilters.push(Criteria.equals('id', compact));
                    searchFilters.push(Criteria.equals('sessionId', compact));
                    searchFilters.push(Criteria.equals('customerId', compact));
                }

                criteria.addFilter(Criteria.multi('OR', searchFilters));
            }

            return criteria;
        },

        applyStatusFilter(criteria, statusValue, attemptedField, sentField) {
            if (statusValue === 'sent') {
                criteria.addFilter(Criteria.equals(sentField, true));
                return;
            }

            if (statusValue === 'failed') {
                criteria.addFilter(Criteria.equals(attemptedField, true));
                criteria.addFilter(Criteria.equals(sentField, false));
                return;
            }

            if (statusValue === 'notAttempted') {
                criteria.addFilter(Criteria.equals(attemptedField, false));
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

        formatSender(item) {
            const name = (item.name || '').trim();
            if (name) {
                return `${name} (${item.email})`;
            }

            return item.email || this.$tc('aica.general.na');
        },

        formatCustomer(item) {
            if (!item.customer) {
                return this.$tc('aica.general.guest');
            }

            const firstName = item.customer.firstName || '';
            const lastName = item.customer.lastName || '';
            const fullName = `${firstName} ${lastName}`.trim();

            return fullName || item.customer.email || item.customer.id;
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

        applyFilters() {
            this.page = 1;
            this.getList();
        },

        resetFilters() {
            this.filters = {
                searchTerm: '',
                dateFrom: null,
                dateTo: null,
                hasSession: 'all',
                internalStatus: 'all',
                confirmationStatus: 'all',
            };

            this.page = 1;
            this.getList();
        },

        onDateFromChange(value) {
            this.filters.dateFrom = value || null;
        },

        onDateToChange(value) {
            this.filters.dateTo = value || null;
        },

        onHasSessionChange(value) {
            this.filters.hasSession = value || 'all';
        },

        onInternalStatusChange(value) {
            this.filters.internalStatus = value || 'all';
        },

        onConfirmationStatusChange(value) {
            this.filters.confirmationStatus = value || 'all';
        },

        onSortColumn({ dataIndex, sortDirection }) {
            if (!dataIndex) {
                return;
            }

            this.sortBy = dataIndex;
            this.sortDirection = sortDirection || 'DESC';
            this.getList();
        },

        onPageChange({ page = 1, limit = this.limit }) {
            this.page = page;
            this.limit = limit;
            this.getList();
        },

        normalizeDateBoundary(value, endOfDay) {
            if (!value) {
                return null;
            }

            const raw = String(value);
            if (!raw.includes('T')) {
                return `${raw}${endOfDay ? 'T23:59:59.999' : 'T00:00:00.000'}`;
            }

            return raw;
        },

        normalizeTerm(value) {
            if (!Utils.types.isString(value)) {
                return '';
            }

            return value.trim();
        },

        toDetail(id) {
            this.$router.push({ name: 'aica.contactDetail', params: { id } });
        },
    },
};
