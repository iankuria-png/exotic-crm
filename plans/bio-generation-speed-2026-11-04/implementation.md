# CRM bio speed — implemented and verified locally

4 November 2026. The code and release package passed review. Ian authorized the scoped CRM commit/push for his cPanel pull (“okay ship this to the crm”). Shipping is in progress; production deployment remains pending. Production SEO settings, API keys and advertiser profiles remain unchanged. No migration or WordPress upload is needed.

The measured choice is to retain the saved **DeepSeek V3.2** model and prioritize **latency routing** for interactive CRM bios. The new Flash snapshot was faster in some samples but produced wrong-language outputs, so it was not promoted globally.

## What changed

- Interactive bios disable optional reasoning on the verified DeepSeek/Qwen OpenRouter models; direct DeepSeek uses its thinking-off parameter. Unsupported reasoning fields are omitted for Mistral. Privacy routing remains `data_collection=deny`.
- The interactive output allowance starts at 384 tokens, including the live 75-word setting. Bulk generation, translation and general CRM AI callers retain their previous budgets and reasoning settings.
- A request-local run coordinates a 30-second outbound-I/O deadline, up to 12 seconds per AI request, a 3-second connection limit and at most two CRM-to-provider HTTP calls total, including any automatic rewrite. WordPress reads use at most 3 seconds and the remaining shared allowance. The reserve prevents a fresh request after exhaustion. This is cooperative network control, not a process-kill guarantee for database/CPU work.
- Two transport/429/5xx failures temporarily bypass that credential/model/routing combination for 60 seconds. Content refusals, truncation, empty text, local budget exhaustion and programming errors do not open the shared circuit. Cache failures fail open.
- Exact model selections stay on the selected model. Empty, refused and explicitly truncated provider responses are rejected. Existing template fallback and editor review remain; a skipped optional quality rewrite preserves the usable draft and its quality flags.
- Each interactive request writes one safe terminal timing summary to a dedicated 14-day daily INFO log, `storage/logs/seo-performance-YYYY-MM-DD.log`. It records stages and actual model/provider/finish/token metadata, including failures. It omits prompts, bios, contacts, keys and vendor error bodies. Telemetry failure cannot break generation.
- Cold SEO catalogue loading now makes one WordPress request. A successful catalogue is cached for one hour; failures return the last good catalogue or static entries with only a 15-second failure backoff. Failed partial catalogues no longer occupy the normal one-hour cache. Uniqueness checks reuse their corpus within one request.
- The modal shows elapsed time, permits cancellation and times out a hung browser request after 40 seconds. Cancelled/late responses cannot replace a newer result. Failed or cancelled regeneration retains the previous draft. A browser abort does not guarantee vendor-side work or billing stops immediately.

The snapshot still reads authoritative WordPress data before applying the form overlay; a form overlay does not establish completeness. That read is bounded rather than skipped.

The saved provider ladder is preserved. The run enforces one initial HTTP request and at most one further request; circuit-open entries may be skipped without spending that allowance. OpenRouter may still perform its own upstream routing inside a single bounded HTTP call.

## Actual provider comparison

Exactly 24 externally billed synthetic requests were made from the production CRM account:16 candidate requests and8 targeted requests against the saved primary. No customer fields, real contact details or saved profiles were used. All routes used the actual CRM prompt builder, the same four language fixtures and 384 output tokens. Request hashes, settings pins and private original responses are retained in Ops.

| Route | Text returned within 12 seconds | Median response time | Findings |
| --- | ---: | ---: | --- |
| Direct Flash 1017, thinking off | 4/4 | 1.580s | French sample returned English; several short drafts |
| OpenRouter Flash 1017, default routing | 4/4 | 2.029s | Portuguese sample returned English |
| OpenRouter Flash 1017, latency routing | 4/4 | 0.718s | Portuguese sample returned English; other quality concerns |
| OpenRouter Mistral Medium 3.1 | 4/4 | 1.494s | All samples below requested 60-word minimum |
| Existing V3.2, new payload, default routing | 1/4 | 3.724s for the sole completed response | Three requests timed out at 12 seconds |
| **Existing V3.2, new payload, latency routing** | **4/4** | **3.563s** | **2.295–5.922s; four requested languages returned** |

All completed responses reported zero reasoning tokens. This comparison tests the combined new payload and routing; it is not an isolated experiment proving reasoning caused every old timeout. Four samples per route do not establish production p95 or an SLA. These are provider response times, not complete CRM click-to-draft timings.

Twenty-one captured successful responses also passed through the actual local sanitizer, character limiter, uniqueness analyzer, link injector and scorer, using HTTP fakes and an empty synthetic corpus. All stayed below 900 characters. The existing language detector agreed with manual identification of the wrong-language outputs. Several V3.2 samples remained below60words, including the Swahili sample; native-language/editor acceptance and real-corpus behavior remain production follow-ups. Empty-corpus uniqueness scores are not a population-wide quality guarantee.

OpenRouter reported USD 0.0048695622 for 17 completed responses. Direct DeepSeek and three timed-out requests had no returned cost receipt and are excluded from that subtotal.

## Verification

- Baseline before changes: 151 SEO tests/465 assertions passed.
- Full changed backend regression: 186 tests/608 assertions passed, covering SEO, WordPress shared-key behavior and `AiFoundationTest`.
- The packaged backend was tested separately against clean deployed Git HEAD plus only this release: 34 tests/134 assertions passed. Reflection verified all 286 loaded application classes came from that isolated copy, including the delivered WordPress-sync file without the unrelated local collection guard.
- Offline captured-response pipeline: 1 test/117 assertions passed; no additional vendor calls.
- Final compiled frontend: 12 browser checks passed at desktop and 390px phone width, including elapsed time, cancellation, stale completion, use-in-form, failed/cancelled regeneration and hung-request recovery. Zero application page errors occurred during the journeys. Screenshots are in `output/playwright/bio-speed-2026-11-04/`.
- All 19 packaged PHP files lint cleanly. Scoped Pint passed 19 files; existing formatting in the three older shared files was preserved rather than broadly rewritten. `git diff --check` passed.
- The isolated Vite build passed. It retained the existing forecast CSS parser and large-chunk warnings. The original working `public/build/` tree and index match their saved digests.
- Release archive readback matches all 27 manifest records. Fresh production reads confirm deployed base `8c64050968e97a432adf5c3f6afd3ec035735192`, unchanged dependencies and exact affected-source preimages. The live WordPress-sync file equals that HEAD; the unrelated local guard was excluded by applying only the owned patch.

## Deliverables

- [Release ZIP](crm-bio-speed-2026-11-04.zip):27 files, 1,434,849 bytes. SHA-256 `dce46a3ba0c174d9773b4b55edf07829526ba5b1c830da256b07a328c99c0944`.
- [Rollback ZIP](crm-bio-speed-rollback-2026-11-04.zip): original affected files and old frontend manifest. SHA-256 `6eabb4f5a90e6f6c7e45ac892b4cc35691233fd266f8aa465a76a51f116bc941`.
- [Exact file manifest](release-manifest.json), [owned WordPress-sync patch](WpSyncService-owned.patch), [build source record](build-sources.json), [delivery checklist](delivery.md), [reviewable plan](plan.html).

Canonical private Ops evidence:

- No-call candidate preview: `20261104T101402Z-d4792-d9410-read-crm-bio-benchmark-ab00`.
-16 candidate calls: `20261104T101442Z-d4792-d9410-read-crm-bio-benchmark-8f6a`.
- No-call baseline preview: `20261104T102134Z-d4792-d9410-read-crm-bio-benchmark-cdc4`.
-8 targeted calls: `20261104T102200Z-d4792-d9410-read-crm-bio-benchmark-870a`.
- Fresh source preimages: `20261104T103335Z-d4792-d9410-read-crm-bio-release-read-e4ca`.

The next action is the authorized scoped commit/push for Ian’s cPanel pull, followed by an actual signed-in draft-generation check and reads of the new performance log. Current production still runs the old code; the benchmark is not evidence of deployment.
