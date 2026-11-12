# Neighborhood Crime Report

**Paste a property listing URL → see nearby crime news on a map.**

Neighborhood Crime Report is an open MVP that turns real-estate listing links into radius-aware reports of crimes mentioned in public online news. Confirm the map pin, choose 1 / 3 / 5 / 10 km, and review source-linked incidents — never a safety score, never invented events.

## Why this project

- **Property research:** Check news-reported crime around a listing before you visit.
- **Multi-country:** Works from listing URLs or a plain street address (geocoded via OpenStreetMap).
- **Map-first:** Leaflet map with distance rings and unclear-location callouts.
- **Transparent:** Every incident needs a public source URL; victim/suspect names are filtered out.

## Live local demo

```text
Frontend  http://127.0.0.1:5173
API       http://127.0.0.1:8000/api/health
```

## Stack

| Layer | Tech |
| --- | --- |
| Backend | Laravel + Sanctum + MongoDB Atlas (`mongodb/laravel-mongodb`) |
| Frontend | React + Vite + Tailwind + Leaflet |
| Queue / cache | Redis (Upstash-ready) or `sync` for local |
| Deploy | FrankenPHP Docker on Vercel + static SPA |
| Optional | Stripe billing, OpenAI-compatible LLM |

## Monorepo layout

```text
backend/   Laravel API, jobs, prompts
frontend/  React SPA (paste-link prompter)
docs/      API, deployment, Hobby → Pro checklist
```

## Quick start (local)

### Backend

1. Copy `backend/.env.example` → `backend/.env` (MongoDB URI, `APP_KEY`, optional `LLM_API_KEY`).
2. `cd backend && composer install --ignore-platform-reqs`
3. `php artisan key:generate`
4. `php artisan mongo:ensure-indexes`
5. `php artisan serve --host=127.0.0.1 --port=8000`

With an empty `LLM_API_KEY`, the API uses a conservative local mock so you can still run end-to-end.

### Frontend

1. Copy `frontend/.env.example` → `frontend/.env` with `VITE_API_URL=http://localhost:8000/api`
2. `cd frontend && npm install && npm run dev -- --host 127.0.0.1 --port 5173`

## Product rules

- Never invent incidents; every incident needs a source URL.
- Never name victims, suspects, or minors.
- No safe/unsafe score and no buy/rent advice.
- Store metadata + one-sentence paraphrase + link only.
- Every report shows: *News reports cover only part of actual crime. A low count does not mean an area is safe.*

## Documentation

- [API reference](docs/API.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Hobby → Pro checklist](docs/HOBBY_TO_PRO.md)
- LLM prompts: `backend/prompts/`

## License

MIT — see [LICENSE](LICENSE) if present; otherwise all rights reserved by the maintainers until a license file is added.

## Keywords

neighborhood crime report, property crime news, real estate listing safety, crime map, Zameen, listing URL crime check, local news crime radius
