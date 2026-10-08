const TZ = 'Europe/Berlin';

const numberFmt = new Intl.NumberFormat('de-DE');
const pctFmt = new Intl.NumberFormat('de-DE', { style: 'percent', maximumFractionDigits: 0, signDisplay: 'exceptZero' });
const dateFmt = new Intl.DateTimeFormat('de-DE', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('de-DE', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
const shortFmt = new Intl.DateTimeFormat('de-DE', { timeZone: TZ, day: '2-digit', month: '2-digit' });
const weekdayFmt = new Intl.DateTimeFormat('de-DE', { timeZone: TZ, weekday: 'long' });

/** 1234 → "1.234"; null → "–". Never renders 0 for missing data. */
export function formatNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? '–' : numberFmt.format(value);
}

/** Change against the previous period, e.g. "+12 %". Null when either side is missing or previous is 0. */
export function formatChange(value: number | null, previous: number | null): string | null {
  if (value === null || previous === null || previous === 0) return null;
  return pctFmt.format((value - previous) / previous).replace(/\u00a0/g, ' ');
}

/** Accepts "YYYY-MM-DD" (calendar date) or a full ISO timestamp. */
function toDate(value: string): Date {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00Z`) : new Date(value);
}

export function formatDate(value: string | null | undefined): string {
  return value ? dateFmt.format(toDate(value)) : '–';
}

export function formatDateShort(value: string): string {
  return shortFmt.format(toDate(value));
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '–';
  const d = toDate(value);
  return `${dateFmt.format(d)}, ${timeFmt.format(d)}`;
}

export function formatWeekday(value: string): string {
  return weekdayFmt.format(toDate(value));
}

export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '–';
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  return `${m}:${String(s).padStart(2, '0')} min`;
}
