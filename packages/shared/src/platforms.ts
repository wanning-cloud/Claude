export const PLATFORMS = ['youtube', 'spotify', 'downloads', 'website', 'apple', 'amazon', 'deezer'] as const;
export type Platform = (typeof PLATFORMS)[number];

export interface PlatformInfo {
  id: Platform;
  name: string;
  /** Lead metric key; only lead metrics of `inReach` platforms count towards total reach. */
  leadMetric: string;
  leadLabel: string;
  /** Tooltip text: how the lead metric is defined. */
  definition: string;
  inReach: boolean;
  source: 'api' | 'routine' | 'server';
}

export const PLATFORM_INFO: Record<Platform, PlatformInfo> = {
  youtube: {
    id: 'youtube',
    name: 'YouTube',
    leadMetric: 'views',
    leadLabel: 'Aufrufe',
    definition: 'Aufrufe laut YouTube Analytics. Tageswerte kommen mit 1 bis 3 Tagen Verzug.',
    inReach: true,
    source: 'api',
  },
  spotify: {
    id: 'spotify',
    name: 'Spotify',
    leadMetric: 'plays',
    leadLabel: 'Plays',
    definition: 'Hauptwert aus dem CSV-Export von Spotify for Creators (Plays bzw. Starts).',
    inReach: true,
    source: 'routine',
  },
  downloads: {
    id: 'downloads',
    name: 'Feed-Downloads',
    leadMetric: 'downloads',
    leadLabel: 'Downloads',
    definition:
      'Downloads nach IAB 2.2: ab einer Minute übertragener Audiodaten, gleiche IP, App und Folge innerhalb von 24 Stunden zählt einmal, ohne Bots und HEAD-Anfragen. Downloads der Spotify-App fallen raus, sobald Spotify-Werte vorliegen.',
    inReach: true,
    source: 'server',
  },
  website: {
    id: 'website',
    name: 'Website-Player',
    leadMetric: 'starts60',
    leadLabel: 'Starts ≥ 60 s',
    definition: 'Starts im Player auf monteur-podcast.de mit mindestens 60 Sekunden Wiedergabe (eigene Definition).',
    inReach: true,
    source: 'server',
  },
  apple: {
    id: 'apple',
    name: 'Apple Podcasts',
    leadMetric: 'plays',
    leadLabel: 'Plays',
    definition: 'Plays laut Apple Podcasts Connect. Zählt nicht in die Reichweite: Diese Hörer stecken schon in den Feed-Downloads.',
    inReach: false,
    source: 'routine',
  },
  amazon: {
    id: 'amazon',
    name: 'Amazon Music',
    leadMetric: 'plays',
    leadLabel: 'Plays',
    definition: 'Werte aus Amazon Music for Podcasters. Zählt nicht in die Reichweite: Diese Hörer stecken schon in den Feed-Downloads.',
    inReach: false,
    source: 'routine',
  },
  deezer: {
    id: 'deezer',
    name: 'Deezer',
    leadMetric: 'streams',
    leadLabel: 'Streams',
    definition: 'Streams laut Analytics by Deezer. Zählt nicht in die Reichweite: Diese Hörer stecken schon in den Feed-Downloads.',
    inReach: false,
    source: 'routine',
  },
};

export const REACH_LABEL = 'Reichweite = Summe der Leitwerte. Keine Personenzahl.';
