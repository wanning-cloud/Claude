import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { formatDate, formatDuration, formatNumber, PLATFORM_INFO, type Milestone, type Platform } from '@cockpit/shared';
import { api } from '../api.ts';
import { PageHead, SectionTitle } from '../components/Bits.tsx';
import { DailyChart } from '../components/ChartBlock.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { Empty, ErrorBox, Notice, PageLoading } from '../components/States.tsx';
import { CommentCard } from './Comments.tsx';

const REACH: Platform[] = ['youtube', 'spotify', 'downloads', 'website'];
const OTHERS: Platform[] = ['apple', 'amazon', 'deezer'];
const MILESTONES: { key: Milestone; label: string }[] = [
  { key: 'd1', label: 'Tag 1' },
  { key: 'd3', label: '3 Tage' },
  { key: 'd7', label: '7 Tage' },
  { key: 'd30', label: '30 Tage' },
];

export default function EpisodeDetail() {
  const id = Number(useParams().id);
  const q = useQuery({ queryKey: ['episode', id], queryFn: () => api.episode(id), enabled: Number.isFinite(id) });

  if (q.isPending) return <PageLoading />;
  if (q.isError) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;
  const { episode: e, daily, milestones, comments } = q.data;

  return (
    <>
      <Link to="/folgen" className="label mb-4 inline-block">
        ← Alle Folgen
      </Link>
      <div className="mb-10 grid gap-6 md:grid-cols-[minmax(0,320px)_1fr] md:items-end">
        {e.thumbnail ? (
          <img src={e.thumbnail} alt="" width={640} height={360} loading="lazy" className="aspect-video w-full border-4 border-ink object-cover shadow-pink" />
        ) : (
          <div className="grid aspect-video place-items-center border-4 border-ink bg-blue text-6xl font-black text-white shadow-pink">{e.number ?? '–'}</div>
        )}
        <div className="min-w-0">
          <span className="sticker mb-3">Folge {e.number ?? '–'}</span>
          <PageHead title={e.title} lead={`Erschienen am ${formatDate(e.publishedAt)} · Länge ${formatDuration(e.durationSeconds)}`} />
        </div>
      </div>

      <section className="mb-10" aria-labelledby="since">
        <SectionTitle>
          <span id="since">Seit Veröffentlichung</span>
        </SectionTitle>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="border-4 border-ink bg-blue p-4 text-white shadow-4">
            <p className="label">Reichweite</p>
            <p className="text-4xl font-black">{formatNumber(e.reach)}</p>
          </div>
          {REACH.filter((p) => p !== 'website').map((p) => (
            <div key={p} className="card p-4">
              <p className="flex items-center gap-2 font-bold">
                <PlatformIcon platform={p} /> {PLATFORM_INFO[p].name}
              </p>
              <p className="text-3xl font-black">{formatNumber(e.values[p] ?? null)}</p>
            </div>
          ))}
        </div>
        <p className="mt-3 text-[0.875rem] text-ink-soft">
          Außerhalb der Reichweite: {OTHERS.map((p) => `${PLATFORM_INFO[p].name} ${formatNumber(e.values[p] ?? null)}`).join(' · ')}
        </p>
      </section>

      <section className="card mb-10 p-4 md:p-6" aria-labelledby="fair">
        <SectionTitle>
          <span id="fair">Nach 1, 3, 7 und 30 Tagen</span>
        </SectionTitle>
        <p className="mb-4 max-w-[65ch] text-ink-soft">
          Summe ab dem Erscheinungstag. So vergleichst du neue und alte Folgen fair. „–“: Der Tag ist noch nicht erreicht oder das Portal hat keine Tageswerte.
        </p>
        <div className="overflow-x-auto">
          <table className="table min-w-[520px]">
            <thead>
              <tr>
                <th scope="col">Portal</th>
                {MILESTONES.map((m) => (
                  <th key={m.key} scope="col" className="num">
                    {m.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {REACH.map((p) => (
                <tr key={p}>
                  <td className="font-semibold">{PLATFORM_INFO[p].name}</td>
                  {MILESTONES.map((m) => (
                    <td key={m.key} className="num">
                      {formatNumber(milestones[m.key][p] ?? null)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <div className="mb-10">
        <DailyChart title="Verlauf seit Veröffentlichung" daily={daily} platforms={REACH} />
      </div>

      <YoutubeMapping episodeId={e.id} current={e.youtubeVideoId} />

      <section aria-labelledby="ep-comments" className="mt-10">
        <SectionTitle
          aside={
            <Link to={`/kommentare?episode=${e.id}`} className="font-bold">
              Im Postfach öffnen
            </Link>
          }
        >
          <span id="ep-comments">Kommentare zu dieser Folge</span>
        </SectionTitle>
        {comments.length === 0 ? (
          <Empty title="Noch keine Kommentare zu dieser Folge." />
        ) : (
          <ul className="grid gap-4">
            {comments.map((c) => (
              <li key={c.id}>
                <Link to={`/kommentare/${c.id}`} aria-label={`${c.author}: ${c.text.slice(0, 80)}`} className="block no-underline">
                  <CommentCard comment={c} compact />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}

function YoutubeMapping({ episodeId, current }: { episodeId: number; current: string | null }) {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const videos = useQuery({ queryKey: ['youtube-videos'], queryFn: api.youtubeVideos, enabled: open });
  const save = useMutation({
    mutationFn: (videoId: string | null) => api.setYoutube(episodeId, videoId),
    onSuccess: () => qc.invalidateQueries(),
  });
  return (
    <section className="border-4 border-dashed border-ink p-4" aria-label="YouTube-Video der Folge">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p>
          <span className="label mr-2">YouTube-Video</span>
          {current ? (
            <a href={`https://www.youtube.com/watch?v=${current}`} target="_blank" rel="noreferrer" className="font-mono font-semibold">
              {current}
            </a>
          ) : (
            <span className="font-semibold">nicht zugeordnet</span>
          )}
        </p>
        <button type="button" className="btn btn-sm" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
          Zuordnung ändern
        </button>
      </div>
      {open && (
        <div className="mt-4 grid gap-3">
          {videos.isPending ? (
            <p>Lädt Videos …</p>
          ) : videos.isError ? (
            <ErrorBox error={videos.error} />
          ) : videos.data.length === 0 ? (
            <p>Noch keine Videos geholt. YouTube verbinden und die YouTube-Zahlen holen.</p>
          ) : (
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(ev) => {
                ev.preventDefault();
                const v = String(new FormData(ev.currentTarget).get('video') ?? '');
                save.mutate(v === '' ? null : v);
              }}
            >
              <label className="grid min-w-0 flex-1 gap-1">
                <span className="label">Video wählen</span>
                <select name="video" className="field" defaultValue={current ?? ''}>
                  <option value="">Kein Video</option>
                  {videos.data.map((v) => (
                    <option key={v.videoId} value={v.videoId}>
                      {v.title} ({formatDate(v.publishedAt)})
                    </option>
                  ))}
                </select>
              </label>
              <button className="btn btn-primary" type="submit" disabled={save.isPending}>
                Zuordnung speichern
              </button>
            </form>
          )}
          {save.isSuccess && <Notice level="info">{save.data.message}</Notice>}
          {save.isError && <ErrorBox error={save.error} />}
        </div>
      )}
    </section>
  );
}
