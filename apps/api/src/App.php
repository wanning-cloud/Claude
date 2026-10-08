<?php

declare(strict_types=1);

namespace Cockpit;

use Cockpit\Auth\AdminSession;
use Cockpit\Connectors\AppleReviewsConnector;
use Cockpit\Connectors\BackupJob;
use Cockpit\Connectors\Connector;
use Cockpit\Connectors\DownloadsLogsConnector;
use Cockpit\Connectors\FeedConnector;
use Cockpit\Connectors\YouTubeCommentsConnector;
use Cockpit\Connectors\YouTubeStatsConnector;
use Cockpit\Counting\IabCounter;
use Cockpit\Counting\UserAgents;
use Cockpit\Google\YouTubeApi;
use Cockpit\Google\YouTubeAuth;
use Cockpit\Http\HttpException;
use Cockpit\Http\Request;
use Cockpit\Http\Response;
use Cockpit\Http\Router;
use Cockpit\Import\EpisodeMatcher;
use Cockpit\Import\ImportService;
use Cockpit\Import\SpotifyCsv;
use Cockpit\Repo\Comments;
use Cockpit\Repo\Metrics;
use Cockpit\Repo\Status;
use Cockpit\Sync\SyncRunner;

/** Wiring and HTTP handlers of the cockpit API. */
final class App
{
    private ?Db $db;
    private ?AdminSession $session = null;
    private ?SyncRunner $runner = null;
    private ?YouTubeAuth $youtubeAuth = null;
    /** Seconds one run may spend on catching up access logs: cron 200, a button click in the browser 40. */
    private int $logBudget = 200;

    public function __construct(
        private readonly Config $config,
        ?Db $db = null,
        private readonly HttpClient $http = new CurlHttpClient(),
    ) {
        $this->db = $db;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->config->get('DATABASE_PATH', dirname(__DIR__) . '/data/cockpit.sqlite'));
            $this->db->migrate();
        }
        return $this->db;
    }

    public function session(): AdminSession
    {
        return $this->session ??= new AdminSession($this->config);
    }

    public function youtubeAuth(): ?YouTubeAuth
    {
        if ($this->youtubeAuth === null && $this->config->get('GOOGLE_CLIENT_ID') !== null && $this->config->get('ENCRYPTION_KEY') !== null) {
            $this->youtubeAuth = new YouTubeAuth($this->db(), $this->http, $this->config, new Crypto($this->config->require('ENCRYPTION_KEY')));
        }
        return $this->youtubeAuth;
    }

    private function youtubeApi(): ?YouTubeApi
    {
        $auth = $this->youtubeAuth();
        return $auth !== null && $auth->isConnected() ? new YouTubeApi($this->http, $auth) : null;
    }

    public function runner(): SyncRunner
    {
        if ($this->runner === null) {
            $db = $this->db();
            /** @var array<string, Connector> $connectors */
            $connectors = [];
            $add = static function (Connector $c) use (&$connectors): void {
                $connectors[$c->id()] = $c;
            };
            $add(new FeedConnector($db, $this->http, $this->config->get('FEED_URL', 'https://monteur-podcast.de/feed.xml')));
            $channel = $this->config->get('YT_CHANNEL_ID', 'UChfzQnxKs6On8gsVByAPZRw');
            if ($this->youtubeAuth() !== null) {
                $api = new YouTubeApi($this->http, $this->youtubeAuth());
                $add(new YouTubeCommentsConnector($db, $api, $channel));
                $add(new YouTubeStatsConnector($db, $api, $channel));
            }
            $storefronts = array_values(array_filter(array_map('trim', explode(',', $this->config->get('APPLE_STOREFRONTS', 'de')))));
            $add(new AppleReviewsConnector($db, $this->http, $this->config->get('APPLE_PODCAST_ID', '6819469570'), $storefronts));
            if ($this->config->get('ACCESS_LOG_DIR') !== null) {
                $counter = new IabCounter($db, new UserAgents(dirname(__DIR__) . '/data'));
                $add(new DownloadsLogsConnector(
                    $db,
                    $counter,
                    self::resolveLogDir((string) $this->config->get('ACCESS_LOG_DIR'), __DIR__),
                    $this->config->get('ACCESS_LOG_GLOB', 'access_log*'),
                    $this->logBudget,
                ));
            }
            $add(new BackupJob($db, $this->config->get('BACKUP_DIR', dirname($this->config->get('DATABASE_PATH', dirname(__DIR__) . '/data/cockpit.sqlite')) . '/backups')));
            $this->runner = new SyncRunner($db, $connectors);
        }
        return $this->runner;
    }

    public function handle(Request $request): Response
    {
        try {
            $router = $this->router();
            $public = in_array($request->path, ['/health', '/cron'], true);
            if (!$public) {
                $this->session()->authenticate($request);
            }
            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = Response::error($e->status, $e->getMessage(), $e->extra);
        } catch (\Throwable $e) {
            error_log('[cockpit] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response = Response::error(500, $this->config->isDev() ? $e->getMessage() : 'Interner Fehler. Details stehen im Fehlerprotokoll des Servers.');
        }
        $response->headers += [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
        return $response->withEtag($request);
    }

    private function router(): Router
    {
        $r = new Router();
        $r->add('GET', '/health', fn (): Response => Response::json(['ok' => true, 'time' => Clock::nowIso(), 'db' => $this->db()->value('SELECT 1') === 1]));
        $r->add('GET', '/session', fn (): Response => Response::json([
            'user' => $this->session()->user(),
            'csrf' => $this->session()->csrf(),
            'loginUrl' => $this->session()->loginUrl(),
            'adminUrl' => $this->session()->adminUrl(),
            'newComments' => (new Comments($this->db()))->newCount(),
        ]));
        $r->add('GET', '/overview', fn (Request $q): Response => Response::json($this->overview($q)));
        $r->add('GET', '/episodes', fn (Request $q): Response => Response::json($this->episodes($q)));
        $r->add('GET', '/episodes/{id}', fn (Request $q): Response => Response::json($this->episode((int) $q->params['id'])));
        $r->add('POST', '/episodes/{id}/youtube', fn (Request $q): Response => Response::json($this->setYoutubeVideo((int) $q->params['id'], $q->json())));
        $r->add('GET', '/youtube/videos', fn (): Response => Response::json($this->youtubeVideos()));
        $r->add('GET', '/platforms/{platform}', fn (Request $q): Response => Response::json($this->platform($q->params['platform'], $q)));
        $r->add('GET', '/comments', fn (Request $q): Response => Response::json((new Comments($this->db()))->list([
            'platform' => $q->q('platform'),
            'episode' => $q->q('episode') === null ? null : (int) $q->q('episode'),
            'status' => $q->q('status'),
            'page' => (int) $q->q('page', '1'),
        ])));
        $r->add('GET', '/comments/{id}', fn (Request $q): Response => Response::json((new Comments($this->db()))->get((int) $q->params['id'])));
        $r->add('PATCH', '/comments/{id}', fn (Request $q): Response => Response::json($this->patchComment((int) $q->params['id'], $q->json())));
        $r->add('POST', '/comments/{id}/reply', fn (Request $q): Response => Response::json($this->reply((int) $q->params['id'], $q->json())));
        $r->add('GET', '/automation', fn (): Response => Response::json($this->automation()));
        $r->add('POST', '/sync/{source}', fn (Request $q): Response => Response::json($this->syncNow($q->params['source'])));
        $r->add('POST', '/import/preview', fn (Request $q): Response => Response::json($this->importService()->preview($this->importInput($q))));
        $r->add('POST', '/import/commit', fn (Request $q): Response => Response::json($this->importService()->commit($this->importInput($q), $this->session()->user())));
        $r->add('POST', '/import/csv-headers', fn (Request $q): Response => Response::json($this->csvHeaders($q->json())));
        $r->add('GET', '/import/spotify-mapping', fn (): Response => Response::json(json_decode($this->db()->setting('spotify.mapping') ?? 'null', true)));
        $r->add('PUT', '/import/spotify-mapping', fn (Request $q): Response => Response::json($this->saveSpotifyMapping($q->json())));
        $r->add('GET', '/routine/queue', fn (): Response => Response::json((new Comments($this->db()))->queue()));
        $r->add('POST', '/aliases/{id}', fn (Request $q): Response => Response::json($this->confirmAlias((int) $q->params['id'], $q->json())));
        $r->add('GET', '/youtube/connect', fn (): Response => $this->youtubeConnect());
        $r->add('GET', '/youtube/callback', fn (Request $q): Response => $this->youtubeCallback($q));
        $r->add('POST', '/youtube/disconnect', fn (): Response => Response::json($this->youtubeDisconnect()));
        $r->add('GET', '/cron', fn (Request $q): Response => Response::json($this->cron($q)));
        return $r;
    }

    /**
     * ACCESS_LOG_DIR may be absolute or relative to the FTP root of the account (all-inkl: "logs").
     * A relative path is searched upwards from the code directory, so nobody has to know the absolute
     * server path. Returns null when nothing is found; the connector then reports it in plain language.
     */
    public static function resolveLogDir(string $configured, string $from): ?string
    {
        if (str_starts_with($configured, '/')) {
            return is_dir($configured) ? $configured : null;
        }
        $relative = trim($configured, '/');
        for ($dir = $from; $dir !== dirname($dir); $dir = dirname($dir)) {
            if (@is_dir($dir . '/' . $relative)) {
                return $dir . '/' . $relative;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- ranges

    /** @return array{from: string, to: string} */
    public static function range(Request $request, ?Db $db = null): array
    {
        $today = Clock::today();
        $from = $request->q('from');
        $to = $request->q('to');
        if ($from !== null || $to !== null) {
            if (!Clock::isDate((string) $from) || !Clock::isDate((string) $to) || $from > $to) {
                throw new HttpException(400, 'Zeitraum ungültig. Bitte Datum von und bis angeben.');
            }
            if (Clock::daysBetween((string) $from, (string) $to) > 3660) {
                throw new HttpException(400, 'Zeitraum ist zu lang (höchstens 10 Jahre).');
            }
            return ['from' => (string) $from, 'to' => min((string) $to, $today)];
        }
        $preset = $request->q('range', '28');
        if ($preset === 'all') {
            $first = $db?->value('SELECT MIN(published_at) FROM episodes');
            return ['from' => $first === null ? Clock::addDays($today, -27) : Clock::dateOf((string) $first), 'to' => $today];
        }
        $days = in_array($preset, ['7', '28', '90'], true) ? (int) $preset : 28;
        return ['from' => Clock::addDays($today, -($days - 1)), 'to' => $today];
    }

    /** @return array{from: string, to: string} */
    public static function previous(array $range): array
    {
        $length = Clock::daysBetween($range['from'], $range['to']) + 1;
        return ['from' => Clock::addDays($range['from'], -$length), 'to' => Clock::addDays($range['from'], -1)];
    }

    // ---------------------------------------------------------------- read models

    private function overview(Request $request): array
    {
        $db = $this->db();
        $metrics = new Metrics($db);
        $status = new Status($db);
        $range = self::range($request, $db);
        $prev = self::previous($range);
        $now = $metrics->reach($range['from'], $range['to']);
        $before = $metrics->reach($prev['from'], $prev['to']);
        $parts = [];
        foreach (Metrics::IN_REACH as $platform) {
            $parts[] = ['platform' => $platform, 'value' => $now['parts'][$platform], 'previous' => $before['parts'][$platform], 'stamp' => $status->stamp($platform)];
        }
        $daily = [];
        foreach ($metrics->daily(Metrics::IN_REACH, $range['from'], $range['to']) as $date => $values) {
            $daily[] = ['date' => $date, 'values' => $values];
        }
        return [
            'range' => $range,
            'previousRange' => $prev,
            'reach' => ['value' => $now['value'], 'previous' => $before['value'], 'parts' => $parts],
            'daily' => $daily,
            'topEpisodes' => $this->topEpisodes($metrics->reachByEpisode($range['from'], $range['to']), 5),
            'openComments' => (new Comments($db))->newCount(),
            'warnings' => $status->warnings(array_keys($this->runner()->connectors())),
        ];
    }

    /** @param array<int, float> $values @return list<array<string, mixed>> */
    private function topEpisodes(array $values, int $limit): array
    {
        arsort($values);
        $out = [];
        foreach (array_slice($values, 0, $limit, true) as $id => $value) {
            $e = $this->db()->one('SELECT id, number, title FROM episodes WHERE id = ?', [$id]);
            if ($e !== null) {
                $out[] = ['id' => (int) $e['id'], 'number' => $e['number'] === null ? null : (int) $e['number'], 'title' => $e['title'], 'reach' => $value];
            }
        }
        return $out;
    }

    private function episodes(Request $request): array
    {
        $db = $this->db();
        $metrics = new Metrics($db);
        $status = new Status($db);
        $range = self::range($request, $db);
        $byPlatform = [];
        foreach (Metrics::ALL as $platform) {
            $byPlatform[$platform] = $metrics->byEpisode($platform, $range['from'], $range['to']);
        }
        $available = [];
        foreach (Metrics::ALL as $platform) {
            $available[$platform] = $metrics->hasEpisodeLevel($platform) && $metrics->platformValue($platform, $range['from'], $range['to']) !== null;
        }
        $rows = [];
        foreach ($db->all('SELECT * FROM episodes ORDER BY published_at DESC') as $e) {
            $id = (int) $e['id'];
            $values = [];
            foreach (Metrics::ALL as $platform) {
                $values[$platform] = $available[$platform] ? ($byPlatform[$platform][$id] ?? 0.0) : null;
            }
            $reachParts = array_filter(array_intersect_key($values, array_flip(Metrics::IN_REACH)), static fn ($v): bool => $v !== null);
            $rows[] = $this->episodeRow($e) + ['values' => $values, 'reach' => $reachParts === [] ? null : (float) array_sum($reachParts)];
        }
        $stamps = [];
        foreach (Metrics::ALL as $platform) {
            $stamps[$platform] = $status->stamp($platform);
        }
        return ['range' => $range, 'rows' => $rows, 'stamps' => $stamps];
    }

    private function episodeRow(array $e): array
    {
        $number = $e['number'] === null ? null : (int) $e['number'];
        return [
            'id' => (int) $e['id'],
            'guid' => $e['guid'],
            'number' => $number,
            'title' => $e['title'],
            'publishedAt' => $e['published_at'],
            'thumbnail' => $e['thumbnail_url'] ?? null,
            'youtubeVideoId' => $e['youtube_video_id'],
        ];
    }

    private function episode(int $id): array
    {
        $db = $this->db();
        $e = $db->one('SELECT * FROM episodes WHERE id = ?', [$id]);
        if ($e === null) {
            throw new HttpException(404, 'Folge nicht gefunden.');
        }
        $metrics = new Metrics($db);
        $published = Clock::dateOf((string) $e['published_at']);
        $to = Clock::today();
        // Totals since publication: an episode has no values before it is out, so the whole history is
        // used. That way routine periods that start a few days before the release still count.
        $since = '2000-01-01';
        $values = [];
        foreach (Metrics::ALL as $platform) {
            $all = $metrics->byEpisode($platform, $since, $to);
            $values[$platform] = !$metrics->hasEpisodeLevel($platform) || $metrics->platformValue($platform, $since, $to) === null ? null : ($all[$id] ?? 0.0);
        }
        $reachParts = array_filter(array_intersect_key($values, array_flip(Metrics::IN_REACH)), static fn ($v): bool => $v !== null);
        $daily = [];
        foreach ($metrics->daily(Metrics::IN_REACH, $published, $to, $id) as $date => $v) {
            $daily[] = ['date' => $date, 'values' => $v];
        }
        $comments = new Comments($db);
        return [
            'episode' => $this->episodeRow($e) + [
                'values' => $values,
                'reach' => $reachParts === [] ? null : (float) array_sum($reachParts),
                'durationSeconds' => $e['duration_s'] === null ? null : (int) $e['duration_s'],
                'mp3Url' => $e['mp3_url'],
            ],
            'daily' => $daily,
            'milestones' => $metrics->milestones($id, $published),
            'comments' => $comments->list(['episode' => $id])['items'],
        ];
    }

    private function platform(string $platform, Request $request): array
    {
        if (!in_array($platform, Metrics::ALL, true)) {
            throw new HttpException(404, 'Unbekanntes Portal.');
        }
        $db = $this->db();
        $metrics = new Metrics($db);
        $range = self::range($request, $db);
        $prev = self::previous($range);
        $daily = [];
        foreach ($metrics->daily([$platform], $range['from'], $range['to']) as $date => $v) {
            $daily[] = ['date' => $date, 'values' => $v];
        }
        return [
            'platform' => $platform,
            'range' => $range,
            'lead' => ['value' => $metrics->platformValue($platform, $range['from'], $range['to']), 'previous' => $metrics->platformValue($platform, $prev['from'], $prev['to'])],
            'extras' => (new PlatformExtras($db, $metrics))->for($platform, $range['from'], $range['to']),
            'daily' => $daily,
            'topEpisodes' => array_map(
                static fn (array $e): array => $e,
                $this->topEpisodes($metrics->byEpisode($platform, $range['from'], $range['to']), 10),
            ),
            'stamp' => (new Status($db))->stamp($platform),
            'notes' => PlatformExtras::notes($platform),
        ];
    }

    private function youtubeVideos(): array
    {
        return array_map(static fn (array $v): array => [
            'videoId' => $v['video_id'],
            'title' => $v['title'],
            'publishedAt' => $v['published_at'],
            'episodeId' => $v['episode_id'] === null ? null : (int) $v['episode_id'],
        ], $this->db()->all('SELECT v.*, e.id AS episode_id FROM youtube_videos v LEFT JOIN episodes e ON e.youtube_video_id = v.video_id ORDER BY v.published_at DESC'));
    }

    private function setYoutubeVideo(int $episodeId, array $body): array
    {
        $videoId = $body['videoId'] ?? null;
        if ($videoId !== null && (!is_string($videoId) || $this->db()->value('SELECT 1 FROM youtube_videos WHERE video_id = ?', [$videoId]) === null)) {
            throw new HttpException(422, 'Unbekanntes YouTube-Video.');
        }
        $this->db()->tx(function () use ($episodeId, $videoId): void {
            if ($videoId !== null) {
                $this->db()->run('UPDATE episodes SET youtube_video_id = NULL WHERE youtube_video_id = ?', [$videoId]);
            }
            $this->db()->run('UPDATE episodes SET youtube_video_id = ?, youtube_manual = 1 WHERE id = ?', [$videoId, $episodeId]);
            $this->db()->run('UPDATE comments SET episode_id = ? WHERE platform = ? AND video_id = ?', [$episodeId, 'youtube', $videoId]);
            $this->db()->audit($this->session()->user(), 'episode.youtube', (string) $episodeId, ['videoId' => $videoId]);
        });
        return ['ok' => true, 'message' => 'Zuordnung gespeichert. Die Tageswerte des Videos kommen mit dem nächsten YouTube-Lauf.'];
    }

    // ---------------------------------------------------------------- comments

    private function patchComment(int $id, array $body): array
    {
        $patch = [];
        if (array_key_exists('status', $body)) {
            if (!in_array($body['status'], ['new', 'answered', 'done', 'later'], true)) {
                throw new HttpException(422, 'Unbekannter Status.');
            }
            $patch['status'] = $body['status'];
        }
        if (array_key_exists('note', $body)) {
            if ($body['note'] !== null && (!is_string($body['note']) || mb_strlen($body['note']) > 4000)) {
                throw new HttpException(422, 'Notiz ist zu lang (höchstens 4.000 Zeichen).');
            }
            $patch['note'] = $body['note'];
        }
        return (new Comments($this->db()))->update($id, $patch, $this->session()->user());
    }

    private function reply(int $id, array $body): array
    {
        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
        if ($text === '' || mb_strlen($text) > 10000) {
            throw new HttpException(422, 'Die Antwort ist leer oder zu lang.');
        }
        return (new Comments($this->db()))->reply($id, $text, $this->session()->user(), $this->youtubeApi());
    }

    // ---------------------------------------------------------------- automation

    private function automation(): array
    {
        $db = $this->db();
        $matcher = new EpisodeMatcher($db);
        $pending = array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'platform' => $a['platform'],
            'foreignTitle' => $a['foreign_title'],
            'suggestions' => $matcher->suggestions((string) $a['foreign_title']),
        ], $db->all("SELECT * FROM episode_aliases WHERE status = 'pending' ORDER BY created_at"));
        $yt = $this->youtubeAuth()?->status() ?? ['connected' => false, 'expiresAt' => null, 'account' => null];
        $routineAvailable = $this->config->get('ROUTINE_FIRE_URL') !== null;
        return [
            'sources' => (new Status($db))->sources(array_keys($this->runner()->connectors())),
            'queue' => (new Comments($db))->queue(),
            'connections' => [
                ['platform' => 'youtube', 'connected' => $yt['connected'], 'expiresAt' => $yt['expiresAt'], 'account' => $yt['account'], 'configured' => $this->youtubeAuth() !== null],
            ],
            'pendingAliases' => $pending,
            'routineTrigger' => [
                'available' => $routineAvailable,
                'hint' => $routineAvailable
                    ? 'Startet die Routine „Podcast-Zahlen holen“ auf deinem Mac.'
                    : 'Im Claude-Chat „Hol die Podcast-Zahlen“ schreiben oder bei der geplanten Aufgabe auf „Jetzt ausführen“ klicken.',
            ],
        ];
    }

    private function syncNow(string $source): array
    {
        $this->logBudget = 40;
        if (!isset($this->runner()->connectors()[$source]) || $source === 'backup') {
            throw new HttpException(404, 'Diese Quelle lässt sich nicht von Hand starten oder ist nicht eingerichtet.');
        }
        return $this->runner()->run($source, 'button:' . $this->session()->user());
    }

    private function cron(Request $request): array
    {
        $key = $this->config->get('CRON_KEY');
        if ($key === null || strlen($key) < 24 || !hash_equals($key, (string) $request->q('key'))) {
            throw new HttpException(403, 'Kein Zugriff.');
        }
        @set_time_limit(280);
        $job = $request->q('job', 'due');
        if ($job === 'due') {
            return ['ran' => $this->runner()->runDue()];
        }
        if (!isset($this->runner()->connectors()[$job])) {
            throw new HttpException(404, 'Unbekannter Job.');
        }
        return ['ran' => [$this->runner()->run($job, 'cron')]];
    }

    // ---------------------------------------------------------------- import

    private function importService(): ImportService
    {
        return new ImportService($this->db(), dirname(__DIR__) . '/schema/import.schema.json');
    }

    /** @return array{type: string, content: string, source?: string, period?: array{from: string, to: string}|null} */
    private function importInput(Request $request): array
    {
        $body = $request->json();
        $period = $body['period'] ?? null;
        return [
            'type' => (string) ($body['type'] ?? ''),
            'content' => (string) ($body['content'] ?? ''),
            'source' => isset($body['source']) ? (string) $body['source'] : null,
            'period' => is_array($period) ? ['from' => (string) ($period['from'] ?? ''), 'to' => (string) ($period['to'] ?? '')] : null,
            'fileName' => isset($body['fileName']) ? basename((string) $body['fileName']) : null,
        ];
    }

    private function csvHeaders(array $body): array
    {
        try {
            $csv = SpotifyCsv::read((string) ($body['content'] ?? ''));
        } catch (\RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        $profile = \Cockpit\Import\CsvProfiles::detect($csv['headers']);
        return [
            'headers' => $csv['headers'],
            'sample' => array_slice($csv['rows'], 0, 3),
            'profile' => $profile,
            'profileLabel' => $profile === null ? null : \Cockpit\Import\CsvProfiles::LABELS[$profile],
        ];
    }

    private function saveSpotifyMapping(array $body): array
    {
        $metrics = $body['metrics'] ?? null;
        if (!is_array($metrics) || $metrics === []) {
            throw new HttpException(422, 'Mindestens eine Kennzahl zuordnen.');
        }
        if (!isset($metrics['plays'])) {
            throw new HttpException(422, 'Der Leitwert „plays“ (Plays bzw. Starts) muss einer Spalte zugeordnet sein.');
        }
        foreach ($metrics as $metric => $column) {
            if (!is_string($metric) || !preg_match('/^[a-z][a-z0-9_]*$/', $metric) || !is_string($column) || $column === '') {
                throw new HttpException(422, 'Zuordnung ungültig.');
            }
        }
        $mapping = [
            'date' => is_string($body['date'] ?? null) && $body['date'] !== '' ? $body['date'] : null,
            'episode' => is_string($body['episode'] ?? null) && $body['episode'] !== '' ? $body['episode'] : null,
            'metrics' => $metrics,
        ];
        $this->db()->setSetting('spotify.mapping', (string) json_encode($mapping, JSON_UNESCAPED_UNICODE));
        $this->db()->audit($this->session()->user(), 'spotify.mapping', null, $mapping);
        return $mapping;
    }

    private function confirmAlias(int $id, array $body): array
    {
        $episodeId = $body['episodeId'] ?? null;
        if ($episodeId !== null && (!is_int($episodeId) || $this->db()->value('SELECT 1 FROM episodes WHERE id = ?', [$episodeId]) === null)) {
            throw new HttpException(422, 'Unbekannte Folge.');
        }
        if ($this->db()->value("SELECT 1 FROM episode_aliases WHERE id = ? AND status = 'pending'", [$id]) === null) {
            throw new HttpException(404, 'Diese Zuordnung ist schon erledigt.');
        }
        (new EpisodeMatcher($this->db()))->confirm($id, $episodeId);
        $this->db()->audit($this->session()->user(), 'alias.confirm', (string) $id, ['episodeId' => $episodeId]);
        return ['ok' => true];
    }

    // ---------------------------------------------------------------- YouTube OAuth

    private function youtubeConnect(): Response
    {
        $auth = $this->youtubeAuth() ?? throw new HttpException(409, 'Google-Zugang ist noch nicht eingerichtet (GOOGLE_CLIENT_ID in der analytics.env).');
        $state = bin2hex(random_bytes(16));
        $this->db()->setSetting('oauth.state', $state . '|' . Clock::nowIso());
        return Response::redirect($auth->authorizationUrl($state));
    }

    private function youtubeCallback(Request $request): Response
    {
        $back = rtrim($this->config->get('PUBLIC_ORIGIN', ''), '/') . '/podcast-admin/analytics/automatik';
        $stored = explode('|', (string) $this->db()->setting('oauth.state'));
        $this->db()->run("DELETE FROM settings WHERE key = 'oauth.state'");
        $valid = count($stored) === 2 && hash_equals($stored[0], (string) $request->q('state'))
            && Clock::parse($stored[1]) > Clock::now()->modify('-15 minutes');
        if (!$valid) {
            return Response::redirect($back . '?youtube=fehler&grund=' . rawurlencode('Anmeldung abgelaufen. Bitte noch einmal „YouTube verbinden“ klicken.'));
        }
        if ($request->q('error') !== null) {
            return Response::redirect($back . '?youtube=fehler&grund=' . rawurlencode('Bei Google abgebrochen.'));
        }
        try {
            $auth = $this->youtubeAuth() ?? throw new \RuntimeException('Google-Zugang ist nicht eingerichtet.');
            $auth->exchangeCode((string) $request->q('code'));
            $channels = (new YouTubeApi($this->http, $auth))->get('channels', ['part' => 'snippet', 'mine' => 'true']);
            $channel = $channels['items'][0] ?? null;
            $expected = $this->config->get('YT_CHANNEL_ID', 'UChfzQnxKs6On8gsVByAPZRw');
            if ($channel === null || $channel['id'] !== $expected) {
                $auth->disconnect();
                throw new \RuntimeException('Das Google-Konto gehört nicht zum Kanal @DerMonteurPodcast. Bitte mit dem richtigen Konto anmelden.');
            }
            $auth->setAccount((string) $channel['snippet']['title']);
            $this->db()->audit('google', 'youtube.connected', $expected);
        } catch (\Throwable $e) {
            return Response::redirect($back . '?youtube=fehler&grund=' . rawurlencode($e->getMessage()));
        }
        return Response::redirect($back . '?youtube=verbunden');
    }

    private function youtubeDisconnect(): array
    {
        $this->youtubeAuth()?->disconnect();
        $this->db()->audit($this->session()->user(), 'youtube.disconnected');
        return ['ok' => true];
    }
}
