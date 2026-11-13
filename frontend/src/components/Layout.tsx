import { Link, Outlet } from 'react-router-dom'

export function Layout() {
  return (
    <div className="min-h-screen min-h-dvh">
      <header className="sticky top-0 z-40 border-b border-[var(--line)] bg-[rgba(246,241,231,0.9)] backdrop-blur-md">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <Link to="/" className="display text-xl text-[var(--moss-deep)] sm:text-2xl">
            Houzing
          </Link>
          <span className="hidden text-sm text-[var(--muted)] md:inline">Paste a listing · read the area · search news</span>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-10">
        <Outlet />
      </main>
      <footer className="mx-auto max-w-5xl px-4 pb-10 text-center text-xs text-[var(--muted)] sm:px-6">
        News coverage is incomplete. Mentions are not a safety score.
      </footer>
    </div>
  )
}
