import { Link, NavLink, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { formatNumber, PLATFORM_INFO, PLATFORMS, type Platform as P } from '@cockpit/shared';
import { api } from '../api.ts';
import { useRange, useRangeSuffix } from '../range.ts';
import { Change, InfoTip, PageHead, RangePicker, SectionTitle, Stamp } from '../components/Bits.tsx';
import { DailyChart } from '../components/ChartBlock.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { Empty, ErrorBox, PageLoading } from '../components/States.tsx';

export default function Platform() {
  const platform = useParams().platform as P;
  const [range, , key] = useRange();
  const suffix = useRangeSuffix();
  const valid = (PLATFORMS as readonly string[]).includes(platform);
  const q = useQuery({ queryKey: ['platform', platform, key], queryFn: () => api.platform(platform, range), enabled: valid });

  const tabs = (
    <nav aria-label="Portale" className="-mx-4 mb-8 overflow-x-auto px-4 md:mx-0 md:px-0">
      <ul className="flex w-max gap-2 pb-2">
        {PLATFORMS.map((p) => (
          <li key={p}>
            <NavLink
              to={`/portale/${p}${suffix}`}
              className={({ isActive }) =>
                `flex min-h-11 items-center gap-2 border-3 border-ink px-3 font-bold no-underline ${isActive ? 'bg-ink text-white [&_svg]:fill-white' : 'bg-white shadow-2 hover:bg-paper'}`
              }
            >
              <PlatformIcon platform={p} size={16} />
              {PLATFORM_INFO[p].name}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );

  if (!valid) return <ErrorBox error={new Error('Dieses Portal gibt es im Cockpit nicht.')} />;
  if (q.isPending)
    return (
      <>
        {tabs}
        <PageLoading />
      </>
    );
  if (q.isError) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;
  const info = PLATFORM_INFO[platform];

  return (
    <>
      {tabs}
      <PageHead title={info.name} lead={info.inReach ? 'Zählt in die Reichweite.' : 'Zählt nicht in die Reichweite.'} />
      <div className="mb-8">
        <RangePicker current={d.range} />
      </div>

      <section className="mb-10 grid gap-5 md:grid-cols-[1.2fr_2fr]" aria-label="Werte">
        <div className={`flex flex-col gap-3 border-4 border-ink p-5 shadow-5 ${info.inReach ? 'bg-blue text-white' : 'bg-white'}`}>
          <div className="flex items-center justify-between gap-2">
            <p className="label">Leitwert · {info.leadLabel}</p>
            <span className="text-ink">
              <InfoTip text={info.definition} />
            </span>
          </div>
          <p className="text-6xl leading-none font-black tracking-tight">{formatNumber(d.lead.value)}</p>
          <Change value={d.lead.value} previous={d.lead.previous} />
          <div className={info.inReach ? '[&_p]:!text-white' : ''}>
            <Stamp stamp={d.stamp} />
          </div>
        </div>
        {d.extras.length === 0 ? (
          <Empty title="Keine Zusatzwerte.">{d.stamp.missing ?? 'Für diesen Zeitraum liegen keine weiteren Werte vor.'}</Empty>
        ) : (
          <dl className="grid content-start gap-3 sm:grid-cols-2">
            {d.extras.map((x) => (
              <div key={x.metric} className="border-3 border-ink bg-white p-3">
                <dt className="text-[0.875rem] font-semibold text-ink-soft">{x.label}</dt>
                <dd className="text-2xl font-black tabular-nums">{formatNumber(x.value)}</dd>
              </div>
            ))}
          </dl>
        )}
      </section>

      {d.notes.length > 0 && (
        <ul className="mb-10 grid max-w-[75ch] gap-2">
          {d.notes.map((n) => (
            <li key={n} className="bg-paper p-3 text-[0.9375rem]">
              {n}
            </li>
          ))}
        </ul>
      )}

      <div className="mb-10">
        <DailyChart title="Verlauf pro Tag" daily={d.daily} platforms={[platform]} />
      </div>

      <section className="card p-4 md:p-6" aria-labelledby="top-platform">
        <SectionTitle>
          <span id="top-platform">Top-Folgen auf {info.name}</span>
        </SectionTitle>
        {d.topEpisodes.length === 0 ? (
          <Empty title="Noch keine Werte je Folge." />
        ) : (
          <ol className="grid gap-1">
            {d.topEpisodes.map((e) => (
              <li key={e.id}>
                <Link
                  to={`/folgen/${e.id}`}
                  className="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 border-b-2 border-paper py-2 no-underline hover:bg-paper"
                >
                  <span className="grid size-9 place-items-center border-3 border-ink bg-white text-sm font-black">{e.number ?? '–'}</span>
                  <span className="min-w-0 truncate font-semibold">{e.title}</span>
                  <span className="font-extrabold tabular-nums">{formatNumber(e.reach)}</span>
                </Link>
              </li>
            ))}
          </ol>
        )}
      </section>
    </>
  );
}
