# Pixelforge image generator

Laravel 13 + Vue 3 + Inertia. Users sign in, generate images through the Pixelforge API, and are charged
Pixelforge's cost plus a 25% markup. Credits are US dollars.

## Setup

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (one key per environment)
php artisan migrate
npm run build
php artisan serve          # web
php artisan queue:work     # submits generations to Pixelforge
php artisan schedule:work  # runs generations:sync every minute (polling fallback)
php artisan test
```

Point the key's webhook URL in the Pixelforge dashboard at `https://<your-host>/webhooks/pixelforge`.
For local development use a tunnel (Expose, ngrok, Cloudflare Tunnel, OutRay).
Make an operator with `php artisan user:make-admin you@example.com`; they get a **Report** page.

## Key decisions

**Money is integer nanodollars.** Pixelforge reports costs to nine decimals, so nanodollars represent
them exactly and no float ever touches money (`app/Support/Money.php`). Provider cost is
`tokens x rate x 10` per component. The customer charge is `ceil(provider_cost x 5/4)`, so sub-cent
amounts are kept exactly and rounding always goes to the nano in our favour. Display shows six decimals.

**Estimate is an upper bound.** Text tokens at +15%, reasoning at the maximum for the quality. The final
charge is computed from the real usage and the rates snapshotted on the generation, so it is never higher
than the estimate. Failed generations are never charged.

**One state machine, three entry points.** `GenerationUpdater::apply()` is used by webhooks, the submit
response and the polling command. Status only moves forward (`queued < processing < succeeded|failed`),
so out-of-order or late events cannot regress a finished generation.

**Never charged twice.** Three layers: (1) webhook event ids are a primary key, so a duplicate delivery
inserts nothing and does no work; (2) a final generation ignores further events; (3) `charges.generation_id`
is unique, so even a bug cannot write a second charge.

**Webhooks.** HMAC-SHA256 over `<t>.<raw body>`, constant-time compare, 300 s timestamp tolerance, CSRF
exempt. If an event arrives before the POST response has stored the `gen_` id, we answer 503 and record
nothing so Pixelforge retries.

**Submission is safe to retry.** The local UUID is the `Idempotency-Key`. 429 and 503 back off using
`Retry-After` (exponential otherwise, up to 12 attempts); validation or policy rejections fail the
generation for free. `generations:sync` polls anything stuck and re-dispatches lost submissions.

**Balances.** Derived, never stored: `purchases - charges - holds`. A hold is the upper-bound estimate of
a generation still in flight, taken under a row lock on the user, so concurrent requests cannot overspend.
On success the hold is replaced by the exact charge; on failure it simply disappears.

**Report.** Charges are bucketed by the UTC day of completion, the same way `GET /v1/usage` bills, so each
day's provider cost is compared with Pixelforge's figure and flagged on mismatch.

## Trade-offs / not done

- Images are served from Pixelforge's signed URLs and stop loading after 24 hours.
- The credit purchase is fake and has no payment record beyond the purchase row.
- Report aggregation is done in PHP for database portability; fine at this size, move to SQL for scale.
