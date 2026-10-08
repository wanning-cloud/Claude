import { useLayoutEffect, useRef, useState } from 'react';
import { formatDateShort, formatNumber, PLATFORM_INFO, type DailyPoint, type Platform } from '@cockpit/shared';

/**
 * Own SVG charts (no chart library): series in blue, pink, ink and hatched ink, labels directly at the
 * line end or bar, at most four series. Loaded lazily, only on pages with charts.
 */

export const SERIES: Partial<Record<Platform, { stroke: string; dash?: string; fill: string }>> = {
  youtube: { stroke: 'var(--color-blue)', fill: 'var(--color-blue)' },
  spotify: { stroke: 'var(--color-pink)', fill: 'var(--color-pink)' },
  downloads: { stroke: 'var(--color-ink)', fill: 'var(--color-ink)' },
  website: { stroke: 'var(--color-ink)', dash: '6 5', fill: 'url(#hatch)' },
  apple: { stroke: 'var(--color-blue)', fill: 'var(--color-blue)' },
  amazon: { stroke: 'var(--color-blue)', fill: 'var(--color-blue)' },
  deezer: { stroke: 'var(--color-blue)', fill: 'var(--color-blue)' },
};

function HatchDefs() {
  return (
    <defs>
      <pattern id="hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <rect width="6" height="6" fill="var(--color-white)" />
        <line x1="0" y1="0" x2="0" y2="6" stroke="var(--color-ink)" strokeWidth="3" />
      </pattern>
    </defs>
  );
}

function useWidth<T extends HTMLElement>(): [React.RefObject<T | null>, number] {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(640);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const ro = new ResizeObserver(([entry]) => setWidth(Math.max(280, Math.floor(entry!.contentRect.width))));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, width];
}

function niceMax(v: number): number {
  if (v <= 0) return 1;
  const exp = 10 ** Math.floor(Math.log10(v));
  const n = v / exp;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * exp;
}

/** Horizontal bars, one per platform, value printed at the bar end. */
export function PlatformBars({ parts }: { parts: { platform: Platform; value: number | null }[] }) {
  const max = Math.max(1, ...parts.map((p) => p.value ?? 0));
  return (
    <ul className="grid gap-3" aria-label="Reichweite nach Portal">
      {parts.map((p) => {
        const s = SERIES[p.platform]!;
        const pct = p.value === null ? 0 : (p.value / max) * 100;
        return (
          <li key={p.platform} className="grid grid-cols-[7.5rem_1fr] items-center gap-3 sm:grid-cols-[10rem_1fr]">
            <span className="truncate text-[0.9375rem] font-bold">{PLATFORM_INFO[p.platform].name}</span>
            <span className="flex min-w-0 items-center gap-2">
              {p.value === null ? (
                <span className="font-mono text-[0.8125rem] font-semibold text-ink-soft">– keine Daten</span>
              ) : (
                <>
                  <svg className="h-7 min-w-0 flex-1 overflow-visible" aria-hidden="true" preserveAspectRatio="none">
                    <HatchDefs />
                    <rect x="0" y="0" width={`${Math.max(pct, 0.5)}%`} height="100%" fill={s.fill} stroke="var(--color-ink)" strokeWidth="3" />
                  </svg>
                  <span className="w-20 shrink-0 text-right text-[0.9375rem] font-extrabold tabular-nums">{formatNumber(p.value)}</span>
                </>
              )}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/** Daily lines per platform with direct labels at the right end. */
export function DailyLines({ daily, platforms, height = 260 }: { daily: DailyPoint[]; platforms: Platform[]; height?: number }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const series = platforms.filter((p) => daily.some((d) => d.values[p] !== null && d.values[p] !== undefined)).slice(0, 4);
  const pad = { top: 16, right: width < 480 ? 64 : 110, bottom: 28, left: 44 };
  const w = width - pad.left - pad.right;
  const h = height - pad.top - pad.bottom;
  const max = niceMax(Math.max(0, ...daily.flatMap((d) => series.map((p) => d.values[p] ?? 0))));
  const x = (i: number) => pad.left + (daily.length <= 1 ? w / 2 : (i / (daily.length - 1)) * w);
  const y = (v: number) => pad.top + h - (v / max) * h;
  const ticks = [0, max / 2, max];
  const labelEvery = Math.max(1, Math.ceil(daily.length / Math.max(2, Math.floor(w / 70))));

  // Direct labels: last known value per series, nudged apart vertically.
  const ends = series
    .map((p) => {
      let i = daily.length - 1;
      while (i >= 0 && (daily[i]!.values[p] ?? null) === null) i--;
      return { p, i, v: i >= 0 ? (daily[i]!.values[p] as number) : 0 };
    })
    .filter((e) => e.i >= 0)
    .sort((a, b) => y(a.v) - y(b.v));
  let last = -Infinity;
  const placed = ends.map((e) => {
    const ly = Math.max(y(e.v), last + 16);
    last = ly;
    return { ...e, ly };
  });

  return (
    <div ref={ref} className="w-full">
      {series.length === 0 ? (
        <p className="font-mono text-[0.8125rem] font-semibold text-ink-soft">Für diesen Zeitraum gibt es noch keine Tageswerte.</p>
      ) : (
        <svg width={width} height={height} role="img" aria-label="Verlauf pro Tag. Die Tabelle daneben enthält dieselben Werte.">
          <HatchDefs />
          {ticks.map((t) => (
            <g key={t}>
              <line x1={pad.left} x2={pad.left + w} y1={y(t)} y2={y(t)} stroke="var(--color-paper)" strokeWidth={t === 0 ? 3 : 2} />
              <text x={pad.left - 8} y={y(t) + 4} textAnchor="end" className="fill-ink-soft font-mono text-[11px] font-semibold">
                {formatNumber(t)}
              </text>
            </g>
          ))}
          {daily.map((d, i) =>
            i % labelEvery === 0 || i === daily.length - 1 ? (
              <text key={d.date} x={x(i)} y={height - 8} textAnchor="middle" className="fill-ink-soft font-mono text-[11px] font-semibold">
                {formatDateShort(d.date)}
              </text>
            ) : null,
          )}
          {series.map((p) => {
            const s = SERIES[p]!;
            const segments: string[] = [];
            let current = '';
            daily.forEach((d, i) => {
              const v = d.values[p];
              if (v === null || v === undefined) {
                if (current) segments.push(current);
                current = '';
              } else {
                current += `${current ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
              }
            });
            if (current) segments.push(current);
            return segments.map((dPath, k) => (
              <path key={`${p}-${k}`} d={dPath} fill="none" stroke={s.stroke} strokeWidth={3.5} strokeDasharray={s.dash} strokeLinejoin="round" />
            ));
          })}
          {placed.map((e) => (
            <g key={e.p}>
              <rect x={x(e.i) - 4} y={y(e.v) - 4} width={8} height={8} fill={SERIES[e.p]!.stroke} stroke="var(--color-ink)" strokeWidth={2} />
              <text x={x(e.i) + 10} y={e.ly + 4} className="fill-ink text-[12px] font-extrabold">
                {width < 480 ? PLATFORM_INFO[e.p].name.split(/[- ]/)[0] : PLATFORM_INFO[e.p].name}
              </text>
            </g>
          ))}
        </svg>
      )}
    </div>
  );
}

export default DailyLines;
