import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { COMMENT_STATUS_LABEL, formatDateTime, formatNumber, sourceName, type Comment, type CommentPlatform, type CommentStatus } from '@cockpit/shared';
import { api } from '../api.ts';
import { PageHead } from '../components/Bits.tsx';
import { PlatformIcon } from '../components/PlatformIcon.tsx';
import { Empty, ErrorBox, PageLoading, Skeleton } from '../components/States.tsx';

const FILTER_PLATFORMS: CommentPlatform[] = ['youtube', 'spotify', 'apple', 'instagram', 'facebook'];
const AREAS = [
  { id: '', label: 'Alle Bereiche' },
  { id: 'podcast', label: 'Podcast' },
  { id: 'social', label: 'Social Media' },
] as const;
const STATUSES: CommentStatus[] = ['new', 'answered', 'later', 'done'];

export default function Comments() {
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const selectedId = Number(useParams().id) || null;
  const filter = {
    platform: params.get('platform') ?? '',
    area: params.get('area') ?? '',
    status: params.get('status') ?? '',
    episode: params.get('episode') ?? '',
    page: params.get('page') ?? '1',
  };
  const list = useQuery({
    queryKey: ['comments', filter],
    queryFn: () => api.comments(Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== ''))),
    refetchInterval: 120_000,
  });
  const items = useMemo(() => list.data?.items ?? [], [list.data]);
  const selected = items.find((c) => c.id === selectedId) ?? null;
  const qs = params.toString() ? `?${params}` : '';

  const setFilter = (k: string, v: string) => {
    const p = new URLSearchParams(params);
    if (v) p.set(k, v);
    else p.delete(k);
    p.delete('page');
    setParams(p);
  };

  // Keyboard: j/k next/previous, r reply, e done.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement;
      if (e.metaKey || e.ctrlKey || e.altKey || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)) return;
      const i = items.findIndex((c) => c.id === selectedId);
      if (e.key === 'j' || e.key === 'k') {
        const next = items[e.key === 'j' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1)];
        if (next) navigate(`/kommentare/${next.id}${qs}`);
      } else if (e.key === 'r' && selectedId) {
        e.preventDefault();
        document.getElementById('reply-text')?.focus();
      } else if (e.key === 'e' && selectedId) {
        document.getElementById('mark-done')?.click();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [items, selectedId, navigate, qs]);

  const filters = (
    <div className="mb-6 flex flex-wrap items-end gap-3">
      <label className="grid gap-1">
        <span className="label">Bereich</span>
        <select className="field w-44" value={filter.area} onChange={(e) => setFilter('area', e.target.value)}>
          {AREAS.map((a) => (
            <option key={a.id} value={a.id}>
              {a.label}
            </option>
          ))}
        </select>
      </label>
      <label className="grid gap-1">
        <span className="label">Portal</span>
        <select className="field w-44" value={filter.platform} onChange={(e) => setFilter('platform', e.target.value)}>
          <option value="">Alle Portale</option>
          {FILTER_PLATFORMS.map((p) => (
            <option key={p} value={p}>
              {sourceName(p)}
            </option>
          ))}
        </select>
      </label>
      <label className="grid gap-1">
        <span className="label">Status</span>
        <select className="field w-44" value={filter.status} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">Alle</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {COMMENT_STATUS_LABEL[s]}
            </option>
          ))}
        </select>
      </label>
      {filter.episode && (
        <button type="button" className="btn btn-sm" onClick={() => setFilter('episode', '')}>
          Folgen-Filter entfernen
        </button>
      )}
      <p className="ml-auto hidden font-mono text-[0.75rem] font-semibold text-ink-soft lg:block">Tasten: j/k weiter · r antworten · e erledigt</p>
    </div>
  );

  if (list.isPending) return <PageLoading />;
  if (list.isError) return <ErrorBox error={list.error} onRetry={() => list.refetch()} />;
  const { total, page, pageSize, newCount } = list.data;
  const pages = Math.max(1, Math.ceil(total / pageSize));

  return (
    <>
      <PageHead
        title="Kommentare"
        lead={`${formatNumber(newCount)} neu · ${formatNumber(total)} im Filter. YouTube, Instagram und Facebook beantwortest du direkt hier, Spotify über die Routine, Apple nur lesen.`}
      />
      {filters}
      {items.length === 0 ? (
        <Empty title={filter.status === 'new' ? 'Alles beantwortet.' : 'Keine Kommentare in diesem Filter.'}>
          Neue YouTube-Kommentare kommen alle 30 Minuten, Instagram und Facebook alle 15 Minuten, Apple-Bewertungen täglich, Spotify-Kommentare mit der Routine.
        </Empty>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
          <section aria-label="Liste" className={selected ? 'hidden lg:block' : ''}>
            <ul className="grid gap-3">
              {items.map((c) => (
                <li key={c.id}>
                  <Link
                    to={`/kommentare/${c.id}${qs}`}
                    aria-current={c.id === selectedId ? 'true' : undefined}
                    aria-label={`${sourceName(c.platform)}, ${c.author}, ${COMMENT_STATUS_LABEL[c.status]}: ${c.text.slice(0, 80)}`}
                    className="block no-underline"
                  >
                    <CommentCard comment={c} compact active={c.id === selectedId} />
                  </Link>
                </li>
              ))}
            </ul>
            {pages > 1 && (
              <nav aria-label="Seiten" className="mt-6 flex items-center gap-3">
                <button type="button" className="btn btn-sm" disabled={page <= 1} onClick={() => setParams((p) => (p.set('page', String(page - 1)), p))}>
                  Zurück
                </button>
                <span className="font-mono text-[0.8125rem] font-semibold">
                  Seite {page} von {pages}
                </span>
                <button type="button" className="btn btn-sm" disabled={page >= pages} onClick={() => setParams((p) => (p.set('page', String(page + 1)), p))}>
                  Weiter
                </button>
              </nav>
            )}
          </section>
          <section aria-label="Verlauf" className={selected ? '' : 'hidden lg:block'}>
            {selected ? (
              <Detail key={selected.id} comment={selected} backTo={`/kommentare${qs}`} />
            ) : selectedId ? (
              <Detail.ById id={selectedId} backTo={`/kommentare${qs}`} />
            ) : (
              <div className="sticky top-6">
                <Empty title="Kommentar auswählen.">Links einen Kommentar anklicken oder mit j und k durchgehen.</Empty>
              </div>
            )}
          </section>
        </div>
      )}
    </>
  );
}

export function CommentCard({ comment: c, compact = false, active = false }: { comment: Comment; compact?: boolean; active?: boolean }) {
  const Tag = compact ? 'div' : 'article';
  return (
    <Tag
      className={`border-3 border-ink bg-white p-4 transition-transform ${active ? 'translate-x-[3px] translate-y-[3px] shadow-blue' : 'shadow-3 hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-1'}`}
    >
      <header className="mb-2 flex flex-wrap items-center gap-2">
        <span className="flex items-center gap-1.5 text-[0.875rem] font-bold">
          <PlatformIcon platform={c.isShort ? 'youtube_shorts' : c.platform} size={16} />
          {c.isShort ? 'YouTube Short' : sourceName(c.platform)}
        </span>
        {c.area === 'social' && <span className="tag bg-paper">Social</span>}
        {c.status === 'new' ? <span className="sticker">Neu</span> : <span className="tag">{COMMENT_STATUS_LABEL[c.status]}</span>}
        {c.rating !== null && (
          <span className="tag bg-blue text-white" aria-label={`${c.rating} von 5 Sternen`}>
            {c.rating}/5 Sterne
          </span>
        )}
        <time dateTime={c.postedAt} className="ml-auto font-mono text-[0.75rem] font-semibold text-ink-soft">
          {formatDateTime(c.postedAt)}
        </time>
      </header>
      <p className="font-bold">{c.author}</p>
      {c.title && <p className="font-semibold">{c.title}</p>}
      <p className={`whitespace-pre-line text-[0.9375rem] ${compact ? 'line-clamp-3' : ''}`}>{c.text}</p>
      {c.episode && (
        <p className="mt-2 font-mono text-[0.75rem] font-semibold text-ink-soft">
          Folge {c.episode.number ?? '–'} · {c.episode.title}
        </p>
      )}
      {compact && c.replies.length > 0 && <p className="mt-1 font-mono text-[0.75rem] font-semibold text-ink-soft">{c.replies.length} Antworten</p>}
    </Tag>
  );
}

function Detail({ comment: c, backTo }: { comment: Comment; backTo: string }) {
  const qc = useQueryClient();
  const [text, setText] = useState('');
  const [note, setNote] = useState(c.note ?? '');
  const textRef = useRef<HTMLTextAreaElement>(null);
  const refresh = () =>
    qc.invalidateQueries({ predicate: (q) => ['comments', 'session', 'overview', 'episode', 'automation'].includes(String(q.queryKey[0])) });

  const patch = useMutation({ mutationFn: (p: { status?: CommentStatus; note?: string | null }) => api.patchComment(c.id, p), onSuccess: refresh });
  const reply = useMutation({
    mutationFn: () => api.reply(c.id, text),
    onSuccess: () => {
      setText('');
      refresh();
    },
    onSettled: refresh,
  });

  const sendLabel = c.replyMode === 'queue' ? 'In Warteschlange (nächster Routine-Lauf)' : 'Antwort senden';

  return (
    <div className="grid gap-5 lg:sticky lg:top-6">
      <Link to={backTo} className="label lg:hidden">
        ← Zur Liste
      </Link>
      <CommentCard comment={c} />

      {c.replies.length > 0 && (
        <ol className="ml-6 grid gap-3 md:ml-10" aria-label="Antworten">
          {c.replies.map((r) => (
            <li key={r.id} className={`border-3 border-ink p-3 ${r.fromHost ? 'bg-paper' : 'bg-white'}`}>
              <p className="mb-1 flex flex-wrap items-center gap-2 text-[0.875rem]">
                <span className="font-bold">{r.author}</span>
                {r.state === 'queued' && <span className="tag bg-pink">Wartet auf Routine</span>}
                {r.state === 'failed' && <span className="tag bg-ink text-white">Nicht gesendet</span>}
                <time className="ml-auto font-mono text-[0.75rem] font-semibold text-ink-soft">{formatDateTime(r.postedAt)}</time>
              </p>
              <p className="whitespace-pre-line text-[0.9375rem]">{r.text}</p>
              {r.error && <p className="mt-1 text-[0.8125rem] font-semibold">Grund: {r.error}</p>}
            </li>
          ))}
        </ol>
      )}

      {c.replyMode === 'none' ? (
        <p className="border-3 border-ink bg-paper p-3 font-semibold">
          {c.platform === 'apple' ? 'Antwort bei Apple nicht möglich.' : 'Auf diesem Portal kann das Cockpit nicht antworten.'}
        </p>
      ) : (
        <form
          className="card grid gap-3 p-4"
          onSubmit={(e) => {
            e.preventDefault();
            if (text.trim()) reply.mutate();
          }}
        >
          <label htmlFor="reply-text" className="label">
            Deine Antwort {c.replyMode === 'queue' ? '(Spotify: wird beim nächsten Routine-Lauf gepostet)' : `(${sourceName(c.platform)}: geht sofort raus)`}
          </label>
          <textarea
            id="reply-text"
            ref={textRef}
            className="field min-h-32 scroll-mb-40"
            value={text}
            maxLength={10000}
            onChange={(e) => setText(e.target.value)}
            onFocus={() => setTimeout(() => textRef.current?.scrollIntoView({ block: 'center' }), 300)}
            placeholder="Kurz und direkt, per du."
          />
          {text.trim() && (
            <div aria-live="polite">
              <p className="label mb-1">Vorschau</p>
              <div className="border-3 border-ink bg-paper p-3">
                <p className="text-[0.875rem] font-bold">Markus Wanning</p>
                <p className="whitespace-pre-line text-[0.9375rem]">{text.trim()}</p>
              </div>
            </div>
          )}
          {reply.isError && <ErrorBox error={reply.error} />}
          {reply.isSuccess && (
            <p role="status" className="font-semibold">
              {c.replyMode === 'queue' ? 'In der Warteschlange. Die Routine postet die Antwort beim nächsten Lauf.' : 'Gesendet.'}
            </p>
          )}
          <div className="flex flex-wrap gap-3">
            <button type="submit" className="btn btn-send" disabled={!text.trim() || reply.isPending}>
              {reply.isPending ? 'Sendet …' : sendLabel}
            </button>
            {c.externalUrl && (
              <a className="btn" href={c.externalUrl} target="_blank" rel="noreferrer">
                Auf {sourceName(c.platform)} öffnen
              </a>
            )}
          </div>
        </form>
      )}

      <div className="flex flex-wrap gap-3" role="group" aria-label="Status setzen">
        <button
          id="mark-done"
          type="button"
          className="btn btn-sm"
          disabled={patch.isPending || c.status === 'done'}
          onClick={() => patch.mutate({ status: 'done' })}
        >
          Erledigt (ohne Antwort)
        </button>
        <button type="button" className="btn btn-sm" disabled={patch.isPending || c.status === 'later'} onClick={() => patch.mutate({ status: 'later' })}>
          Später
        </button>
        <button type="button" className="btn btn-sm" disabled={patch.isPending || c.status === 'new'} onClick={() => patch.mutate({ status: 'new' })}>
          Wieder auf neu
        </button>
        {c.replyMode === 'none' && c.externalUrl && (
          <a className="btn btn-sm" href={c.externalUrl} target="_blank" rel="noreferrer">
            Bei {sourceName(c.platform)} ansehen
          </a>
        )}
      </div>
      {patch.isError && <ErrorBox error={patch.error} />}

      <form
        className="grid gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          patch.mutate({ note: note.trim() === '' ? null : note });
        }}
      >
        <label htmlFor="note" className="label">
          Interne Notiz (nur für dich)
        </label>
        <textarea id="note" className="field min-h-20" value={note} maxLength={4000} onChange={(e) => setNote(e.target.value)} />
        <div>
          <button type="submit" className="btn btn-sm" disabled={patch.isPending || note === (c.note ?? '')}>
            Notiz speichern
          </button>
        </div>
      </form>
    </div>
  );
}

/** Deep link to a comment that is not on the current page of the list. */
Detail.ById = function DetailById({ id, backTo }: { id: number; backTo: string }) {
  const q = useQuery({ queryKey: ['comments', 'one', id], queryFn: () => api.comment(id) });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <ErrorBox error={q.error} />;
  return <Detail comment={q.data} backTo={backTo} />;
};
