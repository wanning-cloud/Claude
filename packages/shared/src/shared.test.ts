import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { z } from 'zod';
import { formatChange, formatDate, formatNumber, PLATFORM_INFO, PLATFORMS } from './index.ts';
import { ImportPayload } from './schemas.ts';

describe('format', () => {
  it('formats German numbers and never shows 0 for missing data', () => {
    expect(formatNumber(1234)).toBe('1.234');
    expect(formatNumber(0)).toBe('0');
    expect(formatNumber(null)).toBe('–');
  });
  it('formats calendar dates without timezone drift', () => {
    expect(formatDate('2026-10-07')).toBe('07.10.2026');
    expect(formatDate('2026-10-07T23:30:00Z')).toBe('08.10.2026');
  });
  it('change needs both values', () => {
    expect(formatChange(110, 100)).toBe('+10 %');
    expect(formatChange(90, 100)).toBe('-10 %');
    expect(formatChange(10, 0)).toBeNull();
    expect(formatChange(null, 5)).toBeNull();
  });
});

describe('platforms', () => {
  it('only lead values of four platforms count towards reach', () => {
    expect(PLATFORMS.filter((p) => PLATFORM_INFO[p].inReach)).toEqual(['youtube', 'spotify', 'downloads', 'website']);
  });
});

describe('import schema', () => {
  const example = {
    source: 'spotify',
    kind: 'metrics',
    captured_at: '2026-10-12T08:31:00+02:00',
    period: { from: '2026-10-05', to: '2026-10-11' },
    show: { followers: 12 },
    episodes: [{ guid: 'monteur-podcast-folge-07', title: 'Folge 07', metrics: { plays: 3, listeners: 2 } }],
  };
  it('accepts the documented example', () => {
    expect(ImportPayload.safeParse(example).success).toBe(true);
  });
  it('rejects negative numbers and unknown sources', () => {
    expect(ImportPayload.safeParse({ ...example, source: 'myspace' }).success).toBe(false);
    expect(ImportPayload.safeParse({ ...example, episodes: [{ title: 'x', metrics: { plays: -1 } }] }).success).toBe(false);
  });
  it('the JSON Schema for PHP is up to date', () => {
    const onDisk = JSON.parse(readFileSync(new URL('../../../apps/api/schema/import.schema.json', import.meta.url), 'utf8'));
    expect(onDisk).toEqual(z.toJSONSchema(ImportPayload, { target: 'draft-2020-12' }));
  });
});
