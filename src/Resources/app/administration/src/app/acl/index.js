Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'aica',
    roles: {
        viewer: {
            privileges: [
                'aica_chat_session:read',
                'aica_chat_message:read',
                'aica_contact_submission:read',
                'aica_product_reference:read',
                'customer:read',
                'sales_channel:read',
                'language:read',
            ],
            dependencies: [],
        },
        manager: {
            privileges: [
                'aica_chat_session:read',
                'aica_chat_session:delete',
                'aica_chat_message:read',
                'aica_chat_message:delete',
                'aica_contact_submission:read',
                'aica_contact_submission:delete',
                'aica_product_reference:read',
                'aica_product_reference:delete',
                'customer:read',
                'sales_channel:read',
                'language:read',
            ],
            dependencies: [
                'aica.viewer',
            ],
        },
    },
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'aica',
    key: 'aica_debug',
    roles: {
        viewer: {
            privileges: [
                'aica_chat_session:read',
                'aica_chat_message:read',
            ],
            dependencies: [
                'aica.viewer',
            ],
        },
    },
});
