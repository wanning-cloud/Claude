import { z } from 'zod';

/**
 * Payload the routine "Podcast-Zahlen holen" posts to POST /api/import.
 * This is the single source of truth: scripts/export-schema.ts writes it as
 * JSON Schema to apps/api/schema/import.schema.json, which the PHP API validates against.
 */
const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD');
const isoDateTime = z.string().regex(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/, 'ISO 8601 mit Zeitzone');
const count = z.number().int().min(0);

export const ImportSource = z.enum(['spotify', 'apple', 'amazon', 'deezer']);
export const ImportKind = z.enum(['metrics', 'comments', 'reply_sent']);

export const ImportEpisode = z.object({
  guid: z.string().min(1).optional(),
  title: z.string().min(1),
  /** Metric name → value for the whole period. */
  metrics: z.record(z.string().regex(/^[a-z][a-z0-9_]*$/), count),
});

export const ImportComment = z.object({
  external_ref: z.string().min(1),
  episode_title: z.string().min(1),
  author: z.string(),
  text: z.string().min(1),
  posted_at: isoDateTime,
});

export const ImportReplySent = z.object({
  reply_id: z.number().int().positive(),
  external_ref: z.string().min(1).optional(),
  sent_at: isoDateTime,
});

export const ImportPayload = z.object({
  source: ImportSource,
  kind: ImportKind,
  captured_at: isoDateTime,
  period: z.object({ from: isoDate, to: isoDate }).optional(),
  show: z.object({ followers: count.optional() }).optional(),
  episodes: z.array(ImportEpisode).optional(),
  comments: z.array(ImportComment).optional(),
  replies: z.array(ImportReplySent).optional(),
});
export type ImportPayload = z.infer<typeof ImportPayload>;
