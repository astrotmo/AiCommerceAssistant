# AICA – AI Commerce Assistant

AICA is a Shopware 6 storefront plugin that integrates an AI-based commerce assistant into the storefront. It renders a floating chat widget, routes browser requests through Shopware, and keeps commerce-specific concerns such as context, pricing, contact escalation, tracking, and administration analytics inside Shopware.

## Key features

- Floating storefront chat widget
- Shopware-controlled chat proxy endpoint at `/aica/chat`
- Context-token handling through `/aica/context-token`
- Structured AI response rendering for text, product lists, info boxes, and forms
- Product suggestion rendering in the storefront widget
- Optional Shopware price enrichment through `/aica/prices`
- Contact escalation form through `/aica/contact`
- Chat/contact tracking and administration analytics dashboard

## Architecture

The browser calls Shopware storefront routes under `/aica/...`. Shopware validates and enriches requests, forwards chat payloads to an external AI backend configured through the plugin settings, and handles price lookup, contact mails, tracking, persistence, and administration analytics locally.
This plugin depends on a separate backend service orchestrating the answer generation process by a local LLM. It can be found [here](https://github.com/astrotmo/shopware-ai-chat-backend).

See [docs/architecture.md](docs/architecture.md) for a fuller implementation overview.

## Requirements

- Shopware core `~6.7.0` as declared in `composer.json`
- PHP version compatible with the installed Shopware 6.7 project; this plugin does not declare an additional PHP constraint
- A reachable external AI backend for the configured chat backend URL
- `CHAT_AUTH_SECRET` environment variable for context-token signing
- Shopware mail delivery configured if the contact escalation form should send emails
- Node/npm tooling compatible with your Shopware installation when rebuilding storefront or administration assets

## Quick installation

From the Shopware project root:

```bash
git clone <repository-url> custom/plugins/AiCommerceAssistant
composer dump-autoload
bin/console plugin:refresh
bin/console plugin:install --activate AiCommerceAssistant
bin/console cache:clear
```

When assets need to be rebuilt, use the commands provided by your Shopware project, commonly:

```bash
bin/build-storefront.sh
bin/build-administration.sh
bin/console theme:compile
```

Paths and build commands can differ between Shopware installations.

## Quick update

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

Rebuild storefront, administration, and theme assets when JavaScript, SCSS, Twig, or administration files changed.

## Configuration

Configure AICA in the Shopware Administration plugin configuration for `AICA - AI Commerce Assistant`.

Available settings include:

- Chat backend URL
- Ollama model
- Welcome message
- Button and window title
- Auto-open behavior
- Widget position
- Product price visibility and price source mode
- Price explanation hint
- Contact recipient email
- Customer confirmation mail
- Contact mail sender name override
- Debug and privacy options for console logs, raw debug payloads, raw IP storage, and contact mail status logging

The corresponding system config keys use the `AiCommerceAssistant.config.*` domain. See [docs/configuration.md](docs/configuration.md) for the complete setting list.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Architecture analysis and recommendations](docs/architecture-analysis-and-recommendations.md)
- [Development](docs/development.md)
- [Troubleshooting](docs/troubleshooting.md)

Additional generated reference files may exist in `docs/` depending on the repository checkout.

## License

AICA is released under the [MIT License](LICENSE).
