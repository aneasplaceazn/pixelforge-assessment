# PixelForge image studio

A Laravel 13 / Vue 3 / Inertia 3 application for the [PromptEdit assessment](https://pixelforge.promptedit.dev/assessment). Users can register, request images with prompts, sizes, quality levels and optional references, review maximum prices, and follow their private generation history through completion. Exact usage-based charges include a 25% markup; failed generations are free. No real payments are taken.

**Live application:** [PixelForge image studio](https://pixelforge-assessment-production-xt3tlo.laravel.cloud).

The Vue/Inertia interface uses compiled Tailwind assets. Laravel owns authentication, provider requests, quotes, webhook verification and billing. PostgreSQL stores users, sessions, generations and the webhook inbox. Redis-compatible Valkey supplies production caching, distributed locks and queues; local development uses Redis and Laravel Sail. The documented [PixelForge API](https://pixelforge.promptedit.dev/docs) is simulated: it returns abstract placeholder PNGs captioned with the prompt, rather than actual AI artwork.

## Reviewer walkthrough

1. Open the live application and select **Log in**. Use the reviewer credentials supplied privately with the submission, or register your own account. Credentials are never published in this repository.
2. The **Image studio** shows the signed-in account's sample generations under **Your generations**. Another account's history is intentionally inaccessible.
3. Enter a prompt, choose size and quality, and optionally add reference URLs. Select **Get price estimate** to see the maximum estimated user price, including the 25% markup.
4. Select **Generate image** only after reviewing that estimate. The history updates automatically through queued, processing, succeeded or failed states.
5. Completed cards show the image, estimated price and exact final charge. Reloading the page preserves history. Failed generations show zero charge; unresolved requests are not mislabeled as free failures.

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

Open `http://localhost:8088` and register an account. The queue and scheduler are Compose services and start with Sail. For frontend development, use `./vendor/bin/sail npm run dev -- --host 0.0.0.0 --port 5188`. Stop that process before a production build; a stale `public/hot` file makes Laravel request the development server.

The example uses host ports 8088 (app), 5188 (Vite), 54328 (PostgreSQL), 6388 (Redis), 1028/8028 (Mailpit). Check `docker ps` and listening sockets before startup and adjust `.env` if needed. Container ports remain unchanged. Test data uses Sail's separate `testing` PostgreSQL database.

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Never use `VITE_` variables for secrets. Each environment needs its own provider key. Configure that key's webhook URL as `https://your-host/webhooks/pixelforge` in the provider dashboard. A local development tunnel must be public; expose only this path when debugging is enabled. Verify unsigned requests are rejected and the provider's signed ping succeeds before testing generation webhooks. Temporary tunnel URLs expire.

## How it works

1. The server fetches pricing (cached for 60 seconds) and calculates the upper estimate: Unicode prompt length, ceiling text-token count plus maximum variance, references, exact output tokens, and maximum reasoning tokens. The quote includes the 25% markup.
2. A five-minute server-held quote binds the estimate to the signed-in user and a SHA-256 hash of the exact settings. The browser receives a fixed-size random UUID, also used as the request key. Quotes contain no prompt/reference content. Unknown, changed, expired, or another user’s quotes cannot create a generation. Redis eviction requires a fresh estimate; an already-saved request remains idempotent after quote expiry.
3. The local generation is committed before dispatch. Every provider POST uses that same idempotency key and immutable parameters, including the local UUID in metadata.
4. Webhooks authenticate the **raw body** with HMAC-SHA256 and a five-minute timestamp window. The event ID is a database primary key. The inbox is committed before a job is dispatched.
5. Creation responses, webhook jobs, and polling all use `ApplyGeneration`. Row locks serialize settlement; terminal states cannot regress. One generation row is the billing record, so there is no separate increment or duplicate charge to apply.
6. `pixelforge:reconcile`, scheduled every minute, recovers missing queue dispatches regardless of event age and polls due records. Invalid webhook events are quarantined; transient event failures back off and stop after five attempts. Each request has a ten-second timeout. A persisted retry-not-before deadline respects `Retry-After` and exponential backoff through manual resume and delayed snapshots. Five consecutive failures pause automatic retries. “Check saved request again” preserves the original provider key; “Use prompt again” requires a new quote.

A submission timeout or worker crash may hide an accepted provider request. Such requests stay unresolved, with no charge recorded yet, until a result is confirmed. They are never falsely labeled as free failures. Provider-confirmed failures and definitive rejected submissions record zero.

All monetary calculations use BCMath decimal strings, stored as PostgreSQL `numeric(30,18)` and sent to JavaScript as strings. Accepted rates have up to nine decimal places: per-million conversion adds six and the markup adds two, fitting within eighteen. Out-of-contract numbers are rejected instead of rounded. Database checks enforce nonnegative estimates, exact markup, zero for failures, and no final charge for pending work. Final cost always uses the **generation's own rates and actual four-category usage**, never today's pricing or the estimate. Estimates describe current prices; provider prices can change before acceptance.

History is paginated and scoped to the authenticated user. Browser polling reads local state, while the scheduler independently contacts the provider. Reference URLs are passed through; this app does not download them. Generated URLs expire after 24 hours; unexpired image failures offer a reload and expired images offer prompt reuse for a new generation.

### Account deletion and retention

Deletion removes the user, database sessions and password-reset tokens. Never-submitted work is cancelled for zero cost. Completed prompts, references, image links, free-text errors and quote pricing snapshots are erased. Financial rows retain opaque provider/request IDs, size/quality, timestamps, actual token usage, generation rates, estimates and exact costs/charges for reconciliation; ownership becomes NULL. These financial rows have no automatic purge in this assessment.

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

The usage command compares exact costs by **UTC completion day**, exits nonzero on discrepancy, and never rewrites accounting records. Use a dedicated provider key; preexisting generations created outside this app will appear as differences. The command is an operational reconciliation check, not the optional admin-report feature.

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

The deploy command is `php artisan migrate --force --no-interaction`. Cloud manages release and worker replacement; do not add cache-clearing or worker-restart commands to deployment. Documentation-only commits do not require redeploying the unchanged application.

One colocated background process runs `php artisan queue:work redis --queue=default --tries=3 --backoff=30 --sleep=1 --timeout=30 --quiet`. `REDIS_QUEUE_RETRY_AFTER=90` exceeds the worker timeout. Cloud's scheduler runs Laravel's schedule every minute, including `pixelforge:reconcile` with overlapping runs prevented. Shared Valkey uses `noeviction` to avoid silently dropping queued work; monitor capacity and failed jobs.

Cloud injects database and cache connections. Production settings include a persistent `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, database sessions with Secure/HttpOnly/SameSite=Lax cookies, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, and `INERTIA_SSR_ENABLED=false`. Dedicated provider credentials stay in Cloud environment settings, never frontend variables. The production key's webhook destination is the live application's `/webhooks/pixelforge` endpoint. Never rotate `APP_KEY` casually: encrypted cookies depend on it.

**Production password-reset email delivery is unavailable.** SMTP and email providers are intentionally not configured. `MAIL_MAILER=log`, `MAIL_LOG_CHANNEL=stderr` and `LOG_LEVEL=info` suppress the log transport's debug-level email bodies. Password-reset routes remain available, but users will not receive reset emails. Registration and login do not require email verification. Local Mailpit remains available for development.

Monitor failed jobs, pending/failed inbox events and paused generations. `pixelforge:webhooks` lists up to 50 quarantined events and exits 1 when any exist; investigate before using `pixelforge:webhooks EVENT_ID --retry`. It never prints payloads. The scheduler scans at most 100 inbox events and 40 due generations per minute. Restore procedures and sustained-load capacity have not been exercised on this assessment deployment. Compute can pause at the configured spending threshold, interrupting scheduled work until service resumes; storage charges can continue. Preserve records and reconcile pending work before an approved shutdown.

## Production verification — 2026-11-17 UTC

The deployed application source is `2a7e9219d0d811c43efb12ee1fa971a2bedb2dfc`. GitHub CI on that source passed 148 tests and 802 assertions, plus code style, static analysis, frontend checks and build. Those automated tests simulate the provider; the following checks used the hosted application and dedicated production provider key:

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

This verifies one production success/duplicate workflow, not all possible failures. Production failure/slow/overload generation tests, sustained load and backup restoration remain unverified; deterministic tests and earlier local provider tests are recorded separately. Additional reviewer samples require separate approval and should be generated near submission because image URLs expire.

Optional balances, fake purchases, and the operator report are intentionally excluded. See [AUDIT.md](AUDIT.md) for measured acceptance evidence and remaining limitations.

## Dependency advisory

As checked on 2026-11-17 UTC, [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) affects picomatch through 4.0.7; upstream lists no patched version, and npm's latest is 4.0.7. The installed 2.3.2/4.0.7 paths are Vite reload, tinyglobby/fdir, Vitest and Vue language tooling. No request input reaches a glob matcher in this application. Production serves compiled static assets with SSR disabled; do not ship or expose the Node development server/toolchain as a public runtime. This is a mitigated outstanding advisory, not a clean npm audit. Do not apply the suggested major downgrade as a substitute for a verified patch. Re-audit and approve the temporary advisory condition before publishing.
