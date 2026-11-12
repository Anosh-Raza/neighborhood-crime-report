import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ReportMap } from '../components/map/ReportMap'
import { api } from '../lib/api'

export function PrompterPage() {
  const navigate = useNavigate()
  const [url, setUrl] = useState('')
  const [preview, setPreview] = useState<any>(null)
  const [radius, setRadius] = useState(10)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

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
      const res = await api.preview(
        looksLikeUrl ? { listing_url: trimmed } : { address: trimmed },
      )
      setPreview(res.preview)
      setRadius(10)
      if (!res.preview?.lat || !res.preview?.lng) {
        setError('Could not place a pin yet — edit the address below and click Geocode.')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Preview failed')
    } finally {
      setLoading(false)
    }
  }

  const geocodeAddress = async () => {
    if (!preview?.address) {
      setError('Enter an address first.')
      return
    }
    setLoading(true)
    setError('')
    try {
      const res = await api.preview({
        listing_url: preview.listing_url || undefined,
        address: preview.address,
      })
      setPreview({
        ...preview,
        ...res.preview,
        listing_url: preview.listing_url || res.preview.listing_url,
      })
      if (!res.preview?.lat || !res.preview?.lng) {
        setError('Still no pin — try a fuller street address with city.')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Geocode failed')
    } finally {
      setLoading(false)
    }
  }

  const create = async () => {
    if (!preview?.lat || !preview?.lng || !preview.address) {
      setError('Need an address and map pin before running the report.')
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
      navigate(`/reports/${res.report.id}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create report')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="space-y-8">
      <section className="mx-auto max-w-3xl space-y-4 text-center">
        <h1 className="display text-5xl text-[var(--moss-deep)] md:text-6xl">Paste a property link</h1>
        <p className="text-lg text-[var(--muted)]">
          Drop a listing URL (or a street address). Confirm the pin, then see nearby crime news.
        </p>
        <form
          onSubmit={runPreview}
          className="card-panel flex flex-col gap-3 rounded-3xl p-4 text-left md:flex-row md:items-center"
        >
          <input
            className="input flex-1"
            placeholder="https://… listing URL, or 123 Main St, City"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            required
            autoFocus
          />
          <button className="btn btn-primary whitespace-nowrap" disabled={loading || !url.trim()}>
            {loading && !preview ? 'Reading…' : 'Find location'}
          </button>
        </form>
        <p className="disclaimer mx-auto max-w-xl text-left">
          News reports cover only part of actual crime. A low count does not mean an area is safe.
        </p>
        {error && <p className="text-[var(--alert)]">{error}</p>}
      </section>

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
              No coordinates yet. Edit the address and click Geocode.
            </div>
          )}

          <div className="card-panel space-y-4 rounded-3xl p-5">
            <div>
              <div className="mb-1 text-sm font-semibold">Address</div>
              <textarea
                className="input min-h-24"
                value={preview.address || ''}
                onChange={(e) => setPreview({ ...preview, address: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <input
                className="input"
                placeholder="City"
                value={preview.city || ''}
                onChange={(e) => setPreview({ ...preview, city: e.target.value })}
              />
              <input
                className="input"
                placeholder="Country"
                value={preview.country || ''}
                onChange={(e) => setPreview({ ...preview, country: e.target.value })}
              />
            </div>
            <button type="button" className="btn btn-ghost w-full" onClick={() => void geocodeAddress()} disabled={loading}>
              Geocode address
            </button>
            <div>
              <div className="mb-2 text-sm font-semibold">Show within</div>
              <div className="flex flex-wrap gap-2">
                {[1, 3, 5, 10].map((km) => (
                  <button
                    key={km}
                    type="button"
                    className={`btn px-4 py-2 ${radius === km ? 'btn-primary' : 'btn-ghost'}`}
                    onClick={() => setRadius(km)}
                  >
                    {km} km
                  </button>
                ))}
              </div>
            </div>
            <button className="btn btn-primary w-full" onClick={() => void create()} disabled={loading}>
              {loading ? 'Starting report…' : 'See crime reports'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
