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
  const [searching, setSearching] = useState(false)

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
      setRadius(10)
      if (!res.preview?.area_keyword && (!res.preview?.lat || !res.preview?.lng)) {
        setError('Could not read that listing yet — edit the address and try again.')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Preview failed')
    } finally {
      setLoading(false)
    }
  }

  const create = async () => {
    if (!preview?.address) {
      setError('Add an address first.')
      return
    }

    setSearching(true)
    setError('')
    try {
      let pin = preview
      if (pin.lat == null || pin.lng == null) {
        // Re-resolve coordinates from the (possibly edited) address.
        const res = await api.preview({
          address: pin.address,
          listing_url: pin.listing_url || undefined,
        })
        pin = { ...pin, ...res.preview }
        setPreview(pin)
        if (pin.lat == null || pin.lng == null) {
          setError('Could not place a map pin for this address. Try a clearer city or area name.')
          setSearching(false)
          return
        }
      }

      const res = await api.createReport({
        listing_url: pin.listing_url,
        address: pin.address,
        city: pin.city,
        country: pin.country,
        country_code: pin.country_code,
        lat: pin.lat,
        lng: pin.lng,
        requested_radius_km: radius,
      })
      navigate(`/reports/${res.report.id}`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not start search')
      setSearching(false)
    }
  }

  return (
    <div className="space-y-7 sm:space-y-10">
      <section className="mx-auto max-w-2xl space-y-4 text-center">
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--moss)]">Step 1 · Trace the listing</p>
        <h1 className="display text-4xl text-[var(--moss-deep)] sm:text-5xl md:text-6xl">Paste a property link</h1>
        <p className="text-base text-[var(--muted)] sm:text-lg">
          Paste a property listing link from any country or site. We read the location, detect the area keyword, then
          show how often that place is mentioned in news.
        </p>

        <form onSubmit={runPreview} className="card-panel space-y-3 rounded-[1.75rem] p-3 text-left sm:p-4">
          <label className="block text-sm font-semibold text-[var(--muted)]" htmlFor="listing">
            Listing URL or address
          </label>
          <textarea
            id="listing"
            className="input min-h-[5.5rem] resize-y"
            placeholder="Paste a property listing URL or a full street address"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            required
            autoFocus
          />
          <button className="btn btn-primary w-full sm:w-auto" disabled={loading || searching || !url.trim()}>
            {loading && !preview ? (
              <>
                <span className="spinner" aria-hidden />
                Reading…
              </>
            ) : (
              'Find area'
            )}
          </button>
        </form>

        <div className="disclaimer text-left">
          News reports cover only part of actual crime. A low count does not mean an area is safe.
        </div>
        {error && <p className="text-[var(--alert)]">{error}</p>}
      </section>

      {preview && (
        <section className="grid gap-5 lg:grid-cols-[1.15fr_0.85fr]">
          <div className="space-y-4">
            <div className="card-panel rounded-[1.75rem] p-4 sm:p-5">
              <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--moss)]">Detected area keyword</p>
              <h2 className="display mt-2 text-3xl text-[var(--moss-deep)] sm:text-4xl">
                {preview.area_keyword || 'Area pending'}
              </h2>
              <p className="mt-2 text-sm text-[var(--muted)]">
                We will search news for this keyword
                {preview.area_aliases?.length ? ` (also: ${preview.area_aliases.join(', ')})` : ''}.
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                <span className="chip">{preview.area_keyword || '—'}</span>
                {(preview.area_aliases || []).map((a: string) => (
                  <span key={a} className="chip chip-muted">
                    {a}
                  </span>
                ))}
              </div>
            </div>

            {center ? (
              <ReportMap
                center={center}
                editable
                radiusKm={radius}
                onPinChange={(lat, lng) => setPreview({ ...preview, lat, lng })}
                height={320}
              />
            ) : (
              <div className="card-panel rounded-2xl p-5 text-[var(--muted)]">Map pin not ready yet — check the address.</div>
            )}
          </div>

          <div className="card-panel space-y-4 rounded-[1.75rem] p-4 sm:p-5">
            <div>
              <div className="mb-1 text-sm font-semibold">Address</div>
              <textarea
                className="input min-h-24"
                value={preview.address || ''}
                onChange={(e) => setPreview({ ...preview, address: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
            <div>
              <div className="mb-2 text-sm font-semibold">Map filter radius</div>
              <div className="flex flex-wrap gap-1.5">
                {[1, 3, 5, 10].map((km) => (
                  <button
                    key={km}
                    type="button"
                    className={`btn btn-inline btn-seg ${radius === km ? 'is-active' : ''}`}
                    onClick={() => setRadius(km)}
                    disabled={searching}
                  >
                    {km} km
                  </button>
                ))}
              </div>
            </div>
            <button
              className="btn btn-primary w-full"
              onClick={() => void create()}
              disabled={searching}
              aria-busy={searching}
            >
              {searching ? (
                <>
                  <span className="spinner" aria-hidden />
                  <span className="sr-only">Searching</span>
                </>
              ) : (
                `Search “${preview.area_keyword || 'area'}”`
              )}
            </button>
          </div>
        </section>
      )}
    </div>
  )
}
