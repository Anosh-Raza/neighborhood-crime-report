import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ReportMap } from '../components/map/ReportMap'
import { ProgressBanner } from '../components/report/ProgressBanner'
import { api } from '../lib/api'

type MentionArticle = {
  url: string
  title: string
  source_name: string
  published_at?: string | null
  snippet?: string | null
  matched_keywords?: string[]
  title_mentions: number
  body_mentions: number
  total_mentions: number
  in_title: boolean
  score: number
  category?: 'crime' | 'other' | string
}

type NewsTab = 'crime' | 'other'

export function ReportViewPage() {
  const { id = '' } = useParams()
  const [data, setData] = useState<any>(null)
  const [status, setStatus] = useState('queued')
  const [error, setError] = useState('')
  const [radius, setRadius] = useState(10)
  const [newsTab, setNewsTab] = useState<NewsTab>('crime')

  const load = async () => {
    try {
      const res = await api.getReport(id, { radius_km: radius, per_page: 20 })
      setData(res)
      setStatus(res.report.status)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load report')
    }
  }

  useEffect(() => {
    void load()
  }, [id, radius])

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
        /* ignore */
      }
    }, 2500)
    return () => window.clearInterval(t)
  }, [id, status, data?.report?.status])

  const center = useMemo<[number, number] | null>(() => {
    if (!data?.report?.lat || !data?.report?.lng) return null
    return [data.report.lat, data.report.lng]
  }, [data])

  const mentions = data?.report?.news_mentions
  const articles: MentionArticle[] = mentions?.articles || []
  const totals = mentions?.totals
  const keyword = data?.report?.area_keyword || mentions?.primary_keyword || 'Area'

  const crimeArticles = useMemo(
    () => articles.filter((a) => (a.category || 'other') === 'crime'),
    [articles],
  )
  const otherArticles = useMemo(
    () => articles.filter((a) => (a.category || 'other') !== 'crime'),
    [articles],
  )
  const visible = newsTab === 'crime' ? crimeArticles : otherArticles
  const maxMentions = Math.max(1, ...visible.map((a) => a.total_mentions || 0))

  useEffect(() => {
    if (status !== 'complete') return
    if (crimeArticles.length === 0 && otherArticles.length > 0) {
      setNewsTab('other')
    }
  }, [status, crimeArticles.length, otherArticles.length])

  const mapIncidents = useMemo(() => {
    const all = data?.map_incidents || []
    return all.filter((i: any) => (i.distance_km ?? 99) <= radius || i.unclear_exact_location)
  }, [data, radius])

  return (
    <div className="space-y-6 sm:space-y-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--moss)]">Area keyword</p>
          <h1 className="display text-3xl text-[var(--moss-deep)] sm:text-5xl">{keyword}</h1>
          <p className="text-sm text-[var(--muted)] sm:text-base">
            {data?.report?.address || 'Property'}
            {data?.report?.city ? ` · ${data.report.city}` : ''}
          </p>
          <div className="flex flex-wrap gap-2 pt-1">
            <span className="chip">{keyword}</span>
            {(data?.report?.area_aliases || []).map((a: string) => (
              <span key={a} className="chip chip-muted">
                {a}
              </span>
            ))}
          </div>
        </div>
        <Link to="/" className="btn btn-ghost btn-inline self-start">
          New link
        </Link>
      </div>

      <ProgressBanner status={status} error={data?.report?.error_message || error} />

      <div className="grid gap-3 sm:grid-cols-4">
        <div className="card-panel rounded-2xl p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">Articles</div>
          <div className="display mt-1 text-3xl">{totals?.article_count ?? '—'}</div>
        </div>
        <div className="card-panel rounded-2xl p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">Crime news</div>
          <div className="display mt-1 text-3xl">{totals?.crime_count ?? crimeArticles.length}</div>
        </div>
        <div className="card-panel rounded-2xl p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">Other news</div>
          <div className="display mt-1 text-3xl">{totals?.other_count ?? otherArticles.length}</div>
        </div>
        <div className="card-panel rounded-2xl p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">In headline</div>
          <div className="display mt-1 text-3xl">{totals?.title_hit_count ?? '—'}</div>
        </div>
      </div>

      <section className="space-y-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="display text-2xl sm:text-3xl">Mentions of {keyword}</h2>
          <div className="flex flex-wrap gap-1.5">
            <button
              type="button"
              className={`btn btn-inline btn-seg ${newsTab === 'crime' ? 'is-active' : ''}`}
              onClick={() => setNewsTab('crime')}
            >
              Crime news ({crimeArticles.length})
            </button>
            <button
              type="button"
              className={`btn btn-inline btn-seg ${newsTab === 'other' ? 'is-active' : ''}`}
              onClick={() => setNewsTab('other')}
            >
              Other news ({otherArticles.length})
            </button>
          </div>
        </div>

        {articles.length === 0 && status === 'complete' && (
          <p className="text-[var(--muted)]">No news hits for this keyword yet.</p>
        )}
        {articles.length === 0 && status !== 'complete' && status !== 'failed' && (
          <div className="flex items-center justify-center gap-3 py-10 text-[var(--muted)]">
            <span className="spinner spinner-dark spinner-lg" aria-hidden />
            <span className="sr-only">Searching</span>
          </div>
        )}
        {articles.length > 0 && visible.length === 0 && (
          <p className="text-[var(--muted)]">
            {newsTab === 'crime' ? 'No crime-related articles in this set.' : 'No other articles in this set.'}
          </p>
        )}
        {visible.map((a) => (
          <article key={a.url} className="card-panel rounded-[1.35rem] p-4 sm:p-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <a
                  href={a.url}
                  target="_blank"
                  rel="noreferrer"
                  className="font-semibold text-[var(--moss-deep)] underline-offset-2 hover:underline"
                >
                  {a.title}
                </a>
                <div className="mt-1 text-sm text-[var(--muted)]">
                  {a.source_name}
                  {a.published_at ? ` · ${a.published_at}` : ''}
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <span className={`chip ${a.category === 'crime' ? '' : 'chip-muted'}`}>
                  {a.category === 'crime' ? 'Crime' : 'Other'}
                </span>
                {a.in_title && <span className="chip">In title</span>}
                <span className="chip chip-muted">Title ×{a.title_mentions}</span>
                <span className="chip chip-muted">Text ×{a.body_mentions}</span>
              </div>
            </div>
            {a.snippet && <p className="mt-3 text-sm leading-relaxed text-[var(--muted)]">{a.snippet}</p>}
            <div className="mt-3">
              <div className="mb-1 flex justify-between text-xs text-[var(--muted)]">
                <span>Mention strength</span>
                <span>{a.total_mentions} hits</span>
              </div>
              <div className="mention-bar">
                <span style={{ width: `${Math.max(10, Math.round((a.total_mentions / maxMentions) * 100))}%` }} />
              </div>
            </div>
          </article>
        ))}
      </section>

      <div className="disclaimer">{data?.disclaimer}</div>
      {data?.report?.summary && (
        <p className="card-panel rounded-2xl p-4 text-sm text-[var(--muted)] sm:p-5">{data.report.summary}</p>
      )}

      <section className="space-y-4 border-t border-[var(--line)] pt-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="display text-2xl">Map view</h2>
          <div className="flex flex-wrap gap-1.5">
            {[1, 3, 5, 10].map((km) => (
              <button
                key={km}
                type="button"
                className={`btn btn-inline btn-seg ${radius === km ? 'is-active' : ''}`}
                onClick={() => setRadius(km)}
              >
                {km} km
              </button>
            ))}
          </div>
        </div>
        {center && <ReportMap center={center} incidents={mapIncidents} radiusKm={radius} height={360} />}
        {(data?.unclear_incidents || []).length > 0 && (
          <div className="space-y-2">
            <h3 className="font-semibold">Wider neighborhood mentions</h3>
            {(data.unclear_incidents as any[]).slice(0, 8).map((i) => (
              <article key={i.id} className="card-panel rounded-xl p-3 text-sm">
                <strong>{i.crime_type}</strong>
                <p className="mt-1 text-[var(--muted)]">{i.summary}</p>
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
