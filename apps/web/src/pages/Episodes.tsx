import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { formatDate, formatNumber, PLATFORM_INFO, type EpisodeRow, type Platform } from '@cockpit/shared';
import { api } from '../api.ts';
import { useRange } from '../range.ts';
import { InfoTip, PageHead, RangePicker } from '../components/Bits.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { Empty, ErrorBox, PageLoading } from '../components/States.tsx';

const COLUMNS: Platform[] = ['youtube', 'spotify', 'downloads', 'apple', 'amazon', 'deezer'];
type SortKey = 'number' | 'publishedAt' | 'reach' | Platform;

export default function Episodes() {
  const [range, , key] = useRange();
  const q = useQuery({ queryKey: ['episodes', key], queryFn: () => api.episodes(range) });
  const [sort, setSort] = useState<{ key: SortKey; dir: 1 | -1 }>({ key: 'number', dir: -1 });

  const rows = useMemo(() => {
    const list = [...(q.data?.rows ?? [])];
    const val = (r: EpisodeRow): number | string | null =>
      sort.key === 'number' ? r.number : sort.key === 'publishedAt' ? r.publishedAt : sort.key === 'reach' ? r.reach : (r.values[sort.key] ?? null);
    return list.sort((a, b) => {
      const x = val(a);
      const y = val(b);
      if (x === y) return 0;
      if (x === null) return 1; // missing values always last
      if (y === null) return -1;
      return (x < y ? -1 : 1) * sort.dir;
    });
  }, [q.data, sort]);

  if (q.isPending) return <PageLoading />;
  if (q.isError) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;

  const head = (k: SortKey, label: React.ReactNode, num = true) => (
    <th scope="col" className={num ? 'num' : ''} aria-sort={sort.key === k ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none'}>
      <button
        type="button"
        className="inline-flex min-h-8 items-center gap-1 uppercase"
        onClick={() => setSort((s) => ({ key: k, dir: s.key === k ? ((s.dir * -1) as 1 | -1) : -1 }))}
      >
        {label}
        <span aria-hidden="true" className={sort.key === k ? '' : 'opacity-0'}>
          {sort.dir === 1 ? '↑' : '↓'}
        </span>
      </button>
    </th>
  );

  return (
    <>
      <PageHead title="Folgen" lead="Alle Folgen × Portale im gewählten Zeitraum. Klick auf eine Spalte sortiert, „–“ heißt: keine Daten von diesem Portal." />
      <div className="mb-6">
        <RangePicker current={q.data.range} />
      </div>
      {rows.length === 0 ? (
        <Empty title="Noch keine Folgen.">Die Folgen kommen stündlich aus dem Feed. Unter Automatik kannst du den Feed sofort holen.</Empty>
      ) : (
        <div className="card relative overflow-x-auto">
          <table className="table min-w-[860px]">
            <thead>
              <tr>
                <th scope="col" className="sticky left-0 z-10 min-w-[15rem]">
                  <button
                    type="button"
                    className="inline-flex min-h-8 items-center gap-1 uppercase"
                    onClick={() => setSort((s) => ({ key: 'number', dir: s.key === 'number' ? ((s.dir * -1) as 1 | -1) : -1 }))}
                  >
                    Folge
                    <span aria-hidden="true" className={sort.key === 'number' ? '' : 'opacity-0'}>
                      {sort.dir === 1 ? '↑' : '↓'}
                    </span>
                  </button>
                </th>
                {head('publishedAt', 'Erschienen', false)}
                {head('reach', <span className="inline-flex items-center gap-1">Reichweite</span>)}
                {COLUMNS.map((p) => (
                  <th key={p} scope="col" className="num">
                    <button
                      type="button"
                      className="inline-flex min-h-8 items-center gap-1.5 uppercase"
                      onClick={() => setSort((s) => ({ key: p, dir: s.key === p ? ((s.dir * -1) as 1 | -1) : -1 }))}
                      title={PLATFORM_INFO[p].definition}
                    >
                      <PlatformIcon platform={p} size={14} />
                      {PLATFORM_INFO[p].name.replace('Feed-', '')}
                      <span aria-hidden="true" className={sort.key === p ? '' : 'opacity-0'}>
                        {sort.dir === 1 ? '↑' : '↓'}
                      </span>
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="hover:bg-paper">
                  <td className="sticky left-0 z-10 bg-white">
                    <Link to={`/folgen/${r.id}`} className="flex items-center gap-3 font-semibold no-underline hover:underline">
                      <span className="grid size-9 shrink-0 place-items-center border-3 border-ink bg-pink text-sm font-black">{r.number ?? '–'}</span>
                      <span className="line-clamp-2">{r.title}</span>
                    </Link>
                  </td>
                  <td className="whitespace-nowrap">{formatDate(r.publishedAt)}</td>
                  <td className="num font-extrabold">{formatNumber(r.reach)}</td>
                  {COLUMNS.map((p) => (
                    <td key={p} className={`num ${PLATFORM_INFO[p].inReach ? '' : 'text-ink-soft'}`}>
                      {formatNumber(r.values[p] ?? null)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-4 flex items-center gap-2 text-[0.875rem] text-ink-soft">
        <InfoTip text="Reichweite = YouTube + Spotify + Feed-Downloads (+ Website-Player ab Stufe 2). Apple, Amazon und Deezer stehen grau daneben: Diese Hörer stecken schon in den Feed-Downloads." />
        Apple, Amazon und Deezer zählen nicht in die Reichweite.
      </p>
    </>
  );
}
