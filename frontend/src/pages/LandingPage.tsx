import { Link } from 'react-router-dom'
import { useAuth } from '../lib/auth'

export function LandingPage() {
  const { user } = useAuth()
  return (
    <section className="grid gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
      <div className="space-y-6">
        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-[var(--moss)]">
          Multi-country · news-sourced · map-first
        </p>
        <h1 className="display text-5xl leading-[1.05] text-[var(--moss-deep)] md:text-6xl">
          Neighborhood Crime Report
        </h1>
        <p className="max-w-xl text-lg text-[var(--muted)]">
          Paste a real estate listing URL. Confirm the pin. Get a radius-aware report of crimes mentioned
          in online local news — never a safety score, never invented incidents.
        </p>
        <div className="flex flex-wrap gap-3">
          <Link to={user ? '/reports/new' : '/register'} className="btn btn-primary">
            Start a report
          </Link>
          <Link to="/pricing" className="btn btn-ghost">
            See pricing
          </Link>
        </div>
        <div className="disclaimer max-w-xl">
          News reports cover only part of actual crime. A low count does not mean an area is safe.
        </div>
      </div>
      <div className="card-panel relative overflow-hidden rounded-[2rem] p-6 shadow-[0_30px_80px_rgba(20,32,27,0.08)]">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_70%_20%,rgba(31,107,74,0.18),transparent_45%)]" />
        <div className="relative space-y-4">
          <div className="display text-3xl">1 · 3 · 5 · 10 km rings</div>
          <p className="text-[var(--muted)]">
            We retrieve once for 10 km, then filter instantly. Free plans include 1 km; paid plans unlock
            wider rings.
          </p>
          <ul className="space-y-2 text-sm text-[var(--ink)]">
            <li>• JSON-LD / Open Graph listing intake with pin confirmation</li>
            <li>• Local-language place names from OpenStreetMap</li>
            <li>• Source-linked incidents only — no victim or suspect names</li>
          </ul>
        </div>
      </div>
    </section>
  )
}
