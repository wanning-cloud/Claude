<?php

declare(strict_types=1);

namespace Cockpit;

use Cockpit\Repo\Metrics;

/** Additional values per platform page. They never count towards the reach. */
final class PlatformExtras
{
    private const LABELS = [
        'listeners' => 'Hörer',
        'engaged' => 'Engagierte Hörer',
        'followers' => 'Follower',
        'starts' => 'Starts',
        'streams' => 'Streams',
        'plays' => 'Plays',
        'fans' => 'Fans',
        'impressions' => 'Impressionen',
        'consumption_hours' => 'Hörstunden',
    ];

    public function __construct(private readonly Db $db, private readonly Metrics $metrics)
    {
    }

    /** @return list<array{metric: string, label: string, value: float|null}> */
    public function for(string $platform, string $from, string $to): array
    {
        return match ($platform) {
            'youtube' => $this->youtube($from, $to),
            'downloads' => $this->downloads($from, $to),
            'website' => [],
            default => $this->routine($platform, $from, $to),
        };
    }

    private function youtube(string $from, string $to): array
    {
        $minutes = $this->metrics->metricValue('youtube', 'estimatedMinutesWatched', $from, $to);
        // Averages are weighted by the views of each day, never summed.
        $weighted = fn (string $metric): ?float => $this->weighted($metric, $from, $to);
        $lifetime = $this->db->value(
            "SELECT SUM(value) FROM metric_totals t WHERE platform = 'youtube' AND metric = 'views' AND episode_id = 0
             AND captured_at = (SELECT MAX(captured_at) FROM metric_totals WHERE platform = 'youtube' AND metric = 'views' AND ref = t.ref)",
        );
        return [
            ['metric' => 'watchHours', 'label' => 'Wiedergabezeit (Stunden)', 'value' => $minutes === null ? null : round($minutes / 60, 1)],
            ['metric' => 'averageViewDuration', 'label' => 'Ø Wiedergabedauer (Sekunden)', 'value' => $weighted('averageViewDuration')],
            ['metric' => 'averageViewPercentage', 'label' => 'Ø Wiedergabeanteil (%)', 'value' => $weighted('averageViewPercentage')],
            ['metric' => 'likes', 'label' => 'Likes', 'value' => $this->metrics->metricValue('youtube', 'likes', $from, $to)],
            ['metric' => 'comments', 'label' => 'Kommentare', 'value' => $this->metrics->metricValue('youtube', 'comments', $from, $to)],
            ['metric' => 'subscribersGained', 'label' => 'Neue Abonnenten', 'value' => $this->metrics->metricValue('youtube', 'subscribersGained', $from, $to)],
            ['metric' => 'lifetimeViews', 'label' => 'Aufrufe gesamt (aktueller Zähler aller Videos)', 'value' => $lifetime === null ? null : (float) $lifetime],
        ];
    }

    private function weighted(string $metric, string $from, string $to): ?float
    {
        $row = $this->db->one(
            "SELECT SUM(a.value * v.value) AS num, SUM(v.value) AS den FROM metric_daily a
             JOIN metric_daily v ON v.episode_id = a.episode_id AND v.date = a.date AND v.platform = 'youtube' AND v.metric = 'views'
             WHERE a.platform = 'youtube' AND a.metric = ? AND a.episode_id = 0 AND a.date BETWEEN ? AND ?",
            [$metric, $from, $to],
        );
        return ($row === null || !(float) $row['den']) ? null : round((float) $row['num'] / (float) $row['den'], 1);
    }

    private function downloads(string $from, string $to): array
    {
        if ($this->metrics->platformValue('downloads', $from, $to) === null) {
            return [];
        }
        $out = [];
        $spotify = (float) $this->db->value("SELECT COALESCE(SUM(downloads), 0) FROM download_daily WHERE app = ? AND date BETWEEN ? AND ?", [Metrics::SPOTIFY_APP, $from, $to]);
        $out[] = [
            'metric' => 'spotifyApp',
            'label' => $this->metrics->spotifyHasData($from, $to) ? 'Davon Spotify-App (nicht in der Summe, steckt in Spotify)' : 'Davon Spotify-App',
            'value' => $spotify,
        ];
        foreach ($this->db->all('SELECT app, SUM(downloads) AS v FROM download_daily WHERE date BETWEEN ? AND ? AND app <> ? GROUP BY app ORDER BY v DESC LIMIT 6', [$from, $to, Metrics::SPOTIFY_APP]) as $row) {
            $out[] = ['metric' => 'app:' . $row['app'], 'label' => 'App: ' . $row['app'], 'value' => (float) $row['v']];
        }
        return $out;
    }

    private function routine(string $platform, string $from, string $to): array
    {
        $lead = Metrics::LEAD[$platform];
        $metricNames = array_unique(array_merge(
            array_column($this->db->all('SELECT DISTINCT metric FROM metric_daily WHERE platform = ?', [$platform]), 'metric'),
            array_column($this->db->all('SELECT DISTINCT metric FROM metric_period WHERE platform = ?', [$platform]), 'metric'),
        ));
        $out = [];
        foreach ($metricNames as $metric) {
            if ($metric === $lead) {
                continue;
            }
            $out[] = ['metric' => $metric, 'label' => self::LABELS[$metric] ?? $metric, 'value' => $this->metrics->metricValue($platform, (string) $metric, $from, $to)];
        }
        $followers = $this->db->value("SELECT value FROM metric_totals WHERE platform = ? AND metric = 'followers' ORDER BY captured_at DESC LIMIT 1", [$platform]);
        if ($followers !== null) {
            $out[] = ['metric' => 'followers', 'label' => 'Follower (aktuell)', 'value' => (float) $followers];
        }
        return $out;
    }

    /** @return list<string> */
    public static function notes(string $platform): array
    {
        return match ($platform) {
            'youtube' => ['Tageswerte aus YouTube Analytics kommen mit 1 bis 3 Tagen Verzug. Der aktuelle Gesamtzähler stammt aus der Data API.'],
            'spotify' => ['Spotify hat keine Schnittstelle für Creator. Die Werte holt die Routine „Podcast-Zahlen holen“ als CSV aus Spotify for Creators.'],
            'apple' => ['Apple zählt Geräte. Diese Werte zählen nicht in die Reichweite, weil Apple-Hörer schon in den Feed-Downloads stecken.', 'Bewertungen kommen automatisch ins Postfach. Antwort bei Apple nicht möglich.'],
            'amazon', 'deezer' => ['Zählt nicht in die Reichweite, weil diese Hörer schon in den Feed-Downloads stecken.', 'Die Werte holt ab Stufe 2 die Routine.'],
            'downloads' => ['Gezählt nach IAB 2.2 aus den Server-Logs: ab einer Minute übertragener Audiodaten, gleiche IP, App und Folge innerhalb von 24 Stunden einmal, ohne Bots und HEAD-Anfragen. IP-Adressen werden nicht gespeichert.'],
            'website' => ['Der Player auf monteur-podcast.de wird in Stufe 2 über einen cookielosen Beacon angebunden. Gezählt werden dann Starts mit mindestens 60 Sekunden Wiedergabe.'],
            default => [],
        };
    }
}
