# Assessment acceptance audit

> **Historical audit preserved:** the initial results below were superseded by the independent-style review, which found F01–F07 and a NOT READY verdict. The remediation results at the end of this file are current. See [INDEPENDENT_REVIEW.md, section 13](INDEPENDENT_REVIEW.md#13-remediation-and-reverification) for the full resolution record.

Observed 2026-11-17 UTC (2026-11-18 in Asia/Manila). Implementation and audit performed by Codex in this workspace. This is local evidence, not hosted deployment approval or an independent third-party review.

The [assessment](https://pixelforge.promptedit.dev/assessment) and [API reference](https://pixelforge.promptedit.dev/docs) were read completely and fetched again during the audit; their visible content was unchanged.

## Required coverage

| Requirement / acceptance condition                                                       | Implementation                                                                                                | Verification                                                                                                 |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Registration, login, logout, password reset, protected routes                            | Existing Fortify actions, middleware, and Vue auth pages                                                      | Auth feature tests; real-browser registration; real local HTTP registration                                  |
| Users can access only their own history and retry actions                                | User-scoped history query and ownership check returning 404                                                   | `GenerationControllerTest`                                                                                   |
| Prompt, all three sizes and qualities, up to four http(s) references                     | `GenerationRequest`, studio form, immutable provider parameters                                               | All nine size/quality combinations, invalid settings, 4000-character Unicode boundary, reference passthrough |
| Price displayed before submission                                                        | Server-calculated encrypted quote bound to user/settings; five-minute expiry                                  | Browser review-and-submit; tampered/expired/cross-user/changed-settings rejection                            |
| Maximum estimate at current rates                                                        | Public pricing endpoint, 60-second cache, maximum text variance/reasoning, exact output/reference counts      | Documented worked example and boundary tests; real pricing requests                                          |
| Exact actual provider cost                                                               | BCMath over all four usage categories and generation-specific rates                                           | `PricingCalculatorTest`, workflow tests, PostgreSQL precision round trip, real usage reconciliation          |
| Exact 25% markup, fractional cents retained                                              | Decimal strings throughout, `numeric(30,18)`, database check constraint                                       | Fractions down to the supported precision; invalid billing states rejected by PostgreSQL                     |
| Failed/rejected generations never charged                                                | Explicit zero settlement; unknown outcomes remain unresolved and uncharged                                    | Rejection matrix; failed/repeated/contradictory events; real `[fail]` and `[blocked]`                        |
| History with queued/processing/succeeded/failed, estimates, actual costs, errors, images | Paginated Vue history, local-state polling, image error fallback                                              | Feature/browser tests; real successful and failed records; real PNG retrieval                                |
| Raw-body HMAC verification and replay window                                             | Constant-time SHA256 comparison, bounded payload, strict signature syntax, ±300 seconds                       | Valid, missing, malformed, incorrect, tampered, expired/future, and missing-secret cases                     |
| Durable event deduplication                                                              | Primary-key event ID, inbox persisted before dispatch                                                         | Duplicate requests, repeated processing, PostgreSQL concurrency                                              |
| Out-of-order and early webhooks                                                          | One row-locked `ApplyGeneration` action; terminal states never regress                                        | Early webhook before creation result, stale queued/processing snapshots, contradictory terminal events       |
| Duplicate submissions/charges prevented                                                  | Signed quote request key, unique request/provider IDs, stable provider idempotency key, single settlement row | Repeated POSTs, database uniqueness, lost-response replay, concurrent success events                         |
| Rate/concurrency/daily limits and overload                                               | Shared local API budget, code-specific errors, `Retry-After`, exponential backoff                             | Mocked 429/503 matrix and budget check; real bounded `[overloaded]` run                                      |
| Timeouts, network errors, safe retries                                                   | Persist uncertainty before POST; reuse key; pause after five consecutive failures; safe manual check          | Failed connection, ambiguous submission, later rejection, early success vs late error, retry/backoff tests   |
| Polling fallback and recovery from lost dispatch                                         | Scheduler scans due generations and unprocessed inbox events                                                  | Real retrieval; mocked poll settlement; scheduler recovery test; running queue/scheduler containers          |
| Clear form errors, loading states, status feedback, retry interactions                   | Native labeled inputs, disabled busy form, explicit quote confirmation, safe retry/prompt reuse               | Desktop/mobile browser workflows, failed-state rendering, basic accessibility scan, no horizontal overflow   |
| Deployable application and reproducible checks                                           | Sail services, PostgreSQL CI service, browser dependencies, environment template, setup/deployment guide      | Production build, migrations on fresh isolated PostgreSQL, local runtime, configuration/static checks        |
| Public repository, deployed URL, provider account identity, sample hosted generations    | Pending explicit user authorization and target selection                                                      | **Not published, deployed, submitted, or verified in hosting**                                               |

## Automated results

Final combined Pest run: **111 passed, 0 failed, 0 skipped**. The suite includes three Chromium browser tests and a four-process PostgreSQL concurrency test. External provider HTTP is faked in automated tests and unexpected outbound HTTP is prohibited. Test processes receive empty provider credentials.

Coverage files:

- `tests/Unit/PricingCalculatorTest.php`, `PricingCalculatorBoundaryTest.php`
- `tests/Feature/GenerationPersistenceTest.php`, `GenerationControllerTest.php`
- `tests/Feature/PixelforgeWorkflowTest.php`, `PixelforgeWebhookTest.php`
- `tests/Feature/ReconcileGenerationsTest.php`, `ReconcileUsageTest.php`
- `tests/Integration/PixelforgeConcurrencyTest.php`
- `tests/Browser/ImageStudioTest.php`
- Existing authentication, settings, home, and dashboard feature tests

The two previously skipped starter security tests targeted absent two-factor UI. They now exercise the implemented password-settings page and its behavior with two-factor disabled. Required auth feature tests fail if their configuration is disabled rather than silently skipping. The starter's empty helper and unconditional example assertion were replaced/removed in favor of meaningful coverage; existing functional tests remain.

## Real provider / runtime evidence

A dedicated local provider key was configured by the user. The public development tunnel rejected an unsigned POST with **400** and accepted a correctly signed ping with **204** before generation webhook testing. The user configured the local key's webhook destination. Real provider events were recorded and processed through the queue.

| Controlled prompt       | Observed outcome                                                                   | Provider cost USD  | User charge USD    |
| ----------------------- | ---------------------------------------------------------------------------------- | ------------------ | ------------------ |
| `[succeed] [duplicate]` | Succeeded; two unique lifecycle events processed; one generation record            | 0.013622500        | 0.017028125        |
| `[succeed] [slow]`      | Processing, then succeeded; two lifecycle events processed                         | 0.016052500        | 0.020065625        |
| `[fail]`                | Failed; two lifecycle events processed                                             | 0                  | 0                  |
| `[blocked]`             | Rejected at submission                                                             | 0                  | 0                  |
| `[overloaded]`          | Automatic submission attempts stopped at five; unresolved record offers safe retry | No charge recorded | No charge recorded |

The duplicate keyword was exercised against the real provider. Repeated-delivery counts from the provider dashboard were not independently inspected; actual duplicate handling and concurrent deduplication are proven by deterministic automated tests.

`pixelforge:usage 2026-11-17 2026-11-17` matched local provider costs **0.029675000000000000** to provider usage **0.029675000**. Both generated images returned **200 / image/png**. Real GET retrieval returned succeeded/succeeded/failed, matching the three accepted local generations. No failed queue jobs remained. The first live HTTP polling helper omitted Inertia's asset-version header and received 409; subsequent verification used the documented provider retrieval, database state, image requests, and browser tests rather than counting that helper as a pass.

Sail app, PostgreSQL, Redis, Mailpit, queue worker, and scheduler ran successfully on separate ports without stopping other projects. Fresh migrations passed in a separately created disposable PostgreSQL database, which was then removed. Hosted migrations and provider configuration for a production key remain unverified.

## Quality and security findings

- **Passed:** full Pest suite, Laravel Pint, PHPStan level 7, Vue/TypeScript checking, frontend lint/format checks, production asset build, Composer manifest validation, and Git whitespace checks.
- **Passed:** credential-value scans of tracked/nonignored files and application logs; no matches. `.env` stays ignored. No provider key or signing secret is sent to Vue or stored in webhook payloads by this app.
- **Passed:** required application-code search for TODO/FIXME/HACK/XXX, not-implemented handlers, dummy returns, skipped requirements, and unfinished functions. Unused starter placeholder artwork and empty service-provider hook were removed. HTML input placeholders and test fixtures are intentional, not mocked production behavior.
- **Passed:** ownership, CSRF on user actions, separate HMAC-only webhook route, server-side validation, safe URL schemes, immutable quote parameters, transactions/constraints, and monotonic settlement reviewed against the code and tested boundaries.
- **Passed:** responsive mobile layout and Pest's critical/serious accessibility scan. This is basic accessibility evidence, not a full WCAG certification or cross-browser audit.
- **Composer audit:** no security advisories or abandoned dependencies reported.
- **npm audit:** 17 high-severity dependency entries, all tracing to the same unpatched [picomatch ReDoS advisory](https://github.com/advisories/GHSA-fc5q-39xc-vjpf). No patched version was listed at inspection. The affected tooling evaluates repository/build globs; the application accepts no user-defined glob patterns. Serve compiled assets, keep Vite private, disable SSR unless deliberately configured, and revisit the advisory before publication. No speculative downgrade or suppression was applied.
- **Nonblocking build notice:** optional font-fallback optimization package is absent. The fonts and production build work; no additional package was added for this optimization.

## Limitations and readiness

The required application is implemented and verified locally, with no known blocking application defect identified in this audit. It is ready for deployment review, **not yet a completed assessment submission**. Public publication/deployment/submission are intentionally withheld. Hosted HTTPS, production mail, backups, queue supervision, domain/proxy settings, production-specific provider credentials, real hosted webhook delivery, and post-deployment usage reconciliation must be verified after approval. GitHub Actions was configured but has not run remotely because no push was authorized.

Automatic retries deliberately pause rather than retry forever. A paused uncertain request may later settle through a webhook or an explicit safe check. Image URLs expire according to the provider contract. Pricing quotes are upper estimates at quoted rates, not a guarantee against a rate change before provider acceptance. Retaining accounting records after account deletion is deliberate. The development tunnel is temporary and must be rechecked before reuse.

Bonus balances/fake purchases and the operator admin report are **not implemented and not claimed**. The usage reconciliation command supports required billing verification without expanding into those bonus features.

## Remediation acceptance — 2026-11-17 19:33 UTC

The original audit above missed real defects. The independent-style review correctly changed the verdict to **NOT READY**. F01–F06 have now been fixed and regression-tested; F07 remains an upstream advisory condition. Current application target: the F01–F06 remediation snapshot. See [the full remediation report](INDEPENDENT_REVIEW.md#13-remediation-and-reverification) for exact file/line references, test names, commands, exits, prior failures and current coverage of every mandatory requirement.

| Finding                       | Resolution                                                                                                                                                                                                                                                                        |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F01 Unicode/quote limits      | Fixed-size UUID identifies a five-minute user/settings-bound server quote. Cache eviction fails closed; saved-request replay survives expiry. Four 4,000-character boundary datasets plus four maximum URLs pass; browser counts emoji code points correctly.                     |
| F02 Cooldown bypass           | Persist retry-not-before independently from pause/resume. Manual and operator retries, polling and delayed snapshots cannot shorten it. Frozen-clock tests assert no premature requests. Upgrade preserves existing deadlines.                                                    |
| F03 Old/poison inbox recovery | Remove age cutoff. Persist attempts/backoff/failure codes, quarantine poison messages, expose operator retry. Old-event, 125-event backlog and bounded transient-failure tests pass.                                                                                              |
| F04 Deletion/retention        | Remove profile/sessions/reset tokens and completed personal content, preserve minimal financial records, cancel unsubmitted work, retain immutable in-flight parameters only until settlement. Atomic submission marker prevents a deletion/send race. Eight deletion cases pass. |
| F05 Test quality              | Contract-consistent usage; nine complete estimate/create/webhook/settlement journeys; unchanged-rate maximum assertions; browser PNG decoding; forced PostgreSQL contention at receipt/settlement; real Redis queue worker.                                                       |
| F06 UX/accessibility          | Accurate paused copy including legacy records, temporary-image retry versus actual expiry, input error associations and keyboard recovery. Seven browser tests pass.                                                                                                              |
| F07 Dependency security       | No compatible patched picomatch version exists at inspection. npm audit still fails; actual paths and mitigation documented. No breaking downgrade or suppression.                                                                                                                |

### Final executed results

- **Full Pest:** 148 passed, **802 assertions**, 0 failed, 0 skipped, exit **0**; final run 77.570 seconds. Includes **7 Chromium browser tests** and **4 PostgreSQL/Redis integration tests**. The original useful tests remain.
- **Pint full pass:** exit 0. **PHPStan level 7:** 0 errors, exit 0. **Frontend lint/format:** 61 formatted files, 50 linted files, 0 warnings/errors, exit 0. **Vue/TypeScript:** exit 0. **Production build:** 3,564 modules, exit 0. Optional font-fallback optimization remains a nonblocking notice.
- **Migrations:** all **9** pass on a fresh owned disposable PostgreSQL database; database removed afterward, all commands exit 0. Separate upgrade test preserves synthetic existing records, exact bills, inbox payloads and future cooldowns. Development migrations applied without resetting development data.
- **Runtime:** all six Sail services running; queue/scheduler restarted after migration; no failed jobs; reconciliation scheduled every minute. A separate real Redis worker consumed a deduplicated synthetic inbox event, with no provider request.
- **Composer validation/audit:** exit 0; no advisories or abandoned packages. **npm audit:** exit **1**, **17 high dependency entries** from the single unpatched picomatch advisory. GitHub lists no patch and npm latest is 4.0.7. The original independent review reported 14 entries; the current count is measured, not copied.
- **Security/completeness:** no real credential matches across 314 file/log candidates and available Git history; no required-code TODO/FIXME/HACK/XXX, not-implemented handlers or disabled tests found. No production mocks. Whitespace check exit 0.

### Real integration and remaining gates

Fresh read-only provider checks retrieved the three existing accepted generations: two succeeded and one failed. All statuses, exact provider costs and 25% user charges matched local rows. Both successful image URLs returned 200 and decoded as PNGs at requested dimensions. `pixelforge:usage 2026-11-17 2026-11-17` freshly matched **0.029675000000000000** locally to **0.029675000** from the provider; exit 0. No identifiers, signed image URLs or credentials were printed.

No new provider generation was requested. Earlier success/failure/blocked/slow/duplicate/overload runs remain historical evidence, not fresh post-fix creation verification. No public tunnel/webhook integration was rerun; reverify reachability before a future approved live test. Deterministic tests cover the corrected flows with mocked provider transport and real application/database/browser code.

**Verdict: READY WITH CONDITIONS for deployment review.** No known blocking F01–F06 defect remains. The F07 advisory is unresolved and explicitly mitigated, not claimed clean. Approve the deployment/publication target and temporary advisory condition before release; approve new live generation smoke tests separately. Hosted TLS/proxies/mail/backups, production worker/scheduler/webhook configuration, hosted sample images and remote CI remain unverified. No push, public publication, deployment or assessment submission occurred. Optional bonus features remain excluded.
