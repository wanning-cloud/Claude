import { useSearchParams } from 'react-router';
import type { RangeQuery } from './api.ts';

export const PRESETS = [
  { id: '7', label: '7 Tage' },
  { id: '28', label: '28 Tage' },
  { id: '90', label: '90 Tage' },
  { id: 'all', label: 'Seit Start' },
] as const;

/** Range lives in the URL (?range=28 or ?from=…&to=…), so it survives reloads and links. */
export function useRange(): [RangeQuery, (next: RangeQuery) => void, string] {
  const [params, setParams] = useSearchParams();
  const from = params.get('from');
  const to = params.get('to');
  const query: RangeQuery = from && to ? { from, to } : { range: params.get('range') ?? '28' };
  const key = 'range' in query ? query.range : `${query.from}_${query.to}`;
  const set = (next: RangeQuery) => {
    const p = new URLSearchParams(params);
    p.delete('range');
    p.delete('from');
    p.delete('to');
    for (const [k, v] of Object.entries(next)) p.set(k, v);
    setParams(p, { replace: true });
  };
  return [query, set, key];
}

/** Keeps the current range when linking to another page. */
export function useRangeSuffix(): string {
  const [params] = useSearchParams();
  const p = new URLSearchParams();
  for (const k of ['range', 'from', 'to']) {
    const v = params.get(k);
    if (v) p.set(k, v);
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}
