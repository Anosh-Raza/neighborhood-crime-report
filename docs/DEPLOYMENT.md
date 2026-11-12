# Deployment (Vercel Hobby first)

Two Vercel projects from this monorepo:

1. **crime-report-api** — `backend/` FrankenPHP Docker container
2. **crime-report-web** — `frontend/` static Vite SPA

## Prerequisites

- Vercel CLI: `npm i -g vercel`
- Docker Desktop (for local image builds / Vercel container builds)
- MongoDB Atlas M0 connection string (Cluster0)
- Upstash Redis URL
- Stripe test keys
- LLM API key (OpenAI-compatible)

## Backend deploy

```bash
cd backend
vercel link          # create/link crime-report-api
vercel env add APP_KEY
vercel env add APP_URL
vercel env add FRONTEND_URL
vercel env add MONGODB_URI
vercel env add MONGODB_DATABASE
vercel env add REDIS_URL
vercel env add REDIS_CLIENT   # predis
vercel env add CRON_SECRET
vercel env add LLM_BASE_URL
vercel env add LLM_API_KEY
vercel env add LLM_MODEL
vercel env add STRIPE_KEY
vercel env add STRIPE_SECRET
vercel env add STRIPE_WEBHOOK_SECRET
vercel env add STRIPE_PRICE_STARTER
vercel env add STRIPE_PRICE_PRO
vercel env add STRIPE_PRICE_CREDITS_PACK
vercel env add CORS_ALLOWED_ORIGINS
vercel env add SANCTUM_STATEFUL_DOMAINS
vercel env add NOMINATIM_USER_AGENT
vercel --prod
```

Generate `APP_KEY` locally:

```bash
php artisan key:generate --show
```

After first deploy, run indexes once (from a one-off shell / local with production URI):

```bash
php artisan mongo:ensure-indexes
```

### Important serverless notes

- Do **not** run `php artisan config:cache` in the Docker build — env vars arrive at runtime.
- Filesystem is ephemeral: `SESSION_DRIVER=cookie`, `CACHE_STORE=redis`, `LOG_CHANNEL=stderr`.
- `vercel.json` schedules `/api/internal/queue/drain` every minute.
- Set `CRON_SECRET` in the Vercel project so Cron requests are authorized.

## Frontend deploy

```bash
cd frontend
npm run build
vercel link          # create/link crime-report-web
vercel env add VITE_API_URL   # https://<api-domain>/api
vercel --prod
```

## Files

| File | Purpose |
|---|---|
| `backend/Dockerfile` | FrankenPHP 8.4 + Mongo/Redis extensions |
| `backend/Caddyfile` | Serve `public/` |
| `backend/vercel.json` | Container service + cron |
| `frontend/vercel.json` | SPA rewrites |

## Smoke test

1. `GET https://<api>/api/health`
2. Register via SPA
3. Preview a listing URL
4. Create a report and watch `/reports/{id}/status` move to `complete`
