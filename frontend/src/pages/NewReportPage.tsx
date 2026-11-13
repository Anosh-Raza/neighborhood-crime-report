import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ReportMap } from '../components/map/ReportMap'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'

export function NewReportPage() {
  const { user, refresh } = useAuth()
  const navigate = useNavigate()
  const [url, setUrl] = useState('')
  const [preview, setPreview] = useState<any>(null)
  const [radius, setRadius] = useState(1)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const maxRadius = user?.max_radius_km ?? 1
  const center = useMemo<[number, number] | null>(() => {
    if (preview?.lat == null || preview?.lng == null) return null
    return [preview.lat, preview.lng]
  }, [preview])

  const runPreview = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      const trimmed = url.trim()
      const looksLikeUrl = /^https?:\/\//i.test(trimmed)
      const res = await api.preview(looksLikeUrl ? { listing_url: trimmed } : { address: trimmed })
      setPreview(res.preview)
      setRadius(Math.min(1, res.user_max_radius_km))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Preview failed')
    } finally {
      setLoading(false)
    }
  }

  const create = async () => {
    if (!preview?.lat || !preview?.lng || !preview.address) {
      setError('Confirm a pin and address before creating the report.')
      return
    }
    if (radius > maxRadius) {
      setError(`Your plan unlocks up to ${maxRadius} km. Upgrade on Pricing.`)
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api.createReport({
        listing_url: preview.listing_url,
        address: preview.address,
        city: preview.city,
        country: preview.country,
        country_code: preview.country_code,
        lat: preview.lat,
        lng: preview.lng,
        requested_radius_km: radius,
      })
      await refresh()
      navigate(`/reports/${res.report.id}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create report')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="display text-4xl">New report</h1>
        <p className="text-[var(--muted)]">Paste any country listing URL, then confirm the map pin.</p>
      </div>

      <form onSubmit={runPreview} className="card-panel flex flex-col gap-3 rounded-3xl p-5 md:flex-row">
        <input
          className="input flex-1"
          placeholder="https://example.com/listing/..."
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          required
        />
        <button className="btn btn-primary" disabled={loading}>
          {loading ? 'Reading listing…' : 'Preview pin'}
        </button>
      </form>

      {error && <p className="text-[var(--alert)]">{error}</p>}

      {preview && (
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          {center ? (
            <ReportMap
              center={center}
              editable
              radiusKm={radius}
              onPinChange={(lat, lng) => setPreview({ ...preview, lat, lng })}
            />
          ) : (
            <div className="card-panel rounded-2xl p-6 text-[var(--muted)]">
              No coordinates found yet. Edit the address and we will geocode on create, or upgrade later with a clearer listing.
            </div>
          )}

          <div className="card-panel space-y-4 rounded-3xl p-5">
            <label className="block text-sm font-semibold">Address</label>
            <textarea
              className="input min-h-24"
              value={preview.address || ''}
              onChange={(e) => setPreview({ ...preview, address: e.target.value })}
            />
            <div className="grid grid-cols-2 gap-3">
              <input className="input" placeholder="City" value={preview.city || ''} onChange={(e) => setPreview({ ...preview, city: e.target.value })} />
              <input className="input" placeholder="Country" value={preview.country || ''} onChange={(e) => setPreview({ ...preview, country: e.target.value })} />
            </div>
            <div>
              <div className="mb-2 text-sm font-semibold">Radius filter</div>
              <div className="flex flex-wrap gap-2">
                {[1, 3, 5, 10].map((km) => {
                  const locked = km > maxRadius
                  return (
                    <button
                      key={km}
                      type="button"
                      className={`btn px-4 py-2 ${radius === km ? 'btn-primary' : 'btn-ghost'} ${locked ? 'opacity-50' : ''}`}
                      onClick={() => !locked && setRadius(km)}
                      title={locked ? 'Upgrade to unlock' : undefined}
                    >
                      {km} km{locked ? ' 🔒' : ''}
                    </button>
                  )
                })}
              </div>
              <p className="mt-2 text-xs text-[var(--muted)]">
                Retrieval always covers 10 km. Your plan controls which radius you can view.
              </p>
            </div>
            <div className="disclaimer">
              News reports cover only part of actual crime. A low count does not mean an area is safe.
            </div>
            <button className="btn btn-primary w-full" onClick={() => void create()} disabled={loading}>
              {loading ? 'Creating…' : 'Create report (1 credit)'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
