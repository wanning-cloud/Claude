import { siApplepodcasts, siDeezer, siSpotify, siYoutube } from 'simple-icons';
import { PLATFORM_INFO, type Platform } from '@cockpit/shared';

const PATHS: Partial<Record<Platform, string>> = {
  youtube: siYoutube.path,
  spotify: siSpotify.path,
  apple: siApplepodcasts.path,
  deezer: siDeezer.path,
};

/** Platform logos in ink (Simple Icons). Own glyphs for platforms without a usable logo. */
export function PlatformIcon({ platform, size = 20 }: { platform: Platform; size?: number }) {
  const path = PATHS[platform];
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" className="shrink-0 fill-ink">
      {path ? (
        <path d={path} />
      ) : platform === 'downloads' ? (
        <path d="M10.5 2h3v11.2l4.1-4.1 2.1 2.1L12 18.9l-7.7-7.7 2.1-2.1 4.1 4.1V2ZM3 19.5h18V23H3v-3.5Z" />
      ) : platform === 'website' ? (
        <path d="M2 3h20v18H2V3Zm3 5v10h14V8H5Zm4 1.8 6 3.2-6 3.2V9.8Z" />
      ) : (
        // Amazon Music: no logo in Simple Icons; a plain note glyph.
        <path d="M9 3h12v13.5a3.5 3.5 0 1 1-3-3.46V8H12v10.5A3.5 3.5 0 1 1 9 15.04V3Z" />
      )}
    </svg>
  );
}

export function PlatformName({ platform, size }: { platform: Platform; size?: number }) {
  return (
    <span className="inline-flex items-center gap-2">
      <PlatformIcon platform={platform} size={size} />
      <span>{PLATFORM_INFO[platform].name}</span>
    </span>
  );
}
