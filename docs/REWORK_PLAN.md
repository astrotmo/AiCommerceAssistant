# Rework plan — meaningful implementation milestones

Date: 2026-09-02. **Planning only; no phase has been implemented.** This plan assumes the [target](TARGET_ARCHITECTURE.md) is approved directionally in a later implementation session. Baseline SHAs and audit limitations are in [AUDIT_EVIDENCE.md](AUDIT_EVIDENCE.md). Do not treat historical stage runtime checks as release gates already passed.

## How a later session should start

Fetch/re-read current main/stage and deployment configuration safely; compare against the audited SHAs. Inspect applicable repository instructions and preserve user work. Establish a disposable Shopware 6.7 test installation, fake mail transport, local test model or deterministic provider stub and synthetic fixtures. Inventory externally deployed route/DAL/Twig/MCP consumers before deciding compatibility adapters. Do not infer other plugins' implementations from historical names.

Implement in isolated branches with explicit phase gates. Keep each commit meaningful and reviewable; coordinate the two repository releases through a tested contract digest. There is no justified calendar estimate without the deployment and consumer inventory.

## Phase 1 — Contain unsafe execution and commerce output

**Goal:** close the highest-risk exposure and correctness gaps without replacing persistence or rebuilding the UI.

**Areas:** A chat proxy/price controller/product renderer/config; B auth/ingress/model calls/output validators/trace routes; deployment profiles. Addresses F01–F09 and the immediate diagnostics part of F13.

**Prerequisites:** safe test environment; documented network paths and secret configuration; supported runtime/model baseline. This is the recommended first implementation phase.

**Behavior changes:** require backend service authentication; restrict runtime/MCP ports; remove public traces/raw INFO content; enforce message/body/model/tool/deadline limits; use async model calls and complete tool transcripts; reject malformed output and model prices; correct guest-checkout/quantity presentation. Existing unrestricted catalog tools must be disabled behind an explicit feature flag until contextual discovery is available, or adapted to the minimal contextual gateway in this phase. Do not leave F02 open while calling the deployment production-ready.

**Breaks:** BC01 temporarily adds auth/validation; BC03 removes unrestricted model selection; BC05 removes model price/invalid block fallback; BC08 protects diagnostics. Existing scripts may need credentials and valid bounded payloads.

**Tests:** unauthenticated calls perform zero model/tool work; wrong credentials; oversized history/body; unauthorized model/tool; malformed output; complete assistant/tool transcript; model and proxy deadline; trace access; price visibility for anonymous/guest-checkout/account; quantity tiers; no displayed price on lookup failure; hidden/other-channel catalog fixtures or explicit feature-disabled behavior.

**Migration:** deploy compatible credentials/config and backend/plugin changes in a planned sequence; old versions must fail visibly rather than expose an unauthenticated fallback. Keep database unchanged where possible. Disabling catalog recommendations is an explicit temporary product limitation, not a silent behavior change.

**Done:** critical security/commerce fixtures pass, supported profile works locally, quotas/deadline are observable, no public diagnostics, rollback procedure documented. Remaining phases are still required for durability and product release.

## Phase 2 — Establish contracts and contextual commerce gateway

**Goal:** replace Admin API/MCP discovery with a testable Shopware-owned commerce boundary.

**Areas:** A abstract search/detail/category/price presenter, internal gateway, configuration; B contracts, ToolRegistry, gateway client, provider port; schema artifacts in both repositories.

**Prerequisites:** Phase 1 containment. Run a small deployment spike to verify PHP callback worker capacity and release session locks. Select synchronous execution only if bounded capacity can be reserved; otherwise use a Shopware execution job and polling in Phase 3. Document the choice and resource limits.

**Behavior changes:** formal v1 envelopes/errors/capabilities; authenticated scoped read grants; search uses actual channel/language/customer visibility; final commerce DTOs come from Shopware. Categories have their own type and route. Thin async provider profile replaces global provider assumptions.

**Breaks:** BC01/BC03/BC04/BC05. MCP is no longer needed by core catalog flow; backend Admin API credentials can be removed after verification. Existing MCP consumers migrate to an optional adapter if proven necessary.

**Tests:** shared fixtures across PHP/Python/TS; contract major mismatch; unauthorized/expired/wrong-owner grants; replay/call-ID conflict; destination/redirect rules; real SearchRoute visibility, variants, locale/currency/quantity; invalid model references; provider conformance; two concurrent chat callbacks plus ordinary storefront requests.

**Migration:** introduce new routes beside old internal adapters, with a single feature switch per execution. Both paths call the same deterministic commerce service; do not duplicate business logic. Pin contract artifacts before activating new backend calls. Remove old credentials only once the old path is unused.

**Done:** the entire product search→selection→presentation flow is contextual and deterministic; no mandatory MCP process or integration credential in the new core; callback deployment gate passed or asynchronous delivery selected; extension contracts documented.

## Phase 3 — Canonical conversation, execution and concurrency

**Goal:** remove browser authority over history/order and make retries, cancellation and restarts predictable.

**Areas:** A DAL migrations, conversation/message/execution services, owner binding, server sequence/idempotency, backend client; B request context and bounded orchestration; temporary frontend adapter.

**Prerequisites:** v1 contract, gateway and execution delivery decision. Decide short-lived/account conversation retention policy and legal-purpose configuration before storing new canonical data.

**Behavior changes:** server creates owner/channel-bound conversations and accepted turns; one active execution lease; same-key/same-input retries return same result, changed input conflicts; server-provided bounded transcript; expiry/cancel/account transitions invalidate grants. Legacy analytics remains distinguishable and is not imported into model memory.

**Breaks:** BC02/BC06/BC10. Browser history/indices/role/model/usage claims stop determining authoritative records. Existing direct DAL/analytics consumers need schema adapters or major-version migration.

**Tests:** transactional sequence races, duplicate requests, late completion versus cancellation, backend restart/lease expiry, logout/account/channel switch, guest-to-account policy, owner read denial, DB failure before and after execution, full-tool-group context trimming, deployment during active work.

**Migration:** additive tables/fields first; classify existing records as legacy; back up and verify counts; migrate approved contact links only after ownership rules are known. Use idempotent migrations and a single canonical writer. Avoid long dual-writing periods that make two stores disagree.

**Done:** one truthful transcript per conversation, atomic accepted/terminal transitions, bounded recovery behavior and tested ownership. Schema rollback keeps new records intact; an older insecure application must not be restored as an emergency fallback.

## Phase 4 — Storefront reliability and accessible product UX

**Goal:** replace the monolithic browser state flow with small native Shopware modules using the canonical API.

**Areas:** widget lifecycle/API/state/renderers/forms, Twig mount and generated routes, snippets/styles; Administration query pagination and async race guards where shared APIs change.

**Prerequisites:** stable conversation/render contracts, deterministic presenter, execution status/cancel semantics.

**Behavior changes:** one accepted turn at a time; generation guard and abort; clear/reload/reconnect consistent with server state; no stale price/history after identity change; typed product/category/contact blocks; all local URLs generated by Shopware; intentional link/image policy; keyboard/status/focus support and localization.

**Breaks:** BC05/BC12, removal of old sessionStorage keys and `formular` as a public contract. Themes and custom JS/renderers need documented block/registration adapters.

**Tests:** delayed/out-of-order network responses, rapid submit, busy rejection before history mutation, retry/cancel/clear/reload, two tabs, login/logout, invalid render block, hostile markdown/URLs, product detail/listing/CMS/account/checkout mounts, base path and two locales, responsive and keyboard/screen-reader checks.

**Migration:** clear or explicitly ignore legacy browser history on contract upgrade; do not rehydrate it as canonical state. Keep a visible reset/recovery path. Reuse CSS and safe rendering helpers where behavior remains correct.

**Done:** main customer flows pass browser integration and accessibility checks; no provider/MCP implementation details appear in user controls. Streaming remains deferred unless a measured latency requirement justifies it.

## Phase 5 — Durable contacts, administration and privacy lifecycle

**Goal:** make acceptance durable and align access/export/delete behavior with clear retention purposes.

**Areas:** contact service/entity migration, delivery outbox/Messenger handlers, mail templates, admin delivery state; protected debug storage/API; retention scheduled tasks; exports and exact deletion preview.

**Prerequisites:** owner/canonical schema and worker infrastructure; operator retention/purpose requirements; sample existing contact records and required external integrations.

**Behavior changes:** accepted means contact + delivery intent persisted; save approved quantity/ZIP; explicit pending/failure/delivery states; retries use delivery identity. Restrict raw diagnostics server-side; validate destructive date ranges; use consistent half-open UTC filters and bounded safe exports; erase references/PII according to policy.

**Breaks:** BC07/BC08/BC09/BC10. Mail templates/destinations may be preserved by adapters; direct consumers of old status fields and entity debug properties need migration.

**Tests:** DB outage never returns accepted; fake mail failure and retry; worker crash before/after send; no duplicate contact on retry; ordinary viewer cannot read diagnostics; exact preview/deletion set; invalid dates rejected; CSV formula/control characters; customer/contact/session erasure graph; task rerun; uninstall keep/delete behavior and upgrade on populated data.

**Migration:** preserve retained contacts and template customizations; backfill status as unknown when old evidence cannot prove delivery. Explicitly delete/migrate old debug fields under policy. Version events/DTOs for CRM integration; do not invent an external CRM implementation.

**Done:** no lost accepted lead, operational delivery visibility, correct ACL and retention/deletion tests, documented operator procedures and worker readiness.

## Phase 6 — Installable local-first release and operational gates

**Goal:** produce a reproducible plugin/backend release for a declared support matrix.

**Areas:** packaging/config, immutable images, CPU/GPU profiles, model storage, compatibility preflight, liveness/readiness, telemetry and release docs/CI.

**Prerequisites:** preceding correctness/durability gates; supported Shopware patch, theme and model/runtime matrix; clean-install and populated-upgrade fixtures.

**Behavior changes:** no mandatory workstation path/GPU; no public model/MCP port by default; separated secrets and sanitized examples; explicit local-only/dedicated-remote profiles and optional domain packs; metadata observability, compatibility diagnostics and bounded load behavior.

**Breaks:** BC03/BC11/BC12. Operators migrate environment names/model profiles and mounts; cloud fallback is always an explicit decision. Resolve B license packaging and dependency declarations.

**Tests:** clean install and upgrade, uninstall options, build artifacts without source/secrets, offline/local-only inference, CPU/GPU configured profiles, credentials rotation, private port checks, readiness with unavailable model/Shopware, saturation/backpressure, backup/restore, active-execution deployment and declared model/provider conformance.

**Migration:** coordinated plugin/backend contract release; retain old schema until migration is validated; back up and verify restore before destructive cleanup. Document actual support/latency limits, not theoretical compatibility.

**Done:** deterministic and authorization gates pass; documented install/upgrade/restore succeeds; no required cloud inference; bounded failure behavior demonstrated on reference hardware. Measure latency/quality and set release thresholds at this phase, using baselines prepared earlier.

## Phase 7 — Optional improvements only from measured need

**Goal:** improve validated product outcomes without expanding the initial core unnecessarily.

**Areas:** streaming/status delivery, semantic/hybrid retrieval, reranking, additional model adapters, optional MCP/CRM modules.

**Prerequisites:** query/latency data, a named consumer or supported requirement and baseline metrics; Phase 6 product stable.

**Behavior changes:** one selected improvement at a time. Streaming emits only allowed partial text/status and validated final commerce objects; an index supplies candidates reauthorized in Shopware.

**Breaks:** additive capabilities where possible; new contract major if semantics change. Shared SaaS tenancy is a separately scoped project, not a hidden extension of this phase.

**Tests:** measurable improvement over baseline; index updates/deletion/authorization; streaming disconnect/backpressure/reconnect; optional adapter errors; locality and cost budgets.

**Migration:** feature flag, rollback and index rebuild strategy; no canonical commerce data moved out of Shopware.

**Done:** documented benefit and operational cost justify the component. Otherwise do not ship it.

## Current-to-target component mapping

| Current component | Limitation | Action and target | Migration consequence |
| --- | --- | --- | --- |
| Plugin bootstrap, DI, DAL infrastructure | Incomplete uninstall and broad services | Retain infrastructure; extend lifecycle, split application services | Forward migrations and lifecycle tests |
| `ChatProxyController` | Blind relay | Replace with conversation application/backend adapter | v1/auth/owner contract |
| Context-token route + handwritten browser JWT | Ignored downstream | Remove after server execution capability available | Browser/custom callers stop using token route |
| `PricesController` + JS price selection | Split authority and quantity bugs | Merge logic into deterministic CommercePresenter; retain platform calculation | New price DTO and renderer |
| MCP catalog server | Admin scope, extra process | Replace core with contextual gateway; optional MCP adapter | Migrate tool clients, remove integration credentials |
| `app.py` | Mixed IO/prompts/contracts/state | Split into route/application/orchestrator/provider/tool modules | Preserve useful behavior through contract fixtures |
| Sync OpenAI client and aliases | Event-loop blocking and runtime coupling | Replace IO with async adapter; retain aliases in profile | Server model config migration |
| Domain provider/resolver | Useful but unsafe hot reload | Retain/refactor validation/reload and optional domain packs | Existing vocabulary can be reused after schema validation |
| Browser history and tracking | Untrusted canonical state, races | Replace canonical ownership; keep optional UI telemetry separately | Legacy records/storage keys retired from live context |
| Large widget | Lifecycle/network/render coupling | Split native plugin modules; reuse safe rendering/style parts | Theme/renderer compatibility adapters |
| Contact mail service/templates | Mail-first durability | Retain templates/sending adapter; add accepted record/outbox | Status and worker migration |
| Analytics service/admin pages | Divergent filters, permissions, loading | Refactor queries, ACL, pagination/export and canonical metrics | Report definitions and role migration |
| Existing migration | Incomplete future lifecycle | Preserve applied migration; add forward migrations | No historical migration rewrite |
| Runtime Compose/Docker | Development assumptions | Retain locked/non-root image concept, replace deployment profiles | Operator env/mount/port changes |
| Fallback result parsing, stale prompt route, unused main | Legacy/dead behavior | Remove after v1 cutover | Document intentional unsupported old inputs |

## Breaking-change register

| ID | Repositories / interface | What breaks and why better | Observed or possible consumers / migration | Implementation risk |
| --- | --- | --- | --- | --- |
| BC01 | A/B unversioned HTTP APIs | Authenticated, bounded v1 DTO/error contract replaces pass-through JSON | Widget, backend client, any direct scripts; pinned schemas and temporary endpoint adapter | Coordinated deployment can interrupt chat if versions differ |
| BC02 | A/B conversation/history/token | Server-owned transcript/epoch/grant replaces browser history and context JWT | Widget and any custom chat caller; start new canonical conversations, do not trust old telemetry | Incorrect ownership migration can expose old history |
| BC03 | A/B model/config fields | Server profiles replace arbitrary raw model names and scattered aliases/env | Plugin config, Compose/scripts/custom callers; explicit profile mapping | Model capability/config mismatch |
| BC04 | B MCP tools and Admin API integration | Typed contextual gateway replaces mandatory MCP catalog | External MCP clients unknown; inventory, optional adapter; remove old credentials after cutover | Callback capacity and context reconstruction |
| BC05 | A/B response blocks/prices | References + hydrated products/categories replace model prices and legacy `formular` | Widget/admin renderers, theme/custom blocks; versioned block adapter, proper category URLs | Dropped cards if model cannot satisfy schema; require quality tests |
| BC06 | A DAL/tracking | Canonical IDs/sequences/usage replace client-controlled records | Administration/reports/custom DAL readers; legacy marker/backfill only where provable | Duplicate/lost records during dual-writing |
| BC07 | A contact/status/event | Durable accepted/pending/delivered semantics replace immediate boolean success | Contact widget, template/custom CRM consumers; fixed form and event adapter | Worker outage or duplicate SMTP after crash |
| BC08 | A/B diagnostic access | Public traces and entity raw fields removed/restricted | Debug UI/scripts; protected diagnostics roles and migration of retained data | Loss of expected troubleshooting access if roles not migrated |
| BC09 | A dashboard/export/delete | Strict ranges/exact scope/safe export replace silent date defaults and mixed metrics | Admin UI, report consumers; explicit half-open filters and preview | Changed report totals and deletion expectations |
| BC10 | A retention/erasure/uninstall | Explicit data lifecycle replaces indefinite storage/nullable links | Operators, backups, external exports; retain/export/delete plan with legal-purpose assessment | Irreversible deletion requires tested preview and backups during implementation |
| BC11 | B runtime/deployment | Production profiles/private ports/separate secrets replace dev topology | Operators and local model scripts; documented CPU/GPU paths, env migration and readiness | Misconfigured networking/worker capacity |
| BC12 | A/B extension/locale/domain | Common mount/generated routes/domain packs replace content-page/root-path/German assumptions | Themes, custom JS, business prompts, mail/domain integrations; adapters/snippets/config | Unseen custom plugins may depend on internal details |

These interfaces are observable in the two repositories. Actual external consumers are not known; no external repository implementation is assumed. Each break needs consumer inventory and acceptance tests, not preservation of weak security or commerce authority for compatibility.

## Migration and review discipline

Use expand–migrate–validate–contract for database changes. Do not destructively erase legacy records merely to simplify development. Existing insecure routes may remain only behind disabled/restricted migration adapters, with an explicit removal gate. Backward compatibility never means accepting unauthenticated requests, model prices or client-declared customer identity.

At each milestone, record what changed, contract/schema version, test evidence, known limitations and deployment/rollback steps. Gate tests should exercise the boundary that was changed, not snapshot every line of implementation. Preserve synthetic fixtures for the confirmed audit failures. A later implementation session should begin with Phase 1, not with a broad rewrite or a new retrieval framework.
