# Architecture audit — findings and production gates

Date: 2026-09-02. Baseline: A `78581facc8470eef3e85ef667339693616c63cc7`, B `fe72dd4af104cef5eaf61104fa73779f0bf7870b`; stage differences inspected. A = AiCommerceAssistant; B = shopware-ai-chat-backend. [Evidence](AUDIT_EVIDENCE.md) defines inspection and probe limits. This is an audit, not a claim that a deployed shop was compromised.

## Overall assessment

The system is a useful working prototype with meaningful Shopware integration, but it is not yet a generally installable production product. The central issue is misplaced trust: the backend ignores Shopware's token, catalog discovery bypasses storefront context, the model supplies renderable commerce values, and the browser supplies the persisted conversation. Existing modules cannot enforce a single authoritative request lifecycle.

A wholesale replacement of Shopware, FastAPI or Ollama is not justified. Keep real SalesChannel price calculation, DAL integration, contact mail templates, Administration workflows, sanitization, the deterministic vocabulary resolver and locked/non-root packaging. Redesign the boundaries around them. MCP adds an authenticated-catalog problem and a session/network boundary without a demonstrated interoperability requirement; make it optional.

## Severity and evidence conventions

- **Critical / P0:** must close before a serious rollout because of commerce correctness, unauthorized access or sensitive-data exposure. Exposure depends on actual network/configuration; priority is not a CVSS score.
- **High / P1:** significant data integrity, reliability or authorization problem.
- **Medium / P2:** substantial product, maintainability or operational improvement.
- **Low / P3:** limited immediate impact; clean up during adjacent work.
- **Future:** add only after a measured need.

**Observed (O)** means visible source behavior. **Reproduced (R)** means an isolated local probe, not a deployed integration test. **Inferred (I)** means a consequence or runtime risk requiring environmental verification. Recommendations are explicitly future work.

## Prioritized findings

### F01 — P0 · security · backend trust boundary is unenforced (O)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `chat`, `get_trace`, CORS setup; [`A:src/Storefront/Controller/ChatProxyController.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Storefront/Controller/ChatProxyController.php); [`B:compose.yaml`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/compose.yaml). `/chat` ignores `client.contextToken`; `X-AICA-Proxy` is not verified. No service credential protects chat, and Compose publishes backend, MCP and model ports. Network-reachable callers can reach these APIs subject to infrastructure restrictions that were not inspected. Default CORS is permissive, but CORS cannot restrict direct clients.

**Recommendation:** distinct server-to-server authentication, private runtime ports, fixed allowed destinations, browser-origin protections and per-installation admission limits. Retiring the browser JWT is preferable to treating a publicly obtainable token as privileged service authentication. Acceptance: unauthenticated/wrong-credential calls fail before model or tool work.

### F02 — P0 · security / commerce correctness · discovery lacks storefront authorization (O/I)

[`B:shopware_mcp_server.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/shopware_mcp_server.py), product/category tools, `sw_search`, `sw_get`. Admin API catalog reads have no product-active, sales-channel or storefront search-visibility filtering. Locale is ignored. Omission of prices reduces returned sensitivity but does not prevent unpublished or wrong-channel product recommendations. The exact records exposed depend on catalog and integration permissions (I).

**Recommendation:** Shopware-owned contextual search/detail ports using appropriate Store API behavior; do not merely swap to a generic repository. Search requires search visibility, while linked detail visibility can be weaker. Test inactive, unavailable, other-channel, variant and language fixtures.

### F03 — P0 · bug / commerce correctness · model data can become authoritative UI (O/R)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `normalize_blocks`; [`A:src/Resources/app/storefront/src/plugin/aica.plugin.js`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Resources/app/storefront/src/plugin/aica.plugin.js), `renderProductList`. A probe retained invented ID/name and `0.01 EUR`. The widget initially displays a model price and leaves it when price hydration fails or omits the item. Product names and links are also model-derived. A prompt prohibition is not an integrity control.

**Recommendation:** model returns references only; Shopware verifies and hydrates allowed identities, prices, labels and links. Unknown/unavailable references produce a safe omission or explicit unavailable state. No model-price fallback. Free-text assertions remain probabilistic and need separate controls for factual commerce answers.

### F04 — P0 when tracing enabled · privacy / security · public diagnostics leak content (O)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `trace_add`, `get_trace`, `chat`; widget history persistence. Traces can include model responses, tool arguments/results and matched domain data, are returned in chat payloads and are retrievable without authentication. Caller-controlled request IDs have no validation and can overwrite another trace key; timestamp-generated IDs are not access control. TRACE activation determines exposure; live settings were not verified.

**Recommendation:** remove traces from public output, protect diagnostic access with server permissions, redact content, cap bytes/count/TTL and use server-generated correlation IDs. Logging must default to metadata; raw user text is currently INFO-level even without full debug capture.

### F05 — P1 · bug / commerce correctness · price policy and quantity presentation diverge (O/R)

[`A:src/Storefront/Controller/PricesController.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Storefront/Controller/PricesController.php), `prices`; widget `resolveProductPrice`; Twig logged-in flag. `customers` accepts a non-null guest-checkout customer while UI excludes it. `advanced-first` can select a quantity-10 tier as the current price without quantity 10; reproduced with base 10 / tier 7. The “personal discount” implication is not established by a tier's existence. Tax state/reference price/currency precision are missing from presentation contract.

**Recommendation:** one Shopware price-presentation service receives explicit quantity and context, returns amount, basis, tax/currency and reference-price fields. Product availability and checkout price still require revalidation when used later.

### F06 — P1 · performance / operational risk · blocking inference and mismatched budgets (O/I)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), global `OpenAI`, `chat_with_tools`; [`A:src/Storefront/Controller/ChatProxyController.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Storefront/Controller/ChatProxyController.php). Synchronous SDK calls block an async request's event loop. Two completions are made on the ordinary successful path. The pinned OpenAI 2.7.1 defaults are 600 seconds and two retries, while the proxy gives up after 60 seconds. The exact throughput/continued GPU work requires live measurement (I).

**Recommendation:** lifespan-owned asynchronous client, explicit overall deadline, bounded model concurrency, no hidden retry multiplication, bounded output/context and cancellation. Local slow models need an explicit supported budget, not arbitrarily extended proxy waits.

### F07 — P1 · bug / contract · invalid tool transcript and misleading loop (O/R)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `chat_with_tools`. The loop breaks after the first selection round; it cannot perform dependent follow-up searches. Tool-role messages are appended without their assistant `tool_calls` message. Probe: final roles were `system,user,tool`. A strict provider can reject this; tolerant Ollama behavior is not portability proof. The first no-tool answer is discarded.

**Recommendation:** explicit bounded state machine, complete call/result transcript, retain policy across phases, exit early on valid final output, with provider conformance tests. Do not add an unbounded autonomous agent.

### F08 — P1 · security / operational risk · request and tool work are unbounded server-side (O/R)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `ChatIn`, `sanitize_history`, `chat_with_tools`; chat proxy and tracking controller. Probe accepted a 100,000-character message, 100 fabricated assistant entries and an arbitrary model. Frontend limits do not constrain direct clients. Tool names are forwarded without a registry authorization decision, counts/results lack a coherent budget, and tracking can write large nested payloads. Current MCP tools are reads; this is not evidence of a present order-writing exploit.

**Recommendation:** schema and byte limits at ingress, profile allowlist, per-tool arguments/results validation, allowed scope, global/IP/session quotas and request/model/tool budgets. Unknown tools fail closed before transport.

`sw_get` also interpolates an unvalidated product ID into an Admin API URL path in [`B:shopware_mcp_server.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/shopware_mcp_server.py). Path/query manipulation under the configured Shopware origin is a risk to test, not a demonstrated arbitrary-host SSRF exploit. Fixed destinations plus strict ID schemas and contextual authorization are required; the plugin backend URL is privileged configuration, not directly user-selected input.

### F09 — P1 · bug / reliability · permissive JSON parsing fails unpredictably (O/R)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `normalize_chat_reply`, `normalize_blocks`. `type: []`, `products: 7`, or `style: {}` cause TypeError in isolated probes. Raw malformed JSON can be presented as chat text. No response model validates the successful envelope; custom validation errors differ from framework-default contracts. Dry-run bypasses major validation paths.

**Recommendation:** discriminated response schema, strict bounded validation and stable typed error envelope; bounded repair only where justified. A provider's JSON mode guarantees neither the intended shape nor semantic correctness.

### F10 — P1 · data integrity / architecture · browser telemetry masquerades as conversation (O/R)

[`A:src/Service/ChatTrackingService.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Service/ChatTrackingService.php), `trackMessageEvent`, `resolveNextMessageIndex`, `updateSessionAggregates`; migration; widget storage/indices. Client role, model, usage, text and indices are trusted. A numeric frontend `requestId` is discarded by string-only normalization and ignored by backend `ChatIn`. Separate insertion/aggregation is not atomic or idempotent. Reproduced: after 64 messages, capped history restores next index 61, colliding with previously persisted indices. Concurrent max+1 and read-modify-write operations can lose updates (I).

**Recommendation:** server-created conversations, message sequences, execution IDs and idempotency; authoritative transcript written on server execution. Separate optional untrusted interaction telemetry. Existing rows must not silently become trusted model history.

### F11 — P1 · privacy / state isolation · principal and channel transitions are not explicit (O/I)

[`A:src/Service/ChatSessionResolver.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Service/ChatSessionResolver.php); tracking `touchSession`; widget `storageKey`, `restoreHistory`. Browser keys have no principal/channel scope; session lookup uses one identifier. Customer IDs are only added when present, not cleared on logout; some earlier diagnostics remain. Continued shared-browser use may expose old conversation content or associate later activity with a previous customer (I).

**Recommendation:** bind conversations to a server principal epoch and channel; rotate on logout/account/channel changes, invalidate grants, clear local display data, and define opt-in guest-to-account continuity. Never key ownership solely by a browser-supplied conversation ID.

### F12 — P1 · bug / business reliability · contact success can mean lost lead (O/I)

[`A:src/Storefront/Controller/FormularController.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Storefront/Controller/FormularController.php), `send`; mail service; tracking `storeContactSubmission`; widget `submitContactForm`. Mail happens before best-effort persistence. Handled mail failures plus a swallowed DAL failure can still return HTTP success; widget ignores `not-sent` fields. Quantity and delivery ZIP are not stored. A generated form can omit email/message required by the server. No mail or DB outage was executed against a shop.

**Recommendation:** persist contact plus delivery job before acknowledging; show accepted versus delivered states, retain all approved business fields, use a fixed server-owned form schema and idempotency. Use Shopware Messenger and test transport, not another mandatory queue service.

### F13 — P1 · authorization / privacy · debug visibility is not a server boundary (O)

[`A:src/Resources/app/administration/src/app/acl/index.js`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Resources/app/administration/src/app/acl/index.js); entity definitions in `src/Core/Content`; session-detail UI. Ordinary viewer permissions read entities containing API-aware debug/raw fields. `canViewDebugData` hides controls but does not enforce field-level access. The custom dashboard endpoints themselves correctly have `_acl` checks.

**Recommendation:** separate debug storage and privileged API/entity access, with retention and access audit; test direct API calls with an ordinary viewer, not just UI visibility.

### F14 — P1 · bug / data management · destructive date inputs default silently (O)

[`A:src/Controller/Api/ChatAnalyticsController.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Controller/Api/ChatAnalyticsController.php), `resolveDateRange`, delete actions; [`A:src/Service/ChatAnalyticsService.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Service/ChatAnalyticsService.php), range helpers. Missing/invalid dates become the last 30 days, reversed dates are swapped, including deletion. Overview/export includes sessions by message activity (or no-message creation); deletion uses session creation. Operators can delete a different set from the displayed one. End-of-day seconds and lifetime aggregates further blur semantics.

**Recommendation:** reject incomplete/invalid destructive requests; one half-open UTC range/filter model, preview of exact affected IDs/count, explicit deletion scope and transaction. UI confirmation does not repair an ambiguous server contract.

### F15 — P1 · privacy / missing capability · no complete retention or deletion lifecycle (O)

Migration, tracking service, analytics deletes and [`A:src/AiCommerceAssistant.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/AiCommerceAssistant.php), `uninstall`. No scheduled retention; customer deletion nulls foreign keys but retains personal text/contact data; contacts survive session deletion; contact references have no contact FK; aggregate effects can remain. Disabling debug only affects future capture. No explicit uninstall cleanup when keepUserData is false.

**Recommendation:** purpose-specific retention, verified erasure graph and deletion jobs, separate required contact records from optional transcripts/telemetry, explicit uninstall policy. Hashes are pseudonymous, not proof of anonymity. Legal basis and statutory retention require operator/legal assessment; this audit does not certify GDPR compliance.

### F16 — P1 · security · CSV formula injection boundary is missing (O/I)

[`A:src/Service/ChatAnalyticsService.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/src/Service/ChatAnalyticsService.php), `buildCsv`, `normalizeCsvValue`. Untrusted names/messages are quoted as CSV but formula prefixes are not neutralized. A spreadsheet application can interpret exported cells as formulas (I; application-dependent).

**Recommendation:** tested text-cell export policy covering formula prefixes and control characters, or typed spreadsheet export; do not equate CSV quoting with formula protection.

### F17 — P1 · UX / reliability · queued history and cancellation are inconsistent (O)

Widget `onSubmit`, `processChatRequest`, `clearHistory`, event registration. History is captured before earlier queued answers finish. A user message is displayed/stored/tracked before queue-overflow rejection. No AbortController or lifecycle cancellation; clear/close can be followed by a late answer, and teardown listeners/timers are not comprehensively removed.

**Recommendation:** one accepted active turn per conversation, queue before capture only if needed, server-owned ordering, abort and stale-generation guard, lifecycle cleanup, explicit retry after terminal failure.

### F18 — P1 · operational risk · MCP ownership and errors are fragile (O/I/R)

[`B:app.py`](https://github.com/astrotmo/shopware-ai-chat-backend/blob/fe72dd4af104cef5eaf61104fa73779f0bf7870b/app.py), `McpSessionCache`, `call_mcp_tool`. Lazy contexts are entered by a request and may be closed by a different task: AnyIO cancel-scope ownership problems are plausible but unverified in the pinned runtime (I). A failing request closes a shared session used by others. All exceptions trigger reconnection/retry. A probe lost `isError: true`, returning ordinary text. `structuredContent` is ignored.

**Recommendation:** remove mandatory MCP from the core; if retained temporarily, own its lifecycle in one task, preserve errors, use explicit timeouts and read-only retry rules. Never generalize retries to future writes without idempotency.

### F19 — P2 · architecture / maintainability · monoliths and duplicated contracts (O)

`app.py` (1,377 lines), widget (1,635), tracking service (921); backend tool JSON vs MCP signatures; Twig/subscriber config defaults; browser/backend/admin rendered schemas. These modules combine unrelated policies, making state, IO and transformations hard to test independently. No circular application dependency was established; size alone is not the finding.

**Recommendation:** split by responsibilities and ports in the existing processes. A versioned contract package and generated artifacts remove cross-language drift; no shared runtime framework or new microservice is necessary.

### F20 — P2 · product / UX · category, routing and localization assumptions (O/I)

Widget `renderProductList`, forms and endpoint constants; content Twig; backend prompts. Categories become product links. Root-based URLs ignore shop subpaths/language routes. Mounting is limited to a content-page override; cross-theme coverage requires runtime testing. German prompts and hardcoded text constrain installation elsewhere.

**Recommendation:** distinct category blocks and Shopware-generated URLs, common tested mount, snippets/locales and domain packs. Preserve contact functionality through an explicit adapter rather than legacy route text.

### F21 — P2 · accessibility · chat lifecycle lacks essential controls (O/I)

Widget DOM construction and SCSS. Click-only header control, incomplete keyboard/focus management, no coherent Escape/focus return/live announcement, and missing clear input labeling. Width/height limits exist; visual responsiveness was not tested.

**Recommendation:** semantic buttons, named dialog, accessible input/status, focus behavior suited to a non-modal or modal design, reduced motion and keyboard/screen-reader/mobile acceptance tests. Decide dialog modality before implementing a focus trap.

### F22 — P2 · abuse / availability · weak contact limiter dimensions (O/I)

Form controller keys limiter by user-controlled email plus IP. Changing email changes the bucket and can cause repeated internal/confirmation mail attempts. No proof of actual abuse. Add session/IP/global limits, durable queue bounds and idempotency; retain honeypot and existing validation.

### F23 — P2 · operational risk · deployment is workstation-specific (O)

Compose, Dockerfile, Makefile. GPU mandatory, host-specific GGUF mount, release-candidate runtime, source bind mounts, public ports, no health checks or CPU profile. Environment interpolation differs from service injection; README refers to absent `.env.example`. Health does not check readiness.

**Recommendation:** explicit development/production and CPU/GPU profiles, sanitized example, separate credentials, immutable minimal images, compatibility preflight and dependency-aware readiness. Verify real operator hardware before setting performance targets.

### F24 — P2 · privacy / packaging · environment-file handling is unsafe by convention (O/I)

Tracked `.env.local` declares credential keys; `.dockerignore` does not exclude it. Values inspected here are empty or placeholders for credentials. A live secret leak is **not established**, and blanket credential-rotation claims would overstate evidence. Future real values could enter Git/image layers (I). Use sanitized examples and excluded mounted secrets; inspect history through an authorized secret-review process if actual exposure is suspected.

### F25 — P2 · reliability · domain reload and mutable globals lack isolation (O/R)

Domain loader/resolver; `app.py` initialization. Resolver is a useful isolated abstraction. However, malformed hot-reloaded JSON raises before chat's main exception boundary; a probe reproduced JSONDecodeError. Global indexes, trace maps, model/MCP clients complicate tests and multiple workers. Use validated atomic domain releases, last-known-good reload, lifespan resources and injected collaborators.

### F26 — P2 · performance / analytics · unbounded admin loading and approximate reporting (O/I)

Session list/detail Administration components and analytics service. Text search uses only first 200 matching messages; detail retrieves every page of related records; old async loads can overwrite a newer route's view; CSV ends in an in-memory string. These can omit results or degrade large histories (I for volume impact). Add server pagination/filtering, stale-load guards, cursor exports and documented metric definitions.

### F27 — P2 · testing / operational risk · important boundaries lack executable repository gates (O)

[`A:tests/Storefront/Controller/ChatContextControllerTest.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/tests/Storefront/Controller/ChatContextControllerTest.php), [`A:tests/Storefront/Controller/PricesControllerTest.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/tests/Storefront/Controller/PricesControllerTest.php), [`A:tests/Storefront/Controller/FormularControllerTest.php`](https://github.com/astrotmo/AiCommerceAssistant/blob/78581facc8470eef3e85ef667339693616c63cc7/tests/Storefront/Controller/FormularControllerTest.php) contain 15 PHPUnit methods using mocks. They test selected validation/token/mail-success paths; price tests do not verify an actual calculated result or guest-checkout customer. The Shopware bootstrap needs a host environment. B has no tracked test suite; stage documentation explicitly refers to ignored local tests. No CI, contract, browser, full persistence or provider conformance gate is present. See target test pyramid and evidence limits.

### F28 — P3 · technical debt · abandoned compatibility and scaffolding (O)

Unused `main.py`; `shop_examples` parsed but unused; resolver `iter_match_mcp_terms` unused; inert `/paul-ai-chat/contact`; fallback FastMCP import; ad-hoc JSON/text result normalization; loop phase scaffolding without a real loop. Remove only after contract tests replace the behavior; retain useful model alias configuration inside the provider adapter. B README claims MIT but contains no license file in the audited tree; resolve product packaging metadata.

### F29 — Future · capability · retrieval expansion and streaming

No embeddings/vector index are present. Search quality should first improve through contextual Shopware search and vocabulary normalization. Hybrid retrieval/reranking require measured recall failures, index ownership/invalidation and authorization-safe hydration. Streaming needs cancellation/backpressure and final validated commerce blocks first. Neither is a prerequisite for the initial rework.

## Failure-mode matrix

| Failure | Current behavior | Required target behavior / change |
| --- | --- | --- |
| Backend unavailable | Proxy returns transport 502; no coherent retry identity | Typed dependency error; same execution status/idempotency key; do not append duplicate user turn |
| Shopware tool API unavailable | MCP HTTP error; broad reconnect/retry; chat often fails | Typed tool failure within remaining deadline; safe no-commerce answer or explicit failure |
| Model unavailable / slow | Sync call/retries; 60-second proxy can stop first | Admission + async deadline; cancel and terminal execution status |
| Context overflow | Provider error; only client character limits | Server context budget including tools/system/output reserve; prune complete turns with provenance |
| Malformed model output | Raw text fallback or TypeError/502 | Validate, at most bounded repair within deadline, then stable `MODEL_OUTPUT_INVALID` |
| Tool timeout/exception | Sequential wait, reconnect once, weak error semantics | Tool result type with retryability, bounded attempt count, no unauthorized fallback |
| MCP unavailable | Lazy connection failure | No MCP dependency in core; optional adapter degrades independently |
| Chat DB write failure | Tracking may disappear independently of chat | Canonical user turn accepted only after persistence; fail clearly if durability unavailable |
| Contact DB/mail failure | Mail-first, storage best effort, possible false success | Persist + outbox before accepted; retry delivery and expose operator state |
| Concurrent / duplicate request | Duplicate indices, stale histories, aggregate races | One active execution lease, transaction, atomic sequence and deduplication |
| Browser disconnect / clear | Work continues, stale completion may render | Abort propagation plus generation check; retrieve terminal status on reconnect |
| Backend restart / deployment | In-flight work and traces lost | Shopware execution lease expires; mark interrupted; explicit retry policy, compatible contract rollout |
| Expired session / account switch | No explicit AICA rotation/ownership recheck | New principal epoch; old grants invalid; owner-authenticated conversation access |
| Analytics export / delete failure | Memory-bound export; divergent range semantics | Bounded exports, exact preview/filter and auditable deletion transaction |

## Documentation discrepancies

Documentation was checked after source reconstruction. Important claims are classified rather than accepted by authority.

| Document / claim | Classification | Source-grounded disposition |
| --- | --- | --- |
| A README: Shopware validates and enriches chat requests | Partially outdated / overstated | Proxy only checks parseability and forwards payload; prices/contact are separate context-aware routes. |
| A main architecture/configuration/development: routes, DAL, configuration, host build/test workflow | Current but incomplete | Correct structural guide; does not establish auth enforcement, successful real prices or end-to-end tests. |
| Context-token naming suggests protected backend execution | Contradicted if interpreted as enforcement | Anonymous signed claims exist; backend deliberately ignores them. Current test confirms no customer claim. |
| B README setup/defaults and model snippets | Partially outdated | Required request model, absent env example, two completion calls and deployment env differences need explicit contracts. |
| A stage architecture-analysis and B stage walkthrough: July 27 runtime/model/network checks | Still useful historical context | Not rerun in this audit; do not infer current deployment, GPU usage or runtime versions from those observations. |
| B stage walkthrough: ignored local tests | Current description of a repository gap | No tests supplied in either tracked branch; their previous execution cannot be verified. |
| A stage notes: credential-bearing environment keys | Current, with qualification | Keys exist; empty/placeholder values do not establish a usable exposed secret. Rotation requires evidence of actual exposure. |
| `docs/text*.txt`: shop-specific commercial/domain examples | Historical context | No runtime document retriever loads them; not generic product policy. |
| B README license statement | Incomplete packaging | No corresponding LICENSE file in audited tree. |

## Recommended order

Close F01–F09 and public-diagnostic exposure in a containment phase; make that phase reviewable before the larger state migration. Then establish canonical conversation and contextual CommerceGateway contracts, followed by frontend/contact/privacy migration. [REWORK_PLAN.md](REWORK_PLAN.md) defines phases and acceptance gates. No production fixes were made during this audit.
