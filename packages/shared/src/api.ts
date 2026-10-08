import type { Platform } from './platforms.ts';

/** Response and request contracts of the PHP API under /api. */

export type Value = number | null;

export interface DateRange {
  from: string;
  to: string;
}

export type RangePreset = '7' | '28' | '90' | 'all' | 'custom';

export interface DataStamp {
  /** ISO timestamp of the newest data for this source, null if never fetched. */
  at: string | null;
  via: 'API' | 'Routine' | 'Server' | 'Import' | null;
  /** Human readable reason when a value is missing. */
  missing?: string;
}

export interface ReachPart {
  platform: Platform;
  value: Value;
  previous: Value;
  stamp: DataStamp;
}

export interface DailyPoint {
  date: string;
  values: Partial<Record<Platform, Value>>;
}

export interface EpisodeRef {
  id: number;
  number: number | null;
  title: string;
}

export interface TopEpisode extends EpisodeRef {
  reach: Value;
}

export interface Warning {
  level: 'warn' | 'error';
  text: string;
}

export interface Overview {
  range: DateRange;
  previousRange: DateRange;
  reach: { value: Value; previous: Value; parts: ReachPart[] };
  daily: DailyPoint[];
  topEpisodes: TopEpisode[];
  openComments: number;
  warnings: Warning[];
}

export interface EpisodeRow extends EpisodeRef {
  guid: string;
  publishedAt: string;
  thumbnail: string | null;
  youtubeVideoId: string | null;
  values: Partial<Record<Platform, Value>>;
  reach: Value;
}

export interface EpisodeList {
  range: DateRange;
  rows: EpisodeRow[];
  stamps: Partial<Record<Platform, DataStamp>>;
}

export type Milestone = 'd1' | 'd3' | 'd7' | 'd30';

export interface EpisodeDetail {
  episode: EpisodeRow & { durationSeconds: number | null; mp3Url: string };
  daily: DailyPoint[];
  /** Cumulative lead values n days after publication, null when the day has not been reached or data is missing. */
  milestones: Record<Milestone, Partial<Record<Platform, Value>>>;
  comments: Comment[];
}

export interface MetricValue {
  metric: string;
  label: string;
  value: Value;
}

export interface PlatformPage {
  platform: Platform;
  range: DateRange;
  lead: { value: Value; previous: Value };
  extras: MetricValue[];
  daily: DailyPoint[];
  topEpisodes: TopEpisode[];
  stamp: DataStamp;
  /** Platform specific notes, e.g. "Antwort bei Apple nicht möglich". */
  notes: string[];
}

export type CommentStatus = 'new' | 'answered' | 'done' | 'later';

export const COMMENT_STATUS_LABEL: Record<CommentStatus, string> = {
  new: 'Neu',
  answered: 'Beantwortet',
  done: 'Erledigt',
  later: 'Später',
};

export interface CommentReply {
  id: number;
  author: string;
  text: string;
  postedAt: string;
  fromHost: boolean;
  /** For queued Spotify replies: queued | sending | sent | failed. */
  state?: 'queued' | 'sending' | 'sent' | 'failed';
  error?: string | null;
}

export interface Comment {
  id: number;
  platform: Platform;
  episode: EpisodeRef | null;
  author: string;
  authorUrl: string | null;
  text: string;
  /** Star rating for Apple reviews. */
  rating: number | null;
  title: string | null;
  postedAt: string;
  status: CommentStatus;
  note: string | null;
  replies: CommentReply[];
  replyMode: 'api' | 'queue' | 'none';
  externalUrl: string | null;
}

export interface CommentList {
  items: Comment[];
  total: number;
  page: number;
  pageSize: number;
  newCount: number;
}

export interface CommentPatch {
  status?: CommentStatus;
  note?: string | null;
}

export interface ReplyRequest {
  text: string;
}

export interface SyncSourceState {
  id: string;
  label: string;
  kind: 'server' | 'routine';
  lastRun: { startedAt: string; finishedAt: string | null; ok: boolean; message: string | null } | null;
  lastSuccessAt: string | null;
  nextRun: string | null;
  stale: boolean;
  canRunNow: boolean;
}

export interface QueuedReply {
  id: number;
  commentId: number;
  platform: Platform;
  episode: EpisodeRef | null;
  commentAuthor: string;
  commentText: string;
  externalRef: string | null;
  text: string;
  approvedAt: string;
  sentAt: string | null;
}

export interface Connection {
  platform: Platform;
  connected: boolean;
  expiresAt: string | null;
  account: string | null;
}

export interface PendingAlias {
  id: number;
  platform: Platform;
  foreignTitle: string;
  suggestions: (EpisodeRef & { score: number })[];
}

export interface Automation {
  sources: SyncSourceState[];
  queue: QueuedReply[];
  connections: Connection[];
  pendingAliases: PendingAlias[];
  routineTrigger: { available: boolean; hint: string };
}

export interface SyncResult {
  source: string;
  ok: boolean;
  message: string;
  items: number;
  startedAt: string;
  finishedAt: string;
}

export interface ImportPreview {
  ok: boolean;
  errors: string[];
  duplicate: boolean;
  summary: {
    episodes: number;
    comments: number;
    replies: number;
    unknownEpisodes: string[];
    /** Recognised CSV export, e.g. "spotify_performance". */
    profile?: string | null;
    period?: DateRange | null;
  };
  checksum: string;
}

export interface Session {
  user: string;
  csrf: string;
  loginUrl: string;
  adminUrl: string;
}
