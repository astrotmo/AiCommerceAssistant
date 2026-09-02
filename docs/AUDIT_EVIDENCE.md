# Audit evidence, coverage and reproducibility

Date: 2026-09-02. This index makes the audit reviewable without treating old documentation or a successful syntax check as production evidence.

## Deliverables

| Document | Purpose |
| --- | --- |
| [CURRENT_ARCHITECTURE.md](CURRENT_ARCHITECTURE.md) | Reconstructed components, contracts, state, deployment and data flows |
| [ARCHITECTURE_AUDIT.md](ARCHITECTURE_AUDIT.md) | Prioritized findings, failure matrix and documentation discrepancies |
| [ARCHITECTURE_RESEARCH.md](ARCHITECTURE_RESEARCH.md) | Primary-source research with project-specific consequences |
| [TARGET_ARCHITECTURE.md](TARGET_ARCHITECTURE.md) | Recommended boundaries, contracts, security, local-first deployment and tests |
| [REWORK_PLAN.md](REWORK_PLAN.md) | Seven meaningful phases, migration mapping, breaking changes and acceptance gates |
| [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) | Eleven proposed ADRs with alternatives and tradeoffs |

Canonical documents are placed in AiCommerceAssistant's `docs/` directory. The backend repository receives a documentation index linking to the same system audit, avoiding two independently editable copies. These documentation changes do not implement the target.

## Repository access and provenance

| Item | AiCommerceAssistant | shopware-ai-chat-backend |
| --- | --- | --- |
| Access | Full public repository tree and tracked text contents | Full public repository tree and tracked text contents |
| Default branch | `main` | `main` |
| Audited main commit | `78581facc8470eef3e85ef667339693616c63cc7` | `fe72dd4af104cef5eaf61104fa73779f0bf7870b` |
| Audited tree | `75ad420069cc44bb068db0173bfda8667c9a706b` | `c3a45a0d9d26f9bf3c0a609d299451554ae4568c` |
| Stage commit | `0555666efe2b94799c7b59dd9a950f6cda292c78` | `604bb5a9c8b5d1e1b3b40d96e108aae2af061a30` |
| Stage difference | One commit ahead; 17 changed/added files inspected | One commit ahead; 9 changed/added files inspected |
| Tracked main files | 79, all text downloaded and blob-hash checked | 31, all text downloaded and blob-hash checked |
| Existing checkout status | No usable pre-existing local Git checkout; no local user modifications were reset or cleaned | Same |

Direct shell clone was unavailable under the session's network controls. Repository metadata, tree/commit/compare and file reads succeeded through the GitHub integration. The audit therefore used exact source snapshots rather than claiming a clean `git status` on a working clone. Every main file's Git blob SHA-1 was recomputed from its bytes and matched the repository tree. No tracked production file was changed.

Recent commits inspected:

| Repository | SHA prefix | Date | Subject |
| --- | --- | --- | --- |
| A main | `78581fa` | 2026-07-27 | Merge |
| A main | `369f75c` | 2026-07-27 | Small fix |
| A main | `709b752` | 2026-07-14 | Backend README link/update |
| A main | `635f944` | 2026-06-09 | Added documentation |
| A main | `36106cb` | 2026-06-09 | Initial commit |
| A stage | `0555666` | 2026-08-13 | Added comments |
| B main | `fe72dd4` | 2026-07-27 | Prompt edited |
| B main | `2c7cbbd` | 2026-04-28 | Text 9 |
| B main | `3b14e35` | 2026-04-28 | Text 9 |
| B main | `7247707` | 2026-04-27 | Text 7 and 8 |
| B main | `2404917` | 2026-04-24 | Documentation |
| B stage | `604bb5a` | 2026-08-13 | Added comments |

Subjects above are shortened descriptions; SHAs identify exact commits. Stage patches expand comments/docstrings/documentation. For the seven changed Python files, ASTs are identical after removing standalone string-expression documentation (including explanatory strings inside functions, not just leading docstrings). This is an executable-syntax comparison, not a byte-identity claim or live regression test. PHP/JS stage patches were inspected as text; no alternative runtime architecture was found.

## Coverage ledger

| Area inspected | Files / focus |
| --- | --- |
| A root/build | Composer, package metadata/license, PHPUnit config/bootstrap, storefront package/lock, Git ignore rules, README/changelog |
| A lifecycle/config | Plugin bootstrap, setup/mail templates, migration, services/routes/config XML, subscriber |
| A HTTP | All five storefront controllers and admin analytics controller, validation, scopes, `_acl`, HTTP client, timeout/error behavior |
| A persistence | Four definition/entity/collection groups; tracking, session and analytics services; FK/index/delete/aggregate behavior |
| A Storefront | Entry registration, complete widget, Twig integration and SCSS; queue/history/token/tracking/price/form/render/lifecycle paths |
| A Administration | App/module registration, ACL, configuration extension, dashboard/list/detail pages, rendered-message component, services/snippets/styles |
| A tests | Three PHPUnit controller test files, 15 test methods, bootstrap and config; mocks and missing integration assertions |
| B application | Complete `app.py`, entry point, request/response behavior, prompts, tools, provider calls, domain assembly, trace/cache/error paths |
| B commerce | Complete MCP server, OAuth cache, criteria, normalization, locale behavior, arguments and transport choices |
| B domain | All model/loader/resolver/prompt modules and curated JSON; exact/synonym/fuzzy matching, reload and unused fields/helpers |
| B packaging/runtime | Pyproject/lock, Dockerfile, Compose, Makefile, alias script, ignore/environment configuration; no live secrets reproduced |
| Documentation | Both READMEs, A docs and changelog, B historical text files, supplemental stage architecture analysis/walkthrough |
| External source verification | Shopware v6.7.0.0 search vs linked-product visibility; OpenAI Python v2.7.1 timeout/retry constants; primary-source research links |

No AGENTS.md or tracked CI workflows were present in the inspected trees. No tracked backend tests were present. Stage documentation describes ignored local backend tests; those files were not available. This is different from claiming their author never ran tests.

## Executed static verification

| Check | Result | Limit |
| --- | --- | --- |
| Exact Git blob hashes | 110/110 main files matched | Does not establish deployed files |
| Python syntax via `ast.parse` | 10 files passed | No dependency import/startup assurance |
| JavaScript syntax via Node module `--check` | 15 files passed | No Shopware bundling/browser behavior assurance |
| JSON parsing | 6 files passed | Not schema or semantic validation |
| XML parsing | 3 configuration files passed | Not Shopware XSD/container validation |
| Changed Python stage ASTs | 7/7 executable comparisons equal | Ignores standalone string documentation; no runtime integration |

PHP and Docker executables were unavailable. No PHP lint, PHPUnit execution, Shopware container build, Composer installation, migration, frontend asset build or real model/MCP transport integration test was performed. Local Python had Pydantic available for isolated DTO probes; this was not a complete installation of B's locked runtime.

## Isolated behavioral probes

The harness extracted selected original Python functions/classes using AST, supplied their constants, and replaced provider/MCP collaborators with in-memory fakes. This avoids module import side effects, credentials and network calls. Domain resolver modules were exercised locally with the tracked vocabulary and a temporary malformed file. JavaScript probes loaded the original class with import lines removed and stubbed Shopware base/storage/render collaborators. These are narrow reproductions, not end-to-end tests.

| Probe / original location | Input or setup | Observed result | Finding |
| --- | --- | --- | --- |
| B `normalize_chat_reply` | Product block `{id: "invented", name: "Invented", price: "0.01 EUR"}` | All three fields retained | F03 |
| B `normalize_chat_reply` | Top-level `type: []` | TypeError | F09 |
| B `normalize_blocks` | Product list with `products: 7` | TypeError | F09 |
| B `normalize_blocks` | Info box with `style: {}` | TypeError | F09 |
| B `ChatIn` + `sanitize_history` | 100,000-character message, `unapproved-model`, 100 assistant entries, numeric extra `requestId` | Message/model/history accepted, extra requestId discarded | F08/F10 |
| B `chat_with_tools` | Fake first completion requests one catalog tool, fake final completion returns valid text block | Exactly two completions; final roles `system,user,tool`; assistant tool-call message absent | F07 |
| B `call_mcp_tool` | Fake MCP result `isError=True`, text `authorization denied` | Returned `{"text":"authorization denied"}` without error metadata | F18 |
| B domain resolver | Query `Traubenzucker` with tracked domain terms | Canonical match `Dextrose` | Useful retained component |
| B domain auto reload | Replace valid temporary terms file with invalid JSON | JSONDecodeError instead of last-known-good response | F25 |
| A `resolveProductPrice` | Base unitPrice 10, first tier quantity 10 / unitPrice 7; mode `advanced-first` | `{price: 7, isAdvancedPrice: true}` | F05 |
| A `persistMessage` / `restoreHistory` | Persist 64 messages with maxStoredMessages 60, restore and request next index | Stored length 60, next index 61 | F10 |

### Minimal parser reproduction without importing the application

From a disposable B checkout with Python and the normal dependencies available:

```python
import ast, json, logging
from pathlib import Path
from typing import Any, Dict, List, Optional

names = {
    "_string_or_none", "_string_or_empty", "_bool_value",
    "text_response_payload", "normalize_blocks", "normalize_chat_reply",
}
tree = ast.parse(Path("app.py").read_text())
nodes = [n for n in tree.body if getattr(n, "name", None) in names]
scope = dict(globals(), TRACE_ENABLED=False, logger=logging.getLogger("probe"),
    RESPONSE_TYPES={"answer", "clarification", "error"},
    INFO_BOX_STYLES={"info", "warning", "error"},
    FORM_FIELD_TYPES={"text", "email", "textarea", "tel", "number"})
exec(compile(ast.Module(body=nodes, type_ignores=[]), "app.py", "exec"), scope)
payload = {"type": "answer", "blocks": [{"kind": "product_list", "products": [
    {"id": "invented", "name": "Invented", "price": "0.01 EUR"}
]}]}
print(scope["normalize_chat_reply"](json.dumps(payload), request_id="probe"))
```

For the transcript probe, the fake synchronous completion method captures a deep copy of each `messages` argument, returns one `tool_calls` assistant response first and a normal final response second. The fake async tool handler returns `{"items": []}`. Inspect captured final roles and call IDs. This tests actual `chat_with_tools` control flow while excluding real inference and transport.

For the index probe, stub `sessionStorage`, `appendBubble`, `renderBlocks` and `open`, then use original `persistMessage`, `restoreHistory` and `nextClientMessageIndex`. Compare against the migration's unique `(session_id,message_index)` constraint. The SQL uniqueness consequence is source-grounded; a real DB collision was not executed.

## What was not verified

- Live shop URL, installed Shopware/PHP patch, custom theme/plugin overrides, sales-channel catalog, customer/group price rules, tax setup, currency rounding and integration credentials/scopes.
- Actual network exposure/firewalls/reverse proxies/TLS, secret values or historic secret exposure, production trace/log configuration and operator access.
- Real LLM output quality, Ollama/vLLM versions running now, GPU memory/latency, schema/tool compatibility, provider cancellation or saturation behavior.
- Actual MCP/AnyIO task-lifecycle failure, OAuth concurrency/401 recovery and SDK transport defaults. Source supports risks; it does not prove every deployment manifests them.
- Real browser layout/accessibility, Shopware asset build, HTTP cache behavior or PHP callback capacity.
- Database migration/install/upgrade/uninstall, data volume, aggregate races, export interpretation, deletion/backup restoration, actual mail delivery or external CRM consumers.
- GDPR compliance, legal basis or retention obligations. The audit identifies engineering controls and missing lifecycle behavior, not a legal certification.

No real customer conversations, emails, orders or integrations were invoked, and no production code/schema/config was changed. Claims marked inferred remain open until the specific tests in the rework plan are performed.
