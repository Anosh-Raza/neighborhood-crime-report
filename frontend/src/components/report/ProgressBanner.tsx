const LABELS: Record<string, string> = {
  queued: 'Queued — waiting for a worker slot',
  processing_ring_1: 'Processing the 0–1 km ring first…',
  processing_outer_rings: 'Filling 3 / 5 / 10 km rings in the background…',
  complete: 'Report complete',
  failed: 'Report failed',
}

export function ProgressBanner({ status, error }: { status: string; error?: string }) {
  if (status === 'complete') return null
  return (
    <div className="card-panel mb-4 rounded-2xl p-4">
      <div className="flex items-center gap-3">
        {status !== 'failed' && (
          <span className="inline-block h-3 w-3 animate-pulse rounded-full bg-[var(--moss)]" />
        )}
        <div>
          <div className="font-semibold">{LABELS[status] || status}</div>
          {error && <div className="text-sm text-[var(--alert)]">{error}</div>}
        </div>
      </div>
    </div>
  )
}
