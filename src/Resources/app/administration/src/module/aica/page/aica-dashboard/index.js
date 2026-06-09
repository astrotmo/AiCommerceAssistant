import template from './aica-dashboard.html.twig';
import './aica-dashboard.scss';

const { Filter, Mixin } = Shopware;

export default {
    name: 'aica-dashboard',
    template,

    inject: [
        'aicaDashboardApiService',
        'acl',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isExporting: {
                sessions: false,
                contacts: false,
                messages: false,
            },
            isDeleting: false,
            overview: null,
            filters: {
                preset: 'last30',
                dateFrom: null,
                dateTo: null,
            },
            deleteModalType: null,
            deleteConfirmation: '',
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

        datePresetOptions() {
            return [
                { value: 'today', label: this.$tc('aica.dashboard.filters.presetToday') },
                { value: 'last7', label: this.$tc('aica.dashboard.filters.presetLast7') },
                { value: 'last30', label: this.$tc('aica.dashboard.filters.presetLast30') },
                { value: 'month', label: this.$tc('aica.dashboard.filters.presetMonth') },
                { value: 'custom', label: this.$tc('aica.dashboard.filters.presetCustom') },
            ];
        },

        canDeleteData() {
            return this.acl.can('aica.manager');
        },

        metrics() {
            return this.overview?.metrics || {};
        },

        dataAvailability() {
            return this.overview?.dataAvailability || {};
        },

        topAssistantProducts() {
            return this.overview?.topAssistantProducts || [];
        },

        topUserProducts() {
            return this.overview?.topUserProducts || [];
        },

        rangeLabel() {
            const from = this.normalizeDateBoundary(this.filters.dateFrom, false);
            const to = this.normalizeDateBoundary(this.filters.dateTo, true);

            if (!from || !to) {
                return this.$tc('aica.dashboard.filters.noRange');
            }

            return `${this.$tc('aica.dashboard.filters.range')}: ${from.substring(0, 10)} - ${to.substring(0, 10)}`;
        },

        deleteModalTitle() {
            if (this.deleteModalType === 'sessions') {
                return this.$tc('aica.dashboard.delete.sessionsTitle');
            }

            if (this.deleteModalType === 'contacts') {
                return this.$tc('aica.dashboard.delete.contactsTitle');
            }

            return '';
        },

        deleteButtonDisabled() {
            return this.deleteConfirmation.trim().toUpperCase() !== 'DELETE' || this.isDeleting;
        },

        totalChatsValue() {
            return this.formatInteger(this.metrics.totalChats || 0);
        },

        totalTokenValue() {
            if (!this.dataAvailability.hasTokenData) {
                return this.$tc('aica.dashboard.general.unavailable');
            }

            return this.formatInteger(this.metrics.totalTokens || 0);
        },

        totalTokenSubline() {
            if (!this.dataAvailability.hasTokenData) {
                return this.$tc('aica.dashboard.metrics.tokensUnavailableInfo');
            }

            return `${this.$tc('aica.dashboard.metrics.input')}: ${this.formatInteger(this.metrics.totalInputTokens || 0)} · ${this.$tc('aica.dashboard.metrics.output')}: ${this.formatInteger(this.metrics.totalOutputTokens || 0)}`;
        },

        averageLatencyValue() {
            if (!this.dataAvailability.hasLatencyData) {
                return this.$tc('aica.dashboard.general.unavailable');
            }

            return this.formatLatency(this.metrics.averageResponseLatencyMs);
        },

        averageLatencySubline() {
            if (!this.dataAvailability.hasLatencyData) {
                return this.$tc('aica.dashboard.metrics.latencyUnavailableInfo');
            }

            return `${this.$tc('aica.dashboard.metrics.samples')}: ${this.formatInteger(this.metrics.responseLatencySampleCount || 0)}`;
        },

        contactSubmissionsValue() {
            return this.formatInteger(this.metrics.contactSubmissions || 0);
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            this.applyPresetRange('last30');
            await this.fetchOverview();
        },

        onPresetChange(value) {
            this.filters.preset = value || 'last30';

            if (this.filters.preset !== 'custom') {
                this.applyPresetRange(this.filters.preset);
            }
        },

        onDateFromChange(value) {
            this.filters.dateFrom = value || null;
            this.filters.preset = 'custom';
        },

        onDateToChange(value) {
            this.filters.dateTo = value || null;
            this.filters.preset = 'custom';
        },

        applyPresetRange(preset) {
            const now = new Date();
            const end = this.toDateBoundary(now, true);
            let start = this.toDateBoundary(now, false);

            if (preset === 'today') {
                start = this.toDateBoundary(now, false);
            } else if (preset === 'last7') {
                start = this.toDateBoundary(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6), false);
            } else if (preset === 'last30') {
                start = this.toDateBoundary(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29), false);
            } else if (preset === 'month') {
                start = this.toDateBoundary(new Date(now.getFullYear(), now.getMonth(), 1), false);
            }

            this.filters.dateFrom = start;
            this.filters.dateTo = end;
        },

        async applyFilters() {
            if (this.filters.preset !== 'custom') {
                this.applyPresetRange(this.filters.preset);
            }

            await this.fetchOverview();
        },

        async resetFilters() {
            this.filters.preset = 'last30';
            this.applyPresetRange('last30');
            await this.fetchOverview();
        },

        async fetchOverview() {
            this.isLoading = true;

            try {
                const response = await this.aicaDashboardApiService.fetchOverview(this.buildDateRangePayload());
                this.overview = response?.data || null;
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.dashboard.errors.loadOverview'),
                });
                this.overview = null;
            } finally {
                this.isLoading = false;
            }
        },

        async exportCsv(type) {
            this.isExporting[type] = true;

            try {
                const payload = this.buildDateRangePayload();
                let response;
                let fallbackFileName = 'ai-chat-export.csv';

                if (type === 'sessions') {
                    response = await this.aicaDashboardApiService.exportSessions(payload);
                    fallbackFileName = 'aica-sessions.csv';
                } else if (type === 'contacts') {
                    response = await this.aicaDashboardApiService.exportContacts(payload);
                    fallbackFileName = 'aica-contacts.csv';
                } else {
                    response = await this.aicaDashboardApiService.exportMessages(payload);
                    fallbackFileName = 'aica-messages.csv';
                }

                this.downloadCsvBlob(response?.data, this.resolveFileName(response, fallbackFileName));

                this.createNotificationSuccess({
                    title: this.$tc('aica.general.mainMenuItemGeneral'),
                    message: this.$tc('aica.dashboard.export.success'),
                });
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.dashboard.export.error'),
                });
            } finally {
                this.isExporting[type] = false;
            }
        },

        openDeleteModal(type) {
            if (!this.canDeleteData) {
                return;
            }

            this.deleteModalType = type;
            this.deleteConfirmation = '';
        },

        closeDeleteModal() {
            this.deleteModalType = null;
            this.deleteConfirmation = '';
        },

        async confirmDeletion() {
            if (!this.deleteModalType || this.deleteButtonDisabled) {
                return;
            }

            this.isDeleting = true;

            try {
                const payload = this.buildDateRangePayload();
                let response;

                if (this.deleteModalType === 'sessions') {
                    response = await this.aicaDashboardApiService.deleteSessions(payload);
                } else {
                    response = await this.aicaDashboardApiService.deleteContacts(payload);
                }

                const result = response?.data || {};

                this.createNotificationSuccess({
                    title: this.$tc('aica.general.mainMenuItemGeneral'),
                    message: this.buildDeletionSuccessMessage(this.deleteModalType, result),
                });

                this.closeDeleteModal();
                await this.fetchOverview();
            } catch {
                this.createNotificationError({
                    title: this.$tc('aica.general.errorTitle'),
                    message: this.$tc('aica.dashboard.delete.error'),
                });
            } finally {
                this.isDeleting = false;
            }
        },

        buildDeletionSuccessMessage(type, result) {
            if (type === 'sessions') {
                const sessionsDeleted = this.formatInteger(result.sessionsDeleted || 0);
                const messagesDeleted = this.formatInteger(result.relatedMessagesDeleted || 0);

                return `${this.$tc('aica.dashboard.delete.sessionsDeleted')}: ${sessionsDeleted} (${this.$tc('aica.dashboard.delete.relatedMessages')}: ${messagesDeleted})`;
            }

            return `${this.$tc('aica.dashboard.delete.contactsDeleted')}: ${this.formatInteger(result.contactsDeleted || 0)}`;
        },

        buildDateRangePayload() {
            const from = this.normalizeDateBoundary(this.filters.dateFrom, false);
            const to = this.normalizeDateBoundary(this.filters.dateTo, true);

            return {
                from,
                to,
            };
        },

        normalizeDateBoundary(value, endOfDay) {
            if (!value) {
                return null;
            }

            if (value instanceof Date) {
                return this.formatDateBoundary(value, endOfDay);
            }

            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (!trimmed) {
                    return null;
                }

                const datePart = trimmed.substring(0, 10);
                return `${datePart} ${endOfDay ? '23:59:59' : '00:00:00'}`;
            }

            return null;
        },

        formatDateBoundary(value, endOfDay) {
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            const time = endOfDay ? '23:59:59' : '00:00:00';

            return `${year}-${month}-${day} ${time}`;
        },

        toDateBoundary(value, endOfDay) {
            const boundary = new Date(value);
            if (endOfDay) {
                boundary.setHours(23, 59, 59, 0);
            } else {
                boundary.setHours(0, 0, 0, 0);
            }

            return boundary;
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

        formatInteger(value) {
            const number = Number(value);
            if (!Number.isFinite(number)) {
                return this.$tc('aica.general.na');
            }

            return Math.round(number).toLocaleString();
        },

        formatLatency(value) {
            const number = Number(value);
            if (!Number.isFinite(number)) {
                return this.$tc('aica.general.na');
            }

            return `${Math.round(number)}ms`;
        },

        resolveProductLabel(entry) {
            if (!entry) {
                return this.$tc('aica.general.na');
            }

            return entry.label || entry.productName || entry.productNumber || entry.referenceText || entry.productId || this.$tc('aica.general.na');
        },

        resolveSourceLabel(source) {
            if (!source) {
                return this.$tc('aica.dashboard.products.sourceOther');
            }

            if (source === 'assistant' || source === 'tool') {
                return this.$tc('aica.dashboard.products.sourceAssistant');
            }

            if (source === 'user' || source === 'contact') {
                return this.$tc('aica.dashboard.products.sourceUser');
            }

            return this.$tc('aica.dashboard.products.sourceOther');
        },

        resolveFileName(response, fallback) {
            const header = response?.headers?.['content-disposition'] || '';
            const match = /filename=\"?([^\";]+)\"?/i.exec(header);

            if (match && match[1]) {
                return match[1];
            }

            return fallback;
        },

        downloadCsvBlob(blobData, fileName) {
            const blob = blobData instanceof Blob
                ? blobData
                : new Blob([blobData || ''], { type: 'text/csv;charset=utf-8;' });

            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', fileName || 'export.csv');
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        },
    },
};
