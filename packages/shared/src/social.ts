import type { CommentPlatform, DataStamp, DateRange, Value } from './api.ts';
import { PLATFORM_INFO, type Platform } from './platforms.ts';

/**
 * Social media area: Instagram, the Facebook page and YouTube Shorts. These numbers live in their own
 * tables and on their own page. They are never added to the podcast reach.
 */
export const SOCIAL_PLATFORMS = ['instagram', 'facebook', 'youtube_shorts'] as const;
export type SocialPlatform = (typeof SOCIAL_PLATFORMS)[number];
export type SocialChannel = SocialPlatform | 'all';

export const SOCIAL_NAMES: Record<SocialPlatform, string> = {
  instagram: 'Instagram',
  facebook: 'Facebook-Seite',
  youtube_shorts: 'YouTube Shorts',
};

export type SocialFormat = 'reel' | 'carousel' | 'image' | 'video' | 'story' | 'short' | 'text';

export const FORMAT_LABEL: Record<SocialFormat, string> = {
  reel: 'Reel',
  carousel: 'Karussell',
  image: 'Bild',
  video: 'Video',
  story: 'Story',
  short: 'Short',
  text: 'Text',
};

export const ENGAGEMENT_DEFINITION =
  'Engagement-Rate = Interaktionen ÷ erreichte Konten. Interaktionen: Likes (Facebook: alle Reaktionen) + Kommentare + Gespeichert + Geteilt. YouTube Shorts: (Likes + Kommentare) ÷ Aufrufe, weil YouTube keine Reichweite je Short liefert. Mehrere Beiträge: Summe der Interaktionen ÷ Summe der Reichweite.';

export const SOCIAL_SEPARATE_NOTE = 'Eigene Statistik: Diese Zahlen zählen nie in die Podcast-Reichweite.';

export interface Compared {
  value: Value;
  previous: Value;
}

export interface SocialChannelSummary {
  platform: SocialPlatform;
  name: string;
  stamp: DataStamp;
  /** Followers at the end of the range vs. the day before the range. Null for YouTube Shorts (subscribers are channel wide). */
  followers: Compared;
  /** Accounts reached, sum of the daily values. Null for YouTube Shorts. */
  reach: Compared;
  views: Compared;
  interactions: Compared;
  engagementRate: Value;
  posts: number;
  stories: number;
  newComments: number;
}

export interface SocialPostMetrics {
  views: Value;
  reach: Value;
  likes: Value;
  comments: Value;
  saves: Value;
  shares: Value;
  interactions: Value;
}

export interface SocialPost {
  id: number;
  platform: SocialPlatform;
  format: SocialFormat;
  caption: string | null;
  permalink: string | null;
  thumbnail: string | null;
  publishedAt: string;
  series: string | null;
  seriesManual: boolean;
  metrics: SocialPostMetrics;
  engagementRate: Value;
  capturedAt: string | null;
}

export interface SeriesRow {
  series: string | null;
  format: SocialFormat;
  posts: number;
  engagementRate: Value;
}

export interface ClipWeek {
  /** Monday of the week. */
  week: string;
  /** Higher of reels and shorts: the same clip usually runs on both. */
  count: number;
  reels: number;
  shorts: number;
  target: number;
}

export interface SocialGoal {
  id: string;
  label: string;
  value: Value;
  target: number;
}

export interface SocialVisits {
  value: Value;
  previous: Value;
  bySource: Partial<Record<'instagram' | 'facebook' | 'youtube_shorts' | 'fb_gruppe', number>>;
  missing?: string;
}

export interface GroupWeek {
  week: string;
  answers: number | null;
  note: string | null;
}

export interface SocialPage {
  range: DateRange;
  previousRange: DateRange;
  channel: SocialChannel;
  channels: SocialChannelSummary[];
  posts: SocialPost[];
  top: SocialPost[];
  series: SeriesRow[];
  clipsPerWeek: ClipWeek[];
  goals: SocialGoal[];
  visits: SocialVisits;
  groups: { weeks: GroupWeek[]; visits: Value };
  connection: { metaConfigured: boolean; metaConnected: boolean; instagram: boolean; facebook: boolean; youtube: boolean };
}

/** "0,0812" → "8,1 %". */
export function formatRate(value: Value | undefined): string {
  if (value === null || value === undefined) return '–';
  return `${new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(value * 100)} %`;
}

/** Display name of any source: podcast platforms, Instagram, Facebook, YouTube Shorts. */
export function sourceName(p: CommentPlatform | SocialPlatform): string {
  if (p in SOCIAL_NAMES) return SOCIAL_NAMES[p as SocialPlatform];
  return PLATFORM_INFO[p as Platform].name;
}
