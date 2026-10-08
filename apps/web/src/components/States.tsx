import type { ReactNode } from 'react';
import { ApiError } from '../api.ts';

export function PageLoading() {
  return (
    <div className="grid gap-6" aria-busy="true" aria-label="Lädt">
      <div className="skeleton h-10 w-64" />
      <div className="grid gap-4 md:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="skeleton h-28" />
        ))}
      </div>
      <div className="skeleton h-64" />
    </div>
  );
}

export function Skeleton({ className = 'h-24' }: { className?: string }) {
  return <div className={`skeleton ${className}`} aria-hidden="true" />;
}

export function ErrorBox({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof ApiError || error instanceof Error ? error.message : 'Unbekannter Fehler.';
  return (
    <div role="alert" className="border-4 border-ink bg-white p-4 shadow-pink">
      <p className="label mb-1">Hat nicht geklappt</p>
      <p className="font-medium">{message}</p>
      {onRetry && (
        <button type="button" className="btn btn-sm mt-3" onClick={onRetry}>
          Noch mal laden
        </button>
      )}
    </div>
  );
}

/** Empty states say what happens next. */
export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="border-4 border-dashed border-ink p-5">
      <p className="text-lg font-extrabold">{title}</p>
      {children && <div className="mt-1 max-w-[65ch] text-ink-soft">{children}</div>}
    </div>
  );
}

export function Notice({ level, children }: { level: 'warn' | 'error' | 'info'; children: ReactNode }) {
  const tone = level === 'error' ? 'bg-ink text-white' : level === 'warn' ? 'bg-pink text-ink' : 'bg-blue text-white';
  return (
    <div role={level === 'error' ? 'alert' : 'status'} className={`flex items-start gap-3 border-3 border-ink px-4 py-3 ${tone}`}>
      <span className="label shrink-0 pt-0.5">{level === 'error' ? 'Fehler' : level === 'warn' ? 'Achtung' : 'Info'}</span>
      <span className="font-medium">{children}</span>
    </div>
  );
}
