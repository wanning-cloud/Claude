import type { ReactNode } from 'react';
import { formatChange, formatDate, formatDateTime, type DataStamp, type DateRange } from '@cockpit/shared';
import { PRESETS, useRange } from '../range.ts';

export function PageHead({ title, children, lead }: { title: string; children?: ReactNode; lead?: ReactNode }) {
  return (
    <div className="mb-8 flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
      <div className="min-w-0">
        <h1 className="title break-words">{title}</h1>
        {lead && <p className="mt-3 max-w-[65ch] text-ink-soft">{lead}</p>}
      </div>
      {children && <div className="flex flex-wrap items-center gap-3">{children}</div>}
    </div>
  );
}

export function SectionTitle({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-baseline justify-between gap-3">
      <h2 className="text-xl font-black tracking-tight uppercase md:text-2xl">{children}</h2>
      {aside}
    </div>
  );
}

/** "Stand 07.10.2026, 06:00 · API" or the reason why a value is missing. */
export function Stamp({ stamp, className = '' }: { stamp: DataStamp | undefined; className?: string }) {
  if (!stamp) return null;
  const text = stamp.at ? `Stand ${formatDateTime(stamp.at)} · ${stamp.via ?? 'Quelle unbekannt'}` : null;
  return (
    <p className={`font-mono text-[0.75rem] leading-snug font-semibold text-ink-soft ${className}`}>
      {text}
      {text && stamp.missing && <br />}
      {stamp.missing && <span className="text-ink">{stamp.missing}</span>}
    </p>
  );
}

export function Change({ value, previous }: { value: number | null; previous: number | null }) {
  const change = formatChange(value, previous);
  if (change === null) return <span className="font-mono text-[0.75rem] font-semibold text-ink-soft">keine Vorperiode</span>;
  const up = value! >= previous!;
  return (
    <span
      className={`tag ${up ? 'bg-blue text-white' : 'bg-white'}`}
      title="Veränderung zur gleich langen Vorperiode"
      aria-label={`Veränderung zur Vorperiode ${change}`}
    >
      {change}
    </span>
  );
}

export function RangePicker({ current }: { current: DateRange | undefined }) {
  const [query, setQuery] = useRange();
  const active = 'range' in query ? query.range : 'custom';
  return (
    <div className="flex flex-wrap items-center gap-3">
      <div role="group" aria-label="Zeitraum" className="flex border-3 border-ink shadow-2">
        {PRESETS.map((p, i) => (
          <button
            key={p.id}
            type="button"
            aria-pressed={active === p.id}
            onClick={() => setQuery({ range: p.id })}
            className={`min-h-11 px-3 text-[0.8125rem] font-extrabold uppercase ${i > 0 ? 'border-l-3 border-ink' : ''} ${
              active === p.id ? 'bg-ink text-white' : 'bg-white hover:bg-paper'
            }`}
          >
            {p.label}
          </button>
        ))}
      </div>
      <details className="relative">
        <summary className={`btn btn-sm list-none ${active === 'custom' ? 'btn-primary' : ''}`}>Frei wählen</summary>
        <form
          className="card absolute right-0 z-30 mt-3 grid w-72 gap-3 p-4"
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            const from = String(f.get('from'));
            const to = String(f.get('to'));
            if (from && to && from <= to) {
              setQuery({ from, to });
              (e.currentTarget.closest('details') as HTMLDetailsElement).open = false;
            }
          }}
        >
          <label className="grid gap-1">
            <span className="label">Von</span>
            <input className="field" type="date" name="from" required defaultValue={current?.from} />
          </label>
          <label className="grid gap-1">
            <span className="label">Bis</span>
            <input className="field" type="date" name="to" required defaultValue={current?.to} />
          </label>
          <button className="btn btn-primary" type="submit">
            Zeitraum anzeigen
          </button>
        </form>
      </details>
      {current && (
        <span className="font-mono text-[0.75rem] font-semibold text-ink-soft">
          {formatDate(current.from)} – {formatDate(current.to)}
        </span>
      )}
    </div>
  );
}

export function InfoTip({ text }: { text: string }) {
  return (
    <span className="group relative inline-flex">
      <button
        type="button"
        className="grid size-6 place-items-center border-2 border-ink bg-white font-mono text-[0.75rem] font-semibold"
        aria-label={`Erklärung: ${text}`}
      >
        ?
      </button>
      <span
        role="tooltip"
        className="pointer-events-none absolute top-8 left-0 z-30 hidden w-72 border-3 border-ink bg-white p-3 text-[0.8125rem] font-medium normal-case shadow-3 group-focus-within:block group-hover:block"
      >
        {text}
      </span>
    </span>
  );
}
