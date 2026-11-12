import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { ReportMap } from '../components/map/ReportMap'
import { ProgressBanner } from '../components/report/ProgressBanner'
import { api } from '../lib/api'

export function ReportViewPage() {
  const { id = '' } = useParams()
  const [data, setData] = useState<any>(null)
  const [status, setStatus] = useState('queued')
  const [error, setError] = useState('')
  const [radius, setRadius] = useState(10)
  const [crimeType, setCrimeType] = useState('')
  const [page, setPage] = useState(1)

  const load = async () => {
    try {
      const res = await api.getReport(id, {
        radius_km: radius,
        crime_type: crimeType || undefined,
        page,
        per_page: 20,
      })
      setData(res)
      setStatus(res.report.status)
      if (res.report.requested_radius_km && page === 1 && !crimeType) {
        // keep user-selected radius; only seed once from report if still default
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load report')
    }
  }

  useEffect(() => {
    void load()
  }, [id, radius, crimeType, page])

  useEffect(() => {
    if (['complete', 'failed'].includes(status)) return
    const t = window.setInterval(async () => {
      try {
        const s = await api.status(id)
        setStatus(s.status)
        if (s.status === 'complete' || s.status !== data?.report?.status) {
          await load()
        }
      } catch {
        /* ignore transient */
      }
    }, 4000)
    return () => window.clearInterval(t)
  }, [id, status, data?.report?.status])

  const center = useMemo<[number, number] | null>(() => {
    if (!data?.report?.lat || !data?.report?.lng) return null
    return [data.report.lat, data.report.lng]
  }, [data])

  const mapIncidents = useMemo(() => {
    const all = data?.map_incidents || []
    return all.filter((i: any) => (i.distance_km ?? 99) <= radius || i.unclear_exact_location)
  }, [data, radius])

  const crimeOptions = Object.keys(data?.report?.crime_type_counts || {})

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="display text-4xl">{data?.report?.address || 'Report'}</h1>
          <p className="text-[var(--muted)]">
            {data?.report?.city} {data?.report?.country ? `· ${data.report.country}` : ''} · coverage{' '}
            {data?.report?.coverage_rating || '—'}
          </p>
        </div>
        <Link to="/" className="btn btn-ghost">
          New link
        </Link>
      </div>

      <ProgressBanner status={status} error={data?.report?.error_message || error} />
      <div className="disclaimer">
        {data?.disclaimer ||
          'News reports cover only part of actual crime. A low count does not mean an area is safe.'}
      </div>

      <div className="flex flex-wrap gap-2">
        {[1, 3, 5, 10].map((km) => (
          <button
            key={km}
            className={`btn px-4 py-2 ${radius === km ? 'btn-primary' : 'btn-ghost'}`}
            onClick={() => {
              setPage(1)
              setRadius(km)
            }}
          >
            {km} km
          </button>
        ))}
        <select
          className="input max-w-xs"
          value={crimeType}
          onChange={(e) => {
            setPage(1)
            setCrimeType(e.target.value)
          }}
        >
          <option value="">All crime types</option>
          {crimeOptions.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
        {center && <ReportMap center={center} incidents={mapIncidents} radiusKm={radius} height={480} />}
        <div className="space-y-4">
          <div className="card-panel rounded-2xl p-4">
            <div className="mb-2 font-semibold">Counts by ring</div>
            <table className="w-full text-sm">
              <tbody>
                {Object.entries(data?.report?.ring_counts || {}).map(([ring, count]) => (
                  <tr key={ring} className="border-t border-[var(--line)]">
                    <td className="py-2">{ring} km</td>
                    <td className="py-2 text-right font-semibold">{count as number}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="card-panel h-56 rounded-2xl p-4">
            <div className="mb-2 font-semibold">Yearly trend</div>
            <ResponsiveContainer width="100%" height="85%">
              <BarChart data={data?.report?.yearly_trend || []}>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(20,32,27,0.1)" />
                <XAxis dataKey="year" />
                <YAxis allowDecimals={false} />
                <Tooltip />
                <Bar dataKey="count" fill="#1f6b4a" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>

      {data?.report?.summary && (
        <div className="card-panel rounded-2xl p-5 text-[var(--muted)]">{data.report.summary}</div>
      )}

      <section className="space-y-3">
        <h2 className="display text-3xl">Incidents</h2>
        {(data?.incidents || []).length === 0 && (
          <p className="text-[var(--muted)]">
            {status === 'complete' ? 'No incidents in this filter yet.' : 'Waiting for the pipeline…'}
          </p>
        )}
        {(data?.incidents || []).map((i: any) => (
          <article key={i.id} className="card-panel rounded-2xl p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <strong>{i.crime_type}</strong>
              <span className="text-sm text-[var(--muted)]">
                {i.distance_km != null ? `${i.distance_km} km` : 'distance n/a'} · {i.status} · confidence{' '}
                {Math.round((i.confidence || 0) * 100)}%
              </span>
            </div>
            <p className="mt-2">{i.summary}</p>
            <div className="mt-2 text-sm text-[var(--muted)]">{i.location_text}</div>
            <div className="mt-2 flex flex-wrap gap-3 text-sm">
              {(i.sources || []).map((s: any) => (
                <a key={s.url} href={s.url} target="_blank" rel="noreferrer" className="text-[var(--moss)] underline">
                  {s.name || 'Source'}
                </a>
              ))}
            </div>
          </article>
        ))}
        <div className="flex gap-2">
          <button className="btn btn-ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </button>
          <button
            className="btn btn-ghost"
            disabled={(data?.filter?.page || 1) * (data?.filter?.per_page || 20) >= (data?.filter?.total || 0)}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </button>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="display text-3xl">Wider neighborhood, exact location unclear</h2>
        {(data?.unclear_incidents || []).length === 0 && (
          <p className="text-[var(--muted)]">No unclear-location items in this filter.</p>
        )}
        {(data?.unclear_incidents || []).map((i: any) => (
          <article key={i.id} className="card-panel rounded-2xl p-4 opacity-90">
            <strong>{i.crime_type}</strong>
            <p className="mt-2">{i.summary}</p>
            <div className="mt-2 flex flex-wrap gap-3 text-sm">
              {(i.sources || []).map((s: any) => (
                <a key={s.url} href={s.url} target="_blank" rel="noreferrer" className="text-[var(--moss)] underline">
                  {s.name || 'Source'}
                </a>
              ))}
            </div>
          </article>
        ))}
      </section>
    </div>
  )
}
