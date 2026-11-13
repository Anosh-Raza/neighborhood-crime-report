const LABELS: Record<string, string> = {
  queued: 'Queued',
  processing_ring_1: 'Searching…',
  processing_outer_rings: 'Gathering mentions…',
  complete: 'Report complete',
  failed: 'Report failed',
}

export function ProgressBanner({ status, error }: { status: string; error?: string }) {
  if (status === 'complete') return null
  return (
    <div className="card-panel mb-4 rounded-2xl p-4">
      <div className="flex items-center gap-3">
        {status !== 'failed' ? (
          <span className="spinner spinner-dark" aria-hidden />
        ) : null}
        <div>
          <div className="font-semibold">{LABELS[status] || status}</div>
          {error && <div className="text-sm text-[var(--alert)]">{error}</div>}
        </div>
      </div>
    </div>
  )
}
