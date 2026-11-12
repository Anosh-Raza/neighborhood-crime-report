# API

Base URL: `{APP_URL}/api`

Auth: `Authorization: Bearer {token}` (Sanctum personal access token)

## Auth

| Method | Path | Body |
|---|---|---|
| POST | `/register` | `name`, `email`, `password`, `password_confirmation` |
| POST | `/login` | `email`, `password` |
| POST | `/logout` | — |
| GET | `/user` | — |

## Reports

| Method | Path | Notes |
|---|---|---|
| POST | `/reports/preview` | Fetch listing → draft pin/address |
| POST | `/reports` | Create report + enqueue pipeline (1 credit) |
| GET | `/reports` | Dashboard list |
| GET | `/reports/{id}` | Query: `radius_km`, `crime_type`, `date_from`, `date_to`, `page`, `per_page` |
| GET | `/reports/{id}/status` | Poll `queued` / `processing_ring_1` / `processing_outer_rings` / `complete` / `failed` |
| DELETE | `/reports/{id}` | Deletes report + incidents + job_state |

### Create body

```json
{
  "listing_url": "https://...",
  "address": "…",
  "city": "…",
  "country": "…",
  "country_code": "PK",
  "lat": 24.86,
  "lng": 67.00,
  "requested_radius_km": 1
}
```

Allowed radii: `1`, `3`, `5`, `10`. Free users are capped by `max_radius_km` (default 1). Retrieval always covers 10 km; radius is a filter.

## Billing

| Method | Path | Notes |
|---|---|---|
| GET | `/billing/plans` | Plan catalog |
| POST | `/billing/checkout` | `{ "plan": "starter" \| "pro" \| "credits_pack" }` |
| GET | `/billing/portal` | Stripe customer portal URL |
| POST | `/billing/webhook` | Stripe webhooks (no user auth) |

## Internal

| Method | Path | Auth |
|---|---|---|
| POST | `/internal/queue/drain` | Bearer `CRON_SECRET` or Vercel Cron header |

Runs `queue:work --stop-when-empty --max-time=50`.

## Pipeline steps (job_state)

`parse_listing` → `geocode` → `build_vocabulary` → `fetch_news` → `extract_incidents` → `match_dedupe` → `build_report` (partial then final)
