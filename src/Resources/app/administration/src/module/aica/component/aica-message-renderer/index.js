import template from './aica-message-renderer.html.twig';
import './aica-message-renderer.scss';

const { Filter } = Shopware;

export default {
    name: 'aica-message-renderer',
    template,

    props: {
        message: {
            type: Object,
            required: true,
        },

        showDebug: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    computed: {
        dateFilter() {
            return Filter.getByName('date');
        },

        role() {
            return (this.message.role || 'assistant').toLowerCase();
        },

        roleClass() {
            if (this.role === 'user') {
                return 'is-user';
            }

            if (this.role === 'system') {
                return 'is-system';
            }

            if (this.role === 'tool') {
                return 'is-tool';
            }

            return 'is-assistant';
        },

        parsedPayload() {
            const payload = this.message.renderedPayload;
            if (!payload) {
                return null;
            }

            if (typeof payload === 'object') {
                return payload;
            }

            if (typeof payload === 'string') {
                try {
                    return JSON.parse(payload);
                } catch {
                    return null;
                }
            }

            return null;
        },

        blocks() {
            if (!this.parsedPayload || !Array.isArray(this.parsedPayload.blocks)) {
                return [];
            }

            return this.parsedPayload.blocks;
        },

        fallbackText() {
            if (typeof this.message.rawText === 'string' && this.message.rawText.trim() !== '') {
                return this.message.rawText;
            }

            if (!this.parsedPayload) {
                return '';
            }

            const fromPayload = this.parsedPayload.reply || this.parsedPayload.message || this.parsedPayload.text;
            if (typeof fromPayload === 'string') {
                return fromPayload;
            }

            return '';
        },

        hasPayloadDebug() {
            return this.showDebug && this.message.debugPayload != null;
        },

        messageTokenInput() {
            return this.resolveMessageNumber(this.message.tokenInput, this.parsedPayload?.meta?.inputTokens);
        },

        messageTokenOutput() {
            return this.resolveMessageNumber(this.message.tokenOutput, this.parsedPayload?.meta?.outputTokens);
        },

        messageTokenTotal() {
            return this.resolveMessageNumber(this.message.tokenTotal, this.parsedPayload?.meta?.totalTokens);
        },

        messageLatencyMs() {
            return this.resolveMessageNumber(this.message.latencyMs, this.parsedPayload?.meta?.latencyMs);
        },

        messageTokenInputDisplay() {
            return this.messageTokenInput !== null ? this.messageTokenInput : 0;
        },

        messageTokenOutputDisplay() {
            return this.messageTokenOutput !== null ? this.messageTokenOutput : 0;
        },
    },

    methods: {
        getRoleLabel(role) {
            const normalized = (role || '').toLowerCase();

            if (normalized === 'user') {
                return this.$tc('aica.detail.roles.user');
            }

            if (normalized === 'system') {
                return this.$tc('aica.detail.roles.system');
            }

            if (normalized === 'tool') {
                return this.$tc('aica.detail.roles.tool');
            }

            return this.$tc('aica.detail.roles.assistant');
        },

        renderPlainText(value) {
            if (typeof value !== 'string') {
                return '';
            }

            return value;
        },

        asArray(value) {
            return Array.isArray(value) ? value : [];
        },

        resolveMessageNumber(primary, fallback) {
            if (typeof primary === 'number' && Number.isFinite(primary)) {
                return primary;
            }

            if (typeof fallback === 'number' && Number.isFinite(fallback)) {
                return fallback;
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

        stringifyDebug(value) {
            if (value == null) {
                return '';
            }

            try {
                return JSON.stringify(value, null, 2);
            } catch {
                return String(value);
            }
        },
    },
};
