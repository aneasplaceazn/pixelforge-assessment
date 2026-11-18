# PixelForge image studio

A Laravel 13 / Vue 3 / Inertia 3 application for the [PromptEdit assessment](https://pixelforge.promptedit.dev/assessment). Users can register, request images with prompts, sizes, quality levels and optional references, review maximum prices, and follow their private generation history through completion. Exact usage-based charges include a 25% markup; failed generations are free. The mandatory functionality and both optional bonuses are deployed: simulated credit purchases, concurrent credit reservations, and operator financial reports. No real payments are taken. Production acceptance verified successful and failed generations, exact billing, credit settlement and release, signed duplicate webhooks, reports, and provider usage comparison.

**Live application:** [PixelForge image studio](https://pixelforge-assessment-production-xt3tlo.laravel.cloud).

The Vue/Inertia interface uses compiled Tailwind assets. Laravel owns authentication, provider requests, quotes, webhook verification and billing. PostgreSQL stores users, sessions, generations and the webhook inbox. Redis-compatible Valkey supplies production caching, distributed locks and queues; local development uses Redis and Laravel Sail. The documented [PixelForge API](https://pixelforge.promptedit.dev/docs) is simulated: it returns abstract placeholder PNGs captioned with the prompt, rather than actual AI artwork.

## Reviewer walkthrough

1. Open the live application and select **Log in**. Use the reviewer credentials supplied privately with the submission, or register your own account. Credentials are never published in this repository.
2. The **Image studio** shows the signed-in account's sample generations under **Your generations**. Another account's history is intentionally inaccessible.
3. Use **Buy credits** to add simulated $5, $10 or $20 credits. New accounts start with zero credits; reviewer credits may be prepared in advance. Existing generations are never debited retroactively. Enter a prompt, choose size and quality, and optionally add reference URLs. Select **Get price estimate** to see the maximum estimated user price, including the 25% markup.
4. Select **Generate image** only after reviewing that estimate. The history updates automatically through queued, processing, succeeded or failed states.
5. Completed cards show the image, estimated price and exact final charge. Reloading the page preserves history. Failed generations show zero charge; unresolved requests are not mislabeled as free failures.
6. With the privately supplied reviewer account, select **Financial reports** to inspect daily and per-user charges, provider costs and profit, filter by UTC dates, and compare with PixelForge. Newly registered accounts cannot access these reports.

Provider image URLs expire 24 hours after completion. History and accounting remain available after the image expires; this application does not archive the PNGs. Prepare reviewer samples close to review time. Prompt reuse creates a new generation and requires a fresh estimate.

## Local setup

Requires Docker. Sail supplies PHP 8.5 with BCMath, Composer, and Node. Application commands below run in Sail.

```sh
cp .env.example .env
# If vendor/ is absent, bootstrap dependencies with the Composer container (scripts/plugins run later in Sail):
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/var/www/html" -w /var/www/html composer:2 install --ignore-platform-reqs --no-scripts --no-plugins
./vendor/bin/sail up -d
./vendor/bin/sail composer install --no-interaction
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --no-interaction
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

Open `http://localhost:8088` and register an account. The queue and scheduler are Compose services and start with Sail. After changing worker code locally, run `./vendor/bin/sail restart queue` so the long-lived worker loads it. For frontend development, use `./vendor/bin/sail npm run dev -- --host 0.0.0.0 --port 5188`. Stop that process before a production build; a stale `public/hot` file makes Laravel request the development server.

The example uses host ports 8088 (app), 5188 (Vite), 54328 (PostgreSQL), 6388 (Redis), 1028/8028 (Mailpit). Check `docker ps` and listening sockets before startup and adjust `.env` if needed. Container ports remain unchanged. Test data uses Sail's separate `testing` PostgreSQL database.

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Never use `VITE_` variables for secrets. Each environment needs its own provider key. Configure that key's webhook URL as `https://your-host/webhooks/pixelforge` in the provider dashboard. A local development tunnel must be public; expose only this path when debugging is enabled. Verify unsigned requests are rejected and the provider's signed ping succeeds before testing generation webhooks. Temporary tunnel URLs expire.

## How it works

1. The server fetches pricing (cached for 60 seconds) and calculates the upper estimate: Unicode prompt length, ceiling text-token count plus maximum variance, references, exact output tokens, and maximum reasoning tokens. The quote includes the 25% markup.
2. A five-minute server-held quote binds the estimate to the signed-in user and a SHA-256 hash of the exact settings. The browser receives a fixed-size random UUID, also used as the request key. Quotes contain no prompt/reference content. Unknown, changed, expired, or another user’s quotes cannot create a generation. Redis eviction requires a fresh estimate; an already-saved request remains idempotent after quote expiry.
3. The user row and credit account are locked while the maximum user charge is reserved and the local generation is created in one transaction. Insufficient credits roll back both records and dispatch no job. The local generation and reservation are committed before dispatch. Every provider POST uses that same idempotency key and immutable parameters, including the local UUID in metadata.
4. Webhooks authenticate the **raw body** with HMAC-SHA256 and a five-minute timestamp window. The event ID is a database primary key. The inbox is committed before a job is dispatched.
5. Creation responses, webhook jobs, and polling all use `ApplyGeneration`. Row locks serialize settlement; terminal states cannot regress. The generation row records its exact final bill. In that same transaction, the append-only credit ledger settles the actual charge once and releases the unused reservation. Unique constraints prevent duplicate reservation, settlement and release entries.
6. `pixelforge:reconcile`, scheduled every minute, recovers missing queue dispatches regardless of event age and polls due records. Invalid webhook events are quarantined; transient event failures back off and stop after five attempts. Each request has a ten-second timeout. A persisted retry-not-before deadline respects `Retry-After` and exponential backoff through manual resume and delayed snapshots. Positive generation delays round **up** to whole seconds before persistence, and an existing later cooldown always wins. An immediate resume with no remaining cooldown stays immediately runnable. Five consecutive failures pause automatic retries. “Check saved request again” preserves the original provider key; “Use prompt again” requires a new quote.

A submission timeout or worker crash may hide an accepted provider request. Such requests stay unresolved, with their credit reservation held and no final charge recorded yet, until a result is confirmed. They are never falsely labeled as free failures. Provider-confirmed failures and definitive rejected submissions record zero.

All monetary calculations use BCMath decimal strings, stored as PostgreSQL `numeric(30,18)` and sent to JavaScript as strings. Accepted rates have up to nine decimal places: per-million conversion adds six and the markup adds two, fitting within eighteen. Out-of-contract numbers are rejected instead of rounded. Database checks enforce nonnegative estimates, exact markup, zero for failures, and no final charge for pending work. Final cost always uses the **generation's own rates and actual four-category usage**, never today's pricing or the estimate. Estimates describe current prices; provider prices can change before acceptance.

History is paginated and scoped to the authenticated user. Browser polling reads local state, while the scheduler independently contacts the provider. Reference URLs are passed through; this app does not download them. Generated URLs expire after 24 hours; unexpired image failures offer a reload and expired images offer prompt reuse for a new generation.

### Account deletion and retention

Deletion removes the user, database sessions and password-reset tokens. Never-submitted work is cancelled for zero cost. Completed prompts, references, image links, free-text errors and quote pricing snapshots are erased. Financial rows retain opaque provider/request IDs, size/quality, timestamps, actual token usage, generation rates, estimates and exact costs/charges for reconciliation; ownership becomes NULL. These financial rows and anonymous credit-ledger entries have no automatic purge in this assessment. Deletion assigns an opaque reporting identifier so retained financial totals remain distinct for accounts deleted after this upgrade. Older records whose owner was already erased remain grouped as historical deleted accounts; that identity cannot be recovered. Unused simulated credits become inaccessible after deletion, and in-flight reservations still settle or release normally.

Accepted or uncertain in-flight work retains its original parameters until the outcome is reconciled, so retries cannot change an idempotent request. It may finish and incur a charge. Terminal settlement then erases its content. Processed webhook payloads are cleared; event IDs and processing metadata remain for deduplication. Pending/failed inbox payloads remain for recovery/investigation, with terminal deleted-account payloads redacted. Provider-held copies and infrastructure backups are outside this application's deletion operation. Configure backup retention for the selected host.

Monitor paused requests, including deleted accounts, and resolve them promptly. `pixelforge:resume GENERATION_UUID` resumes the same request with its existing cooldown and key; it may send a provider request. Investigate before invoking it and obtain authorization for any provider usage it may incur.

## Verification

```sh
./vendor/bin/sail exec --user root laravel.test npx playwright install-deps chromium
./vendor/bin/sail npx playwright install chromium
./vendor/bin/sail artisan test --compact
./vendor/bin/sail bin pint --dirty --format agent
./vendor/bin/sail composer types:check
./vendor/bin/sail npm run check
./vendor/bin/sail npm run types:check
./vendor/bin/sail npm run build
./vendor/bin/sail artisan queue:failed
./vendor/bin/sail artisan pixelforge:usage YYYY-MM-DD YYYY-MM-DD
```

Automated tests never use provider credentials; outgoing HTTP is blocked unless faked. Browser tests use real Chromium and the application, with only provider responses simulated. Concurrency tests force four independent PHP processes to wait on real PostgreSQL locks at both HTTP inbox insertion and settlement boundaries. A separate test consumes a unique inbox job through a real Redis worker. Migrations also run against an isolated database during acceptance.

The usage command compares exact costs by **UTC completion day**, exits nonzero on discrepancy, and never rewrites accounting records. Use a dedicated provider key; preexisting generations created outside this app will appear as differences. The command refuses unverified legacy scope and excludes other API keys. The operator dashboard additionally compares counts, overall totals, and generation inventory.

## Laravel Cloud deployment

The `production` environment is deployed on Laravel Cloud Starter in Ohio (`us-east-2`). It uses one Flex 512 MiB app instance, PHP 8.5 with BCMath, Node 24 during builds, PostgreSQL 18 at a fixed 0.25 CU, and private Valkey Flex 250 MB. PostgreSQL has one day of point-in-time recovery retention. No dedicated worker server, SSR process, object storage, or email service is required. Automatic deployments, compute scaling, cache upgrades and hibernation are disabled for this assessment configuration.

Cloud's native build command is:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci --include=dev
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
rm -rf node_modules
```

The completed first credit-aware release used a full-stop cutover. Its one-time command remains configured: `php artisan down --retry=60 --no-interaction && php artisan migrate --force --no-interaction`. Maintenance was explicitly lifted after verification. Changing the command to `php artisan migrate --force --no-interaction` for future compatible releases requires separate approval; it has not been changed. Cloud manages release and worker replacement; do not add cache-clearing or worker-restart commands to deployment. Documentation-only commits do not require redeploying the unchanged application.

One colocated background process runs `php artisan queue:work redis --queue=default --tries=3 --backoff=30 --sleep=1 --timeout=30 --quiet`. `REDIS_QUEUE_RETRY_AFTER=90` exceeds the worker timeout. Cloud's scheduler runs Laravel's schedule every minute, including `pixelforge:reconcile` with overlapping runs prevented. Shared Valkey uses `noeviction` to avoid silently dropping queued work; monitor capacity and failed jobs.

Cloud injects database and cache connections. Production settings include a persistent `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, database sessions with Secure/HttpOnly/SameSite=Lax cookies, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, and `INERTIA_SSR_ENABLED=false`. Dedicated provider credentials stay in Cloud environment settings, never frontend variables. The production key's webhook destination is the live application's `/webhooks/pixelforge` endpoint. Never rotate `APP_KEY` casually: encrypted cookies depend on it.

**Production password-reset email delivery is unavailable.** SMTP and email providers are intentionally not configured. `MAIL_MAILER=log`, `MAIL_LOG_CHANNEL=stderr` and `LOG_LEVEL=info` suppress the log transport's debug-level email bodies. Password-reset routes remain available, but users will not receive reset emails. Registration and login do not require email verification. Local Mailpit remains available for development.

Monitor failed jobs, pending/failed inbox events and paused generations. `pixelforge:webhooks` lists up to 50 quarantined events and exits 1 when any exist; investigate before using `pixelforge:webhooks EVENT_ID --retry`. It never prints payloads. The scheduler scans at most 100 inbox events and 40 due generations per minute. Restore procedures and sustained-load capacity have not been exercised on this assessment deployment. Compute can pause at the configured spending threshold, interrupting scheduled work until service resumes; storage charges can continue. Preserve records and reconcile pending work before an approved shutdown.

## Historical production verification — 2026-11-17 UTC

The application source deployed for these earlier checks was `2a7e9219d0d811c43efb12ee1fa971a2bedb2dfc`. GitHub CI on that source passed 148 tests and 802 assertions, plus code style, static analysis, frontend checks and build. Those automated tests simulate the provider; the following checks used the hosted application and dedicated production provider key:

- Native production build and all nine migrations succeeded. HTTPS, health checks, registration/login/logout, database sessions, cache operations, lock exclusion/release, secure cookies, compiled assets and mobile layout passed.
- The actual Cloud scheduler recovered an undispatched inbox ping, and the Redis worker processed it once. A provider-originated dashboard ping verified the configured webhook URL and signing secret. Invalid, missing, expired and tampered signatures were rejected.
- One approved `[succeed][duplicate]` generation progressed through queued, processing and succeeded. Its PNG decoded at 1024×1024 before and after reload; history and charges persisted. Logs recorded four successful webhook deliveries, while two unique lifecycle events were each processed once. Access logs omit event IDs, so individual HTTP deliveries cannot be mapped conclusively to each event.

| Real generation result    |   Exact USD |
| ------------------------- | ----------: |
| Maximum provider estimate | 0.017480000 |
| Actual provider cost      | 0.016812500 |
| Exactly 25% markup        | 0.004203125 |
| Final user charge         | 0.021015625 |

Actual usage was 5 text, 0 reference-image, 280 output and 447 reasoning tokens. Generation-specific rates, the database and the provider's usage report matched exactly. `php artisan pixelforge:usage 2026-11-17 2026-11-17` exited 0 with MATCH. After duplicate deliveries there was still one billing row, unchanged charges, no failed jobs and no pending webhook events. Inspected browser responses and bounded production logs contained no known credentials or critical errors.

Three additional controlled production scenarios passed on 2026-11-17/18 UTC: `[fail]` completed asynchronously with exactly zero charge; `[slow][succeed]` processed for 64 seconds, produced a decoded 1024×1024 PNG and settled at **0.012295000 USD provider cost / 0.015368750 USD user charge**; `[overloaded]` paused after five bounded attempts with the same request key, no provider generation and no recorded charge. The slow test's fresh maximum estimate was **0.017475000 / 0.021843750 USD**, below its approved limits. History survived reload in all three scenarios. Scheduled recovery continued during slow processing, and final provider usage reconciliation matched exactly. See the [reliability addendum](AUDIT.md#controlled-production-reliability--2026-11-1718-utc) for timings and evidence limits, including the absence of raw outbound overload-response headers.

These checks cover the specified scenarios, not all possible failures. Sustained load was deliberately skipped; backup restoration remains unverified. Additional reviewer samples require separate approval and should be generated near submission because image URLs expire.

**Historical local follow-up, 2026-11-18 UTC; before the production release:** one separately approved isolated provider request captured HTTP 503 with `Retry-After: 2`. Its cooldown exceeded two seconds, but a simulated 300-second header exposed fractional-second truncation that could permit a retry up to almost one second early. The local fix rounds positive generation delays upward before persistence. Eighteen new regression cases cover fractional boundaries, later cooldowns, all five backoffs, HTTP/CLI resume, delayed signed webhooks and scheduled recovery. The full Sail/PostgreSQL suite passed **166 tests / 932 assertions**, with no failures or skips; PHPStan, Pint, frontend lint/format/types and the production build passed. No further provider requests were made while fixing it. See [F08 evidence and deployment considerations](AUDIT.md#f08-fractional-retry-deadline-remediation--2026-11-18-utc). At that stage the fix was not deployed; the later production release is recorded below.

Both optional features were initially verified locally. The initial bonus suite passed **210 tests / 1,294 assertions**, including nine browser and twelve PostgreSQL/Redis integration cases, with zero failures or skips. Pint, PHPStan, frontend lint/format, Vue types, production build and Composer audit passed; npm audit retains the documented advisory. See the latest bonus acceptance addendum in [AUDIT.md](AUDIT.md) and [INDEPENDENT_REVIEW.md](INDEPENDENT_REVIEW.md) for executed checks, the requirement matrix, limitations, and deployment gates. The earlier hosted results predate these financial workflows; later production acceptance is recorded below.

## Current production verification — 2026-11-18 UTC

Production runs approved application commit `83ef82c06a4149a952a1b1e22ebdc0891b802316`. Later README-only commits do not require redeployment. The final independent verification and CI passed **255 tests / 1,590 assertions**; the controlled production release applied all eleven migrations and preserved existing financial records.

Hosted acceptance verified both bonuses through the real UI, PostgreSQL, Valkey, queue worker, scheduler, and provider:

- Insufficient credits blocked submission. One simulated purchase added credit once despite a repeated purchase key.
- A successful generation cost **0.015190000 USD** at generation-specific rates; its exact 25% markup produced a **0.018987500 USD** user charge. One settlement and the unused reservation release were recorded. The PNG decoded and history survived reload.
- A failed generation recorded zero provider cost and user charge and released its full reservation without a settlement.
- Signed terminal webhook replays, each delivered twice, left the ledger and generation records unchanged. Invalid signatures were rejected. Duplicate and out-of-order handling also have automated regression coverage.
- Operator authorization, daily/per-user totals and exact profit passed. The new day's local provider cost matched `GET /v1/usage` exactly. Temporary operator access was revoked after that acceptance test. The designated reviewer account was subsequently granted reporting access with explicit approval; login, sample history and report access passed, and an ordinary account was denied both report viewing and comparison with HTTP 403.
- Queues drained, no failed jobs or pending webhook events remained, and scheduled reconciliation ran using the approved code. These are bounded acceptance results, not a sustained-load test.

**Historical reconciliation update — 2026-11-18 UTC:** four legacy requests were explicitly attributed to the Production key using the owner's continuous-key deployment history and corroborating application/provider records. An approved, guarded operation changed only their scope markers; financial values, timestamps, request parameters, idempotency keys and retry states remained unchanged. Fresh comparisons for November 17 and 18 matched provider costs, billed counts and token totals: **0.055702500 USD** provider cost and **0.069628125 USD** user charges, including exactly 25% markup. Failed generations remain free.

**Remaining reconciliation limitation:** one rejected legacy request remains paused and is reported as pending; three account-wide provider records associated with earlier local development lack confirmed creator-key metadata, so inventory completeness remains **unverified**. The established Production billing cohort reconciles, but this does not prove full account-wide inventory completeness. Other limitations remain: image URLs expire after 24 hours, production password-reset email is unavailable, and the dependency advisory below is unresolved.

## Credits and operator reports

### Credits

**Buy credits** simulates $5, $10 or $20 purchases. There is no payment provider or real money. Each purchase has a UUID idempotency key; repeating it cannot add credit twice. Balances are exact sums of immutable ledger deltas, not independently editable counters. The page shows available, reserved and outstanding USD and paginated transactions.

Submission rechecks affordability inside a transaction. User/account row locks serialize simultaneous requests; PostgreSQL also locks the account and rejects negative aggregate balances. The ledger records purchase, reserve, settle, release and debt-payment operations. `CreditEntryKind` is a PHP string-backed enum; migration CHECK constraints retain literal values so future PHP enum changes cannot rewrite historical migration behavior. Composite foreign keys bind each generation operation to the correct credit account, and uniqueness prevents duplicate operations.

A successful image settles its actual generation-specific usage plus exactly 25%, then returns unused reserved credits. A failure releases the full reservation with zero generation charge. Unknown outcomes, paused retries, manual resume, polling and webhook recovery preserve the original reservation. Historical generations without a reservation remain uncharged by the new wallet.

Provider rates are fixed at acceptance, which can occur after the estimate. If the actual bill exceeds the reservation, settlement uses additional **unreserved** credits, then records any remainder as an outstanding amount. It never consumes another image's reservation, rounds the bill, or creates a negative balance. New reservations are blocked while outstanding charges remain. Subsequent fake purchases and released credits automatically pay that amount through separate ledger entries. A failed image remains free even if its returned credits pay an earlier outstanding charge.

### Operator access and reporting

Public registration cannot grant reporting privileges. A server operator can grant or revoke access to a specific existing account using its numeric ID:

```sh
./vendor/bin/sail artisan operator:access USER_ID --no-interaction
./vendor/bin/sail artisan operator:access USER_ID --revoke --no-interaction
```

On Cloud, run the equivalent `php artisan` command only after authorization for that production account. No admin email or password is hardcoded. A Gate protects routes, controllers and report services; normal Inertia props expose only whether the signed-in user may see the reports link.

**Financial reports** shows exact user charges, provider costs, gross profit (charges minus costs), successful/failed/total counts, daily totals and paginated user totals. Filter by an inclusive UTC date range of up to 92 days. Completed work is attributed to the provider's completion day even when settled locally later; unresolved work is counted on its creation day with no charge yet. Normal report requests use a read-only repeatable-read PostgreSQL transaction. Totals are billed amounts, not simulated purchase receipts or cash revenue.

**Compare with PixelForge** reads `GET /v1/usage` for the configured API key, validating every requested UTC day, nine-decimal provider amounts, counts and overall totals. It also reads cursor-paginated generation inventory (maximum ten pages / 1,000 records per check). Local records are fetched in page batches. It shows missing local/provider records, unsettled work, cost/status/date discrepancies, pending states and unverified scope. No comparison edits accounting records. Snapshots expire from cache after five minutes and display their check time; a new settlement can make an earlier snapshot stale. A busy provider's cooldown is retained before allowing another comparison.

Usage is **API-key scoped**, while the provider generation listing is **account scoped** and contains no authoritative key field. New local requests record an API-key fingerprint and immutable scope metadata. Unscoped provider records cannot be attributed with certainty and are explicitly unverified. Closed-day comparisons are more stable than a day with ongoing generations. A truncated inventory scan is unverified even when aggregate usage matches.

Legacy generations have no automatically assumed key scope. First independently establish that **every** legacy row belongs to the currently configured dedicated key, then use the explicit adoption command:

```sh
./vendor/bin/sail artisan pixelforge:adopt-scope --no-interaction
# Preview above; only after independently verifying the key/records:
./vendor/bin/sail artisan pixelforge:adopt-scope --confirm-dedicated-key --no-interaction
```

The bare adoption command assigns the scope marker and normally updates `updated_at`; it does not change provider parameters, costs or balances. The approved production adoption used a separately reviewed transaction wrapper with exact-row guards, timestamp updates suppressed, and before/after checks proving that only `provider_scope` changed. If legacy rows span keys, do not run the blanket adoption command; investigate individual provenance before a reviewed data migration. Changing keys while a submission is unresolved pauses new POSTs for that scoped request and retains its reservation. Resolve it with its original key; never silently replay a new create under a different billing scope.

### Migration and release safety

`2026_11_18_005628_add_credit_ledger_and_operator_access.php` adds the operator flag (false), credit tables, nullable generation associations/scope/reporting fields, constraints, a ledger guard and a reporting index. It does not purchase credits, debit old generations, change existing costs or create operator accounts. Existing users and the reviewer login remain valid. DDL needs brief table locks; deploy during low traffic with verified recovery options. Do not reset or truncate production data.

Once ledger entries exist, rollback deliberately refuses to discard them. Prefer a forward repair. Rolling web code back to the pre-credit release would allow unreserved generation submissions, so it is not a safe normal rollback. An approved release must replace both web and worker code together, preserve existing provider metadata/idempotency keys, run migrations, then verify fake purchases, ownership and worker readiness on the hosted release before any separately approved hosted generation. Stop old workers and prevent new submissions during the transition: old workers do not write the credit ledger. Keep the scheduler and web/worker code on the same release before reopening submissions.

No additional Cloud resource, dependency, worker, scheduler or real payment service is required. The existing 512 MiB app, PostgreSQL and Valkey architecture remains the target. Reports use indexed exact aggregates; reconciliation bounds provider pages. Bounded hosted acceptance passed on these resources; sustained-load capacity and backup restoration remain unverified.

## Independently verified retry remediation

The second independent verification confirmed F-03 (scalar storage) and F-04 (legacy completeness), and the original F-02 cross-path cases. It also found F-09 (failed Redis deadline persistence) and F-10 (local limiter consuming provider attempts). Final independent targeted verification subsequently confirmed F-09 and F-10 fixed, with prior F-02/F-03/F-04 regressions passing. The approved corrections are deployed. Historical findings and implementation-session results remain in AUDIT.md and INDEPENDENT_REVIEW.md.

### Durable provider cooldown and no-send deferrals

Every authenticated request passes through the common PixelForge client. PostgreSQL's `provider_cooldowns` table is the mandatory shared authority. HTTP 429 `rate_limit_exceeded` establishes an account deadline; the atomic SQL maximum cannot shorten it. Positive delays round upward to whole UTC epoch seconds. Redis retains the validated scalar/Lua atomic-max mirror; Carbon objects and unrestricted deserialization remain prohibited. A missing Redis key never bypasses the PostgreSQL check.

Before HTTP, a short row-locked transaction commits an observation token through a separate connection to the same primary database. This commits independently of a caller's financial transaction. It serializes authenticated sends for one account, without holding a database lock during network I/O. Response handling atomically records any applicable deadline and clears only that token. If Redis rejects the subsequent mirror write, the durable deadline still blocks every application path after Redis recovers. If PostgreSQL cannot commit the response, the existing token remains a shared barrier. Storage failures are sanitized and fail closed.

`PIXELFORGE_ACCOUNT_SCOPE=primary` is a nonsecret account label, not an API key fingerprint. The key also hashes the provider base URL and account/read-endpoint scope. Keys for one provider account must use the same label and primary database; environments that do not share this authority cannot coordinate. Keep Redis connection/database/prefix settings consistent too. `PIXELFORGE_COOLDOWN_CONNECTION=cache` selects the existing Redis connection. No new infrastructure or credentials are required; one additional PostgreSQL connection per PHP process is used. Use synchronized clocks, durable database storage and consistent configuration; restoration to stale data cannot preserve observations that were lost.

Local budget refusals, limiter lock/storage failures, account pauses and an active observation token are no-send deferrals. They preserve provider attempts, reservations, uncertainty and idempotency, and schedule a future check without a busy loop. Actual outbound failures retain exponential backoff and the five-failure pause. Permanent missing/key-changed configuration errors retain their existing explicit operator-recovery behavior.

Generation concurrency limits and overload rejections retain per-generation backoff rather than account-wide cooldowns. Retryable GET failures pause their read endpoint; unrelated creation can continue after that HTTP request finishes. Public pricing remains available. Already-started requests and external clients cannot be retroactively canceled or coordinated by this application.

### Observation recovery and migration

`2026_11_18_032434_create_provider_cooldowns_table.php` adds an initially empty, primary-keyed coordination table with integer deadline and paired observation-token/time constraints. It does not alter users, generations, ledger entries or balances. Apply it only inside the full-stop cutover below before new workers are enabled. Fresh eleven-migration replay and populated upgrades are tested. The migration refuses rollback while coordination rows exist; prefer forward repair, never drop active deadlines or unresolved observations to unblock traffic.

A process crash after committing its token, or a failed database response commit, can require operator recovery. Tokens deliberately have **no automatic expiry**: an elapsed HTTP timeout cannot prove what Retry-After a response required. First stop/identify all producers and confirm the original sender cannot still run. Inspect the pending token and safe status/header/timing evidence. With explicit authorization and known provider requirements, use the existing `ProviderCooldown::finish(token, delay, scope)` under a row lock, preserving later deadlines. A full known delay measured from recovery time is conservative. If the response is unknown, obtain provider confirmation before clearing the barrier; never invent a timeout or delete the row. Ambiguous commit outcomes remain safe through the atomic deadline/token transaction, subject to database durability. There is no automatic reconstruction after database loss or a provider response that was never observed.

The complete local suite passes **255 tests / 1,590 assertions**, including 18 PostgreSQL/Redis integration cases and ten browser cases. Original independent counterexamples now pass unchanged (3 / 14); the separate independent financial/crash safety suite passes (4 / 37). Those counts were also confirmed by the final independent verification and GitHub CI for the deployed commit. Code checks/build and Composer audit passed; npm's documented advisory remains.

### Reconciliation completeness

Unknown-scope queued/processing legacy requests created before the selected end boundary are now disclosed even without a completion date, provider ID or scheduled retry. This includes older unresolved work that might complete in the range. It does not assign them to the current API key. The overall comparison and inventory remain unverified while individual completed-cost rows can still match exactly. Known other-key records and requests created on/after the next UTC day remain excluded. Old comparison snapshots use a different cache version and cannot display the pre-fix conclusion after release. No money or generation scope is rewritten by a comparison.

### Required first credit release: stop, deploy into maintenance, verify, reopen

This procedure was executed for the first credit-aware release on 2026-11-18. It remains a reference for any future incompatible cutover, not an instruction to redeploy. Ordinary graceful rolling replacement of the old pre-credit version is unsafe: old workers can settle the generation bill but leave new reservations held forever. Replaying a terminal webhook through new code does not repair that mismatch.

Laravel Cloud supports [stopping an entire environment](https://laravel.com/cloud/docs/environments#stop-and-restart), including its web, background and scheduler processes, while preserving attached data services. Starting it performs a deployment. After approval for the exact release and outage:

1. Verify the production target, recoverable backup options and pending/in-flight records. Freeze branch pushes and other deployment/remote-command activity. Confirm Cloud Stop/Start permissions and the actual health-check path (`/up`) in the dashboard.
2. Stop the whole old environment and wait for confirmed stopped compute. An accepted Stop request or a nominal shutdown timeout is insufficient. Preserve PostgreSQL and Valkey, including delayed/reserved jobs. No new credit code may run before all old producers/consumers are gone.
3. While stopped, configure `APP_MAINTENANCE_DRIVER=cache` and `APP_MAINTENANCE_STORE=database` using the existing cache table. Replace the one-time deploy command with `php artisan down --retry=60 --no-interaction && php artisan migrate --force --no-interaction`. Never automatically run `up` or clear the maintenance store. Database-backed maintenance survives ephemeral deploy containers and a Valkey flush. A file-backed `down` command does not provide that guarantee.
4. Start/deploy the exact approved code into maintenance. Freeze `main` at that SHA and omit/set `redeploy=false` when using Cloud Start; `redeploy=true` selects the previous deployed commit, not the new candidate. Verify the resulting deployment SHA, current source fingerprints, sole worker PID/start time/command, scheduler configuration, migrations and ledger consistency. Confirm `/up` returns 200 while normal submission POSTs return 503. The configured worker must omit `--force`; scheduled tasks must not use `evenInMaintenanceMode()`.
5. Only after those gates, explicitly run `php artisan up` and verify worker/scheduler recovery and non-chargeable acceptance. Verify uncertain provider submissions with their original keys; never create replacements. Provider webhooks may exhaust their delivery retries during downtime, so preserve the inbox and reconcile missing events through polling. New provider generation tests still need separate approval.

If a gate fails, keep maintenance enabled or stop the environment again. Do not reopen old code after credit activity, reset financial tables, clear queues or restore a database casually across provider side effects. After successful activation, restore the ordinary migration-only deploy command as an explicitly reviewed configuration change for later compatible releases. Hosted cutover and maintenance checks passed for the approved release. The shutdown decision used the stopped control-plane status and sustained zero activity with explicit acceptance of unexplained replica telemetry; it was not independent proof of every old process terminating. Backup restoration remains untested.

A specifically approved stranded terminal reservation can be repaired under a generation-row transaction/lock by validating the stored usage/rates and ledger, then calling the existing `Credits::settle()` directly. It appends the missing settlement/release without rewriting the generation bill; repeating after the release exists is a no-op. Unexpected partial entries require investigation, not deletion. The audit's $5/$3 example was reproduced locally and repaired to **$4.98596875 available / $0 reserved**, with exactly one $0.01403125 settlement. No production repair was performed.

## Dependency advisory

As checked on 2026-11-17 UTC, [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) affects picomatch through 4.0.7; upstream lists no patched version, and npm's latest is 4.0.7. The installed 2.3.2/4.0.7 paths are Vite reload, tinyglobby/fdir, Vitest and Vue language tooling. No request input reaches a glob matcher in this application. Production serves compiled static assets with SSR disabled; do not ship or expose the Node development server/toolchain as a public runtime. This is a mitigated outstanding advisory, not a clean npm audit. Do not apply the suggested major downgrade as a substitute for a verified patch. Re-audit and approve the temporary advisory condition before publishing.
