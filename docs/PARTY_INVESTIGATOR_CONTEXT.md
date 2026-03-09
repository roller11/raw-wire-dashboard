# Party Investigator Context Map

Status: Current runtime map for the SoothSayer investigation flow in the lead pipeline.

## Admin Ownership

- `AI Settings` is provider-only and should expose only actual AI providers.
- That means provider-facing fields only: API keys, base URLs, model selection, and provider defaults.
- `AI Settings -> Perplexity` owns direct Perplexity credentials and runtime defaults.
- `AI Settings -> OpenAI` owns OpenAI-compatible provider credentials.
- `AI Settings -> Venice.ai` owns Venice provider credentials and model defaults.
- Provider tabs now also own live model refresh from provider `/models` endpoints where supported.
- Provider tabs also own provider-native request defaults such as sampling, reasoning, search, and tool-availability toggles when those fields are sent directly to the provider API.
- `Lead Generator` owns workflow behavior and investigation routing: enablement, search depth, cache windows, auto-investigation, `pipeline_mode` selection, direct-lane pass count, and pass-level workflow overrides.
- `Lead Generator -> Investigation -> Perplexity` now owns Perplexity investigation workflow overrides that are not provider identity/model state: pass count, Responses API preset selection, optional max-step override, search-mode override, search-result toggles, and pass-specific prompt/model overrides for passes 1-3.

## Scope

This map covers only the investigation path triggered by the SoothSayer UI (`.btn-investigate`) and processed by:

- `rawwire_lead_investigate` AJAX handler
- `RawWire_Party_Investigator::investigate_source_parties()`
- Source status + notes persistence in `rawwire_lead_sources`

## High-Level Flow

1. User clicks Investigate in SoothSayer
2. Frontend sends AJAX request (`action=rawwire_lead_investigate`)
3. Backend resolves `source_id` and runs Party Investigator
4. Investigator enforces discovery/quality gates before saving
5. Backend returns success/error with optional `failure_reasons`
6. SoothSayer updates badge + reloads lead details
7. Aggregated investigation data supports downstream industry network mapping (people, firms, relationships, procurement paths)

## Sequence (Current)

```text
SoothSayer UI (btn-investigate)
  -> AJAX: action=rawwire_lead_investigate (candidate_id/source_id)
  -> Lead Generator AJAX handler
	 -> validate nonce + permissions
	 -> resolve source record
	 -> call investigate_source_parties(source_id)
		-> availability gate (provider configured/reachable)
		-> load source row
		-> recent-investigation skip gate (<24h unless force)
		-> extract_parties(source)
		-> if no named parties:
			-> discover_parties_from_permit(source)
				-> RawWire_LADBS_Scraper::scrape_permit(permit_nbr)
				-> if owner-builder: set investigator_notes and continue permit-context investigation (do not early-skip)
				-> if contractor found: persist discovered fields + metadata
			-> re-extract parties
			-> if still no usable names: mark source failed and return skipped (except owner-builder permit-context continuation)
		-> per-party investigation loop:
			-> investigate_party_via_agent(party, source)
				-> if `pipeline_mode=perplexity_direct`:
					-> run direct HTTP dossier request via `chat_with_metadata()` using Perplexity Responses API semantics
					-> read model, temperature, token budget, `top_p`, and `reasoning_effort` from `rawwire_perplexity_settings`
					-> read direct workflow overrides such as pass count, preset, optional `max_steps`, think-strip behavior, search-mode override, search toggles, and pass 1-3 prompt/model overrides from `rawwire_party_investigator_settings`
					-> use Perplexity native web research instead of OpenClaw browser tools
						-> pass 1 uses Responses API `instructions` + `input` fields instead of relying on a system prompt for search behavior
						-> each pass can override the preset-backed model; blank pass overrides allow the selected preset model to stand, otherwise they fall back to the AI Settings Perplexity model when no preset is selected
						-> namespaced Perplexity catalog IDs such as `perplexity/sonar` are normalized before requests are sent
						-> pass 3 gap-fill retry only runs when Lead Generator enables three passes
					-> inject provider citation/search metadata into `EVIDENCE LOG`
					-> accept evidence-rich direct dossiers via direct-lane fallback thresholds
				-> otherwise use VeniceClaw / OpenClaw browser-agent lane:
					-> resolve OpenAI-compatible auth from `rawwire_openai_settings` before falling back to legacy OpenClaw settings
						-> read OpenAI-compatible request defaults such as `top_p`, `reasoning_effort`, `tool_choice`, `parallel_tool_calls`, and tool exposure flags from `rawwire_openai_settings`
						-> selected OpenAI-compatible model comes from `rawwire_openai_settings`; the Lead Gen lane no longer prefers the legacy hidden `investigation_model` value
					-> allows helper tools for parsing/list extraction, but social/profile details requiring navigation must be browser-verified
					-> tool payloads are filtered before send based on `allow_tool_calls`, `allow_mcp_tools`, and `allow_openclaw_tools`
					-> requires per-decision-maker mini-investigations (contact, role, importance, access route, sources)
					-> reject agent output if it has failure signatures, too few URLs, or no evidence section
			-> fallback: search_party + analyze_with_ai
			-> if both fail: add failure_reasons entry and skip save for that party
		-> if all parties fail:
			-> source.investigation_status = failed
			-> source.investigator_notes = failure summary
			-> return success=false + failure_reasons
		-> dump findings file (temp rawwire/investigations/source_{id}.json)
		-> cheap extraction pass: extract_profiles_from_file(file)
		-> merge extracted profile fields
		-> save_investigations(source_id, investigations)
			-> placeholder-quality filter
			-> evidence-quality filter for agent raw_investigation
			-> if all placeholder: mark failed, do not save profiles
			-> else status = completed|incomplete and save party_profiles
			-> trigger scoring for source
		-> return success=true + parties_count + failure_reasons(optional)
	 -> AJAX response:
		 success=true  => { message, source_id, result, failure_reasons }
		 success=false => { message, source_id, failure_reasons }
```

## SoothSayer UI Contract

### Request

- Endpoint: `admin-ajax.php`
- Action: `rawwire_lead_investigate`
- Fields: `nonce`, `candidate_id`, `source_id`

### Response Handling Rules

Frontend behavior (current):

1. If `response.success === true`:
   - If `failure_reasons.length > 0`: show warning toast (`completed with warnings`)
   - Else: show success toast
   - Reload current lead details (preferred) to render canonical status
   - Fallback only (no current lead): set badge to `Complete`

2. If `response.success === false`:
   - Show error toast using `message` + joined `failure_reasons`
   - Set badge to `Failed`
   - Reload current lead details so persistent failure banner appears
   - If no current lead context, re-enable investigate button

3. Failure banner lifecycle:
	- Failure banner is rendered from lead details/state, not only toast output
	- User can dismiss banner via `.ss-failure-dismiss` without mutating underlying source status

## Investigation Status Outcomes

Possible source status values written by investigation flow:

- `completed`: meaningful profile data saved
- `incomplete`: data saved but below quality threshold
- `failed`: discovery/investigation failed or all profiles filtered as placeholder
- `no_parties_found`: no parties even after discovery path

Owner-builder handling:

- Owner-Builder permits remain valid investigation targets.
- If no external contractor is found, the flow continues via permit-context investigation to capture owner-side decision-maker access signals.

## Failure Reason Semantics

`failure_reasons` is additive context, not always terminal failure.

- Present with `success=true`: partial success (some parties failed/skipped, at least one produced savable data)
- Present with `success=false`: terminal failure (no savable investigation output)

SoothSayer must keep treating `failure_reasons` as warning/error detail, not as sole success determinant.

## Critical Gates (Do Not Bypass)

1. No named-party investigation after failed permit discovery
2. No placeholder-only profile persistence
3. No evidence-light output can pass as meaningful research
4. Browser-lane social/profile details that require navigation must be browser-verified
5. Direct Perplexity dossiers may pass via direct-lane fallback only when URL/host/target evidence thresholds are met
6. Each potential decision maker must have a mini-investigation (contact info, role, why important)
7. All-party failure forces `investigation_status=failed`
8. Source record remains single source of truth for status/banner rendering
9. Availability checks must match the selected `pipeline_mode`; do not block direct Perplexity on legacy OpenClaw-only requirements
10. Provider-side tool toggles are functional gates, not cosmetic UI; filtered tools must not be sent when a provider tab disables them
11. Provider model dropdowns are cache-backed views of live `/models` lookups and should be treated as provider state, not hardcoded local catalogs
12. Provider model refresh should tolerate both `/models` and `/v1/models` endpoint variants so saved base URLs without `/v1` still populate dropdowns

## Canonical Files

- `js/soothsayer-v2.js`
- `cores/lead-generator/class-lead-generator.php`
- `cores/lead-generator/class-party-investigator.php`
- `cores/lead-generator/class-ladbs-scraper.php`

