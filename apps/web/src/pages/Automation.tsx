import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { formatDate, formatDateTime, PLATFORM_INFO, type ImportPreview, type MetaConnection, type SyncSourceState } from '@cockpit/shared';
import { api, API_BASE, type ImportBody, type SpotifyMapping } from '../api.ts';
import { PageHead, SectionTitle } from '../components/Bits.tsx';
import { Empty, ErrorBox, Notice, PageLoading } from '../components/States.tsx';
import { SyncAllButton } from '../components/Sync.tsx';

export default function Automation() {
  const q = useQuery({ queryKey: ['automation'], queryFn: api.automation });
  const [params] = useSearchParams();
  const ytResult = params.get('youtube');
  const metaResult = params.get('meta');

  if (q.isPending) return <PageLoading />;
  if (q.isError) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;
  const server = d.sources.filter((s) => s.kind === 'server');
  const routine = d.sources.filter((s) => s.kind === 'routine');

  return (
    <>
      <PageHead
        title="Automatik"
        lead="Was der Server selbst holt, was die Claude-Routine holt, und wann zuletzt. Hier musst du nur eingreifen, wenn etwas rot ist."
      >
        <SyncAllButton />
      </PageHead>

      {ytResult === 'verbunden' && (
        <div className="mb-6">
          <Notice level="info">YouTube ist verbunden. Zahlen und Kommentare laufen ab jetzt automatisch.</Notice>
        </div>
      )}
      {ytResult === 'fehler' && (
        <div className="mb-6">
          <Notice level="error">YouTube wurde nicht verbunden: {params.get('grund') ?? 'unbekannter Grund'}</Notice>
        </div>
      )}
      {metaResult === 'verbunden' && (
        <div className="mb-6">
          <Notice level={params.get('ohne') === 'instagram' ? 'warn' : 'info'}>
            {params.get('ohne') === 'instagram'
              ? 'Facebook-Seite ist verbunden. Instagram fehlt noch: Instagram auf Profikonto umstellen, mit der Seite verknüpfen und „Meta verbinden“ erneut klicken.'
              : 'Instagram und Facebook sind verbunden. Zahlen und Kommentare laufen ab jetzt automatisch unter Social Media.'}
          </Notice>
        </div>
      )}
      {metaResult === 'fehler' && (
        <div className="mb-6">
          <Notice level="error">Meta wurde nicht verbunden: {params.get('grund') ?? 'unbekannter Grund'}</Notice>
        </div>
      )}

      <section className="mb-10" aria-labelledby="server">
        <SectionTitle>
          <span id="server">Server (ohne Claude)</span>
        </SectionTitle>
        <SourceTable sources={server} />
      </section>

      <section className="mb-10" aria-labelledby="routine">
        <SectionTitle>
          <span id="routine">Claude-Routine „Podcast-Zahlen holen“</span>
        </SectionTitle>
        <div className="card mb-5 grid gap-3 p-4 md:grid-cols-[1fr_auto] md:items-center">
          <p className="max-w-[65ch]">
            Läuft jeden Montag um 08:20 auf deinem Mac und holt Spotify (ab Stufe 2 auch Apple und Amazon). {d.routineTrigger.hint}
          </p>
          <button type="button" className="btn" disabled title="Der Knopf kommt in Stufe 2. Bis dahin: im Claude-Chat „Hol die Podcast-Zahlen“ schreiben.">
            Plattform-Daten holen
          </button>
        </div>
        <SourceTable sources={routine} />
      </section>

      <section className="mb-10" aria-labelledby="conn">
        <SectionTitle>
          <span id="conn">Verbindungen</span>
        </SectionTitle>
        <div className="grid gap-4">
          <YoutubeConnection connection={d.connections.find((c) => c.platform === 'youtube')} />
          <MetaConnectionCard connection={d.connections.find((c): c is MetaConnection => c.platform === 'meta')} />
        </div>
      </section>

      <section className="mb-10" aria-labelledby="queue">
        <SectionTitle>
          <span id="queue">Antwort-Warteschlange (Spotify)</span>
        </SectionTitle>
        {d.queue.length === 0 ? (
          <Empty title="Keine Antwort wartet.">Antworten auf Spotify-Kommentare landen hier und werden beim nächsten Routine-Lauf gepostet.</Empty>
        ) : (
          <ul className="grid gap-3">
            {d.queue.map((r) => (
              <li key={r.id} className="card grid gap-1 p-4">
                <p className="font-mono text-[0.75rem] font-semibold text-ink-soft">
                  Freigegeben {formatDateTime(r.approvedAt)} · an {r.commentAuthor}
                  {r.episode && ` · Folge ${r.episode.number ?? '–'}`}
                </p>
                <p className="text-[0.9375rem] text-ink-soft">„{r.commentText}“</p>
                <p className="font-semibold whitespace-pre-line">{r.text}</p>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="mb-10" aria-labelledby="aliases">
        <SectionTitle>
          <span id="aliases">Zuordnung von Folgen</span>
        </SectionTitle>
        {d.pendingAliases.length === 0 ? (
          <Empty title="Alles zugeordnet.">
            Wenn ein Portal einen Folgentitel anders schreibt, fragt das Cockpit hier einmal nach. Danach läuft es automatisch.
          </Empty>
        ) : (
          <ul className="grid gap-4">
            {d.pendingAliases.map((a) => (
              <AliasRow key={a.id} alias={a} />
            ))}
          </ul>
        )}
      </section>

      <details className="card p-4 md:p-6">
        <summary className="cursor-pointer text-xl font-black uppercase">Import (Rückfalllösung)</summary>
        <p className="mt-3 max-w-[65ch] text-ink-soft">
          Normalerweise importiert die Routine selbst. Falls sie einmal nicht läuft, kannst du hier eine Spotify-CSV oder eine JSON-Datei nach dem festen Schema
          einspielen. Dieselbe Datei zweimal ändert nichts.
        </p>
        <ImportPanel />
      </details>
    </>
  );
}

function SourceTable({ sources }: { sources: SyncSourceState[] }) {
  const qc = useQueryClient();
  const run = useMutation({ mutationFn: (id: string) => api.sync(id), onSettled: () => qc.invalidateQueries() });
  return (
    <div className="card relative overflow-x-auto">
      <table className="table min-w-[720px]">
        <thead>
          <tr>
            <th scope="col">Quelle</th>
            <th scope="col">Letzter Lauf</th>
            <th scope="col">Ergebnis</th>
            <th scope="col">Nächster Lauf</th>
            <th scope="col">
              <span className="sr-only">Aktion</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {sources.map((s) => {
            const failed = s.lastRun !== null && !s.lastRun.ok;
            return (
              <tr key={s.id}>
                <td className="font-semibold">
                  {s.label}
                  {s.stale && <span className="sticker ml-2">Alt</span>}
                </td>
                <td className="whitespace-nowrap">{s.lastRun ? formatDateTime(s.lastRun.finishedAt ?? s.lastRun.startedAt) : '–'}</td>
                <td className="max-w-[28rem]">
                  {s.lastRun === null ? (
                    <span className="text-ink-soft">{s.kind === 'routine' ? 'Noch kein Routine-Lauf.' : 'Noch nicht gelaufen.'}</span>
                  ) : (
                    <span className="flex items-start gap-2">
                      <span className={`tag shrink-0 ${failed ? 'bg-ink text-white' : 'bg-blue text-white'}`}>{failed ? 'Fehler' : 'OK'}</span>
                      <span className="text-[0.875rem]">{s.lastRun.message}</span>
                    </span>
                  )}
                </td>
                <td className="whitespace-nowrap">{s.nextRun ? formatDateTime(s.nextRun) : s.kind === 'routine' ? 'Montag, 08:20' : '–'}</td>
                <td className="text-right">
                  {s.canRunNow && (
                    <button type="button" className="btn btn-sm" disabled={run.isPending} onClick={() => run.mutate(s.id)}>
                      {run.isPending && run.variables === s.id ? 'Läuft …' : 'Jetzt holen'}
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {run.isError && (
        <div className="p-3">
          <ErrorBox error={run.error} />
        </div>
      )}
    </div>
  );
}

function YoutubeConnection({ connection }: { connection: { connected: boolean; account: string | null; configured?: boolean } | undefined }) {
  const qc = useQueryClient();
  const disconnect = useMutation({ mutationFn: api.disconnectYoutube, onSuccess: () => qc.invalidateQueries() });
  const configured = connection?.configured ?? false;
  return (
    <div className="card flex flex-wrap items-center justify-between gap-4 p-4">
      <div>
        <p className="text-lg font-black">YouTube</p>
        <p className="text-[0.9375rem]">
          {!configured
            ? 'Google-Zugang fehlt noch in der analytics.env (Google-Cloud-Projekt, siehe README).'
            : connection?.connected
              ? `Verbunden mit ${connection.account ?? 'dem Kanal'}. Läuft ohne Ablaufdatum, solange der Zustimmungsbildschirm auf „In Produktion“ steht.`
              : 'Nicht verbunden. Einmal verbinden, danach läuft YouTube ohne Zutun.'}
        </p>
      </div>
      <div className="flex flex-wrap gap-3">
        {configured && (
          <a className="btn btn-primary" href={`${API_BASE}/youtube/connect`}>
            {connection?.connected ? 'Neu verbinden' : 'YouTube verbinden'}
          </a>
        )}
        {connection?.connected && (
          <button
            type="button"
            className="btn"
            disabled={disconnect.isPending}
            onClick={() => {
              if (window.confirm('YouTube trennen? Zahlen und Kommentare werden dann nicht mehr geholt.')) disconnect.mutate();
            }}
          >
            Trennen
          </button>
        )}
      </div>
    </div>
  );
}

function MetaConnectionCard({ connection }: { connection: MetaConnection | undefined }) {
  const qc = useQueryClient();
  const disconnect = useMutation({ mutationFn: api.disconnectMeta, onSuccess: () => qc.invalidateQueries() });
  const configured = connection?.configured ?? false;
  return (
    <div className="card flex flex-wrap items-center justify-between gap-4 p-4">
      <div className="max-w-[65ch]">
        <p className="text-lg font-black">Instagram und Facebook-Seite (Meta)</p>
        <p className="text-[0.9375rem]">
          {!configured
            ? 'Meta-App fehlt noch in der analytics.env (META_APP_ID, META_APP_SECRET, META_REDIRECT_URI). Anleitung im README unter „Social Media“.'
            : connection?.connected
              ? `Verbunden: ${connection.account ?? 'Seite'}.${connection.instagram ? '' : ' Instagram fehlt: Profikonto mit der Seite verknüpfen, dann neu verbinden.'} Läuft ohne Ablaufdatum, bis du dein Facebook-Passwort änderst oder die App entfernst.`
              : 'Nicht verbunden. Einmal verbinden, danach laufen Zahlen und Kommentare von Instagram und der Facebook-Seite ohne Zutun. Das Cockpit liest nur und antwortet nur, wenn du auf „Antwort senden“ klickst.'}
        </p>
        <p className="mt-1 text-[0.8125rem] text-ink-soft">
          Facebook-Gruppen hat Meta 2024 für Apps gesperrt. Dafür gibt es unter Social Media ein Wochenprotokoll.
        </p>
      </div>
      <div className="flex flex-wrap gap-3">
        {configured && (
          <a className="btn btn-primary" href={`${API_BASE}/meta/connect`}>
            {connection?.connected ? 'Neu verbinden' : 'Meta verbinden'}
          </a>
        )}
        {connection?.connected && (
          <button
            type="button"
            className="btn"
            disabled={disconnect.isPending}
            onClick={() => {
              if (window.confirm('Meta trennen? Zahlen und Kommentare von Instagram und Facebook werden dann nicht mehr geholt.')) disconnect.mutate();
            }}
          >
            Trennen
          </button>
        )}
      </div>
    </div>
  );
}

function AliasRow({
  alias,
}: {
  alias: { id: number; platform: string; foreignTitle: string; suggestions: { id: number; number: number | null; title: string; score: number }[] };
}) {
  const qc = useQueryClient();
  const confirm = useMutation({ mutationFn: (episodeId: number | null) => api.confirmAlias(alias.id, episodeId), onSuccess: () => qc.invalidateQueries() });
  const name = alias.platform in PLATFORM_INFO ? PLATFORM_INFO[alias.platform as keyof typeof PLATFORM_INFO].name : alias.platform;
  return (
    <li className="card grid gap-3 p-4">
      <p>
        <span className="label mr-2">{name} schreibt</span>
        <span className="font-bold">„{alias.foreignTitle}“</span>
      </p>
      <div className="flex flex-wrap gap-3">
        {alias.suggestions.map((s) => (
          <button
            key={s.id}
            type="button"
            className="btn btn-sm max-w-full !whitespace-normal text-left"
            disabled={confirm.isPending}
            onClick={() => confirm.mutate(s.id)}
          >
            Folge {s.number ?? '–'}: {s.title.length > 40 ? s.title.slice(0, 40) + '…' : s.title}
          </button>
        ))}
        <button type="button" className="btn btn-sm" disabled={confirm.isPending} onClick={() => confirm.mutate(null)}>
          Keine Folge (Trailer o. Ä.)
        </button>
      </div>
      {confirm.isError && <ErrorBox error={confirm.error} />}
    </li>
  );
}

function ImportPanel() {
  const qc = useQueryClient();
  const [content, setContent] = useState('');
  const [fileName, setFileName] = useState('');
  const [period, setPeriod] = useState({ from: '', to: '' });
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [result, setResult] = useState<string | null>(null);
  const type: ImportBody['type'] = content.trim().startsWith('{') ? 'json' : 'csv';
  const mapping = useQuery({ queryKey: ['spotify-mapping'], queryFn: api.spotifyMapping });
  const headers = useQuery({ queryKey: ['csv-headers', content], queryFn: () => api.csvHeaders(content), enabled: type === 'csv' && content.trim() !== '' });
  const body = (): ImportBody => ({ type, content, fileName, period: type === 'csv' && period.from && period.to ? period : null });

  const check = useMutation({ mutationFn: () => api.importPreview(body()), onSuccess: (p) => (setPreview(p), setResult(null)) });
  const commit = useMutation({
    mutationFn: () => api.importCommit(body()),
    onSuccess: (r) => {
      setResult(r.message);
      setPreview(null);
      qc.invalidateQueries();
    },
  });

  const profile = type === 'csv' ? (headers.data?.profile ?? null) : null;
  const needsMapping = type === 'csv' && content.trim() !== '' && headers.isSuccess && !profile && !mapping.data;
  const periodInName = /\d{1,2}\.\d{1,2}\.\d{4}\s*[-–_]\s*\d{1,2}\.\d{1,2}\.\d{4}/.test(fileName);
  const needsPeriod = type === 'csv' && ((profile === 'amazon_overview' && !periodInName) || (!profile && mapping.data && !mapping.data.date));

  return (
    <div className="mt-5 grid gap-4">
      <label className="grid gap-1">
        <span className="label">Datei (CSV oder JSON)</span>
        <input
          type="file"
          accept=".csv,.json,text/csv,application/json"
          className="field"
          onChange={async (e) => {
            const f = e.target.files?.[0];
            if (!f) return;
            setFileName(f.name);
            setContent(await f.text());
            setPreview(null);
            setResult(null);
          }}
        />
      </label>
      <label className="grid gap-1">
        <span className="label">Oder Inhalt einfügen</span>
        <textarea
          className="field min-h-32 font-mono text-[0.8125rem]"
          value={content}
          onChange={(e) => (setContent(e.target.value), setFileName(''), setPreview(null))}
        />
      </label>
      {content && (
        <p className="font-mono text-[0.75rem] font-semibold">
          Erkannt: {type === 'json' ? 'JSON nach Import-Schema' : (headers.data?.profileLabel ?? 'unbekannte CSV (Spalten-Zuordnung für Spotify)')}{' '}
          {fileName && `· ${fileName}`}
        </p>
      )}

      {type === 'csv' && content.trim() !== '' && headers.data && !profile && <MappingEditor headers={headers.data.headers} current={mapping.data ?? null} />}
      {headers.isError && <ErrorBox error={headers.error} />}

      {needsPeriod && (
        <fieldset className="flex flex-wrap gap-3">
          <legend className="label mb-1">Zeitraum des Exports (steht nicht in der Datei)</legend>
          <input className="field w-44" type="date" aria-label="Von" value={period.from} onChange={(e) => setPeriod((p) => ({ ...p, from: e.target.value }))} />
          <input className="field w-44" type="date" aria-label="Bis" value={period.to} onChange={(e) => setPeriod((p) => ({ ...p, to: e.target.value }))} />
        </fieldset>
      )}

      <div className="flex flex-wrap gap-3">
        <button type="button" className="btn" disabled={!content.trim() || check.isPending || needsMapping} onClick={() => check.mutate()}>
          Prüfen
        </button>
        <button type="button" className="btn btn-primary" disabled={!preview?.ok || preview.duplicate || commit.isPending} onClick={() => commit.mutate()}>
          Import speichern
        </button>
      </div>
      {check.isError && <ErrorBox error={check.error} />}
      {commit.isError && <ErrorBox error={commit.error} />}
      {preview && (
        <div className="border-3 border-ink p-4" aria-live="polite">
          {preview.ok ? (
            <>
              <p className="font-bold">
                {preview.duplicate ? 'Diese Datei wurde schon importiert. Nichts zu tun.' : 'Sieht gut aus.'} {preview.summary.episodes} Folgen,{' '}
                {preview.summary.comments} Kommentare, {preview.summary.replies} gesendete Antworten.
              </p>
              {preview.summary.unknownEpisodes.length > 0 && (
                <p className="mt-2 text-[0.9375rem]">
                  Unbekannte Titel (werden gespeichert und dir unter „Zuordnung von Folgen“ vorgelegt): {preview.summary.unknownEpisodes.join(' · ')}
                </p>
              )}
            </>
          ) : (
            <ul className="grid gap-1">
              {preview.errors.map((e) => (
                <li key={e} className="font-semibold">
                  {e}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      {result && <Notice level="info">{result}</Notice>}
    </div>
  );
}

function MappingEditor({ headers, current }: { headers: string[]; current: SpotifyMapping | null }) {
  const qc = useQueryClient();
  const [m, setM] = useState<SpotifyMapping>(current ?? { date: null, episode: null, metrics: {} });
  const save = useMutation({ mutationFn: () => api.saveSpotifyMapping(m), onSuccess: () => qc.invalidateQueries({ queryKey: ['spotify-mapping'] }) });
  const select = (label: string, value: string | null, onChange: (v: string | null) => void, required = false) => (
    <label className="grid gap-1">
      <span className="label">{label}</span>
      <select className="field" value={value ?? ''} onChange={(e) => onChange(e.target.value || null)} required={required}>
        <option value="">{required ? 'Spalte wählen' : 'Keine'}</option>
        {headers.map((h) => (
          <option key={h} value={h}>
            {h}
          </option>
        ))}
      </select>
    </label>
  );
  const metric = (key: string) => (v: string | null) =>
    setM((prev) => {
      const metrics = { ...prev.metrics };
      if (v) metrics[key] = v;
      else delete metrics[key];
      return { ...prev, metrics };
    });
  return (
    <form
      className="grid gap-3 border-3 border-ink bg-paper p-4"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <p className="font-bold">{current ? 'Spalten-Zuordnung für Spotify (gespeichert)' : 'Spalten einmal zuordnen. Das Cockpit merkt sich die Zuordnung.'}</p>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {select('Datum (falls vorhanden)', m.date, (v) => setM((p) => ({ ...p, date: v })))}
        {select('Folgentitel (falls je Folge)', m.episode, (v) => setM((p) => ({ ...p, episode: v })))}
        {select('Plays bzw. Starts (Leitwert)', m.metrics.plays ?? null, metric('plays'), true)}
        {select('Hörer', m.metrics.listeners ?? null, metric('listeners'))}
        {select('Follower', m.metrics.followers ?? null, metric('followers'))}
        {select('Streams', m.metrics.streams ?? null, metric('streams'))}
      </div>
      <div>
        <button type="submit" className="btn btn-primary" disabled={!m.metrics.plays || save.isPending}>
          Zuordnung speichern
        </button>
      </div>
      {save.isError && <ErrorBox error={save.error} />}
      {save.isSuccess && <p role="status">Gespeichert {formatDate(new Date().toISOString())}.</p>}
    </form>
  );
}
