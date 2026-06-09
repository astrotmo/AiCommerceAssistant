import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';
import './service/aica-dashboard.api.service';

const { Module } = Shopware;

Shopware.Component.register('aica-dashboard', () => import('./page/aica-dashboard'));
Shopware.Component.register('aica-session-list', () => import('./page/aica-session-list'));
Shopware.Component.register('aica-session-detail', () => import('./page/aica-session-detail'));
Shopware.Component.register('aica-contact-list', () => import('./page/aica-contact-list'));
Shopware.Component.register('aica-contact-detail', () => import('./page/aica-contact-detail'));
Shopware.Component.register('aica-message-renderer', () => import('./component/aica-message-renderer'));

Module.register('aica', {
    type: 'plugin',
    name: 'aicaAdmin',
    title: 'aica.general.mainMenuItemGeneral',
    description: 'aica.general.descriptionTextModule',
    color: '#3F8C78',
    icon: 'regular-comments',
    entity: 'aica_chat_session',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE,
    },

    routes: {
        dashboard: {
            component: 'aica-dashboard',
            path: 'dashboard',
            meta: {
                privilege: 'aica.viewer',
            },
        },
        index: {
            component: 'aica-session-list',
            path: 'index',
            meta: {
                privilege: 'aica.viewer',
            },
        },
        detail: {
            component: 'aica-session-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'aica.index',
                privilege: 'aica.viewer',
            },
            props: {
                default(route) {
                    return {
                        id: route.params.id,
                    };
                },
            },
        },
        contact: {
            component: 'aica-contact-list',
            path: 'contact/index',
            meta: {
                privilege: 'aica.viewer',
                parentPath: 'aica.index',
            },
        },
        contactDetail: {
            component: 'aica-contact-detail',
            path: 'contact/detail/:id',
            meta: {
                parentPath: 'aica.contact',
                privilege: 'aica.viewer',
            },
            props: {
                default(route) {
                    return {
                        id: route.params.id,
                    };
                },
            },
        },
    },

    navigation: [
        {
            id: 'aica',
            label: 'aica.general.mainMenuItemGeneral',
            color: '#3F8C78',
            icon: 'regular-comments',
            path: 'aica.dashboard',
            position: 95,
            privilege: 'aica.viewer',
        },
        {
            id: 'aica-sessions',
            parent: 'aica',
            label: 'aica.list.title',
            color: '#3F8C78',
            icon: 'regular-comments',
            path: 'aica.index',
            position: 96,
            privilege: 'aica.viewer',
        },
        {
            id: 'aica-contact',
            parent: 'aica',
            label: 'aica.contactList.title',
            color: '#3F8C78',
            icon: 'regular-envelope',
            path: 'aica.contact',
            position: 97,
            privilege: 'aica.viewer',
        },
    ],
});
