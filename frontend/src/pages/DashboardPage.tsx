import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type ReportSummary } from '../lib/api'
import { useAuth } from '../lib/auth'

export function DashboardPage() {
  const { user, refresh } = useAuth()
  const [reports, setReports] = useState<ReportSummary[]>([])
  const [error, setError] = useState('')

  useEffect(() => {
    void (async () => {
      try {
        const res = await api.listReports()
        setReports(res.reports)
        await refresh()
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load reports')
      }
    })()
  }, [refresh])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="display text-4xl">Your reports</h1>
          <p className="text-[var(--muted)]">
            Welcome back{user ? `, ${user.name}` : ''}. {user?.credits ?? 0} credits left.
          </p>
        </div>
        <Link to="/reports/new" className="btn btn-primary">
          New report
        </Link>
      </div>
      {error && <p className="text-[var(--alert)]">{error}</p>}
      <div className="grid gap-4">
        {reports.length === 0 && (
          <div className="card-panel rounded-2xl p-6 text-[var(--muted)]">No reports yet.</div>
        )}
        {reports.map((r) => (
          <Link
            key={r.id}
            to={`/reports/${r.id}`}
            className="card-panel block rounded-2xl p-5 transition hover:-translate-y-0.5"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="font-semibold">{r.address || r.listing_url}</div>
              <span className="rounded-full bg-white/80 px-3 py-1 text-xs uppercase tracking-wide">
                {r.status}
              </span>
            </div>
            <div className="mt-1 text-sm text-[var(--muted)]">
              {r.city || '—'} {r.country ? `· ${r.country}` : ''} · requested {r.requested_radius_km} km
            </div>
          </Link>
        ))}
      </div>
    </div>
  )
}
