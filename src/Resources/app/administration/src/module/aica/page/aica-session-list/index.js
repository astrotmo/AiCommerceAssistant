import template from './aica-session-list.html.twig';
import './aica-session-list.scss';

const { Criteria } = Shopware.Data;
const { Filter, Utils, Mixin } = Shopware;

export default {
    name: 'aica-session-list',
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
            sessions: [],
            isLoading: false,
            page: 1,
            limit: 25,
            total: 0,
            sortBy: 'lastMessageAt',
            sortDirection: 'DESC',

            filters: {
                searchTerm: '',
                dateFrom: null,
                dateTo: null,
                customerType: 'all',
                contactSubmitted: 'all',
                debugOnly: false,
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

        sessionRepository() {
            return this.repositoryFactory.create('aica_chat_session');
        },

        messageRepository() {
            return this.repositoryFactory.create('aica_chat_message');
        },

        customerTypeOptions() {
            return [
                { value: 'all', label: this.$tc('aica.list.filters.customerTypeAll') },
                { value: 'customer', label: this.$tc('aica.list.filters.customerTypeCustomer') },
                { value: 'guest', label: this.$tc('aica.list.filters.customerTypeGuest') },
            ];
        },

        contactSubmittedOptions() {
            return [
                { value: 'all', label: this.$tc('aica.list.filters.contactAll') },
                { value: 'yes', label: this.$tc('aica.list.filters.contactYes') },
                { value: 'no', label: this.$tc('aica.list.filters.contactNo') },
            ];
        },

        columns() {
            return [
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: 'aica.list.columns.createdAt',
                    sortable: true,
                    width: '180px',
                    primary: true,
                },
                {
                    property: 'lastMessageAt',
                    dataIndex: 'lastMessageAt',
                    label: 'aica.list.columns.lastActivity',
                    sortable: true,
                    width: '180px',
                },
                {
                    property: 'participant',
                    dataIndex: 'participant',
                    label: 'aica.list.columns.participant',
                    sortable: false,
                    width: '270px',
                },
                {
                    property: 'totalMessageCount',
                    dataIndex: 'totalMessageCount',
                    label: 'aica.list.columns.messageCount',
                    sortable: true,
                    width: '130px',
                },
                {
                    property: 'totalTokens',
                    dataIndex: 'totalTokens',
                    label: 'aica.list.columns.totalTokens',
                    sortable: true,
                    width: '130px',
                },
                {
                    property: 'modelName',
                    dataIndex: 'modelName',
                    label: 'aica.list.columns.model',
                    sortable: true,
                    width: '180px',
                },
                {
                    property: 'contactFormSubmitted',
                    dataIndex: 'contactFormSubmitted',
                    label: 'aica.list.columns.contactSubmitted',
                    sortable: true,
                    width: '160px',
                },
                {
                    property: 'debug',
                    dataIndex: 'debug',
                    label: 'aica.list.columns.debug',
                    sortable: false,
                    width: '110px',
                },
                {
                    property: 'averageResponseLatencyMs',
                    dataIndex: 'averageResponseLatencyMs',
                    label: 'aica.list.columns.averageLatency',
                    sortable: true,
                    width: '160px',
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
                const term = this.normalizeTerm(this.filters.searchTerm);
                const messageSessionIds = term ? await this.findSessionIdsByMessageTerm(term) : [];

                const criteria = this.buildCriteria(messageSessionIds);
                const result = await this.sessionRepository.search(criteria, Shopware.Context.api);

                this.sessions = result;
                this.total = result.total;
                this.page = result.criteria.page;
                this.limit = result.criteria.limit;
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.list.errors.loadSessions'),
                });

                this.sessions = [];
                this.total = 0;
            } finally {
                this.isLoading = false;
            }
        },

        buildCriteria(messageSessionIds) {
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('customer');
            criteria.addAssociation('salesChannel');
            criteria.addAssociation('language');

            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            if (this.sortBy !== 'lastMessageAt') {
                criteria.addSorting(Criteria.sort('lastMessageAt', 'DESC'));
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

            if (this.filters.customerType === 'customer') {
                criteria.addFilter(Criteria.not('AND', [Criteria.equals('customerId', null)]));
            } else if (this.filters.customerType === 'guest') {
                criteria.addFilter(Criteria.equals('customerId', null));
            }

            if (this.filters.contactSubmitted === 'yes') {
                criteria.addFilter(Criteria.equals('contactFormSubmitted', true));
            } else if (this.filters.contactSubmitted === 'no') {
                criteria.addFilter(Criteria.equals('contactFormSubmitted', false));
            }

            if (this.filters.debugOnly) {
                criteria.addFilter(Criteria.multi('OR', [
                    Criteria.equals('debugEnabledSnapshot', true),
                    Criteria.not('AND', [Criteria.equals('rawIp', null)]),
                    Criteria.not('AND', [Criteria.equals('userAgentRaw', null)]),
                    Criteria.equals('storeRawDebugPayloadsSnapshot', true),
                ]));
            }

            const term = this.normalizeTerm(this.filters.searchTerm);
            if (term) {
                const searchFilters = [
                    Criteria.contains('storefrontSessionIdentifier', term),
                    Criteria.contains('guestFingerprintHash', term),
                    Criteria.contains('modelName', term),
                    Criteria.contains('customer.firstName', term),
                    Criteria.contains('customer.lastName', term),
                    Criteria.contains('customer.email', term),
                ];

                if (messageSessionIds.length > 0) {
                    searchFilters.push(Criteria.equalsAny('id', messageSessionIds));
                }

                const compactUuidTerm = term.replace(/-/g, '');
                if (Utils.types.isString(compactUuidTerm) && compactUuidTerm.length === 32) {
                    searchFilters.push(Criteria.equals('id', compactUuidTerm));
                }

                criteria.addFilter(Criteria.multi('OR', searchFilters));
            }

            return criteria;
        },

        async findSessionIdsByMessageTerm(term) {
            const criteria = new Criteria(1, 200);
            criteria.addFilter(Criteria.contains('rawText', term));
            criteria.addFilter(Criteria.not('AND', [Criteria.equals('sessionId', null)]));

            try {
                const result = await this.messageRepository.search(criteria, Shopware.Context.api);

                const ids = [];
                const seen = {};

                result.forEach((message) => {
                    const sessionId = message.sessionId;
                    if (!sessionId || seen[sessionId]) {
                        return;
                    }

                    seen[sessionId] = true;
                    ids.push(sessionId);
                });

                return ids;
            } catch {
                return [];
            }
        },

        onSortColumn(data) {
            const nextSortBy = data?.dataIndex || data?.property || this.sortBy;
            const nextSortDirection = data?.sortDirection || this.sortDirection;

            this.sortBy = nextSortBy;
            this.sortDirection = nextSortDirection;
            this.getList();
        },

        onPageChange(data) {
            if (typeof data === 'number') {
                this.page = data;
            } else if (data && typeof data === 'object') {
                this.page = data.page || this.page;
                this.limit = data.limit || this.limit;
            }

            this.getList();
        },

        onDateFromChange(value) {
            this.filters.dateFrom = value;
        },

        onDateToChange(value) {
            this.filters.dateTo = value;
        },

        onCustomerTypeChange(value) {
            this.filters.customerType = value;
        },

        onContactSubmittedChange(value) {
            this.filters.contactSubmitted = value;
        },

        onDebugOnlyChange(value) {
            this.filters.debugOnly = Boolean(value);
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
                customerType: 'all',
                contactSubmitted: 'all',
                debugOnly: false,
            };

            this.page = 1;
            this.getList();
        },

        normalizeTerm(value) {
            if (!Utils.types.isString(value)) {
                return '';
            }

            return value.trim();
        },

        normalizeDateBoundary(value, endOfDay) {
            if (!value) {
                return null;
            }

            if (value instanceof Date) {
                const year = value.getFullYear();
                const month = String(value.getMonth() + 1).padStart(2, '0');
                const day = String(value.getDate()).padStart(2, '0');
                const time = endOfDay ? '23:59:59' : '00:00:00';

                return `${year}-${month}-${day} ${time}`;
            }

            if (Utils.types.isString(value)) {
                const trimmed = value.trim();
                if (trimmed.length === 0) {
                    return null;
                }

                const datePart = trimmed.substring(0, 10);
                const time = endOfDay ? '23:59:59' : '00:00:00';

                return `${datePart} ${time}`;
            }

            return null;
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

        formatLatency(value) {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                return this.$tc('aica.general.na');
            }

            return `${Math.round(value)}ms`;
        },

        formatTokens(value) {
            if (typeof value !== 'number' || !Number.isFinite(value)) {
                return this.$tc('aica.general.na');
            }

            return value.toLocaleString();
        },

        getParticipantLabel(session) {
            if (session.customer) {
                const firstName = session.customer.firstName || '';
                const lastName = session.customer.lastName || '';
                const name = `${firstName} ${lastName}`.trim();

                return name || session.customer.email || this.$tc('aica.general.customer');
            }

            return this.$tc('aica.general.guest');
        },

        getParticipantSubline(session) {
            if (session.customer) {
                return session.customer.email || this.$tc('aica.general.na');
            }

            if (session.guestFingerprintHash) {
                return `${this.$tc('aica.list.columns.guestFingerprint')}: ${session.guestFingerprintHash.substring(0, 14)}...`;
            }

            return this.$tc('aica.general.na');
        },

        hasDebugData(session) {
            return Boolean(
                session.debugEnabledSnapshot
                || session.rawIp
                || session.userAgentRaw
                || session.storeRawDebugPayloadsSnapshot,
            );
        },

        toDetail(sessionId) {
            this.$router.push({
                name: 'aica.detail',
                params: { id: sessionId },
            });
        },
    },
};
