# Installation

This guide assumes AICA is installed into a standard Shopware project under:

```text
custom/plugins/AiCommerceAssistant
```

## Clone into Shopware

From the Shopware project root:

```bash
git clone <repository-url> custom/plugins/AiCommerceAssistant
composer dump-autoload
bin/console plugin:refresh
bin/console plugin:install --activate AiCommerceAssistant
bin/console cache:clear
```

If your Shopware installation uses a different project layout, adjust paths accordingly.

## Build assets

When storefront, administration, Twig, or SCSS assets need to be compiled, use the build commands available in your Shopware project. Common defaults are:

```bash
bin/build-storefront.sh
bin/build-administration.sh
bin/console theme:compile
```

Some local development setups compile assets through container wrappers or npm scripts instead of the root `bin/*` scripts.

## Configure the backend connection

AICA proxies chat requests from `/aica/chat` to the configured chat backend URL. The external AI backend must be reachable from the Shopware server or container that executes the request.

Also configure `CHAT_AUTH_SECRET` in the Shopware environment so `/aica/context-token` can sign context tokens.

## Update an existing checkout

From the Shopware project root:

```bash
cd custom/plugins/AiCommerceAssistant
git pull
cd ../../..
composer dump-autoload
bin/console plugin:refresh
bin/console plugin:update AiCommerceAssistant
bin/console cache:clear
```

Rebuild assets when the update contains storefront, administration, Twig, or style changes.
