# Architecture decisions

Date: 2026-09-02. All decisions are **proposed for review**, not implemented or already accepted by the operator. Evidence is in [audit](ARCHITECTURE_AUDIT.md); external rationale is in [research](ARCHITECTURE_RESEARCH.md). Breaking-change IDs refer to [REWORK_PLAN.md](REWORK_PLAN.md).

## ADR-01 — Keep two application deployment units

**Current:** Shopware plugin, Python backend, mandatory MCP service and model runtime.

**Alternatives:** put inference orchestration entirely in PHP; add more microservices; keep the existing topology unchanged.

**Decision:** retain Shopware and a modular FastAPI backend, with independently deployable model runtime. Remove a mandatory catalog MCP process after gateway migration.

**Why:** Shopware remains authoritative for its own commerce extensions and identity; Python keeps mature model IO/tooling. Splitting internal modules improves tests without adding network boundaries.

**Tradeoff:** cross-language API and network deployment remain. Callback capacity must be verified; existing Shopware Messenger can decouple execution where synchronous hosting is unsuitable.

**Migration:** BC01/BC04/BC11. Preserve existing platform integrations, introduce typed boundary and retire old route wiring after cutover.

## ADR-02 — Move commerce access into contextual Shopware services

**Current:** MCP Admin API searches plus separate browser price enrichment.

**Alternatives:** add filters to Python Admin API criteria; call Store API directly from Python; route typed tools through the plugin.

**Decision:** plugin-owned search/detail/category/price services, exposed through an authenticated scoped gateway. Use the appropriate Shopware Store API route/decorator behavior.

**Why:** context, installed business extensions, availability and price rules remain in one system. Read-only Admin credentials still have the wrong authorization scope for storefront decisions.

**Tradeoff:** a gateway and short-lived execution context are required. Avoid reimplementing platform search and avoid synchronous callback worker starvation.

**Migration:** BC04/BC05. Remove backend Shopware Admin credentials from the core path; re-test custom search/pricing decorators and add migrations for observed external consumers.

## ADR-03 — Make MCP optional

**Current:** one Python client, one MCP server, four fixed catalog reads and duplicate schemas.

**Alternatives:** retain and harden MCP; expand discovery; replace with typed internal dispatch plus a commerce HTTP adapter.

**Decision:** use a small ToolRegistry and typed CommerceGateway; reserve MCP as an optional transport adapter.

**Why:** no external interoperability consumer is observed, while lifecycle, retry and error handling already create concrete faults. A standard protocol cannot supply missing Shopware customer context.

**Tradeoff:** future MCP consumers need an adapter and authentication. Preserve their possibility through service contracts rather than a permanently running service.

**Migration:** BC04; existing external MCP clients, if any, must be inventoried and moved to an optional adapter. Do not assume there are none outside these repositories.

## ADR-04 — Shopware owns canonical conversation and execution state

**Current:** browser history drives the model; best-effort client telemetry drives the database; backend is ephemeral.

**Alternatives:** backend conversation database; fully stateless browser-owned history; Shopware canonical state.

**Decision:** conversations/messages/execution leases and idempotency in Shopware. Backend receives a bounded trusted transcript for one execution and keeps transient model/tool messages only.

**Why:** identity, Administration, contacts and deletion already reside in Shopware; a second durable store would duplicate ownership. Browser analytics cannot prove ordering, role or provider usage.

**Tradeoff:** canonical chat now depends on DAL availability, and retention becomes an explicit product obligation. Store only the necessary data and offer short-lived conversation policies.

**Migration:** BC02/BC06/BC10. Keep legacy rows identifiable; do not feed them to models as trusted history. Rotate owner context explicitly.

## ADR-05 — Thin asynchronous model port and tested provider profiles

**Current:** synchronous global OpenAI client with Ollama-specific parameters and browser-selected model.

**Alternatives:** Ollama-only code; universal provider framework; small adapter with capability profiles.

**Decision:** minimal async ModelPort, first OpenAI-compatible adapter, explicit local/remote profiles; native adapters only for a required unsupported feature.

**Why:** preserves local-first while isolating actual differences in tools, schema output, context configuration, token metrics and cancellation.

**Tradeoff:** compatibility still requires per-model/runtime tests. Avoid promising that every OpenAI-compatible endpoint supports every feature.

**Migration:** BC03. Move model aliases/endpoints/secrets into server configuration. No silent cloud fallback or unrestricted browser model ID.

## ADR-06 — Bounded workflow, complete transcript and validated proposals

**Current:** one selection round, unconditional second completion, missing assistant tool-call message, JSON-object mode and permissive normalization.

**Alternatives:** unrestricted agent loop; fixed two-call pipeline; explicit bounded state machine.

**Decision:** state machine with early final exit, complete call/result pairs, tool/round/token/time limits and schema-validated answer proposals. At most one repair within the same budget.

**Why:** handles follow-up tool needs while bounding compute and preventing protocol drift. Shopware verifies reference identity and fills commerce fields.

**Tradeoff:** stricter output handling exposes weak model behavior instead of showing arbitrary malformed text. Model selection must meet conformance and quality gates.

**Migration:** BC01/BC05. Retire `formular`/category-as-product compatibility through deliberate schema migration. Deterministic cards do not prove free-text truth; control factual commerce answers separately.

## ADR-07 — One versioned contract source, small language adapters

**Current:** separate JSON shapes in PHP, Python, prompts, JS and MCP signatures.

**Alternatives:** informal documentation; shared runtime package; generated contract artifacts with fixtures.

**Decision:** backend-owned Pydantic schema source for cross-system v1, exported OpenAPI 3.1/JSON Schema, pinned digest in both repositories, generated TS types where helpful and small PHP DTO validators.

**Why:** directly removes observed response/error/schema drift without a new package service. The plugin's public commerce DTO is separately owned by Shopware presentation.

**Tradeoff:** releases need compatible artifacts and cross-language tests. Schema changes cannot be casual independent repository changes.

**Migration:** BC01. Add compatibility preflight and fail closed on unsupported major versions; temporary adapters have explicit removal criteria.

## ADR-08 — Reuse Shopware messaging for durable contact delivery

**Current:** mail-first, best-effort database recording, success even when handled failures lose a lead.

**Alternatives:** continue synchronous delivery; introduce separate message broker; use native jobs plus transactional acceptance.

**Decision:** contact + delivery intent persisted before acknowledgment; worker delivery with retry/idempotency via Shopware infrastructure.

**Why:** “accepted” becomes a meaningful durable promise, with observable delivery status.

**Tradeoff:** workers are operational prerequisites; exactly-once SMTP is not guaranteed. UI distinguishes acceptance from delivery.

**Migration:** BC07. Preserve templates and map custom destinations/events; save quantity/ZIP and approved fields that are currently lost.

## ADR-09 — Privacy-safe operational metadata, separate restricted diagnostics

**Current:** raw user text in logs, optional public traces, API-aware raw data behind UI-only hiding, no complete retention.

**Alternatives:** disable all observability; mandatory hosted tracing; minimal local-compatible metrics with optional restricted content capture.

**Decision:** metadata by default, no public trace endpoint, protected diagnostics, purpose-specific retention/deletion and optional OpenTelemetry exporter.

**Why:** latency/error visibility does not require exposing conversations. Canonical data and untrusted analytics have different purposes and access needs.

**Tradeoff:** opt-in raw diagnostics may occasionally be needed for model failures; collecting them requires explicit bounded access and retention.

**Migration:** BC08/BC09/BC10. Revisit exports and erasure graph; old raw data needs deletion policy, not merely capture-off flags.

## ADR-10 — Keep native widget; defer streaming and vector retrieval

**Current:** large Shopware widget, complete-response JSON, lexical/domain lookup and no vector index.

**Alternatives:** new SPA framework; token streaming immediately; RAG platform/index; modularize existing widget and improve contextual search first.

**Decision:** native lifecycle with modular state/API/renderers, cancellation, snippets and accessibility. Add streaming or hybrid retrieval only after measurable UX/search requirements.

**Why:** current correctness defects are ordering, trust and failure semantics; neither a framework nor embeddings resolve them.

**Tradeoff:** initial answers arrive as completed blocks. Local-model latency may justify progress/status or asynchronous execution before token streaming.

**Migration:** BC05/BC12. Replace hardcoded routes/mount assumptions and explicitly migrate custom themes/renderers.

## ADR-11 — Product reuse before SaaS tenancy

**Current:** one-shop configuration, hardcoded development paths, domain-specific prompts.

**Alternatives:** preserve bespoke installation; build shared multi-tenant SaaS now; package one backend per installation with channel-aware policies and adapters.

**Decision:** support documented local and dedicated remote deployments, CPU/GPU profiles, configurable domain packs and clean extension points. Full SaaS tenancy is future work.

**Why:** general installation does require clean lifecycle, locales, secrets and compatibility; it does not require billing or shared tenant storage.

**Tradeoff:** more than one shop requires separate backend deployments initially. App-only Shopware hosting needs a distinct integration project.

**Migration:** BC03/BC11/BC12. Remove workstation paths, declare requirements and support matrix, preserve custom behavior through configured adapters.
