import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { api, setCsrf } from '../api.ts';
import { useRangeSuffix } from '../range.ts';
import { ErrorBox } from './States.tsx';

const NAV = [
  { to: '/', label: 'Übersicht', short: 'Übersicht', end: true, mobile: true },
  { to: '/folgen', label: 'Folgen', short: 'Folgen', mobile: true },
  { to: '/portale', label: 'Portale', short: 'Portale', mobile: false },
  { to: '/kommentare', label: 'Kommentare', short: 'Postfach', mobile: true, counter: true },
  { to: '/automatik', label: 'Automatik', short: 'Automatik', mobile: true },
] as const;

export function useSession() {
  return useQuery({
    queryKey: ['session'],
    queryFn: async () => {
      const s = await api.session();
      setCsrf(s.csrf);
      return s;
    },
    refetchInterval: 120_000,
  });
}

function Counter({ n }: { n: number }) {
  if (n <= 0) return null;
  return (
    <span className="sticker ml-1.5 !text-[0.6875rem]" aria-label={`${n} neue`}>
      {n > 99 ? '99+' : n}
    </span>
  );
}

export function Layout() {
  const session = useSession();
  const suffix = useRangeSuffix();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const mainRef = useRef<HTMLElement>(null);

  useEffect(() => {
    setMenuOpen(false);
    mainRef.current?.focus({ preventScroll: true });
    window.scrollTo({ top: 0 });
  }, [location.pathname]);

  const newCount = session.data?.newComments ?? 0;
  const keepRange = (to: string) => (to === '/kommentare' || to === '/automatik' ? to : to + suffix);

  return (
    <div className="min-h-dvh pb-24 md:pb-0">
      <a href="#inhalt" className="btn btn-primary sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50">
        Zum Inhalt
      </a>
      <header className="border-b-4 border-ink bg-ink text-white">
        <div className="mx-auto flex min-h-16 max-w-[1240px] items-center gap-4 px-4 md:px-8">
          <NavLink to={'/' + suffix} className="flex items-center gap-3 no-underline">
            <span className="grid size-9 place-items-center border-3 border-white bg-pink text-ink">
              <span className="text-lg leading-none font-black">M</span>
            </span>
            <span className="leading-tight">
              <span className="block text-[0.9375rem] font-black tracking-tight uppercase">Podcast-Cockpit</span>
              <span className="label block text-[0.625rem] text-white/80">Der Monteur-Podcast</span>
            </span>
          </NavLink>

          <nav aria-label="Hauptnavigation" className="ml-auto hidden md:block">
            <ul className="flex items-stretch">
              {NAV.map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={keepRange(item.to)}
                    end={'end' in item ? item.end : false}
                    className={({ isActive }) =>
                      `flex min-h-16 items-center px-3 text-[0.9375rem] font-bold no-underline lg:px-4 ${
                        isActive ? 'bg-blue text-white shadow-[inset_0_-6px_0_0_var(--color-pink)]' : 'text-white hover:bg-white/10'
                      }`
                    }
                  >
                    {item.label}
                    {'counter' in item && <Counter n={newCount} />}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>

          <a href={session.data?.adminUrl ?? '/podcast-admin/'} className="label ml-auto hidden text-white underline decoration-pink md:ml-4 md:inline lg:ml-6">
            ← podcast-admin
          </a>

          <button
            type="button"
            className="btn btn-sm ml-auto md:hidden"
            aria-expanded={menuOpen}
            aria-controls="mobile-menu"
            onClick={() => setMenuOpen((v) => !v)}
          >
            Menü
          </button>
        </div>
        {menuOpen && (
          <nav id="mobile-menu" aria-label="Weitere Seiten" className="border-t-4 border-pink bg-ink px-4 pb-4 md:hidden">
            <ul className="grid gap-1 pt-2">
              <li>
                <NavLink to={'/portale' + suffix} className="label flex min-h-11 items-center text-white">
                  Portale
                </NavLink>
              </li>
              <li>
                <a href={session.data?.adminUrl ?? '/podcast-admin/'} className="label flex min-h-11 items-center text-white">
                  Zurück zum podcast-admin
                </a>
              </li>
            </ul>
          </nav>
        )}
      </header>

      <main id="inhalt" ref={mainRef} tabIndex={-1} className="mx-auto max-w-[1240px] px-4 py-6 outline-none md:px-8 md:py-10">
        {/* Pages load in parallel with the session; a missing login redirects from the API client. */}
        {session.isError ? <ErrorBox error={session.error} onRetry={() => session.refetch()} /> : <Outlet />}
      </main>

      <nav aria-label="Navigation" className="fixed inset-x-0 bottom-0 z-40 border-t-4 border-ink bg-white md:hidden">
        <ul className="grid grid-cols-4">
          {NAV.filter((n) => n.mobile).map((item) => (
            <li key={item.to}>
              <NavLink
                to={keepRange(item.to)}
                end={'end' in item ? item.end : false}
                className={({ isActive }) =>
                  `flex min-h-16 flex-col items-center justify-center gap-0.5 text-[0.75rem] font-extrabold uppercase no-underline ${
                    isActive ? 'bg-blue text-white' : 'text-ink'
                  }`
                }
              >
                <span className="relative">
                  {item.short}
                  {'counter' in item && newCount > 0 && (
                    <span className="sticker absolute -top-5 -right-5 !text-[0.625rem]" aria-label={`${newCount} neue`}>
                      {newCount > 99 ? '99+' : newCount}
                    </span>
                  )}
                </span>
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
