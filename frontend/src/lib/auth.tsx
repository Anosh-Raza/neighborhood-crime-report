import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, type User } from './api'

type AuthCtx = {
  user: User | null
  loading: boolean
  setSession: (user: User, token: string) => void
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const Ctx = createContext<AuthCtx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = async () => {
    const token = localStorage.getItem('ncr_token')
    if (!token) {
      setUser(null)
      setLoading(false)
      return
    }
    try {
      const res = await api.me()
      setUser(res.user)
    } catch {
      localStorage.removeItem('ncr_token')
      setUser(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void refresh()
  }, [])

  const setSession = (next: User, token: string) => {
    localStorage.setItem('ncr_token', token)
    setUser(next)
  }

  const logout = async () => {
    try {
      await api.logout()
    } catch {
      /* ignore */
    }
    localStorage.removeItem('ncr_token')
    setUser(null)
  }

  return (
    <Ctx.Provider value={{ user, loading, setSession, logout, refresh }}>
      {children}
    </Ctx.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useAuth requires AuthProvider')
  return ctx
}
