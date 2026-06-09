# Development

This repository is a Shopware platform plugin. The technical plugin class is `AiCommerceAssistant`, and the PHP namespace is `AiCommerceAssistant`.

## Repository structure

- `src/AiCommerceAssistant.php`: main Shopware plugin class
- `src/Storefront/Controller/`: storefront controllers for chat proxy, context token, tracking, prices, and contact submission
- `src/Controller/Api/`: administration API controller for analytics/dashboard actions
- `src/Service/`: chat tracking, analytics, session resolution, and contact mail services
- `src/Core/Content/`: custom DAL entity definitions for AICA persistence
- `src/Migration/`: database migrations
- `src/Resources/config/`: Shopware `config.xml`, `services.xml`, and `routes.xml`
- `src/Resources/views/`: Twig storefront integration
- `src/Resources/app/storefront/`: storefront JavaScript and SCSS
- `src/Resources/app/administration/`: Shopware Administration module, ACL, snippets, and components
- `tests/`: PHPUnit tests

## Useful commands

Run commands from the Shopware project root unless noted otherwise.

Refresh Composer autoloading:

```bash
composer dump-autoload
```

Check PHP syntax in the plugin:

```bash
find custom/plugins/AiCommerceAssistant/src custom/plugins/AiCommerceAssistant/tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Refresh the plugin list and clear cache:

```bash
bin/console plugin:refresh
bin/console cache:clear
```

Install or update the plugin:

```bash
bin/console plugin:install --activate AiCommerceAssistant
bin/console plugin:update AiCommerceAssistant
```

Build assets where required:

```bash
bin/build-storefront.sh
bin/build-administration.sh
bin/console theme:compile
```

Local projects may wrap these commands through Docker, DDEV, Symfony CLI, or another task runner.

## Tests

The repository includes `phpunit.xml.dist`. In a Shopware test-capable environment, run:

```bash
vendor/bin/phpunit -c custom/plugins/AiCommerceAssistant/phpunit.xml.dist
```

The Shopware test bootstrap may require a working database connection.
