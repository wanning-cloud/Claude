import { lazy, Suspense, useState } from 'react';
import { formatDate, formatNumber, PLATFORM_INFO, type DailyPoint, type Platform } from '@cockpit/shared';
import { Skeleton } from './States.tsx';

const DailyLines = lazy(() => import('./Charts.tsx').then((m) => ({ default: m.DailyLines })));

/** Every chart has a table view with the same numbers. */
export function DailyChart({ daily, platforms, title }: { daily: DailyPoint[]; platforms: Platform[]; title: string }) {
  const [view, setView] = useState<'chart' | 'table'>('chart');
  const shown = platforms.filter((p) => daily.some((d) => d.values[p] !== null && d.values[p] !== undefined));
  return (
    <section className="card p-4 md:p-6" aria-label={title}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-xl font-black tracking-tight uppercase">{title}</h2>
        <div role="group" aria-label="Ansicht" className="flex border-3 border-ink">
          {(['chart', 'table'] as const).map((v, i) => (
            <button
              key={v}
              type="button"
              aria-pressed={view === v}
              onClick={() => setView(v)}
              className={`min-h-10 px-3 text-[0.8125rem] font-extrabold uppercase ${i ? 'border-l-3 border-ink' : ''} ${
                view === v ? 'bg-ink text-white' : 'bg-white hover:bg-paper'
              }`}
            >
              {v === 'chart' ? 'Diagramm' : 'Tabelle'}
            </button>
          ))}
        </div>
      </div>
      {view === 'chart' ? (
        <Suspense fallback={<Skeleton className="h-[260px]" />}>
          <DailyLines daily={daily} platforms={platforms} />
        </Suspense>
      ) : (
        <div className="max-h-[420px] overflow-auto border-3 border-ink">
          <table className="table">
            <thead className="sticky top-0">
              <tr>
                <th scope="col">Tag</th>
                {(shown.length ? shown : platforms).map((p) => (
                  <th key={p} scope="col" className="num">
                    {PLATFORM_INFO[p].name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {[...daily].reverse().map((d) => (
                <tr key={d.date}>
                  <td className="font-semibold whitespace-nowrap">{formatDate(d.date)}</td>
                  {(shown.length ? shown : platforms).map((p) => (
                    <td key={p} className="num">
                      {formatNumber(d.values[p] ?? null)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
