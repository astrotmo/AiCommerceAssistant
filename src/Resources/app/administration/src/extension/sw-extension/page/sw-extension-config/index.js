import template from './sw-extension-config.html.twig';
import './sw-extension-config.scss';

Shopware.Component.override('sw-extension-config', {
    template,

    computed: {
        isAicaConfig() {
            return String(this.namespace || '').toLowerCase() === 'aicommerceassistant';
        },

        aicaDashboardRoute() {
            return {
                name: 'aica.dashboard',
            };
        },
    },
});
