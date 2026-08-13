# Configuration

AICA settings are defined in `src/Resources/config/config.xml` and use the `AiCommerceAssistant.config.*` system config domain.

Configure the plugin in Shopware Administration through the plugin configuration for `AICA - AI Commerce Assistant`.

## Backend and model

### `AiCommerceAssistant.config.chatUrl`

The chat backend URL used by the Shopware proxy route. Enter either the backend base URL or the full chat endpoint. If a base URL is provided, the proxy appends the default backend chat path used by the controller.

Example placeholder from the plugin config:

```text
http://ai-backend:8002
```

The backend must be reachable from the Shopware server or container.

### `AiCommerceAssistant.config.llmModel`

The model name sent with each chat request so the external backend can select the matching local model.

Configured options:

- `llama3.1:8b`
- `llama3.2:3b`
- `ministral-3:8b`
- `qwen3.5:9b`
- `granite4.1:8b`

Default: `ministral-3:8b`

## Widget display

### `AiCommerceAssistant.config.welcomeMessage`

The first assistant message shown when the storefront chat widget is rendered.

Default: `Hallo, wie kann ich behilflich sein?`

### `AiCommerceAssistant.config.buttonLabel`

Text used for the floating chat button and the chat panel header.

Default: `Chat`

### `AiCommerceAssistant.config.autoOpen`

Controls whether the chat panel opens automatically shortly after the first page load.

Default: `false`

### `AiCommerceAssistant.config.position`

Controls the screen corner used for the floating chat button.

Options:

- `bottom-right`
- `bottom-left`

Default: `bottom-right`

## Product prices

### `AiCommerceAssistant.config.productPriceVisibility`

Controls whether product cards returned by the assistant may show Shopware prices.

Options:

- `customers`: only logged-in customers
- `all`: every visitor
- `none`: never show prices

Default: `customers`

### `AiCommerceAssistant.config.productPriceMode`

Controls which Shopware price source should be preferred when enriching product suggestions.

Options:

- `advanced-first`: prefer tier or special prices where available
- `calculated`: use the current calculated Shopware price

Default: `advanced-first`

### `AiCommerceAssistant.config.showProductPriceHint`

Adds the asterisk and hover hint explaining whether a standard or special price is shown.

Default: `true`

## Contact mails

### `AiCommerceAssistant.config.contactRecipientEmail`

Internal email address that receives AICA contact form submissions.

Set this before relying on contact escalation in production.

### `AiCommerceAssistant.config.enableContactConfirmationMail`

Controls whether AICA sends a confirmation email to the address entered in the chat contact form.

Default: `true`

### `AiCommerceAssistant.config.contactMailSenderName`

Optional sender name override used for the internal notification mail and the customer confirmation mail.

If empty, Shopware mail defaults apply.

## Debug and privacy

### `AiCommerceAssistant.config.enableDebugMode`

Enables storefront console logs and gates the optional raw debug storage settings below. The console output can include request history, the short-lived context token, and complete backend responses, so it should not be left enabled on shared or production browsers.

When enabled, the tracking service may also store the raw user-agent string. Raw IP storage and the full assistant debug payload remain controlled by their separate settings below.

Default: `false`

### `AiCommerceAssistant.config.storeRawDebugPayloads`

Stores the complete structured assistant payload supplied by storefront tracking while debug mode is enabled. It does not store a second copy of the complete browser request. Independently of this setting, normal tracking stores message text and a selected rendered response containing supported blocks and metadata.

Default: `false`

Use this only temporarily, because raw payloads may contain sensitive conversation content.

### `AiCommerceAssistant.config.storeRawIpAddress`

Stores raw IP addresses while debug mode is enabled.

Default: `false`

Keep this disabled unless raw IPs are explicitly needed for short-term troubleshooting.

## Tracking data that is not debug-only

The debug settings do not enable or disable AICA analytics as a whole. When browser events or validated, non-honeypot contact submissions are successfully persisted, records can include normalized user/assistant text, selected structured blocks, session/customer relationships, product references, contact data, and available latency/model metadata. Browser tracking and controller-side contact persistence are best-effort, so delivery or repository failures can leave gaps. Guest IP and user-agent values are salted and hashed outside debug mode.

There is currently no automatic retention job. Administration users with the required ACL can delete sessions or contact submissions by date range; clearing browser history affects only the current tab's `sessionStorage`.

### `AiCommerceAssistant.config.debugLogContactSubmissions`

Writes detailed mail status information for AICA contact submissions.

Default: `false`
