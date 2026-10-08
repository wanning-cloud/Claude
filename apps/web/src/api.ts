import type {
  Automation,
  CommentList,
  Comment,
  CommentPatch,
  EpisodeDetail,
  EpisodeList,
  ImportPreview,
  Overview,
  PlatformPage,
  SyncResult,
} from '@cockpit/shared';

export const API_BASE = `${import.meta.env.BASE_URL}api`;

export interface SessionInfo {
  user: string;
  csrf: string;
  loginUrl: string;
  adminUrl: string;
  newComments: number;
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public details: Record<string, unknown> = {},
  ) {
    super(message);
  }
}

let csrf = '';
export function setCsrf(token: string) {
  csrf = token;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`${API_BASE}${path}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(method !== 'GET' ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'Keine Verbindung zum Server. Bist du online?');
  }
  const data = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) {
    const message = (data && typeof data.error === 'string' && data.error) || `Fehler ${res.status}`;
    if (res.status === 401 && data?.loginUrl) {
      window.location.assign(String(data.loginUrl));
    }
    throw new ApiError(res.status, message, data ?? {});
  }
  return data as T;
}

export type RangeQuery = { range: string } | { from: string; to: string };

function rangeParams(r: RangeQuery): string {
  return new URLSearchParams(r as Record<string, string>).toString();
}

export const api = {
  session: () => request<SessionInfo>('GET', '/session'),
  overview: (r: RangeQuery) => request<Overview>('GET', `/overview?${rangeParams(r)}`),
  episodes: (r: RangeQuery) => request<EpisodeList>('GET', `/episodes?${rangeParams(r)}`),
  episode: (id: number) => request<EpisodeDetail>('GET', `/episodes/${id}`),
  setYoutube: (id: number, videoId: string | null) => request<{ ok: boolean; message: string }>('POST', `/episodes/${id}/youtube`, { videoId }),
  youtubeVideos: () => request<{ videoId: string; title: string; publishedAt: string; episodeId: number | null }[]>('GET', '/youtube/videos'),
  platform: (p: string, r: RangeQuery) => request<PlatformPage>('GET', `/platforms/${p}?${rangeParams(r)}`),
  comments: (q: Record<string, string>) => request<CommentList>('GET', `/comments?${new URLSearchParams(q)}`),
  comment: (id: number) => request<Comment>('GET', `/comments/${id}`),
  patchComment: (id: number, patch: CommentPatch) => request<Comment>('PATCH', `/comments/${id}`, patch),
  reply: (id: number, text: string) => request<Comment>('POST', `/comments/${id}/reply`, { text }),
  automation: () => request<Automation & { connections: (Automation['connections'][number] & { configured?: boolean })[] }>('GET', '/automation'),
  sync: (source: string) => request<SyncResult>('POST', `/sync/${source}`),
  importPreview: (body: ImportBody) => request<ImportPreview>('POST', '/import/preview', body),
  importCommit: (body: ImportBody) => request<ImportPreview & { imported: boolean; message: string }>('POST', '/import/commit', body),
  csvHeaders: (content: string) =>
    request<{ headers: string[]; sample: Record<string, string>[]; profile: string | null; profileLabel: string | null }>('POST', '/import/csv-headers', {
      content,
    }),
  spotifyMapping: () => request<SpotifyMapping | null>('GET', '/import/spotify-mapping'),
  saveSpotifyMapping: (m: SpotifyMapping) => request<SpotifyMapping>('PUT', '/import/spotify-mapping', m),
  confirmAlias: (id: number, episodeId: number | null) => request<{ ok: boolean }>('POST', `/aliases/${id}`, { episodeId }),
  disconnectYoutube: () => request<{ ok: boolean }>('POST', '/youtube/disconnect'),
};

export interface ImportBody {
  type: 'json' | 'csv';
  content: string;
  period?: { from: string; to: string } | null;
  fileName?: string;
}

export interface SpotifyMapping {
  date: string | null;
  episode: string | null;
  metrics: Record<string, string>;
}
