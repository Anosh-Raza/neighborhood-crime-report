# Hobby → Pro upgrade checklist

Vercel **Hobby** is for non-commercial use / personal projects. Before charging real customers with Stripe live mode, complete this checklist.

## Product / legal

- [ ] Switch Stripe from test keys to live keys
- [ ] Confirm disclaimer is visible on every report
- [ ] Confirm extraction never invents incidents and never names victims/suspects/minors
- [ ] Privacy policy + terms published (data retention for articles_cache TTL, LLM logs)
- [ ] Cookie/consent notice if required in target markets

## Vercel

- [ ] Upgrade team from Hobby to **Pro**
- [ ] Re-check Function / container duration limits for the Pro plan
- [ ] Confirm Cron Jobs remain within plan quotas
- [ ] Attach custom domains for API + SPA
- [ ] Enable deployment protection for preview if needed
- [ ] Review spend alerts / usage caps

## Data & reliability

- [ ] MongoDB Atlas: confirm IP access / VPC as needed; consider M10+ if M0 throttles
- [ ] Upstash Redis: verify eviction policy and connection limits
- [ ] Cap `NEWS_MAX_ARTICLES_PER_REPORT` and LLM model cost
- [ ] Monitor `llm_logs` cost_usd
- [ ] Add alerting on `reports.status = failed`

## Payments

- [ ] Stripe webhook endpoint points to production API `/api/billing/webhook`
- [ ] Customer portal return URLs use production frontend
- [ ] Price IDs set for starter / pro / credits pack
- [ ] Tax / VAT settings configured in Stripe if required

## Security

- [ ] Rotate `APP_KEY`, `CRON_SECRET`, DB credentials
- [ ] Enable 2FA on Vercel, Atlas, Stripe, Upstash
- [ ] Restrict CORS / Sanctum stateful domains to production hosts only
