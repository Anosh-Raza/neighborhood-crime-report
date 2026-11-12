const API_URL = (import.meta.env.VITE_API_URL as string) || 'http://localhost:8000/api'

export type User = {
  id: string
  name: string
  email: string
  credits: number
  plan: string
  max_radius_km: number
}

export type ReportSummary = {
  id: string
  listing_url: string
  address?: string
  city?: string
  country?: string
  status: string
  coverage_rating?: string
  requested_radius_km: number
  lat?: number
  lng?: number
  created_at?: string
}

function authHeaders(): HeadersInit {
  const token = localStorage.getItem('ncr_token')
  return token
    ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' }
    : { 'Content-Type': 'application/json', Accept: 'application/json' }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: { ...authHeaders(), ...(options.headers || {}) },
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || data.error || `Request failed (${res.status})`)
  }
  return data as T
}

export const api = {
  register: (body: { name: string; email: string; password: string; password_confirmation: string }) =>
    request<{ user: User; token: string }>('/register', { method: 'POST', body: JSON.stringify(body) }),
  login: (body: { email: string; password: string }) =>
    request<{ user: User; token: string }>('/login', { method: 'POST', body: JSON.stringify(body) }),
  me: () => request<{ user: User }>('/user'),
  logout: () => request<{ message: string }>('/logout', { method: 'POST' }),
  plans: () => request<{ plans: Record<string, unknown>; radii: Record<string, unknown> }>('/billing/plans'),
  checkout: (plan: string) =>
    request<{ checkout_url: string }>('/billing/checkout', { method: 'POST', body: JSON.stringify({ plan }) }),
  preview: (body: { listing_url?: string; address?: string }) =>
    request<{
      preview: {
        listing_url: string
        title?: string
        address?: string
        city?: string
        country?: string
        country_code?: string
        lat?: number | null
        lng?: number | null
      }
      user_max_radius_km: number
      disclaimer: string
    }>('/reports/preview', { method: 'POST', body: JSON.stringify(body) }),
  createReport: (body: Record<string, unknown>) =>
    request<{ report: ReportSummary }>('/reports', { method: 'POST', body: JSON.stringify(body) }),
  listReports: () => request<{ reports: ReportSummary[] }>('/reports'),
  getReport: (id: string, params: Record<string, string | number | undefined>) => {
    const q = new URLSearchParams()
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== '') q.set(k, String(v))
    })
    return request<any>(`/reports/${id}?${q.toString()}`)
  },
  status: (id: string) => request<{ id: string; status: string; jobs: any[]; error_message?: string }>(`/reports/${id}/status`),
  deleteReport: (id: string) => request<{ message: string }>(`/reports/${id}`, { method: 'DELETE' }),
}
