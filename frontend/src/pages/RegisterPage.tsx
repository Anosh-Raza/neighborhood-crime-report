import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'

export function RegisterPage() {
  const { setSession } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      const res = await api.register(form)
      setSession(res.user, res.token)
      navigate('/dashboard')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Registration failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="mx-auto max-w-md card-panel rounded-3xl p-8">
      <h1 className="display mb-2 text-4xl">Create account</h1>
      <p className="mb-6 text-[var(--muted)]">Includes 3 free reports at the 1 km radius.</p>
      <form className="space-y-4" onSubmit={onSubmit}>
        {(['name', 'email', 'password', 'password_confirmation'] as const).map((key) => (
          <input
            key={key}
            className="input"
            type={key.includes('password') ? 'password' : key === 'email' ? 'email' : 'text'}
            placeholder={key.replace('_', ' ')}
            value={form[key]}
            onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            required
          />
        ))}
        {error && <p className="text-sm text-[var(--alert)]">{error}</p>}
        <button className="btn btn-primary w-full" disabled={loading}>
          {loading ? 'Creating…' : 'Create account'}
        </button>
      </form>
      <p className="mt-4 text-sm text-[var(--muted)]">
        Already registered? <Link to="/login" className="text-[var(--moss)]">Log in</Link>
      </p>
    </div>
  )
}
