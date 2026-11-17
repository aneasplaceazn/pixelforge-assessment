# PixelForge image studio

A Laravel 13 / Vue 3 / Inertia 3 application for the [PromptEdit assessment](https://pixelforge.promptedit.dev/assessment). It uses the documented [PixelForge API](https://pixelforge.promptedit.dev/docs), PostgreSQL, Redis, and Sail. No real payments are taken.

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

Monitor paused requests, including deleted accounts, and resolve them promptly. `pixelforge:resume GENERATION_UUID` resumes the same request with its existing cooldown and key; it may send a provider request. Investigate before invoking it. It must not be used to bypass the user's restriction on new live generation requests during this review.

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

## Deployment preparation — approval required

No deployment or public repository has been created. After approval, select a host with PHP 8.5 + BCMath + PostgreSQL support, Redis, a durable queue worker, and a scheduler. Point the web server at `public/`; do not serve the project root. Run a production asset build, install Composer dependencies with `--no-dev --optimize-autoloader`, apply reviewed migrations, cache configuration/routes/views, and restart workers after release. Do not enable SSR unless an SSR build and process are also configured; client rendering is sufficient.

Set a persistent `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`, secure session cookies, database/Redis credentials, and a distinct provider key/signing secret. Configure trusted proxies for the chosen host rather than blindly trusting arbitrary forwarding headers. Configure mail transport if retaining password resets. Never rotate `APP_KEY` casually: encrypted cookies depend on it.

Run `php artisan queue:work --sleep=1 --tries=3 --timeout=30` under a process supervisor and `php artisan schedule:run` every minute. Keep the queue reservation timeout above the worker timeout (the default 90 seconds exceeds 30). Use shared Redis across app/worker instances. Back up PostgreSQL, retain a previous release, and prefer a forward fix over destructive schema rollback. Monitor failed jobs, pending/failed inbox events and paused generations. `pixelforge:webhooks` lists up to 50 quarantined events and exits 1 when any exist; inspect and resolve these before using `pixelforge:webhooks EVENT_ID --retry`. It never prints payloads. Monitor pending-event age as well as failures: the scheduler scans at most 100 inbox events and 40 due generations per minute. Polling provides independent recovery.

Before release acceptance: test HTTPS registration/login, signed provider ping, success/failure/duplicate/slow workflows, queue restart recovery, image display, and `pixelforge:usage`. Record actual hosted results separately from local tests. Leave a few completed images for the assessment reviewers only after deployment is approved.

Optional balances, fake purchases, and the operator report are intentionally excluded. See [AUDIT.md](AUDIT.md) for measured acceptance evidence and remaining limitations.

## Dependency advisory

As checked on 2026-11-17 UTC, [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) affects picomatch through 4.0.7; upstream lists no patched version, and npm's latest is 4.0.7. The installed 2.3.2/4.0.7 paths are Vite reload, tinyglobby/fdir, Vitest and Vue language tooling. No request input reaches a glob matcher in this application. Production serves compiled static assets with SSR disabled; do not ship or expose the Node development server/toolchain as a public runtime. This is a mitigated outstanding advisory, not a clean npm audit. Do not apply the suggested major downgrade as a substitute for a verified patch. Re-audit and approve the temporary advisory condition before publishing.
