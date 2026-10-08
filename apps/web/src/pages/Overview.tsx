import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { formatNumber, PLATFORM_INFO, REACH_LABEL, type ReachPart } from '@cockpit/shared';
import { api } from '../api.ts';
import { useRange, useRangeSuffix } from '../range.ts';
import { Change, InfoTip, PageHead, RangePicker, SectionTitle, Stamp } from '../components/Bits.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { DailyChart } from '../components/ChartBlock.tsx';
import { PlatformBars } from '../components/Charts.tsx';
import { Empty, ErrorBox, Notice, PageLoading } from '../components/States.tsx';
import { SyncAllButton } from '../components/Sync.tsx';

export default function Overview() {
  const [range, , key] = useRange();
  const suffix = useRangeSuffix();
  const q = useQuery({ queryKey: ['overview', key], queryFn: () => api.overview(range) });

  if (q.isPending) return <PageLoading />;
  if (q.isError) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;
  const reachPlatforms = d.reach.parts.map((p) => p.platform);

  return (
    <>
      <PageHead title="Übersicht">
        <SyncAllButton />
      </PageHead>
      <div className="mb-8">
        <RangePicker current={d.range} />
      </div>

      {d.warnings.length > 0 && (
        <div className="mb-8 grid gap-3">
          {d.warnings.map((w) => (
            <Notice key={w.text} level={w.level}>
              {w.text}
            </Notice>
          ))}
        </div>
      )}

      <section aria-label="Kennzahlen" className="mb-10 grid gap-5 lg:grid-cols-[1.25fr_2fr]">
        <ReachPanel value={d.reach.value} previous={d.reach.previous} parts={d.reach.parts} />
        <div className="grid gap-5 sm:grid-cols-2">
          {d.reach.parts
            .filter((p) => p.platform !== 'website')
            .map((p) => (
              <PartTile key={p.platform} part={p} to={`/portale/${p.platform}${suffix}`} />
            ))}
          <Link
            to="/kommentare?status=new"
            className="group flex flex-col justify-between gap-3 border-4 border-ink bg-pink p-4 no-underline shadow-4 transition-transform hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-1"
          >
            <span className="label">Offene Kommentare</span>
            <span className="text-5xl leading-none font-black">{formatNumber(d.openComments)}</span>
            <span className="font-bold underline decoration-2 underline-offset-4">Zum Postfach</span>
          </Link>
        </div>
      </section>

      <div className="mb-10 grid gap-8 lg:grid-cols-[1fr_1fr]">
        <section className="card p-4 md:p-6" aria-labelledby="by-portal">
          <SectionTitle aside={<InfoTip text={REACH_LABEL} />}>
            <span id="by-portal">Nach Portal</span>
          </SectionTitle>
          <PlatformBars parts={d.reach.parts} />
        </section>

        <section className="card p-4 md:p-6" aria-labelledby="top5">
          <SectionTitle
            aside={
              <Link to={'/folgen' + suffix} className="font-bold">
                Alle Folgen
              </Link>
            }
          >
            <span id="top5">Top 5 Folgen</span>
          </SectionTitle>
          {d.topEpisodes.length === 0 ? (
            <Empty title="Noch keine Werte je Folge.">
              Sobald YouTube, Spotify oder die Downloads Daten liefern, stehen hier die stärksten Folgen des Zeitraums.
            </Empty>
          ) : (
            <ol className="grid gap-2">
              {d.topEpisodes.map((e, i) => (
                <li key={e.id}>
                  <Link
                    to={`/folgen/${e.id}`}
                    className="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 border-b-2 border-paper py-2 no-underline hover:bg-paper"
                  >
                    <span className={`grid size-9 place-items-center border-3 border-ink text-sm font-black ${i === 0 ? 'bg-pink' : 'bg-white'}`}>
                      {e.number ?? '–'}
                    </span>
                    <span className="min-w-0 truncate font-semibold">{e.title}</span>
                    <span className="font-extrabold tabular-nums">{formatNumber(e.reach)}</span>
                  </Link>
                </li>
              ))}
            </ol>
          )}
        </section>
      </div>

      <DailyChart title="Verlauf pro Tag" daily={d.daily} platforms={reachPlatforms} />
    </>
  );
}

function ReachPanel({ value, previous, parts }: { value: number | null; previous: number | null; parts: ReachPart[] }) {
  const known = parts.filter((p) => p.value !== null);
  return (
    <section className="flex flex-col justify-between gap-6 border-4 border-ink bg-blue p-5 text-white shadow-5 md:p-7" aria-labelledby="reach-title">
      <div className="flex items-start justify-between gap-3">
        <h2 id="reach-title" className="text-2xl leading-none font-black tracking-tight uppercase md:text-3xl">
          Reichweite gesamt
        </h2>
        <span className="text-ink">
          <InfoTip text={REACH_LABEL} />
        </span>
      </div>
      <div>
        <p className="text-[clamp(3rem,2rem+5vw,5.5rem)] leading-[0.9] font-black tracking-[-0.04em]">{formatNumber(value)}</p>
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <Change value={value} previous={previous} />
        </div>
      </div>
      <p className="font-mono text-[0.75rem] leading-relaxed font-semibold">
        {known.length === 0
          ? 'Noch keine Quelle liefert Werte. Die Summe erscheint, sobald YouTube, Spotify oder die Downloads Daten haben.'
          : `= ${known.map((p) => `${PLATFORM_INFO[p.platform].name} ${formatNumber(p.value)}`).join(' + ')}`}
      </p>
    </section>
  );
}

function PartTile({ part, to }: { part: ReachPart; to: string }) {
  const info = PLATFORM_INFO[part.platform];
  return (
    <Link to={to} className="card flex flex-col gap-2 p-4 no-underline transition-transform hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-1">
      <span className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-2 font-bold">
          <PlatformIcon platform={part.platform} />
          {info.name}
        </span>
        <span className="label text-ink-soft">{info.leadLabel}</span>
      </span>
      <span className="text-4xl leading-none font-black tracking-tight">{formatNumber(part.value)}</span>
      <span>
        <Change value={part.value} previous={part.previous} />
      </span>
      <Stamp stamp={part.stamp} />
    </Link>
  );
}
