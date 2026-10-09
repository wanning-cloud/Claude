import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ENGAGEMENT_DEFINITION,
  FORMAT_LABEL,
  SOCIAL_SEPARATE_NOTE,
  formatDate,
  formatDateShort,
  formatNumber,
  formatRate,
  type ClipWeek,
  type Compared,
  type GroupWeek,
  type SocialChannel,
  type SocialChannelSummary,
  type SocialGoal,
  type SocialPage,
  type SocialPost,
} from '@cockpit/shared';
import { api } from '../api.ts';
import { useRange } from '../range.ts';
import { Change, InfoTip, PageHead, RangePicker, SectionTitle, Stamp } from '../components/Bits.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { Empty, ErrorBox, Notice, PageLoading } from '../components/States.tsx';
import { SyncAllButton } from '../components/Sync.tsx';

const CHANNELS: { id: SocialChannel; label: string }[] = [
  { id: 'all', label: 'Alle' },
  { id: 'instagram', label: 'Instagram' },
  { id: 'facebook', label: 'Facebook' },
  { id: 'youtube_shorts', label: 'Shorts' },
];

const VISIT_SOURCES = { instagram: 'Instagram', facebook: 'Facebook-Seite', youtube_shorts: 'YouTube Shorts', fb_gruppe: 'Facebook-Gruppen' } as const;

export default function Social() {
  const [params, setParams] = useSearchParams();
  const raw = params.get('kanal') ?? 'all';
  const channel: SocialChannel = CHANNELS.some((c) => c.id === raw) ? (raw as SocialChannel) : 'all';
  const [range, , key] = useRange();
  const q = useQuery({ queryKey: ['social', channel, key], queryFn: () => api.social(channel, range) });

  const setChannel = (c: SocialChannel) => {
    const p = new URLSearchParams(params);
    if (c === 'all') p.delete('kanal');
    else p.set('kanal', c);
    setParams(p, { replace: true });
  };

  const switcher = (
    <div role="group" aria-label="Kanal" className="-mx-4 mb-8 overflow-x-auto px-4 md:mx-0 md:px-0">
      <div className="flex w-max gap-2 pb-2">
        {CHANNELS.map((c) => (
          <button
            key={c.id}
            type="button"
            aria-pressed={channel === c.id}
            onClick={() => setChannel(c.id)}
            className={`flex min-h-11 items-center gap-2 border-3 border-ink px-3 font-bold ${
              channel === c.id ? 'bg-ink text-white [&_svg]:fill-white' : 'bg-white shadow-2 hover:bg-paper'
            }`}
          >
            {c.id !== 'all' && <PlatformIcon platform={c.id} size={16} />}
            {c.label}
          </button>
        ))}
      </div>
    </div>
  );

  const head = (
    <PageHead title="Social Media" lead={`${SOCIAL_SEPARATE_NOTE} Instagram, Facebook-Seite und YouTube Shorts.`}>
      <SyncAllButton />
    </PageHead>
  );

  if (q.isPending)
    return (
      <>
        {head}
        {switcher}
        <PageLoading />
      </>
    );
  if (q.isError)
    return (
      <>
        {head}
        <ErrorBox error={q.error} onRetry={() => q.refetch()} />
      </>
    );
  const d = q.data;
  const shown = channel === 'all' ? d.channels : d.channels.filter((c) => c.platform === channel);

  return (
    <>
      {head}
      {switcher}
      <div className="mb-8">
        <RangePicker current={d.range} />
      </div>

      <ConnectionNotice page={d} channel={channel} />

      <section aria-label="Kanäle" className={`mb-10 grid gap-5 ${shown.length > 1 ? 'lg:grid-cols-3' : ''}`}>
        {shown.map((c) => (
          <ChannelPanel key={c.platform} summary={c} wide={shown.length === 1} />
        ))}
      </section>

      <div className="mb-10 grid gap-8 lg:grid-cols-[1fr_1.25fr]">
        <section className="card p-4 md:p-6" aria-labelledby="goals">
          <SectionTitle>
            <span id="goals">Ziele (90 Tage)</span>
          </SectionTitle>
          <div className="grid gap-5">
            {d.goals.map((g) => (
              <GoalBar key={g.id} goal={g} />
            ))}
          </div>
        </section>
        <section className="card p-4 md:p-6" aria-labelledby="clips">
          <SectionTitle aside={<InfoTip text="Reels und Shorts je Woche. Läuft derselbe Clip auf beiden, zählt die Woche die höhere Zahl, nicht die Summe." />}>
            <span id="clips">Clips pro Woche</span>
          </SectionTitle>
          <ClipWeeks weeks={d.clipsPerWeek} />
        </section>
      </div>

      <section className="mb-10" aria-labelledby="top">
        <SectionTitle aside={<InfoTip text={ENGAGEMENT_DEFINITION} />}>
          <span id="top">Top 3 der Woche</span>
        </SectionTitle>
        {d.top.length === 0 ? (
          <Empty title="Noch keine Beiträge mit Zahlen in den letzten 7 Tagen.">
            Sobald Beiträge Reichweite oder Aufrufe haben, stehen hier die drei mit der höchsten Engagement-Rate.
          </Empty>
        ) : (
          <ol className="grid gap-5 md:grid-cols-3">
            {d.top.map((p, i) => (
              <li key={p.id}>
                <TopCard post={p} rank={i + 1} />
              </li>
            ))}
          </ol>
        )}
      </section>

      <section className="card mb-10 p-4 md:p-6" aria-labelledby="series">
        <SectionTitle
          aside={
            <InfoTip text={`Ø Engagement-Rate je Serie und Format im gewählten Zeitraum. Grundlage für die Prüfung nach 3 Wochen. ${ENGAGEMENT_DEFINITION}`} />
          }
        >
          <span id="series">Welche Serie trägt?</span>
        </SectionTitle>
        <SeriesTable rows={d.series} />
      </section>

      <section className="mb-10" aria-labelledby="posts">
        <SectionTitle aside={<span className="font-mono text-[0.75rem] font-semibold text-ink-soft">{formatNumber(d.posts.length)} im Zeitraum</span>}>
          <span id="posts">Beiträge</span>
        </SectionTitle>
        <PostTable posts={d.posts} />
      </section>

      <div className="grid gap-8 lg:grid-cols-2">
        <VisitsCard page={d} />
        <GroupsCard weeks={d.groups.weeks} visits={d.groups.visits} />
      </div>
    </>
  );
}

function ConnectionNotice({ page, channel }: { page: SocialPage; channel: SocialChannel }) {
  const c = page.connection;
  const metaNeeded = channel === 'all' || channel === 'instagram' || channel === 'facebook';
  if (metaNeeded && (!c.metaConfigured || !c.metaConnected)) {
    return (
      <div className="mb-8">
        <Notice level="info">
          {c.metaConfigured ? 'Instagram und Facebook sind noch nicht verbunden.' : 'Für Instagram und Facebook fehlt noch die Meta-App.'}{' '}
          <Link to="/automatik" className="font-bold text-white underline decoration-pink">
            Zu Automatik › Verbindungen
          </Link>
        </Notice>
      </div>
    );
  }
  if (metaNeeded && c.metaConnected && !c.instagram) {
    return (
      <div className="mb-8">
        <Notice level="warn">
          Instagram ist nicht mit der Facebook-Seite verknüpft. In Instagram auf Profikonto umstellen, mit der Seite verbinden, dann „Meta verbinden“ erneut
          klicken.
        </Notice>
      </div>
    );
  }
  return null;
}

function Metric({ label, value, format = formatNumber }: { label: string; value: Compared; format?: (v: number | null) => string }) {
  return (
    <div className="grid content-start gap-1 border-t-3 border-ink pt-2">
      <dt className="text-[0.8125rem] font-semibold text-ink-soft">{label}</dt>
      <dd className="text-2xl leading-tight font-black tabular-nums">{format(value.value)}</dd>
      <dd>
        <Change value={value.value} previous={value.previous} />
      </dd>
    </div>
  );
}

function ChannelPanel({ summary: s, wide }: { summary: SocialChannelSummary; wide: boolean }) {
  const isShorts = s.platform === 'youtube_shorts';
  return (
    <article className="card flex flex-col gap-4 p-4 md:p-5" aria-labelledby={`ch-${s.platform}`}>
      <header className="flex items-center justify-between gap-3">
        <h2 id={`ch-${s.platform}`} className="flex items-center gap-2 text-lg font-black tracking-tight uppercase">
          <PlatformIcon platform={s.platform} size={22} />
          {s.name}
        </h2>
        {s.newComments > 0 && (
          <Link to={`/kommentare?area=social&status=new${isShorts ? '' : `&platform=${s.platform}`}`} className="sticker no-underline">
            {s.newComments} neu
          </Link>
        )}
      </header>

      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2 border-4 border-ink bg-blue p-4 text-white shadow-3">
        <div>
          <p className="label">{isShorts ? 'Aufrufe' : 'Follower'}</p>
          <p className="text-5xl leading-none font-black tracking-tight tabular-nums">{formatNumber(isShorts ? s.views.value : s.followers.value)}</p>
        </div>
        <div className="text-ink">
          {isShorts ? (
            <Change value={s.views.value} previous={s.views.previous} />
          ) : s.followers.value !== null && s.followers.previous !== null ? (
            <span className="tag bg-white">
              {s.followers.value - s.followers.previous >= 0 ? '+' : ''}
              {formatNumber(s.followers.value - s.followers.previous)} im Zeitraum
            </span>
          ) : (
            <span className="tag bg-white">kein Vorwert</span>
          )}
        </div>
      </div>

      <dl className={`grid gap-4 ${wide ? 'sm:grid-cols-4' : 'grid-cols-2'}`}>
        {!isShorts && <Metric label="Erreichte Konten (Summe je Tag)" value={s.reach} />}
        {!isShorts && <Metric label="Aufrufe" value={s.views} />}
        <Metric label={isShorts ? 'Likes + Kommentare' : 'Interaktionen'} value={s.interactions} />
        <div className="grid content-start gap-1 border-t-3 border-ink pt-2">
          <dt className="flex items-center gap-2 text-[0.8125rem] font-semibold text-ink-soft">
            Engagement-Rate <InfoTip text={ENGAGEMENT_DEFINITION} />
          </dt>
          <dd className="text-2xl leading-tight font-black tabular-nums">{formatRate(s.engagementRate)}</dd>
          <dd className="font-mono text-[0.75rem] font-semibold text-ink-soft">
            {formatNumber(s.posts)} Beiträge{s.stories > 0 ? ` · ${formatNumber(s.stories)} Stories` : ''}
          </dd>
        </div>
      </dl>
      <Stamp stamp={s.stamp} className="mt-auto" />
    </article>
  );
}

function GoalBar({ goal }: { goal: SocialGoal }) {
  const pct = goal.value === null ? 0 : Math.min(100, (goal.value / goal.target) * 100);
  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between gap-3">
        <span className="font-bold">{goal.label}</span>
        <span className="font-extrabold tabular-nums">
          {formatNumber(goal.value)} <span className="font-mono text-[0.75rem] font-semibold text-ink-soft">von {formatNumber(goal.target)}</span>
        </span>
      </div>
      <div
        className="h-7 border-3 border-ink bg-white"
        role="progressbar"
        aria-label={goal.label}
        aria-valuemin={0}
        aria-valuemax={goal.target}
        aria-valuenow={goal.value ?? undefined}
        aria-valuetext={goal.value === null ? 'Noch kein Wert' : `${formatNumber(goal.value)} von ${formatNumber(goal.target)}`}
      >
        <div className={`h-full ${pct >= 100 ? 'bg-pink' : 'bg-blue'}`} style={{ width: `${pct}%` }} />
      </div>
      {goal.value === null && <p className="mt-1 font-mono text-[0.75rem] font-semibold text-ink-soft">Noch kein Wert. Kommt mit dem ersten Abruf.</p>}
    </div>
  );
}

/** Weekly bars with the target as a dashed line; the numbers sit on the bars, so the list is its own table. */
function ClipWeeks({ weeks }: { weeks: ClipWeek[] }) {
  const target = weeks[0]?.target ?? 7;
  // Headroom above the target line, so the line never sits on the card edge.
  const max = Math.max(target, ...weeks.map((w) => w.count)) * 1.2;
  return (
    <div>
      <ol className="relative grid h-48 grid-cols-8 items-end gap-2 border-b-3 border-ink" aria-label={`Clips je Woche, Ziel ${target}`}>
        <span
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-0 border-t-3 border-dashed border-ink"
          style={{ bottom: `${(target / max) * 100}%` }}
        >
          <span className="label absolute -top-6 left-0 bg-white pr-1">Ziel {target}</span>
        </span>
        {weeks.map((w) => (
          <li
            key={w.week}
            className="flex h-full flex-col justify-end"
            aria-label={`Woche ab ${formatDate(w.week)}: ${w.count} Clips (${w.reels} Reels, ${w.shorts} Shorts)`}
          >
            <span className="mb-1 text-center text-[0.8125rem] font-extrabold tabular-nums">{w.count}</span>
            <span
              className={`block border-3 border-ink ${w.count >= w.target ? 'bg-pink' : 'bg-blue'}`}
              style={{ height: `${Math.max(w.count === 0 ? 0 : 4, (w.count / max) * 100)}%`, minHeight: w.count === 0 ? 0 : 6 }}
            />
          </li>
        ))}
      </ol>
      <ol className="mt-2 grid grid-cols-8 gap-2" aria-hidden="true">
        {weeks.map((w) => (
          <li key={w.week} className="text-center font-mono text-[0.6875rem] font-semibold text-ink-soft">
            {formatDateShort(w.week)}
          </li>
        ))}
      </ol>
    </div>
  );
}

function Thumb({ post, className = 'size-14' }: { post: SocialPost; className?: string }) {
  const [broken, setBroken] = useState(false);
  if (!post.thumbnail || broken) {
    return (
      <span className={`grid shrink-0 place-items-center border-3 border-ink bg-paper ${className}`} aria-hidden="true">
        <PlatformIcon platform={post.platform} size={18} />
      </span>
    );
  }
  return (
    <img
      src={post.thumbnail}
      alt=""
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setBroken(true)}
      className={`shrink-0 border-3 border-ink object-cover ${className}`}
    />
  );
}

function caption(post: SocialPost): string {
  const text = (post.caption ?? '').replace(/\s+/g, ' ').trim();
  return text === '' ? `${FORMAT_LABEL[post.format]} ohne Text` : text;
}

function TopCard({ post, rank }: { post: SocialPost; rank: number }) {
  return (
    <article className={`flex h-full flex-col gap-3 border-4 border-ink p-4 ${rank === 1 ? 'bg-pink shadow-5' : 'bg-white shadow-4'}`}>
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center border-3 border-ink bg-white text-lg font-black">{rank}</span>
        <Thumb post={post} className="size-16" />
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-1.5">
            <PlatformIcon platform={post.platform} size={14} />
            <span className="tag bg-white">{FORMAT_LABEL[post.format]}</span>
          </p>
          <p className="mt-1 font-mono text-[0.75rem] font-semibold">{formatDate(post.publishedAt)}</p>
        </div>
      </div>
      <p className="line-clamp-3 text-[0.9375rem] font-medium">{caption(post)}</p>
      <div className="mt-auto flex items-end justify-between gap-3">
        <div>
          <p className="label">Engagement</p>
          <p className="text-4xl leading-none font-black tabular-nums">{formatRate(post.engagementRate)}</p>
        </div>
        {post.permalink && (
          <a href={post.permalink} target="_blank" rel="noreferrer" className="btn btn-sm">
            Ansehen
          </a>
        )}
      </div>
    </article>
  );
}

function SeriesTable({ rows }: { rows: SocialPage['series'] }) {
  if (rows.length === 0) return <Empty title="Noch keine Beiträge im Zeitraum." />;
  const max = Math.max(0.0001, ...rows.map((r) => r.engagementRate ?? 0));
  return (
    <div className="overflow-x-auto">
      <table className="table min-w-[560px]">
        <thead>
          <tr>
            <th scope="col">Serie</th>
            <th scope="col">Format</th>
            <th scope="col" className="num">
              Beiträge
            </th>
            <th scope="col" className="w-[40%]">
              Ø Engagement-Rate
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={`${r.series ?? ''}|${r.format}`}>
              <td className={`font-semibold ${r.series ? '' : 'text-ink-soft'}`}>{r.series ?? 'Ohne Serie'}</td>
              <td>{FORMAT_LABEL[r.format]}</td>
              <td className="num">{formatNumber(r.posts)}</td>
              <td>
                {r.engagementRate === null ? (
                  <span className="font-mono text-[0.8125rem] font-semibold text-ink-soft">– noch keine Reichweite</span>
                ) : (
                  <span className="flex items-center gap-2">
                    <span className="h-5 border-3 border-ink bg-blue" style={{ width: `${Math.max(4, (r.engagementRate / max) * 75)}%` }} aria-hidden="true" />
                    <span className="font-extrabold tabular-nums">{formatRate(r.engagementRate)}</span>
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type SortKey = 'date' | 'views' | 'reach' | 'interactions' | 'rate';
const SORTS: { id: SortKey; label: string }[] = [
  { id: 'date', label: 'Datum' },
  { id: 'views', label: 'Aufrufe' },
  { id: 'reach', label: 'Reichweite' },
  { id: 'interactions', label: 'Interaktionen' },
  { id: 'rate', label: 'Engagement' },
];

const PAGE = 15;

function PostTable({ posts }: { posts: SocialPost[] }) {
  const [sort, setSort] = useState<SortKey>('date');
  const [limit, setLimit] = useState(PAGE);
  const sorted = useMemo(() => {
    const value = (p: SocialPost): number => {
      switch (sort) {
        case 'date':
          return Date.parse(p.publishedAt);
        case 'rate':
          return p.engagementRate ?? -1;
        default:
          return p.metrics[sort] ?? -1;
      }
    };
    return [...posts].sort((a, b) => value(b) - value(a));
  }, [posts, sort]);
  const knownSeries = useMemo(() => [...new Set(posts.map((p) => p.series).filter((s): s is string => !!s))].sort(), [posts]);

  if (posts.length === 0) {
    return (
      <Empty title="Keine Beiträge im Zeitraum.">Neue Beiträge kommen alle 6 Stunden, Stories alle 2 Stunden, Shorts mit dem YouTube-Lauf um 6 Uhr.</Empty>
    );
  }
  return (
    <div className="card overflow-x-auto">
      <table className="table min-w-[860px]">
        <caption className="sr-only">Beiträge im Zeitraum, sortiert nach {SORTS.find((s) => s.id === sort)!.label}</caption>
        <thead>
          <tr>
            <th scope="col">
              <SortButton id="date" sort={sort} onSort={setSort} label="Beitrag" />
            </th>
            <th scope="col">Serie</th>
            <th scope="col" className="num">
              <SortButton id="views" sort={sort} onSort={setSort} label="Aufrufe" />
            </th>
            <th scope="col" className="num">
              <SortButton id="reach" sort={sort} onSort={setSort} label="Reichweite" />
            </th>
            <th scope="col" className="num">
              <SortButton id="interactions" sort={sort} onSort={setSort} label="Interaktionen" />
            </th>
            <th scope="col" className="num">
              <SortButton id="rate" sort={sort} onSort={setSort} label="Engagement" />
            </th>
          </tr>
        </thead>
        <tbody>
          {sorted.slice(0, limit).map((p) => (
            <tr key={p.id}>
              <td className="max-w-[24rem]">
                <div className="flex items-start gap-3">
                  <Thumb post={p} />
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-1.5">
                      <PlatformIcon platform={p.platform} size={14} />
                      <span className="tag">{FORMAT_LABEL[p.format]}</span>
                      <span className="font-mono text-[0.75rem] font-semibold text-ink-soft">{formatDate(p.publishedAt)}</span>
                    </p>
                    {p.permalink ? (
                      <a href={p.permalink} target="_blank" rel="noreferrer" className="mt-1 line-clamp-2 text-[0.875rem] font-medium">
                        {caption(p)}
                      </a>
                    ) : (
                      <p className="mt-1 line-clamp-2 text-[0.875rem] font-medium">{caption(p)}</p>
                    )}
                  </div>
                </div>
              </td>
              <td>
                <SeriesCell post={p} known={knownSeries} />
              </td>
              <td className="num">{formatNumber(p.metrics.views)}</td>
              <td className="num">{formatNumber(p.metrics.reach)}</td>
              <td className="num">{formatNumber(p.metrics.interactions)}</td>
              <td className="num font-extrabold">{formatRate(p.engagementRate)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {sorted.length > limit && (
        <div className="flex flex-wrap items-center gap-3 border-t-3 border-ink p-3">
          <button type="button" className="btn btn-sm" onClick={() => setLimit((l) => l + PAGE)}>
            {Math.min(PAGE, sorted.length - limit)} weitere zeigen
          </button>
          <span className="font-mono text-[0.75rem] font-semibold text-ink-soft">
            {limit} von {sorted.length}
          </span>
        </div>
      )}
    </div>
  );
}

function SortButton({ id, sort, onSort, label }: { id: SortKey; sort: SortKey; onSort: (k: SortKey) => void; label: string }) {
  const active = sort === id;
  return (
    <button
      type="button"
      onClick={() => onSort(id)}
      aria-pressed={active}
      className={`inline-flex min-h-8 items-center gap-1 uppercase ${active ? 'underline decoration-blue decoration-3 underline-offset-4' : 'hover:underline'}`}
      title={`Nach ${label} sortieren (absteigend)`}
    >
      {label}
      {active && (
        <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" className="fill-ink">
          <path d="M0 2h10L5 9z" />
        </svg>
      )}
    </button>
  );
}

function SeriesCell({ post, known }: { post: SocialPost; known: string[] }) {
  const qc = useQueryClient();
  const [value, setValue] = useState(post.series ?? '');
  const save = useMutation({
    mutationFn: (series: string | null) => api.setPostSeries(post.id, series),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['social'] }),
  });
  const listId = `series-${post.id}`;
  return (
    <details className="group relative">
      <summary className="flex min-h-11 cursor-pointer list-none items-center gap-1.5 text-[0.875rem]">
        <span className={post.series ? 'font-semibold' : 'text-ink-soft'}>{post.series ?? 'Ohne Serie'}</span>
        {post.seriesManual && <span className="tag">von Hand</span>}
        <span className="font-bold underline decoration-2 underline-offset-2">ändern</span>
      </summary>
      <form
        className="card absolute left-0 z-30 mt-2 grid w-72 gap-3 p-4"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate(value.trim() === '' ? null : value.trim(), {
            onSuccess: () => ((e.target as HTMLElement).closest('details') as HTMLDetailsElement).removeAttribute('open'),
          });
        }}
      >
        <label className="grid gap-1">
          <span className="label">Serie</span>
          <input className="field" list={listId} value={value} maxLength={60} onChange={(e) => setValue(e.target.value)} placeholder="z. B. Fehler der Woche" />
          <datalist id={listId}>
            {known.map((s) => (
              <option key={s} value={s} />
            ))}
          </datalist>
        </label>
        {save.isError && <ErrorBox error={save.error} />}
        <div className="flex flex-wrap gap-2">
          <button type="submit" className="btn btn-primary btn-sm" disabled={save.isPending}>
            Serie speichern
          </button>
          {post.seriesManual && (
            <button type="button" className="btn btn-sm" disabled={save.isPending} onClick={() => save.mutate(null)}>
              Automatisch erkennen
            </button>
          )}
        </div>
        <p className="text-[0.8125rem] text-ink-soft">Automatisch per Hashtag, z. B. #fehlerderwoche oder #rechnungin60sekunden.</p>
      </form>
    </details>
  );
}

function VisitsCard({ page }: { page: SocialPage }) {
  const v = page.visits;
  return (
    <section className="card p-4 md:p-6" aria-labelledby="visits">
      <SectionTitle
        aside={
          <InfoTip text="Aufrufe von monteur-podcast.de über Links mit utm_source=instagram, facebook, youtube_shorts oder fb_gruppe. Aus den Server-Logs, ohne Bots. Gezählt werden Seitenaufrufe, keine Personen." />
        }
      >
        <span id="visits">Besuche auf der Homepage</span>
      </SectionTitle>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <p className="text-5xl leading-none font-black tabular-nums">{formatNumber(v.value)}</p>
        <Change value={v.value} previous={v.previous} />
      </div>
      {v.missing ? (
        <p className="text-[0.9375rem] text-ink-soft">{v.missing}</p>
      ) : (
        <ul className="grid gap-1">
          {(Object.keys(VISIT_SOURCES) as (keyof typeof VISIT_SOURCES)[]).map((s) => (
            <li key={s} className="flex justify-between gap-3 border-b-2 border-paper py-1.5">
              <span className="font-semibold">{VISIT_SOURCES[s]}</span>
              <span className="font-extrabold tabular-nums">{formatNumber(v.bySource[s] ?? null)}</span>
            </li>
          ))}
        </ul>
      )}
      <p className="mt-4 font-mono text-[0.75rem] font-semibold text-ink-soft">Link-Muster: monteur-podcast.de/?utm_source=instagram&amp;utm_medium=social</p>
    </section>
  );
}

function GroupsCard({ weeks, visits }: { weeks: GroupWeek[]; visits: number | null }) {
  const current = weeks[weeks.length - 1];
  return (
    <section className="card p-4 md:p-6" aria-labelledby="groups">
      <SectionTitle
        aside={
          <InfoTip text="Meta hat die Schnittstelle für Facebook-Gruppen 2024 abgeschaltet. Deshalb trägst du hier pro Woche ein, wie oft du in Gruppen geantwortet hast." />
        }
      >
        <span id="groups">Facebook-Gruppen</span>
      </SectionTitle>
      {current && <GroupWeekForm key={current.week + String(current.answers)} week={current} />}
      <table className="table mt-5">
        <caption className="sr-only">Antworten in Facebook-Gruppen je Woche</caption>
        <thead>
          <tr>
            <th scope="col">Woche ab</th>
            <th scope="col" className="num">
              Antworten
            </th>
            <th scope="col">Notiz</th>
          </tr>
        </thead>
        <tbody>
          {[...weeks].reverse().map((w) => (
            <tr key={w.week}>
              <td className="whitespace-nowrap">{formatDate(w.week)}</td>
              <td className="num">{formatNumber(w.answers)}</td>
              <td className="text-[0.875rem] text-ink-soft">{w.note ?? ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="mt-4 text-[0.9375rem]">
        Besuche über Gruppen-Links (utm_source=fb_gruppe) im Zeitraum: <span className="font-extrabold tabular-nums">{formatNumber(visits)}</span>
      </p>
    </section>
  );
}

function GroupWeekForm({ week }: { week: GroupWeek }) {
  const qc = useQueryClient();
  const [answers, setAnswers] = useState(week.answers === null ? '' : String(week.answers));
  const [note, setNote] = useState(week.note ?? '');
  const save = useMutation({
    mutationFn: () => api.setGroupWeek(week.week, Number(answers), note.trim() === '' ? null : note),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['social'] }),
  });
  const valid = /^\d{1,3}$/.test(answers);
  return (
    <form
      className="grid gap-3 bg-paper p-4 sm:grid-cols-[8rem_1fr_auto] sm:items-end"
      onSubmit={(e) => {
        e.preventDefault();
        if (valid) save.mutate();
      }}
    >
      <label className="grid gap-1">
        <span className="label">Diese Woche</span>
        <input
          className="field"
          inputMode="numeric"
          pattern="\d{1,3}"
          value={answers}
          onChange={(e) => setAnswers(e.target.value)}
          aria-describedby="groups-week"
        />
      </label>
      <label className="grid gap-1">
        <span className="label">Notiz (optional)</span>
        <input className="field" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} placeholder="z. B. welche Gruppen" />
      </label>
      <button type="submit" className="btn btn-primary" disabled={!valid || save.isPending}>
        Woche speichern
      </button>
      <p id="groups-week" className="font-mono text-[0.75rem] font-semibold text-ink-soft sm:col-span-3">
        Woche ab {formatDate(week.week)}
        {save.isSuccess && ' · gespeichert'}
      </p>
      {save.isError && (
        <div className="sm:col-span-3">
          <ErrorBox error={save.error} />
        </div>
      )}
    </form>
  );
}
