# Troubleshooting

## Plugin is not visible after cloning

Run the plugin refresh from the Shopware project root:

```bash
composer dump-autoload
bin/console plugin:refresh
```

Verify the plugin folder is exactly:

```text
custom/plugins/AiCommerceAssistant
```

The technical plugin name used by Shopware commands is `AiCommerceAssistant`.

## Namespace or autoload errors

Refresh Composer autoloading:

```bash
composer dump-autoload
```

Check that `composer.json` still maps `AiCommerceAssistant\\` to `src/` and that the main class file exists at `src/AiCommerceAssistant.php`.

## Routes are not found

Refresh the plugin list and clear caches:

```bash
bin/console plugin:refresh
bin/console cache:clear
```

Expected storefront routes use `/aica/...`, for example:

- `/aica/context-token`
- `/aica/chat`
- `/aica/track`
- `/aica/prices`
- `/aica/contact`

There are no legacy route aliases.

## Storefront or administration assets are not updated

Rebuild the relevant Shopware assets:

```bash
bin/build-storefront.sh
bin/build-administration.sh
bin/console theme:compile
```

In containerized projects, run the equivalent command inside the PHP or Node container used by the Shopware setup.

## Backend URL is not configured

Configure the Chat backend URL in the AICA plugin configuration. The corresponding key is:

```text
AiCommerceAssistant.config.chatUrl
```

If no backend URL is configured, `/aica/chat` returns a backend-not-configured error.

## Chat request fails because the backend is unreachable

The external AI backend must be reachable from the Shopware server or container, not only from your browser. Check DNS, container network names, ports, and whether the configured URL includes the correct scheme.

The plugin sends proxied requests with the `X-AICA-Proxy: 1` header.

## Context token fails

Set `CHAT_AUTH_SECRET` in the Shopware environment. The context-token endpoint uses it to sign short-lived tokens.

Also make sure storefront requests include the expected AJAX markers. The frontend sends `X-Requested-With: XMLHttpRequest` and `X-AICA: 1`.

## Contact mails are not sent

Check:

- `AiCommerceAssistant.config.contactRecipientEmail`
- Shopware mailer configuration
- `AiCommerceAssistant.config.enableContactConfirmationMail`
- Mail template availability after plugin install/update

Enable `AiCommerceAssistant.config.debugLogContactSubmissions` only when you need detailed mail status logs.

## Cache or theme compilation issues

Clear cache first:

```bash
bin/console cache:clear
```

Then compile the theme:

```bash
bin/console theme:compile
```

Theme compilation can require a working database connection and an active sales channel/theme setup.
