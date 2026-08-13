# AICA architecture analysis and recommendations

## Scope and evidence

This report evaluates the current AICA implementation across:

- **[SHOPWARE REPOSITORY]** `/home/paul/shopware-test/custom/plugins/AiCommerceAssistant`
- **[BACKEND REPOSITORY]** `/home/paul/shopware-ollama-mcp`

It is based on source inspection, existing tests, Docker Compose inspection, backend OpenAPI/health responses, Shopware-to-backend reachability, Ollama/GPU inspection, and one controlled direct backend request on 2026-07-27. Recommendations describe future work only. No recommendation in this report was implemented as part of the documentation review.

For the full runtime and data-flow walkthrough, see [architecture.md](architecture.md).

## Executive summary

AICA has a sensible top-level separation: the browser stays on Shopware routes; Python owns model and MCP orchestration; and Shopware remains authoritative for prices, mail, customer context, persistence, and Administration. A constrained block envelope, field-minimized MCP results, deterministic domain matching, sales-channel price lookup, output sanitation, and explicit contact controls are strong foundations.

The most important future work is at the trust boundaries rather than in the user-facing feature set:

1. The signed Shopware context token and `X-AICA-Proxy` marker are not validated by the backend. The host-published chat, MCP, and Ollama services have no application authentication; the chat API also accepts client-selected models.
2. Product discovery uses the Admin API without storefront visibility or sales-channel filtering.
3. Live traces are returned to the shopper, persisted in browser `sessionStorage`, and retrievable without authentication.
4. Normal storefront request IDs cannot be correlated with backend traces.
5. Chat/contact data has no automatic retention policy, sensitive DAL fields are broadly API-aware, and current deletion paths are incomplete.

The next tier of work should align duplicated contracts, expose safe usage/correlation metadata, make price/customer semantics consistent, define response models in OpenAPI, and expand tests across both repositories.

## Verified strengths

### Architecture

- **Clear responsibility split.** The Shopware plugin owns storefront and commerce side effects; the backend owns language-model orchestration. Evidence:
  - [SHOPWARE] `src/Storefront/Controller/*`
  - [BACKEND] `app.py`
- **Same-origin browser flow.** The browser normally calls `/aica/...` rather than discovering backend or Admin API credentials.
- **Independent repositories remain operationally separate.** Each has its own branch, Git status, Docker/validation context, and documentation.

### Shopware integration

- **Authoritative price lookup.** `PricesController` uses `sales_channel.product.repository` and the active `SalesChannelContext`, preserving Shopware rule, currency, tax, customer, and visibility calculation.
- **Limited, explicit storefront routes.** Context, price, tracking, and contact endpoints require Shopware storefront scope and explicit request markers.
- **Contact abuse controls.** The form has a honeypot, input length checks, email validation, and Shopware’s contact-form rate limiter.
- **Output sanitation.** Markdown passes through DOMPurify, while product names, form labels, and most other dynamic values use `textContent` or Administration interpolation.
- **Structured persistence.** Sessions, messages, contact submissions, and product references have explicit DAL schemas, associations, indexes, and Administration views.
- **Operational Administration features.** Viewer/manager roles, filtered lists, detail views, aggregate metrics, CSV exports, and typed-confirmation deletion exist.

### Backend and MCP

- **Constrained response normalizer.** Only four known block kinds cross the backend boundary; malformed blocks are dropped and invalid JSON receives a deterministic text fallback.
- **Field minimization.** MCP product results exclude prices, stock, delivery promises, customer data, and commercial conditions.
- **Read-only tools.** All four MCP functions read catalog/category data; none mutate Shopware.
- **Deterministic domain context.** Exact, phrase, abbreviation/synonym, normalization, and high-threshold fuzzy stages are separated and ranked before prompt injection.
- **Bounded domain injection.** Runtime maximum was four matches.
- **OAuth caching.** Shopware Admin API tokens are cached until shortly before expiry instead of fetched for each tool call.
- **Non-root Python image.** The Dockerfile switches to a dedicated application user.

### Runtime and observability

- **Working plain-HTTP topology was verified.** Shopware reached `http://host.docker.internal:8002/chat`; Python reached Ollama and MCP by Compose service name.
- **Model/context behavior was verified.** A configured Granite request ran with a 16,384-token context.
- **Two-phase orchestration was confirmed.** A harmless request made a tool-selection call followed by final formatting and returned a normalized text block.
- **Trace semantics were confirmed.** A known successful request returned its trace; an unknown ID returned `Trace not found`, matching in-memory—not filesystem—behavior.

## Verified issues

### 1. Backend authentication controls are not enforced

- **Classification:** verified issue
- **Areas:** security, API design, model runtime, operations
- **Repositories:** both
- **Evidence:**
  - [SHOPWARE] `src/Storefront/Controller/ChatContextController.php`
  - [SHOPWARE] `src/Storefront/Controller/ChatProxyController.php`
  - [BACKEND] `app.py`, `ChatIn` and `/chat`
  - [BACKEND] `compose.yaml`
- **Current behavior:** Shopware signs a 60-second HS256 token and sends it in `client.contextToken`. The backend ignores the complete `client` object and does not read `CHAT_AUTH_SECRET`. The proxy sends `X-AICA-Proxy: 1`, but Python does not check it. Port 8002 is published on `0.0.0.0`; Compose also publishes MCP on 8005 and Ollama on 11434 without application authentication.
- **Impact:** The intended Shopware-to-backend trust boundary does not exist in enforcement. Network-reachable callers can invoke inference through the chat API or Ollama directly and may call the catalog MCP service.
- **Future direction:** Authenticate proxy requests, validate expiry/signature/audience and any required claims, restrict network exposure/port publication, and treat CORS only as a browser policy.
- **Priority:** high

### 2. Model selection and backend input are client-controlled

- **Classification:** verified issue
- **Areas:** security, reliability, performance, model runtime
- **Repository:** backend
- **Evidence:** [BACKEND] `app.py` (`ChatIn`, model resolution, `sanitize_history`)
- **Current behavior:** Any non-empty request `model` wins over the backend default. The backend has no allowlist, application rate limiter, or independent message/history/body limits. Browser bounds can be bypassed by direct or crafted proxy calls.
- **Impact:** A caller can request unapproved/unavailable models, cause model loading pressure, or submit oversized histories.
- **Future direction:** Enforce a server-side model allowlist, request/body/history limits, rate limits, and controlled concurrency.
- **Priority:** high

### 3. Product discovery is not storefront-visibility aware

- **Classification:** verified issue
- **Areas:** Shopware integration, security, API design
- **Repository:** backend
- **Evidence:** [BACKEND] `shopware_mcp_server.py`, product search/get criteria
- **Current behavior:** Catalog tools use Shopware’s Admin API and do not filter by active state, product visibility, sales channel, or storefront availability.
- **Impact:** The assistant may suggest product records a shopper cannot see. Field minimization does not establish record-level public visibility.
- **Future direction:** Use a sales-channel-aware API or explicitly verify active/visibility/sales-channel assignment before returning records.
- **Priority:** high

### 4. Trace data crosses the public boundary

- **Classification:** verified issue
- **Areas:** privacy, security, observability
- **Repositories:** both
- **Evidence:**
  - [BACKEND] `app.py` (`text_response_payload`, `normalize_chat_reply`, `/trace`)
  - [SHOPWARE] `src/Resources/app/storefront/src/plugin/aica.plugin.js` (`persistMessage`)
- **Current behavior:** With deployed `TRACE_ENABLED=1`, successful chat responses include full trace events. The proxy relays them, the browser persists the structured response in `sessionStorage`, and `/trace/{request_id}` is unauthenticated. Events can contain model messages, matched domain context, tool arguments/results, and usage. Trace commit precedes normalization, so an exceptional normalization failure can also leave a retrievable trace.
- **Impact:** Internal diagnostic and user/business-derived data is exposed beyond the backend and retained in the tab.
- **Future direction:** Keep internal traces out of public responses, authenticate diagnostic access, redact payloads, apply bounded storage, and document operator access.
- **Priority:** high

### 5. Request correlation is broken across repositories

- **Classification:** verified issue
- **Areas:** observability, error handling, API design
- **Repositories:** both
- **Evidence:**
  - [SHOPWARE] browser payload and `ChatProxyController.php`
  - [BACKEND] `/chat` request-ID assignment
- **Current behavior:** The browser sends numeric JSON `requestId`; Pydantic ignores it. The backend uses `X-Request-Id` or `time_ns()`, while the proxy does not create or forward that header. Shopware tracking accepts only string request IDs, so it drops the widget's numeric value; normal message records have no request ID and the backend uses a separate one.
- **Impact:** A Storefront/Admin record cannot normally be matched to a backend trace or log entry.
- **Future direction:** Define one bounded opaque ID, create it at a trusted boundary, forward/return it consistently, and store it with the message.
- **Priority:** high

### 6. Usage data is generated but not available to Shopware tracking

- **Classification:** verified issue
- **Areas:** observability, administration, API design
- **Repositories:** both
- **Evidence:**
  - [BACKEND] trace `ollama_response` events
  - [SHOPWARE] frontend `extractTokenUsage()` and `ChatTrackingService`
- **Current behavior:** Ollama usage exists inside trace events only. The frontend looks for top-level `tokenUsage`, `usage`, or metrics and does not aggregate trace events.
- **Impact:** AICA’s token dashboard and session totals normally remain unavailable even though both model calls report usage.
- **Future direction:** Return a minimal aggregate `usage` object independent of trace and specify whether it covers both model phases.
- **Priority:** medium

### 7. Price policy has two authority gaps

- **Classification:** verified issue
- **Areas:** security, Shopware integration, frontend
- **Repositories:** both
- **Evidence:**
  - [SHOPWARE] `StorefrontSubscriber.php`, Twig, `PricesController.php`
  - [BACKEND] `normalize_blocks()`
  - [SHOPWARE] frontend `renderProductList()`
- **Current behavior:** UI policy excludes Shopware guest customers, but the controller accepts any non-null customer in `customers` mode. Separately, when price display is allowed, backend normalization accepts a model `price` string; the frontend renders it and leaves it in place if Shopware enrichment fails or has no matching result.
- **Impact:** A direct guest-context call can differ from UI policy; malformed model output can retain a non-authoritative price.
- **Future direction:** Centralize customer eligibility and reject all backend/model price fields.
- **Priority:** high

### 8. Stored data has no complete retention or erasure path

- **Classification:** verified issue
- **Areas:** privacy, persistence, operations, plugin lifecycle
- **Repository:** Shopware
- **Evidence:**
  - [SHOPWARE] `ChatTrackingService.php`
  - [SHOPWARE] migration and DAL definitions
  - [SHOPWARE] `ChatAnalyticsService.php`
  - [SHOPWARE] `AiCommerceAssistant.php`
- **Current behavior:** Normal message text and selected blocks are persisted independently of debug when best-effort tracking succeeds. There is no automatic retention. A session first seen anonymously can retain its guest hashes and raw identifiers after a later customer-linked upsert because omitted null fields do not clear stored values. Session deletion preserves contact rows by nulling their link; contact deletion can leave contact-sourced product references; browser clearing is local only; uninstall removes no data even when `keepUserData=false`.
- **Impact:** Personal/free-text data and derived references can outlive the user-facing history and operator expectations.
- **Future direction:** Define retention/anonymization, complete referential erasure, uninstall policy, and clear operator/user-facing semantics.
- **Priority:** high

### 9. Debug ACL and debug-setting semantics do not enforce isolation

- **Classification:** verified issue
- **Areas:** security, privacy, Administration
- **Repository:** Shopware
- **Evidence:**
  - [SHOPWARE] DAL definitions, `app/acl/index.js`
  - [SHOPWARE] `ChatTrackingService.php`
- **Current behavior:** Sensitive fields are API-aware and ordinary `aica.viewer` can read the owning entities. `aica_debug.viewer` only hides/shows UI sections. Raw user agent is stored whenever debug is on, while raw IP requires a second flag. Disabling debug does not clear existing values. Fields named “snapshot” are overwritten on later session touches.
- **Impact:** UI visibility can be mistaken for authorization and previously captured raw data remains accessible.
- **Future direction:** Enforce sensitive-field access at an API/service boundary, make snapshot semantics immutable or rename them, and provide cleanup.
- **Priority:** high

### 10. Category and product navigation contracts are incorrect for some storefronts

- **Classification:** verified issue
- **Areas:** frontend, API design, Shopware integration
- **Repositories:** both
- **Evidence:**
  - [BACKEND] formatting prompt’s category guidance
  - [SHOPWARE] frontend `renderProductList()`
- **Current behavior:** Categories are encoded as `product_list`, so category IDs become `/detail/{id}` product links. Product cards also hard-code the origin root and ignore sales-channel base paths or authoritative SEO URLs.
- **Impact:** Category results and subdirectory/language storefronts can navigate incorrectly.
- **Future direction:** Add a typed category/navigation block or authoritative URL fields resolved by Shopware.
- **Priority:** medium

### 11. Contact success does not mean delivered or persisted

- **Classification:** verified issue
- **Areas:** reliability, error handling, frontend
- **Repository:** Shopware
- **Evidence:** `FormularController.php`, `ContactSubmissionMailService.php`, frontend `submitContactForm()`
- **Current behavior:** A valid request returns `success: true` even when internal mail, confirmation mail, or best-effort persistence fails. The response can carry mail-status warnings, but the frontend ignores them and shows generic success.
- **Impact:** A shopper can believe the request was delivered when no internal notification was sent.
- **Future direction:** Define accepted, persisted, and delivered states and reflect the appropriate state in the UI without exposing internals.
- **Priority:** medium

### 12. Backend API documentation is incomplete and inconsistent

- **Classification:** verified issue
- **Areas:** API design, documentation
- **Repository:** backend
- **Evidence:** runtime `/openapi.json`, `app.py` exception handlers
- **Current behavior:** OpenAPI accurately marks `message` and `model` as required but supplies empty success-response schemas. Its advertised standard 422 structure differs from the custom runtime `{message, details}` envelope. Framework-generated method errors may still use `{detail}`.
- **Impact:** Generated clients, contract tests, and operators cannot rely on the advertised response shapes.
- **Future direction:** Define Pydantic response/error models and make exception envelopes consistent.
- **Priority:** medium

### 13. A tracked local environment file declares secret-bearing settings

- **Classification:** verified issue
- **Areas:** security, Docker and operations
- **Repository:** backend
- **Evidence:** [BACKEND] tracked `.env.local` and `git ls-files`
- **Current behavior:** The tracked file declares credential-bearing keys, including Shopware client credentials and application secrets. Values are intentionally not reproduced here. Runtime Compose uses ignored `.env`, not `.env.local`, for service injection. `.dockerignore` excludes `.env` but not `.env.local`, while the Dockerfile copies the repository into `/app`.
- **Impact:** Real or historical credentials can be exposed through repository distribution, Git history, and built Python service image layers.
- **Future direction:** Audit and rotate affected credentials, remove real secret values from tracking/history through an approved security process, and retain only a sanitized example file.
- **Priority:** high

### 14. Two blocking model calls run inside an async endpoint

- **Classification:** architectural trade-off with verified implementation
- **Areas:** performance, reliability, backend
- **Repository:** backend
- **Evidence:** [BACKEND] synchronous `OpenAI` client in async `chat_with_tools()`
- **Current behavior:** Every normal success makes two synchronous Ollama calls inside FastAPI’s async endpoint. The Shopware proxy stops waiting after 60 seconds, but no matching backend/model cancellation contract exists.
- **Impact:** Inference blocks the event loop and timed-out upstream work may continue.
- **Future direction:** Use an async client or controlled worker/thread boundary, explicit timeouts/cancellation, and concurrency/backpressure.
- **Priority:** medium

### 15. Prompt and effective contact contract have drifted

- **Classification:** documentation gap
- **Areas:** maintainability, API design
- **Repository:** backend
- **Evidence:** [BACKEND] `FORMAT_PROMPT_PUBLIC`, `normalize_blocks()`
- **Current behavior:** The prompt instructs `/paul-ai-chat/contact`, but the live route is `/aica/contact`. Normalization removes endpoint/method and the frontend hard-codes the correct route, masking the drift.
- **Impact:** Current runtime works, but a future renderer or normalizer change could resurrect the stale route.
- **Future direction:** Align the prompt with the versioned effective schema in an approved functional change.
- **Priority:** medium

### 16. Tracking accepts client-asserted analytics without integrity bounds

- **Classification:** verified issue
- **Areas:** security, reliability, observability, persistence
- **Repository:** Shopware
- **Evidence:** [SHOPWARE] `ChatTrackingController.php`, `ChatTrackingService.php`, storefront `trackEvent()`
- **Current behavior:** The tracking route relies on forgeable AJAX markers and accepts client-provided role, text, payload, message index/type, model, latency/usage, flags, and product references. It has no tracking-specific rate limit, event-size cap, trusted recomputation, or idempotency key.
- **Impact:** Direct callers can pollute analytics, create misleading product/message records, and consume storage with attacker-selected content.
- **Future direction:** Bound and validate the envelope, derive authoritative fields server-side, add rate/idempotency controls, and separate untrusted client telemetry from trusted operational metrics.
- **Priority:** medium

## Likely risks

These are plausible risks inferred from code; they were not demonstrated as production incidents.

| Risk | Evidence and reasoning | Suggested investigation |
| --- | --- | --- |
| Proxy target can become an internal-network request primitive | `chatUrl` accepts HTTP(S), adds HTTP to bare hosts, and is controlled by privileged configuration. | Decide an allowed-host/scheme policy and test IPv4/IPv6/redirect behavior. |
| Multiple tabs can collide on message order | Tabs share the Shopware session but keep independent `messageIndex` counters; DB uniqueness is `(session_id,message_index)`. | Reproduce concurrent-tab tracking and move ordering/idempotency server-side. |
| Tool-call message sequence may be model-provider sensitive | The first assistant tool-call message is not appended, while tool-role results refer to its call IDs. | Add transcript conformance tests against supported Ollama versions/models. |
| Contact/product data may be inferred as “public” from tool names | The tool suffix is `_public`, but visibility is not proven and locale is unused. | Rename/document semantics or enforce record-level storefront checks. |
| Upstream error details may reach shoppers | Proxy preserves upstream bodies and browser shows truncated response text. | Define sanitized public errors plus internal logs/correlation IDs. |
| Logging can retain personal content | Backend logs the raw current message at INFO and full requests/responses at DEBUG. | Review log destination, retention, access, and structured redaction. |
| Model output can expand browser/storage work | The backend allow-lists response kinds/keys but does not cap block/product/field counts or retained string lengths; the browser renders and stores the complete normalized payload. | Add adversarial-size fixtures and define response/cardinality/string limits across backend, proxy, DOM, session storage, and tracking. |
| Spreadsheet formula injection in exports | Chat/contact values can begin with `=`, `+`, `-`, or `@`; `fputcsv` quotes them but does not neutralize spreadsheet formula interpretation. | Test common spreadsheet clients and adopt a documented formula-neutralization policy. |
| Contact limiter can be evaded by rotating email | The limiter key combines caller-controlled lowercased email with IP, so each new email creates a new bucket while still triggering internal and optional confirmation mail attempts. | Add IP-, session-, and global-layer throttles, abuse telemetry, and rotating-address tests. |
| Analytics date semantics can surprise operators | Dashboard, exports, and deletion use different session/message date bases; session exports contain lifetime aggregates. | Specify range semantics in the UI and add regression fixtures. |
| Health can mask dependency failure | `/healthz` returned 200 before a model was loaded and does not probe MCP/Shopware. | Split liveness/readiness and exercise dependency outages. |

## Architectural trade-offs

### Separate repositories

The split makes Python/model work deployable independently from Shopware and prevents Python dependencies from entering the Shopware plugin. The cost is duplicated contracts, separate release coordination, and harder request correlation. A versioned contract and compatibility matrix would retain the benefit while reducing drift.

### Shopware proxy

The proxy keeps the browser same-origin and hides topology, which is appropriate. It also creates a second timeout/error boundary and currently forwards an almost-unvalidated body. Stronger boundary validation belongs here or immediately behind it.

### Local model runtime

Ollama keeps prompts and user text on local infrastructure and avoids an external model provider. It requires GPU capacity planning, model allowlisting, warm/loading behavior, context management, and backpressure. The live Granite model consumed about 9.6 GB on a 12 GB RTX 3080.

### Structured response blocks

Blocks give the frontend a constrained, extensible rendering contract and make tracking possible. They duplicate schema across prompt text, Python normalization, JavaScript rendering, PHP persistence, and Administration. A shared versioned schema would reduce drift.

### Shopware persistence

Persisting analytics beside customers, sales channels, mail, and ACL makes Administration integration straightforward. It increases privacy obligations and couples conversational telemetry to Shopware retention and backup processes.

### Synchronous tool/model flow

Sequential execution is easy to reason about and prevents uncontrolled parallel tool load. It increases latency, and the unconditional second model request doubles baseline inference work even when no tool is used.

### Admin API for MCP

OAuth client credentials and Admin API search are convenient for a standalone backend. They bypass storefront `SalesChannelContext`; record-level public visibility must therefore be recreated or delegated.

## Documentation gaps

The review found and addressed the central architecture gap by replacing the previous high-level diagram with a cross-repository walkthrough. Remaining or historically inaccurate areas include:

- Legacy `/paul-ai-chat/contact` text remains inside a production prompt and was not changed under this documentation-only brief.
- The legacy `PaulAiChat` checkout still exists in the Shopware installation, although it is inactive.
- Backend README examples historically described older model/context/CORS defaults and did not fully state required `model`, custom 422 responses, trace privacy, or two model calls.
- Existing Shopware configuration text described “full request/response” debug storage, while the implementation stores the structured assistant payload plus always-stored normalized text/blocks.
- The context-token documentation previously implied customer claims; the actual claims have no customer ID.
- The old architecture overview did not distinguish Admin API product discovery from sales-channel price lookup.
- Environment files have overlapping roles: Compose services use `.env`; `.env.local` is tracked but not the injected service file in the current Compose invocation.
- Health documentation did not distinguish configuration reporting from dependency readiness.
- Container `/app/...` and WSL host paths were not explained.
- Request ID, trace commit timing, lazy TTL eviction, and which failure phases do or do not retain traces were undocumented.
- Administration generic DAL calls versus custom action endpoints were not distinguished.

## Testing gaps

### Shopware

Current unit tests cover selected context-token, contact, and price-controller paths. Missing or incomplete coverage includes:

- proxy URL normalization, body forwarding, status/content-type preservation, and timeout/error mapping;
- trusted-marker behavior on every route;
- guest-customer versus registered-customer price policy;
- tracking persistence, privacy gates, aggregate calculations, and multi-tab index collisions;
- tracking-envelope bounds, client-asserted analytics integrity, rate limiting, and idempotency;
- raw/debug field ACL enforcement;
- mail recipient fallbacks, template lookup, and failure status;
- rotating-email limiter evasion and layered IP/session/global throttling;
- contact persistence failure versus UI outcome;
- analytics SQL date semantics, exports, cascades, and partial deletion;
- CSV formula-prefix neutralization for user-controlled chat/contact cells;
- install/update/uninstall data policy;
- storefront JavaScript history bounds, queueing, token caching, fallback rendering, XSS sanitation, product/category links, and price enrichment;
- Administration permissions, debug visibility, CSV/download, and deletion confirmation.

### Backend

No tracked backend test suite currently exercises:

- `ChatIn` extra/required-field behavior and custom error envelopes;
- history sanitation and independent server-side bounds;
- model mapping, aliases, and allowlisting expectations;
- domain JSON validation, auto-reload, exact/phrase/fuzzy ranking, and maximum matches;
- prompt construction without snapshotting secrets or brittle prose;
- no-tool, one-tool, multiple-tool, bad-argument, unknown-tool, MCP outage, and model outage paths;
- the exact two-phase transcript sent to Ollama;
- response normalization for every valid/invalid block shape;
- normalized response cardinality/string bounds and large-output behavior;
- trace commit-before-normalization, failure/TTL/restart/multi-worker, and redaction behavior;
- OAuth expiry/refresh and Shopware API timeout/error behavior;
- active/visibility/sales-channel filtering;
- OpenAPI response and error schemas.

### Cross-repository

Add contract and controlled end-to-end tests for:

- frontend request → proxy → `ChatIn`;
- context JWT creation and backend validation;
- one request ID through logs, response, trace, tracking, and Administration;
- every normalized block through frontend rendering and persisted representation;
- product tool fields → product card → authoritative price;
- category results and links;
- contact form schema → controller validation and safe mail test transport;
- trace-disabled production responses;
- backend/proxy timeout coordination and sanitized public errors.

## Prioritized recommendations

### High priority

1. Enforce Shopware-to-backend authentication and restrict backend/trace network exposure.
2. Add server-side model allowlisting, request/history/body and normalized-response bounds, inference rate limiting, and backpressure.
3. Make MCP product discovery storefront/sales-channel visibility aware.
4. Remove internal trace payloads from shopper responses/session storage and protect/redact trace access.
5. Establish one cross-repository request ID and persist it consistently.
6. Fix guest-customer price authorization and reject model-provided prices.
7. Define data retention, anonymization, complete erasure, and uninstall behavior.
8. Enforce debug/sensitive data permissions at the API boundary.
9. Audit the tracked secret-bearing environment file, rotate affected credentials, and adopt sanitized secret handling.
10. Build automated tests around these security, privacy, and contract boundaries.

### Medium priority

1. Return a documented aggregate usage/latency/model object outside trace.
2. Add explicit Pydantic success/error response models and align OpenAPI/runtime errors.
3. Introduce typed category/navigation results and authoritative Shopware URLs.
4. Make contact accepted/delivered/persisted state visible and testable.
5. Use async or isolated blocking model calls with coordinated timeout/cancellation.
6. Apply MCP locale parameters and validate tool names/arguments before dispatch.
7. Split liveness from dependency readiness.
8. Align the stale prompt endpoint and consolidate duplicated schemas during an approved functional change.
9. Validate and bound tracking events, derive trusted analytics server-side, and add rate/idempotency controls.
10. Neutralize spreadsheet formula prefixes in CSV exports and add regression fixtures.
11. Define dashboard/export/deletion range semantics.
12. Add layered contact throttling that cannot be reset by rotating caller-controlled email values.

### Optional or long-term

1. Generate Python, JavaScript, and PHP types/validators from a shared versioned schema.
2. Add a compatibility/version handshake between plugin and backend releases.
3. Evaluate asynchronous job/streaming architecture if response latency or concurrency requires it.
4. Version mail-template migrations while preserving merchant customizations.
5. Add privacy-preserving aggregate telemetry that does not require retaining message text.
6. Add deployment profiles for development tracing and production-hardening defaults.
