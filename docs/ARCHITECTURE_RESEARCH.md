# Architecture research — engineering decisions for AICA

Research date: 2026-09-02. Source reconstruction preceded research. Links below are primary platform/vendor/standards sources or first-party engineering publications. Statements in the “AICA consequence” column are recommendations/inferences, not claims that those sources prescribe this application's design. Documentation is current at access time unless a version is pinned explicitly; production dependencies were not upgraded.

## Research outcome

Retain Shopware-native business behavior, FastAPI and local runtimes. Introduce narrow contracts and deterministic authorization/hydration. Use a bounded workflow rather than a framework-heavy agent. Keep MCP as an optional interoperability adapter. Start with contextual Shopware search rather than importing thesis-era RAG machinery. Make state and error ownership explicit before streaming or broad provider support.

## Shopware architecture and product packaging

| Source and observed guidance | AICA consequence |
| --- | --- |
| [Plugin base guide](https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide) and [plugin lifecycle](https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/plugin-lifecycle.html) describe platform plugin registration and lifecycle hooks. | Keep the native plugin. Test install/update/deactivate/uninstall and deliberate keep-data behavior; a cleanup TODO is not a product lifecycle. |
| [Database migrations](https://developer.shopware.com/docs/guides/plugins/plugins/database/database-migrations.html) explains Shopware migration classes and non-destructive/destructive evolution. | Add forward migrations for conversation/execution/contact ownership. Do not edit an already applied initial migration; separate backfill, validation and cleanup. |
| [Adjusting a service](https://developer.shopware.com/docs/guides/plugins/plugins/services/adjusting-service.html) documents decoration and abstract service contracts. | Make contextual search/presentation and contact destinations replaceable through a few deliberate services, not dozens of generic interfaces. Preserve existing platform decorators. |
| [Extensions versus events](https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/extension-vs-events.html) and [custom extension points](https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/creating-custom-extension.html) distinguish controlled extension flow from event notification. | Use a service/extension when an integration must change a result; use a versioned post-commit event for contact notifications. Events do not replace durable outbox delivery. Confirm API availability on the minimum supported 6.7 patch. |
| [Plugin configuration](https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/use-plugin-configuration.html) describes system configuration access. | Retain channel-aware product/widget/mail policies; separate backend credentials and model-runtime settings from public widget config. One typed config resolver eliminates conflicting defaults. |
| [Storefront custom JavaScript](https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/add-custom-javascript.html) uses Shopware's plugin system. | Keep that lifecycle and split the large widget internally. A React/Vue rewrite of the Storefront widget is not required to fix ordering, cancellation or accessibility. |
| [Administration ACL](https://developer.shopware.com/docs/guides/plugins/plugins/administration/permissions-error-handling/add-acl-rules.html) covers privileges and Administration handling. | Server entity/action authorization is the actual boundary. Separate raw diagnostics from ordinary read permissions and test direct API requests. |
| [HTTP cache concepts](https://developer.shopware.com/docs/concepts/framework/http_cache.html) and [reverse HTTP cache](https://developer.shopware.com/docs/guides/hosting/infrastructure/reverse-http-cache.html) explain Shopware's cached delivery layers. | Keep public page HTML free of personalized chat/price payloads; fetch owner-specific state through non-cacheable endpoints. Invalidate catalog caches correctly and never globally cache customer quotes. |
| [Sales channels](https://developer.shopware.com/docs/concepts/commerce/catalog/sales-channels.html) and [Store API](https://developer.shopware.com/docs/concepts/api/store-api.html) define contextual storefront access. | Backend Admin API credentials are a poor substitute for the live customer's commerce context. Use Shopware-owned contextual gateway services. |
| [Store API authentication/context](https://shopware.stoplight.io/docs/store-api/8e1d78252fa6f-authentication-and-authorisation) explains access and context tokens. | Treat context tokens as sensitive session material. Resolve context inside Shopware and send only a bounded execution capability to Python; never trust client-supplied customer IDs. |
| [ProductSearchRoute at v6.7.0.0](https://github.com/shopware/shopware/blob/v6.7.0.0/src/Core/Content/Product/SalesChannel/Search/ProductSearchRoute.php) applies search visibility and platform search machinery. [SalesChannelProductDefinition](https://github.com/shopware/shopware/blob/v6.7.0.0/src/Core/Content/Product/SalesChannel/SalesChannelProductDefinition.php) defaults repository reads to linked-product availability. | This pinned source distinction matters: simply replacing Admin API with a SalesChannel repository is not enough to reproduce storefront discovery policy. Use the route appropriate to search versus detail. Actual installed patches/extensions still require integration tests. |
| [Price display concepts](https://developer.shopware.com/frontends/guides/e-commerce/prices.html) and [price calculation extension](https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/customize-price-calculation.html) distinguish calculated, advanced and reference prices and platform calculation. | Let Shopware produce a complete context/quantity-aware presentation DTO. A first tier is not necessarily the requested quantity's price or a personal discount. |
| [Adding scheduled tasks](https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-scheduled-task.html), [task operation](https://developer.shopware.com/docs/guides/hosting/infrastructure/scheduled-task.html) and [message queue](https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html) document existing background infrastructure. | Use scheduled batched retention and durable contact jobs. Operators must run the relevant workers; no second mandatory queue technology is needed. |
| [Extension platform overview](https://developer.shopware.com/docs/guides/development/extensions/) distinguishes plugin and app deployment models. | “Generally installable” initially means supported plugin-capable Shopware deployments. App-only SaaS support would require a different packaging/integration effort, not just changing a backend URL. |

## LLM application architecture and runtime behavior

| Source and observed guidance | AICA consequence |
| --- | --- |
| [FastAPI async behavior](https://fastapi.tiangolo.com/async/) distinguishes async request handlers from directly called synchronous utilities. | Changing a route to `async def` does not make the synchronous OpenAI SDK non-blocking. Use an async adapter and explicit concurrency/backpressure. |
| [FastAPI lifespan](https://fastapi.tiangolo.com/advanced/events/) provides startup/shutdown ownership for shared resources. [Async tests](https://fastapi.tiangolo.com/advanced/async-tests/) describes async client testing and lifespan considerations. | Create/close model and gateway clients in lifespan; inject fakes in application tests. Exercise startup/shutdown explicitly, especially if temporarily retaining MCP. |
| [OpenAI Python SDK](https://github.com/openai/openai-python) exposes asynchronous clients and configurable timeouts/retries. [Pinned v2.7.1 constants](https://github.com/openai/openai-python/blob/v2.7.1/src/openai/_constants.py) set a 600-second timeout and two retries. | Set AICA deadlines deliberately and prevent nested retries. The observed 60-second Shopware proxy is not coordinated with these defaults. The SDK is a transport implementation, not the application architecture. |
| [Ollama OpenAI compatibility](https://docs.ollama.com/api/openai-compatibility) documents a compatibility surface, including runtime-specific context configuration. | Support tested feature profiles; `/v1` naming does not promise identical behavior. Keep aliases/Modelfile context settings within runtime configuration rather than request DTOs. |
| [Ollama tool calling](https://docs.ollama.com/capabilities/tool-calling) shows appending assistant tool calls and tool results before the next completion. | Fix the incomplete transcript and test every declared runtime/model combination. A tolerant model is not proof of a valid portable protocol. |
| [Ollama structured outputs](https://docs.ollama.com/capabilities/structured-outputs) describes JSON-schema output. | Prefer schema-constrained proposals where supported; still validate on receipt and verify identities in Shopware. Do not assume every local/cloud endpoint has identical support. |
| [Ollama context length](https://docs.ollama.com/context-length) describes context configuration and memory implications. | Select context and concurrency from reference hardware measurements. A large advertised context does not justify sending all history, tool data and diagnostics. |
| [vLLM online serving](https://docs.vllm.ai/en/latest/serving/online_serving/), [tool calling](https://docs.vllm.ai/en/latest/features/tool_calling/) and [structured outputs](https://docs.vllm.ai/en/latest/features/structured_outputs/) document server features, model/parser/template requirements and structured generation. | vLLM is a plausible optional local profile, not an initial mandatory runtime replacement. Test the actual model/parser/template combination before advertising tools and schema support. |
| [Structured outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs) distinguishes schema adherence from ordinary JSON generation and details supported behaviors. | Separate syntax/schema validation from commerce truth. Include refusal, truncation and unsupported-schema behavior in adapters. No proprietary model is required by this design. |
| Anthropic's [Building effective agents](https://www.anthropic.com/engineering/building-effective-agents) (2024) argues for simple composable patterns and explicit workflows; the publication's examples are historical. | Adopt the enduring workflow principle, not its model/version examples. AICA needs a small bounded tool state machine; LangChain/LangGraph are not justified by four catalog reads. |
| Anthropic's [Context engineering](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents) (2025) discusses selecting and managing finite context. | Build context from canonical turns, bounded tool results and optional domain matches. Summaries and long-context expansion are later optimizations with provenance, not memory/authorization mechanisms. |

Recommended execution policy: one overall deadline, explicit tool/round/output limits, cancellation, stable error taxonomy and minimal retries. Streaming is deferred until ownership and terminal states are correct. Streaming partial JSON cards is particularly inappropriate because commercial objects must be validated as a whole. These are application-design conclusions from the inspected failure paths, not vendor promises.

## MCP decision

[MCP Streamable HTTP transports, specification 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports) defines HTTP transport, session behavior and security requirements such as origin validation. This versioned specification is a stable reference, not a claim that it is the newest available protocol revision. The [2026-07-28 authorization tutorial](https://modelcontextprotocol.io/docs/2026-07-28/tutorials/security/authorization) describes standardized authorization for HTTP MCP deployments. Authentication, authorization and Shopware customer context are separate concerns.

| Approach | What it solves here | Cost / limitation | Decision |
| --- | --- | --- | --- |
| Mandatory standalone MCP server | Standard tool transport between one backend and four catalog functions | Extra process/network session, duplicate schemas, lifecycle/error/auth complexity; still no storefront context | Remove from core path |
| Internal typed tool registry | Dispatch, validation, budgets, testable handlers inside backend | No cross-product discovery standard | Use for orchestration |
| Typed Shopware HTTP gateway | Cross-language commerce boundary with context and scopes | Requires request authentication, contract versioning and callback capacity | Use for commerce |
| Direct Admin API tools | Easy integration credential setup | Integration authority is not customer/storefront authority | Do not use for storefront catalog decisions |
| Optional MCP over the same services | Genuine outside client interoperability | Additional authorization and lifecycle work only when enabled | Reserve extension point; no initial implementation |

The recommendation is based on this deployment's single backend consumer, not a general judgment against MCP. Its discovery/interoperability benefit becomes worthwhile only with an actual external-client requirement. If MCP remains during migration, own its async resources correctly and preserve `isError`/structured results; protocol standardization does not excuse unsafe retries or context bypasses.

## Retrieval, indexing and caching

[Elastic hybrid search](https://www.elastic.co/docs/solutions/search/hybrid-search) and [reciprocal rank fusion](https://www.elastic.co/docs/reference/elasticsearch/rest-apis/reciprocal-rank-fusion) illustrate practical combination of lexical/semantic result sets. They do not demonstrate that AICA needs a new search cluster.

| Candidate | AICA fit | Adoption gate |
| --- | --- | --- |
| Shopware lexical search + exact product number | Existing catalog semantics, no new data pipeline | Initial implementation; measure visible-product recall and variant correctness |
| Curated synonym/domain expansion | Existing useful isolated resolver; predictable and local | Retain as optional language/domain pack with regression fixtures |
| Semantic candidate retrieval | Could help vague natural-language needs | Labeled failures after lexical/synonym baseline, acceptable latency and index operations |
| Hybrid search / reranking | Could improve mixed exact and conceptual queries | Demonstrable ranking gain, authorized hydration and bounded extra cost |
| Standalone vector DB | Adds indexing, deletion, backup and channel-filter responsibility | No initial need established; prefer existing infrastructure if later required |
| Long-context catalog stuffing | Avoids explicit search only for tiny data but raises token/memory/staleness cost | Not recommended for a changing catalog |
| Result cache | Helps repeated metadata retrieval | Explicit key/invalidation policy; never cross-context personalized price reuse |

Any future index supplies candidates, not authoritative availability/price/permissions. Separate query-quality evaluation from model answer evaluation so a poor retrieval result is not concealed by fluent text.

## Contracts and observability

[OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0.html) supplies a language-neutral HTTP contract with aligned JSON Schema semantics. Choose 3.1 because it fits the intended tooling; this is not a claim that it is the latest OpenAPI release. Export from one source, pin contract artifacts and test runtime envelopes across PHP/Python/TypeScript. Generated types are useful only alongside runtime validation and semantic fixtures.

[OpenTelemetry's 2026 GenAI observability discussion](https://opentelemetry.io/blog/2026/genai-observability/) and the [GenAI attribute registry](https://github.com/open-telemetry/semantic-conventions-genai/blob/main/docs/registry/attributes/gen-ai.md) support structured model/tool metadata. This convention area is evolving; pin a revision and treat content capture as a separate sensitive feature.

Start with request/trace IDs, profile/prompt/contract versions, queue/model/tool latency, token counts when available, timeout/cancellation/validation failures and contact delivery attempts. Unknown usage stays null. Keep high-cardinality/customer text out of metric labels. An optional local collector gives useful traces without mandatory hosted LLM observability software. Quality datasets use synthetic or explicitly approved/redacted examples; production transcripts are not automatically training/evaluation assets.

## Security and privacy

| Source | AICA application |
| --- | --- |
| [OWASP prompt injection prevention](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html) | Retrieved catalog descriptions and user history are untrusted data. Prompts are one layer; real controls are allowed tools, contextual authorization, budgets and deterministic commerce rendering. |
| [OWASP SSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) | Backend/provider/gateway destinations come from privileged fixed configuration, not model/user URLs. Validate scheme, destinations and redirects. Localhost/private ranges are legitimate local-first needs, so use an operator allowlist rather than a blanket ban. |
| [OWASP CSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html) | Preserve appropriate same-origin/non-simple request protections for browser actions, while adding real service authentication separately. AJAX headers and CORS are not identity. |
| [OWASP CSV injection](https://owasp.org/www-community/attacks/CSV_Injection) | Formula interpretation is separate from CSV quoting. Test exported adversarial cells in supported spreadsheet clients. |
| [EDPB secure personal data guidance](https://www.edpb.europa.eu/sme/be-compliant/secure-personal-data_en) | Minimize raw message/diagnostic collection, limit access and define retention/deletion across records and backups. Local inference reduces third-party transfer but does not remove privacy duties. |
| [EDPB discussion of pseudonymisation](https://www.edpb.europa.eu/news/edpb-adopts-pseudonymisation-guidelines-and-paves-the-way-to-improve-cooperation-with_en) | Hashing identifiers does not automatically anonymize linked conversation/contact records. This source discusses guidelines; it is not a finding that AICA meets or violates a particular legal obligation. |

Keep existing DOMPurify/textContent protections; no successful XSS exploit was demonstrated. Add intentional URL/image policy and test safe rendering rather than claiming sanitization proves all privacy or content safety. Contacts require anti-abuse limits and durable acceptance; future cart/order tools require stronger command authorization and confirmation than current read tools.

## Explicit non-adoptions and unresolved evidence

No academic papers were added merely because the project uses an LLM. No new agent framework, SaaS observability service, embedding provider, vector database or orchestration platform is recommended as a prerequisite. Full SaaS tenancy is outside the initial product boundary.

Research cannot determine actual GPU throughput, proxy/PHP capacity, supported installed Shopware patch/extensions, mail durability, live model schema behavior, data-retention legal basis or current network exposure. Those require the named integration/deployment checks in [REWORK_PLAN.md](REWORK_PLAN.md). Provider and framework documentation should be checked again when implementing against a pinned release.
