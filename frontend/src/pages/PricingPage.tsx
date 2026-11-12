import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'

export function PricingPage() {
  const { user } = useAuth()
  const [plans, setPlans] = useState<Record<string, any>>({})
  const [error, setError] = useState('')

  useEffect(() => {
    void api.plans().then((res) => setPlans(res.plans)).catch((err) => setError(err.message))
  }, [])

  const checkout = async (plan: string) => {
    if (!user) {
      window.location.href = '/login'
      return
    }
    try {
      const res = await api.checkout(plan)
      window.location.href = res.checkout_url
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Checkout failed')
    }
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="display text-5xl">Pricing</h1>
        <p className="mt-2 max-w-2xl text-[var(--muted)]">
          1 km is included on free reports. Unlock 3 / 5 / 10 km rings with a paid plan or credit pack.
          Hobby hosting is non-commercial — upgrade Vercel to Pro before charging live customers.
        </p>
      </div>
      {error && <p className="text-[var(--alert)]">{error}</p>}
      <div className="grid gap-4 md:grid-cols-3">
        {Object.entries(plans).map(([key, plan]) => (
          <div key={key} className="card-panel rounded-3xl p-6">
            <div className="display text-3xl">{plan.name}</div>
            <div className="mt-2 text-4xl font-semibold">
              {plan.price_monthly === 0 ? 'Free' : `$${plan.price_monthly}`}<span className="text-base font-normal text-[var(--muted)]">/mo</span>
            </div>
            <ul className="mt-4 space-y-2 text-sm text-[var(--muted)]">
              <li>{plan.credits} credits</li>
              <li>Up to {plan.max_radius_km} km radius unlock</li>
            </ul>
            {key !== 'free' && (
              <button className="btn btn-primary mt-6 w-full" onClick={() => void checkout(key)}>
                Choose {plan.name}
              </button>
            )}
          </div>
        ))}
      </div>
      <div className="card-panel rounded-2xl p-5">
        <div className="font-semibold">Need a one-off boost?</div>
        <p className="text-sm text-[var(--muted)]">Buy a 10-credit pack without changing your monthly plan.</p>
        <button className="btn btn-ghost mt-3" onClick={() => void checkout('credits_pack')}>
          Buy credit pack
        </button>
      </div>
    </div>
  )
}
