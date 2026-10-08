# Stage 2 — Agent configuration (source-only delivery)

## Boundary and routes

Four roles/profile identities come only from `config/agents.php`. This feature does not write Hermes profile directories, credentials, toolsets, native skills, project source, or service configuration. Only the PO's existing authenticated single-user workspace may access:

- `GET /agents/{slug}/config` — role editor, saved snapshot/defaults, catalog status, history.
- `PUT /agents/{slug}/config` — optimistic-version save.
- `GET /agents/{slug}/config/history` — allowlisted immutable snapshots.
- `POST /agents/{slug}/config/restore/{version}` — create a new version from prior content; never rewrite history.
- `GET /agents/{slug}/config/connectivity` — authenticated GET model catalog only. Never inference or tool testing.

The standard web middleware protects writes with CSRF. Unknown roles, non-curated skill IDs, arbitrary skill paths, endpoint/key/profile/policy edits are rejected. Blade escapes editable content and labels. Specialists remain non-interactive for the PO; consultation callers use `AgentProfileClient` through existing SM coordination.

## Schema (not applied to the active database)

Target migration: `database/migrations/2026_10_08_000001_create_agent_configuration_tables.php`.

- `agent_role_settings`: one current-version pointer per role, optimistic config version, unique activated model ID.
- `agent_config_versions`: append-only actor/date/provider/model/instructions/SOUL/selected skills/body+hash snapshots and immutable policy snapshot. No keys, URLs or runtime credentials.
- `agent_dispatches`: append-only invocation evidence: role/profile/version, requested and metadata-served provider/model, verification/failure code, session/config/persona/policy/skill hashes. Never prompts, user input or raw responses.

SQLite triggers reject UPDATE/DELETE of version and dispatch audit rows. `down()` drops only these new tables. Other DB engines retain application-level append-only behavior; trigger enforcement has only been implemented and tested for the workspace's SQLite engine.

Without all three tables the editor explains migration pending and config writes/history/connectivity fail before outbound requests. Legacy consultation remains compatible when schema/settings are absent, as required; it does not claim effective model proof.

## Saved draft versus applied snapshot versus served-model proof

Application-owned defaults and safe skill bodies are in `resources/agents/role-packs.json`, copied from the supplied role pack, with no runtime dependency on scratch paths. No real model choices are invented or automatically selected.

- Changing provider/model requires that role's live `/api/model/options` catalog to contain the exact available/authenticated pair. Bare catalog listing is not auth proof. False readiness, pending entitlement, unavailable model IDs and unsafe identifiers are excluded. Only safe label/slug/readiness/model fields leave the server.
- Unchanged provider/model permits content-only saves/restores while offline. They create a **pending** application draft. Such drafts cannot dispatch consultation and do not imply native profile sync or runtime readiness.
- Verified-at-save snapshots actually bind both clients' native session-chat POST bodies: explicit provider/model, `require_model_lock: true`, and `system_message` containing that version's instructions, SOUL and selected inline curated skills, followed by overriding immutable consultation/safety policy. Maximum discussion rounds = 3; QA correction cycles = 2. No unsupported `skills` request field is sent.
- Native session IDs are `scrumspace-` plus SHA-256 of original conversation ID + role + config version. A missing session is created with the selected model/provider lock and read back before chat; existing sessions are verified by ID. Editing settings uses a new ID and never mutates the prior version's prompt.
- Response acceptance for configured calls requires actual `runtime.provider/model` or `usage.runtime.provider/model` metadata to match exactly. Requested echoes, top-level virtual `model` aliases and LLM text are not proof. Missing metadata => `UNVERIFIED` / `RUNTIME_METADATA_MISSING`; mismatch => `MISMATCH` / `RUNTIME_MODEL_MISMATCH`; results are rejected. Legacy no-settings responses remain compatible and unverified.

Inline selected skill content is the application payload path. This feature does not claim native API skill autoload is active; native autoload remains gated by the runtime's enabled skills tool, which is intentionally unavailable in the present no-execution profile configuration.

## Installed runtime limitation found by source inspection

The installed `gateway/platforms/api_server_openai_routes.py` builds non-streaming `/v1/chat/completions` responses at lines 824–841 with a virtual top-level model and `_chat_usage_payload(usage)`. The installed `gateway/platforms/api_server.py` helper at lines 688–691 returns only token counts, discarding runtime metadata. Therefore this installed compatibility route does **not** currently provide served provider/model evidence, even though the agent internally tracks it. The metadata-returning session-chat route is a separate protocol with session creation/lifecycle prerequisites.

No runtime files were changed. Configured application calls now use the installed native `/api/sessions/{id}/chat` protocol, which returns `message.content` plus genuine `runtime` metadata (`api_server.py:3595–3637`). Missing sessions are created via `/api/sessions`, and the exact session is read back before inference. Provider/model locks are requested both on creation and chat. This path was verified through source-contract-aligned tests, not live inference. Legacy no-settings calls keep the compatibility route and remain explicitly unverified. Metadata is never inferred from aliases or request echoes.

## Verification and remaining authorization

New feature tests: `tests/Feature/AgentConfigurationTest.php`, `tests/Feature/AgentConfiguredDispatchTest.php`.

Isolated runner/artifacts are under `/home/sipua/.hermes/cache/scratch/stage2-agent-config-20261008/`, using the bundled PHP runtime, `sqlite :memory:`, array cache/session, sync queue, scratch storage/temp/log/JUnit outputs and disabled PHPUnit result cache. The restart test additionally uses a disposable SQLite file under that scratch tmp directory, migrates it, reconnects, verifies persistence and immutable history, rolls back the targeted schema and removes the file.

Real HTTP model behavior is tested with `Http::fake`; test-only provider/model IDs are fixtures, not production catalog data or recommended PO selections. Red/green artifacts document failing tests before implementation and later passing suites. No active DB migration, gateway start/restart, native profile edit, dependency install, deployment, project execution, commit or push was performed by this implementation.

Before live readiness can be established, obtain separate authorization for the targeted active DB migration and for starting the four role gateways, then let the PO select four distinct exact provider/model pairs from each live authenticated catalog. Live inference requires separate approval and real served-model metadata evidence. Source test success is not live inference evidence.
