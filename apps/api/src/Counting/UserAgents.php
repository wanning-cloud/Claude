<?php

declare(strict_types=1);

namespace Cockpit\Counting;

/**
 * App and bot detection from the User-Agent.
 *
 * The OPAWG lists (github.com/opawg/user-agents-v2) are the reference. When data/opawg-apps.json and
 * data/opawg-bots.json exist (written by the "opawg" sync job), their patterns are used first; the
 * built-in lists below are the fallback so counting works from day one.
 */
final class UserAgents
{
    /** @var list<array{pattern: string, name: string}> */
    private array $apps;
    /** @var list<string> */
    private array $bots;

    private const BUILTIN_APPS = [
        ['^Spotify/', 'Spotify'],
        ['^AppleCoreMedia/.*\\(Apple ?TV|AppleTV', 'Apple Podcasts'],
        ['^Podcasts/|^Balados/|^Podcasti/|^Podcastit/|^Podcasturi/|^Podcasty/|^Podcast’ler/|^Podkaster/|^Podcaster/|^Podcast/|^ApplePodcasts|AirPodcasts|iTMS', 'Apple Podcasts'],
        ['^AppleCoreMedia/1\\..*\\(Macintosh', 'Apple Podcasts'],
        ['^AppleCoreMedia/', 'Apple Podcasts'],
        ['^Amazon Music|AmazonMusic|^Echo/|Alexa', 'Amazon Music'],
        ['^Deezer|DeezerSDK', 'Deezer'],
        ['^Overcast/', 'Overcast'],
        ['Pocket ?Casts', 'Pocket Casts'],
        ['^Castro ', 'Castro'],
        ['AntennaPod', 'AntennaPod'],
        ['PodcastAddict|Podcast Addict', 'Podcast Addict'],
        ['^Castbox|CastBox', 'Castbox'],
        ['^Podimo', 'Podimo'],
        ['^RSSRadio', 'RSSRadio'],
        ['^Player FM|PlayerFM', 'Player FM'],
        ['^Podbean', 'Podbean'],
        ['^Audible', 'Audible'],
        ['^Fountain', 'Fountain'],
        ['^Podverse', 'Podverse'],
        ['^Podcast Guru|PodcastGuru', 'Podcast Guru'],
        ['^Google-Podcast|GoogleChirp|^Google Podcasts', 'Google Podcasts'],
        ['YouTube Music|com\\.google\\.android\\.apps\\.youtube\\.music', 'YouTube Music'],
        ['^Mozilla/.*(Chrome|Firefox|Safari|Edg)/', 'Browser'],
    ];

    private const BUILTIN_BOTS = [
        'bot\\b', 'bot/', 'spider', 'crawler', 'crawl', 'slurp', 'facebookexternalhit', 'curl/', 'wget/',
        'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp/(?!.*(antennapod|podcast))',
        'libwww-perl', 'httpclient', 'axios/', 'node-fetch', 'headlesschrome', 'phantomjs', 'lighthouse',
        'pingdom', 'uptimerobot', 'statuscake', 'feedfetcher', 'feedly', 'inoreader', 'podcastindex',
        'podnews', 'podchaser', 'listennotes', 'op3\\.dev', 'castos', 'chartable', 'podtrac',
        'itms-podcasts-crawler', 'iTMS-Podcasts', 'AppleBot', 'Applebot', 'Amazon-Podcast', 'Spotify-Crawler',
        'Googlebot', 'Bingbot', 'ia_archiver', 'Podcasts/.*\\(iTunes Podcast Crawler\\)',
    ];

    public function __construct(?string $dataDir = null)
    {
        $this->apps = [];
        $this->bots = [];
        if ($dataDir !== null) {
            $this->apps = self::loadOpawg($dataDir . '/opawg-apps.json');
            $this->bots = array_column(self::loadOpawg($dataDir . '/opawg-bots.json'), 'pattern');
        }
        foreach (self::BUILTIN_APPS as [$pattern, $name]) {
            $this->apps[] = ['pattern' => $pattern, 'name' => $name];
        }
        $this->bots = [...$this->bots, ...self::BUILTIN_BOTS];
    }

    /** @return list<array{pattern: string, name: string}> */
    private static function loadOpawg(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        $out = [];
        foreach (is_array($data) ? ($data['entries'] ?? $data) : [] as $entry) {
            foreach ((array) ($entry['pattern'] ?? $entry['user_agents'] ?? []) as $pattern) {
                if (is_string($pattern) && @preg_match('#' . str_replace('#', '\\#', $pattern) . '#', '') !== false) {
                    $out[] = ['pattern' => $pattern, 'name' => (string) ($entry['name'] ?? $entry['app'] ?? 'Unbekannt')];
                }
            }
        }
        return $out;
    }

    public function isBot(string $ua): bool
    {
        if (trim($ua) === '' || $ua === '-') {
            return true;
        }
        foreach ($this->bots as $pattern) {
            if (preg_match('#' . str_replace('#', '\\#', $pattern) . '#i', $ua)) {
                return true;
            }
        }
        return false;
    }

    public function app(string $ua): string
    {
        foreach ($this->apps as $app) {
            if (preg_match('#' . str_replace('#', '\\#', $app['pattern']) . '#i', $ua)) {
                return $app['name'];
            }
        }
        return 'Andere';
    }
}
