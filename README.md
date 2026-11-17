# Forge Studio

A small Laravel + Vue + Inertia app where signed-in users generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API and are charged, accurately, for what each image used.

Built as the take-home assessment for PromptEdit's Senior Product Engineer role.

- **Generate**: prompt, size, quality and optional reference image URLs, with the cost shown before submitting.
- **History**: every generation with live status, the estimated and the actual cost, and the image.
- **Credits** (stretch goal): a balance, a demo "Buy credits" button, and generating is blocked when the user can't afford it.
- **Report** (stretch goal): spend, provider cost and profit per day and per user, checked against Pixelforge's `GET /v1/usage`.

Stack: Laravel 12, Inertia 2, Vue 3, Tailwind 4, PHPUnit. Runs on SQLite locally; the tests also pass on PostgreSQL.

## How it works

```
Browser ──POST /generations──▶ GenerationController   validates, prices, checks credits, saves a "pending" row
                                      │
                                      ▼ queue
                               SubmitGeneration       POST /v1/generations with an Idempotency-Key, retries politely
                                      │
Pixelforge ──webhook──▶ PixelforgeWebhookController   verifies signature, dedupes by event id
                                      │
                                      ▼
                               GenerationUpdater      the only place state changes and money moves
                                      ▲
                               SyncGeneration         polling fallback when webhooks are lost
```

The files worth reading first:

| File | What it does |
| --- | --- |
| `app/Support/Money.php` | Decimal strings ⇄ integer nanodollars |
| `app/Services/Pixelforge/Pricing.php` | Provider cost, markup, estimates |
| `app/Services/Pixelforge/GenerationUpdater.php` | Applies a Pixelforge generation object: status, cost, charge |
| `app/Http/Controllers/PixelforgeWebhookController.php` | Signature check and event deduplication |
| `app/Jobs/SubmitGeneration.php` | Submitting with retries and idempotency |
| `app/Services/Credits.php` | Balance, holds, purchases, charges |

## Decisions

### Money is integer nanodollars

Pixelforge reports money with nine decimals and never rounds, so the smallest amount that can exist is one
nanodollar ($0.000000001). Every amount is stored and computed as a whole number of nanodollars in a `bigint`.
There are no floats anywhere in the money path, in PHP or in the browser.

A rate of $1 per 1M tokens is exactly 1,000 nanodollars per token, so `cost = tokens × nanodollars-per-token` is
exact integer arithmetic. A rate finer than three decimals could not be represented and is refused with an
exception rather than rounded.

### The 25% markup rounds up, by less than a nanodollar

`charge = ceil(provider cost × 1.25)` in nanodollars. Provider cost × 1.25 can end in a quarter, half or three
quarters of a nanodollar. Rounding up means we never charge less than cost plus markup, and the user is
over-charged by under $0.000000001 per image. The markup is configurable (`PIXELFORGE_MARKUP_BPS`).

Users see exact amounts: a charge of $0.3818875 is shown as $0.3818875, not $0.38.

### The price shown up front is a range

Output and reference-image tokens are exact. Text tokens are within 15% of `ceil(characters / 4)` and reasoning
tokens fall in a range set by quality. So before submitting, the user sees:

- an **estimate** (expected text tokens, mid-range reasoning tokens), and
- the **most it can cost** (most text tokens, most reasoning tokens).

The estimate is computed in the browser for instant feedback as the user types (`resources/js/lib/pricing.js`),
and again on the server on submit (`Pricing::estimate`), which is the one that is stored. Both read the token
tables and rates from `GET /v1/pricing` (cached five minutes, with the last good copy as a fallback), so a price
change needs no deploy. If prices can't be loaded at all, generating is paused rather than guessed.

The actual charge is calculated from the usage Pixelforge reports and the rates on the generation object, which
are fixed when Pixelforge created it.

### Credits are held, then settled

When a generation is submitted, its maximum possible charge is held: `available = balance − holds of running
generations`. Submitting is refused if `available < maximum`, inside a transaction that locks the user row, so
two simultaneous submits can't spend the same credits. When the generation succeeds the actual charge is deducted
and the hold disappears; when it fails nothing is deducted. A balance can therefore never go negative as long as
Pixelforge stays inside its published ranges.

Every balance change is a row in `credit_transactions` with the balance after it.

### Exactly-once charging

Three layers, each sufficient on its own for the case it covers:

1. **Event deduplication.** `webhook_events.event_id` is unique. The event is inserted with `insertOrIgnore` in
   the same transaction that applies it; a redelivery inserts nothing and stops. If applying fails, the insert
   rolls back too, so Pixelforge's retry is processed normally.
2. **Forward-only status under a row lock.** `GenerationUpdater` locks the generation and only applies a status
   that ranks higher than the current one (`queued → processing → succeeded | failed`). A late
   `generation.processing` after `succeeded` is ignored, and a final state is never replaced. This also covers
   two *different* events that both say "succeeded", and a webhook racing a poll.
3. **A database constraint.** `credit_transactions.generation_id` is unique, so a second charge for the same
   generation cannot be written even if the code above were wrong.

Webhooks, the create response and polling all deliver the same Pixelforge generation object, so they all go
through `GenerationUpdater::apply()`. There is one code path for state and money.

### Webhooks

- The signature is verified against the raw body with a constant-time comparison, and timestamps older than five
  minutes are rejected.
- A webhook can arrive before `POST /v1/generations` has returned, when we don't know Pixelforge's id yet. The
  local id is sent as `metadata.generation_id`, and the webhook is matched on that.
- The handler does a few indexed queries and returns `204`, well inside the 10-second limit.
- Events for generations this app doesn't know (another environment's key, say) are acknowledged and ignored.

### Coping with Pixelforge

| Pixelforge does | The app does |
| --- | --- |
| `503 overloaded`, `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, network error | Retries in the queue, waiting at least `Retry-After` and backing off up to a minute, for up to 10 minutes. The user sees "Queued". |
| Request timed out but was actually accepted | Every attempt sends the same `Idempotency-Key`, so the retry returns the same generation. |
| Still refusing after 10 minutes | Marks the generation failed, not charged. If Pixelforge turns out to have accepted it, its result still wins and is billed correctly. |
| `400 content_policy_violation`, `429 daily_limit_exceeded` | Fails the generation with a plain explanation. Not charged. |
| Generation fails after being accepted | Shown as failed with Pixelforge's reason. Not charged. |
| Webhook delivered twice / out of order | See above. |
| Webhook never arrives | `pixelforge:reconcile` (scheduled every minute) polls generations that have been quiet for 45 seconds. As a second net, the history page polls a quiet generation at most every 30 seconds while the user is watching. |

### Smaller choices

- **Live status** is plain Inertia partial reloads every two seconds while something is running, and nothing when
  everything is final. No websockets to deploy.
- **"Pending"** (saved here, not yet accepted by Pixelforge) is shown to users as "Queued". They don't need to
  know about our queue versus Pixelforge's.
- **Images** are linked from Pixelforge's signed URLs and not copied. After 24 hours the card says the image has
  expired instead of showing a broken image.
- **Auth** is a few small controllers rather than a starter kit, to keep the code under review focused.
- **The report** groups by the UTC day a generation completed, which is how Pixelforge bills, so each day can be
  compared with `GET /v1/usage` and is marked ✓ or ✗.

## Running it locally

Requirements: PHP 8.2+, Composer, Node 20+.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
```

Create an API key for local development in the Pixelforge dashboard and set in `.env`:

```
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=whsec_...
ADMIN_EMAILS=you@example.com
```

Webhooks need a public URL. Start a tunnel (Expose, ngrok, Cloudflare Tunnel) and set the key's webhook URL to
`https://<your-tunnel>/webhooks/pixelforge`.

Then run these in separate terminals:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
npm run dev
```

Register, buy some demo credits, and generate. The prompt keywords from the Pixelforge docs (`[fail]`, `[slow]`,
`[duplicate]`, `[blocked]`, `[overloaded]`) trigger each failure on purpose.

## Tests

```bash
php artisan test
```

The suite covers the money arithmetic against the worked examples in the Pixelforge docs, duplicate and
out-of-order webhooks, signature and replay rejection, retry handling, credit holds, and the report totals.

## Deploying

Any host that runs Laravel works. It needs:

- a database (PostgreSQL or MySQL recommended; SQLite is fine for a demo),
- a **queue worker** (`php artisan queue:work`) — generations are submitted from the queue,
- the **scheduler** (`php artisan schedule:run` every minute) for the polling fallback,
- `npm run build` during the build, and `php artisan migrate --force` on deploy,
- the environment variables above, using a **separate Pixelforge API key** for the deployed app, with its webhook
  URL set to `https://<your-domain>/webhooks/pixelforge`. `GET /v1/usage` is reported per key, so a dedicated key
  is what lets the report match Pixelforge exactly.

## With more time

- Store images ourselves so history survives the 24-hour links.
- Let users retry a failed generation in one click.
- A per-user concurrency limit, so one user can't fill all three Pixelforge slots.
- Server-sent events instead of polling for status.
- Paginate and export the report.
