import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api.ts';

type Step = { id: string; label: string; state: 'waiting' | 'running' | 'ok' | 'error'; message?: string };

/**
 * "Jetzt aktualisieren": runs every server source one after the other (one request per source, so the
 * PHP time limit on shared hosting never hits) and shows the progress per source.
 */
export function SyncAllButton() {
  const qc = useQueryClient();
  const automation = useQuery({ queryKey: ['automation'], queryFn: api.automation, staleTime: 30_000 });
  const [steps, setSteps] = useState<Step[] | null>(null);
  const running = steps?.some((s) => s.state === 'running' || s.state === 'waiting') ?? false;

  const run = async () => {
    const sources = (automation.data ?? (await automation.refetch()).data)?.sources.filter((s) => s.kind === 'server' && s.canRunNow) ?? [];
    const list: Step[] = sources.map((s) => ({ id: s.id, label: s.label, state: 'waiting' }));
    setSteps(list);
    for (let i = 0; i < list.length; i++) {
      setSteps((prev) => prev!.map((s, k) => (k === i ? { ...s, state: 'running' } : s)));
      try {
        const r = await api.sync(list[i]!.id);
        setSteps((prev) => prev!.map((s, k) => (k === i ? { ...s, state: r.ok ? 'ok' : 'error', message: r.message } : s)));
      } catch (e) {
        setSteps((prev) => prev!.map((s, k) => (k === i ? { ...s, state: 'error', message: (e as Error).message } : s)));
      }
    }
    await qc.invalidateQueries();
  };

  return (
    <div className="relative">
      <button type="button" className="btn btn-primary" onClick={run} disabled={running}>
        {running ? 'Aktualisiert …' : 'Jetzt aktualisieren'}
      </button>
      {steps && (
        <div className="card absolute right-0 z-30 mt-3 w-[min(22rem,calc(100vw-2rem))] p-4" role="status" aria-live="polite">
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="label">Server-Quellen</span>
            {!running && (
              <button type="button" className="font-bold underline" onClick={() => setSteps(null)}>
                Schließen
              </button>
            )}
          </div>
          {steps.length === 0 ? (
            <p className="text-[0.9375rem]">Keine Quelle eingerichtet. Siehe Automatik.</p>
          ) : (
            <ul className="grid gap-2">
              {steps.map((s) => (
                <li key={s.id} className="grid grid-cols-[1fr_auto] gap-x-3 text-[0.9375rem]">
                  <span className="font-semibold">{s.label}</span>
                  <span
                    className={`tag ${s.state === 'ok' ? 'bg-blue text-white' : s.state === 'error' ? 'bg-ink text-white' : s.state === 'running' ? 'bg-pink' : 'bg-white'}`}
                  >
                    {{ waiting: 'wartet', running: 'läuft', ok: 'fertig', error: 'Fehler' }[s.state]}
                  </span>
                  {s.message && <span className="col-span-2 text-[0.8125rem] text-ink-soft">{s.message}</span>}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
