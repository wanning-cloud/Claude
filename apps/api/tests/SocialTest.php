<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\App;
use Cockpit\Clock;
use Cockpit\Config;
use Cockpit\Connectors\FacebookConnector;
use Cockpit\Connectors\InstagramConnector;
use Cockpit\Connectors\MetaCommentsConnector;
use Cockpit\Connectors\YouTubeStatsConnector;
use Cockpit\Counting\SocialVisits;
use Cockpit\Counting\UserAgents;
use Cockpit\Crypto;
use Cockpit\Db;
use Cockpit\Google\YouTubeApi;
use Cockpit\Google\YouTubeAuth;
use Cockpit\Http\Request;
use Cockpit\Meta\MetaApi;
use Cockpit\Meta\MetaAuth;
use Cockpit\Repo\Metrics;
use Cockpit\Repo\Social;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

/**
 * Social media area: Instagram, Facebook page, YouTube Shorts. TEST DATA ONLY.
 * Main rule under test: no social value ever reaches the podcast numbers.
 */
final class SocialTest extends TestCase
{
    private const GRAPH = 'https://graph.facebook.com/v25.0/';
    private Db $db;
    private FakeHttp $http;

    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T12:00:00+02:00');
        $this->db = Support::db();
        Support::seedEpisodes($this->db);
        $this->http = new FakeHttp();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function config(): Config
    {
        return Config::fromArray([
            'APP_ENV' => 'test', 'DEV_AUTH_USER' => 'markus', 'ADMIN_TOKEN_SECRET' => 'test-secret', 'ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
            'GOOGLE_CLIENT_ID' => 'id', 'GOOGLE_CLIENT_SECRET' => 'secret', 'GOOGLE_REDIRECT_URI' => 'https://example.test/cb',
            'META_APP_ID' => 'app', 'META_APP_SECRET' => 'app-secret', 'META_REDIRECT_URI' => 'https://monteur-podcast.de/podcast-admin/analytics/api/meta/callback',
        ]);
    }

    private function connectMeta(bool $instagram = true): MetaAuth
    {
        $crypto = new Crypto(base64_encode(str_repeat('k', 32)));
        $this->db->run("INSERT INTO connections (platform, access_token_enc, account, updated_at) VALUES ('meta', ?, 'Test', ?)", [$crypto->encrypt('page-token'), Clock::nowIso()]);
        $this->db->setSetting('meta.page_id', 'page1');
        if ($instagram) {
            $this->db->setSetting('meta.ig_user_id', 'ig1');
            $this->db->setSetting('meta.ig_username', 'dermonteurpodcast');
        }
        return new MetaAuth($this->db, $this->http, $this->config(), $crypto);
    }

    private function youtubeApi(): YouTubeApi
    {
        $crypto = new Crypto(base64_encode(str_repeat('k', 32)));
        $this->db->run(
            "INSERT INTO connections (platform, access_token_enc, refresh_token_enc, expires_at, updated_at) VALUES ('youtube', ?, ?, ?, ?)",
            [$crypto->encrypt('access'), $crypto->encrypt('refresh'), '2026-10-08T13:00:00+02:00', Clock::nowIso()],
        );
        return new YouTubeApi($this->http, new YouTubeAuth($this->db, $this->http, $this->config(), $crypto));
    }

    private static function json(mixed $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode($data)];
    }

    // ---------------------------------------------------------------- YouTube Shorts split

    public function testContentTypeSplitSumsAndWeighsAverages(): void
    {
        $split = YouTubeStatsConnector::splitByContentType([
            ['day' => '2026-10-07', 'creatorContentType' => 'VIDEO_ON_DEMAND', 'views' => 30, 'averageViewDuration' => 600, 'likes' => 2],
            ['day' => '2026-10-07', 'creatorContentType' => 'LIVE_STREAM', 'views' => 10, 'averageViewDuration' => 200, 'likes' => 1],
            ['day' => '2026-10-07', 'creatorContentType' => 'SHORTS', 'views' => 500, 'averageViewDuration' => 20, 'likes' => 40],
        ]);
        self::assertCount(1, $split['podcast']);
        self::assertSame(40.0, $split['podcast'][0]['views'], 'Shorts are not in the podcast row');
        self::assertSame(3.0, $split['podcast'][0]['likes']);
        self::assertSame(500.0, (float) $split['podcast'][0]['averageViewDuration'], '(30×600 + 10×200) ÷ 40, weighted, not summed');
        self::assertSame(500.0, $split['shorts'][0]['views']);
        self::assertSame(1800, YouTubeStatsConnector::seconds('PT30M'));
        self::assertSame(59, YouTubeStatsConnector::seconds('PT59S'));
        self::assertNull(YouTubeStatsConnector::seconds(''));
    }

    public function testShortsLeaveThePodcastReachAndGoToSocial(): void
    {
        $api = $this->youtubeApi();
        // Old stored channel value from before the split (mixed with Shorts) must be replaced.
        $this->db->run("INSERT INTO metric_daily (episode_id, platform, metric, date, value, source, imported_at) VALUES (0, 'youtube', 'views', '2026-10-06', 999, 'api', ?)", [Clock::nowIso()]);
        $yt = 'https://www.googleapis.com/youtube/v3/';
        $this->http->routes = [
            "GET {$yt}playlistItems?part=snippet%2CcontentDetails&playlistId=UUhfzQnxKs6On8gsVByAPZRw" => self::json(['items' => [['contentDetails' => ['videoId' => 'long1']], ['contentDetails' => ['videoId' => 'short1']]]]),
            "GET {$yt}playlistItems?part=contentDetails&playlistId=UUSHhfzQnxKs6On8gsVByAPZRw" => self::json(['items' => [['contentDetails' => ['videoId' => 'short1']]]]),
            "GET {$yt}videos" => self::json(['items' => [
                ['id' => 'long1', 'snippet' => ['title' => 'Folge 01: Warum Monteure?', 'publishedAt' => '2026-10-01T06:00:00Z'], 'statistics' => ['viewCount' => '120'], 'contentDetails' => ['duration' => 'PT58M']],
                // A Short whose title mentions an episode must never become the episode video.
                ['id' => 'short1', 'snippet' => ['title' => 'Folge 02 in 30 Sekunden #fehlerderwoche', 'publishedAt' => '2026-10-05T09:00:00Z'], 'statistics' => ['viewCount' => '800', 'likeCount' => '50', 'commentCount' => '4'], 'contentDetails' => ['duration' => 'PT31S']],
            ]]),
            "GET {$yt}channels" => self::json(['items' => [['statistics' => ['subscriberCount' => '42']]]]),
            'GET https://youtubeanalytics.googleapis.com/v2/reports' => function (string $url): array {
                if (str_contains($url, 'creatorContentType')) {
                    return self::json(['columnHeaders' => array_map(static fn ($n) => ['name' => $n], ['day', 'creatorContentType', 'views', 'likes', 'comments']), 'rows' => [
                        ['2026-10-06', 'VIDEO_ON_DEMAND', 20, 1, 0],
                        ['2026-10-06', 'SHORTS', 300, 30, 2],
                        ['2026-10-07', 'SHORTS', 500, 20, 2],
                    ]]);
                }
                return self::json(['columnHeaders' => [['name' => 'day'], ['name' => 'views']], 'rows' => [['2026-10-06', 20]]]);
            },
        ];
        $outcome = (new YouTubeStatsConnector($this->db, $api, 'UChfzQnxKs6On8gsVByAPZRw'))->sync();
        self::assertStringContainsString('1 Shorts', $outcome->message);

        $metrics = new Metrics($this->db);
        self::assertSame(20.0, $metrics->platformValue('youtube', '2026-10-01', '2026-10-07'), 'podcast YouTube = long videos only, old mixed value replaced');
        self::assertSame(0.0, $metrics->reach('2026-10-07', '2026-10-07')['parts']['youtube'], 'a day with only Shorts views is 0 for the podcast');

        self::assertSame('long1', $this->db->value('SELECT youtube_video_id FROM episodes WHERE number = 1'));
        self::assertNull($this->db->value('SELECT youtube_video_id FROM episodes WHERE number = 2'), 'the Short is not episode 2');

        $page = (new Social($this->db))->page(['from' => '2026-10-01', 'to' => '2026-10-07'], ['from' => '2026-09-24', 'to' => '2026-09-30'], 'youtube_shorts', $this->stamps());
        $shorts = array_column($page['channels'], null, 'platform')['youtube_shorts'];
        self::assertSame(800.0, $shorts['views']['value']);
        self::assertCount(1, $page['posts']);
        self::assertSame('Fehler der Woche', $page['posts'][0]['series']);
        self::assertSame(round(54 / 800, 4), $page['posts'][0]['engagementRate'], 'Shorts: (likes + comments) ÷ views');
        self::assertSame(42.0, array_column($page['goals'], null, 'id')['youtube_subscribers']['value']);

        // Lifetime views on the YouTube podcast page leave the Short out.
        $extras = array_column((new \Cockpit\PlatformExtras($this->db, $metrics))->for('youtube', '2026-10-01', '2026-10-07'), null, 'metric');
        self::assertSame(120.0, $extras['lifetimeViews']['value']);
    }

    // ---------------------------------------------------------------- Meta

    public function testInsightsSkipRejectedMetricsAndTreatTinyPostsAsNoData(): void
    {
        $api = new MetaApi($this->http, $this->connectMeta());
        $this->http->routes = [
            'GET ' . self::GRAPH . 'm1/insights?metric=views%2Creach%2Cimpressions' => ['status' => 400, 'body' => '{"error":{"code":100,"message":"(#100) metric[2] must be one of the following values: views, reach"}}'],
            'GET ' . self::GRAPH . 'm1/insights?metric=views' => self::json(['data' => [['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 77]]]]]),
            'GET ' . self::GRAPH . 'm1/insights?metric=reach' => self::json(['data' => [['name' => 'reach', 'total_value' => ['value' => 50]]]]),
            'GET ' . self::GRAPH . 'm1/insights?metric=impressions' => ['status' => 400, 'body' => '{"error":{"code":100,"message":"(#100) impressions is deprecated"}}'],
            'GET ' . self::GRAPH . 's1/insights' => ['status' => 400, 'body' => '{"error":{"code":10,"message":"(#10) Not enough viewers for the media to show insights"}}'],
        ];
        self::assertSame(['views' => 77.0, 'reach' => 50.0], $api->insights('m1', ['views', 'reach', 'impressions']));
        self::assertSame(['impressions'], $api->skippedMetrics);
        self::assertSame([], $api->insights('s1', ['views']), 'fewer than 5 viewers: no data, not 0 and not an error');
        self::assertStringContainsString('appsecret_proof=', $this->http->requests[0]['url']);
    }

    public function testInstagramSyncStoresPostsAndAccountDaysButNoPodcastValues(): void
    {
        $auth = $this->connectMeta();
        $api = new MetaApi($this->http, $auth);
        $this->http->routes = [
            'GET ' . self::GRAPH . 'ig1?fields=followers_count' => self::json(['followers_count' => 87, 'username' => 'dermonteurpodcast']),
            'GET ' . self::GRAPH . 'ig1/media' => self::json(['data' => [
                ['id' => 'r1', 'caption' => 'Rechnung an Monteure #rechnungin60sekunden', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'permalink' => 'https://www.instagram.com/reel/r1/', 'timestamp' => '2026-10-06T07:00:00+0000', 'like_count' => 12, 'comments_count' => 3],
                ['id' => 'c1', 'caption' => 'Ganze Folge 07: Link in Bio', 'media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED', 'permalink' => 'https://www.instagram.com/p/c1/', 'timestamp' => '2026-10-07T07:00:00+0000', 'like_count' => 5, 'comments_count' => 0],
            ]]),
            'GET ' . self::GRAPH . 'r1/insights' => self::json(['data' => [['name' => 'views', 'values' => [['value' => 400]]], ['name' => 'reach', 'values' => [['value' => 200]]], ['name' => 'saved', 'values' => [['value' => 4]]], ['name' => 'shares', 'values' => [['value' => 1]]]]]),
            'GET ' . self::GRAPH . 'c1/insights' => self::json(['data' => [['name' => 'reach', 'values' => [['value' => 100]]], ['name' => 'saved', 'values' => [['value' => 6]]]]]),
            'GET ' . self::GRAPH . 'ig1/insights' => self::json(['data' => [['name' => 'reach', 'total_value' => ['value' => 30]], ['name' => 'views', 'total_value' => ['value' => 45]], ['name' => 'total_interactions', 'total_value' => ['value' => 6]]]]),
        ];
        $outcome = (new InstagramConnector($this->db, $api, $auth))->sync();
        self::assertSame(2, $outcome->items);
        self::assertStringContainsString('28 Tage Kontowerte', $outcome->message, 'first run fills 28 days');

        $page = (new Social($this->db))->page(['from' => '2026-10-02', 'to' => '2026-10-08'], ['from' => '2026-09-25', 'to' => '2026-10-01'], 'instagram', $this->stamps());
        $ig = array_column($page['channels'], null, 'platform')['instagram'];
        self::assertSame(87.0, $ig['followers']['value']);
        self::assertSame(180.0, $ig['reach']['value'], '6 days × 30 (yesterday is the last day with values)');
        $byId = array_column($page['posts'], null, 'format');
        self::assertSame('Rechnung in 60 Sekunden', $byId['reel']['series']);
        self::assertSame(Social::SERIES_EPISODE_CLIP, $byId['carousel']['series']);
        self::assertSame(round((12 + 3 + 4 + 1) / 200, 4), $byId['reel']['engagementRate'], 'IG: (likes + comments + saves + shares) ÷ reach');
        self::assertSame(round((20 + 11) / 300, 4), $ig['engagementRate'], 'pooled over posts: (20 + 11) ÷ (200 + 100)');
        self::assertSame(1, $page['clipsPerWeek'][7]['reels']);

        // Not a single row in the podcast tables.
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM metric_daily WHERE platform NOT IN ('youtube', 'spotify', 'apple', 'amazon', 'deezer')"));
        self::assertNull((new Metrics($this->db))->reach('2026-10-01', '2026-10-08')['value']);
    }

    public function testFacebookDailyValuesUsePacificDayEnds(): void
    {
        $daily = MetaApi::parseDaily(['data' => [['name' => 'page_media_view', 'values' => [
            ['value' => 10, 'end_time' => '2026-10-07T07:00:00+0000'],
            ['value' => 12, 'end_time' => '2026-10-08T07:00:00+0000'],
        ]]]]);
        self::assertSame(['2026-10-06' => 10.0, '2026-10-07' => 12.0], $daily['page_media_view']);
        self::assertSame('carousel', FacebookConnector::format(['attachments' => ['data' => [['media_type' => 'album']]]]));
        self::assertSame('text', FacebookConnector::format([]));
    }

    public function testMetaCommentsLandInTheInboxAndRepliesGoOutOnClick(): void
    {
        $auth = $this->connectMeta();
        $this->http->routes = [
            'GET ' . self::GRAPH . 'ig1/media' => self::json(['data' => [['id' => 'r1', 'comments_count' => 2, 'timestamp' => '2026-10-07T07:00:00+0000']]]),
            'GET ' . self::GRAPH . 'r1/comments' => self::json(['data' => [
                ['id' => 'ic1', 'text' => 'Gilt das auch für Kleinunternehmer?', 'username' => 'vermieterin_nrw', 'timestamp' => '2026-10-07T08:00:00+0000', 'replies' => ['data' => [
                    ['id' => 'ic2', 'text' => 'Ja, kommt in Folge 9.', 'username' => 'dermonteurpodcast', 'timestamp' => '2026-10-07T09:00:00+0000'],
                ]]],
            ]]),
            'GET ' . self::GRAPH . 'page1/published_posts' => self::json(['data' => [['id' => 'page1_p1', 'created_time' => '2026-10-07T10:00:00+0000', 'comments' => ['summary' => ['total_count' => 1]]]]]),
            'GET ' . self::GRAPH . 'page1_p1/comments' => self::json(['data' => [['id' => 'fc1', 'message' => 'Wo finde ich die Folge?', 'from' => ['id' => 'u9', 'name' => 'Disponent Jörg'], 'created_time' => '2026-10-07T11:00:00+0000']]]),
            'POST ' . self::GRAPH . 'fc1/comments' => self::json(['id' => 'fc2']),
        ];
        $outcome = (new MetaCommentsConnector($this->db, new MetaApi($this->http, $auth), $auth))->sync();
        self::assertSame(2, $outcome->items);
        self::assertSame('answered', $this->db->value("SELECT status FROM comments WHERE external_id = 'ic1'"));

        $app = new App($this->config(), $this->db, $this->http);
        $csrf = $this->call($app, 'GET', '/session')['csrf'];
        $social = $this->call($app, 'GET', '/comments', ['area' => 'social']);
        self::assertSame(2, $social['total']);
        self::assertSame(0, $this->call($app, 'GET', '/comments', ['area' => 'podcast'])['total']);
        $fb = array_column($social['items'], null, 'platform')['facebook'];
        self::assertSame('api', $fb['replyMode']);
        self::assertSame('social', $fb['area']);

        $after = $this->call($app, 'POST', '/comments/' . $fb['id'] . '/reply', [], ['text' => 'Link ist in der Beschreibung.'], $csrf);
        self::assertSame('answered', $after['status']);
        $post = array_values(array_filter($this->http->requests, static fn ($r) => $r['method'] === 'POST'))[0];
        self::assertStringContainsString('message=Link+ist+in+der+Beschreibung.', (string) $post['body']);
        self::assertStringContainsString('appsecret_proof=', (string) $post['body']);
    }

    public function testSocialEndpointGroupsLogAndSeries(): void
    {
        $app = new App($this->config(), $this->db, $this->http);
        $csrf = $this->call($app, 'GET', '/session')['csrf'];
        $page = $this->call($app, 'GET', '/social', ['range' => '28']);
        self::assertSame(['instagram', 'facebook', 'youtube_shorts'], array_column($page['channels'], 'platform'));
        self::assertTrue($page['connection']['metaConfigured']);
        self::assertFalse($page['connection']['metaConnected']);
        self::assertStringContainsString('Meta verbinden', $page['channels'][0]['stamp']['missing']);
        self::assertNull($page['channels'][0]['reach']['value'], 'no data → null, never 0');
        self::assertCount(8, $page['clipsPerWeek']);
        $this->call($app, 'GET', '/social', ['channel' => 'tiktok'], expect: 404);

        $this->call($app, 'PUT', '/social/groups/2026-10-07', [], ['answers' => 3], $csrf, 422);
        self::assertSame(3, $this->call($app, 'PUT', '/social/groups/2026-10-05', [], ['answers' => 3, 'note' => 'Gruppe Vermieter NRW'], $csrf)['answers']);
        $weeks = array_column($this->call($app, 'GET', '/social')['groups']['weeks'], null, 'week');
        self::assertSame(3, $weeks['2026-10-05']['answers']);

        $id = (new Social($this->db))->upsertPost('instagram', 'x1', ['format' => 'image', 'caption' => 'ohne Hashtag', 'publishedAt' => '2026-10-07T10:00:00+02:00']);
        self::assertSame('Mythos der Woche', $this->call($app, 'PATCH', "/social/posts/{$id}", [], ['series' => 'Mythos der Woche'], $csrf)['series']);
        (new Social($this->db))->upsertPost('instagram', 'x1', ['format' => 'image', 'caption' => '#fehlerderwoche', 'publishedAt' => '2026-10-07T10:00:00+02:00']);
        self::assertSame('Mythos der Woche', $this->db->value('SELECT series FROM social_posts WHERE id = ?', [$id]), 'manual series survives the next sync');

        $automation = $this->call($app, 'GET', '/automation');
        $meta = array_column($automation['connections'], null, 'platform')['meta'];
        self::assertTrue($meta['configured']);
        self::assertNotContains('instagram', array_column($automation['sources'], 'id'), 'social sources stay hidden until Meta is connected');
    }

    public function testMetaConnectPicksThePodcastPage(): void
    {
        $page = MetaAuth::pickPage([['id' => '1', 'name' => 'PFALZGRAF Immobilien'], ['id' => '2', 'name' => 'Der Monteur Podcast']], null);
        self::assertSame('2', $page['id']);
        $this->expectExceptionMessage('META_PAGE_ID');
        MetaAuth::pickPage([['id' => '1', 'name' => 'A'], ['id' => '2', 'name' => 'B']], null);
    }

    public function testUtmVisitsAreCountedForSocialOnly(): void
    {
        $visits = new SocialVisits($this->db, new UserAgents());
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Instagram 350.0';
        $visits->add(['time' => '2026-10-07T20:00:00+02:00', 'method' => 'GET', 'path' => '/folgen/07-welche-rechtsform/', 'query' => 'utm_source=instagram&utm_medium=social', 'status' => 200, 'ua' => $ua]);
        $visits->add(['time' => '2026-10-07T20:01:00+02:00', 'method' => 'GET', 'path' => '/', 'query' => 'utm_source=fb_gruppe', 'status' => 200, 'ua' => $ua]);
        $visits->add(['time' => '2026-10-07T20:02:00+02:00', 'method' => 'GET', 'path' => '/media/folge-07.mp3', 'query' => 'utm_source=instagram', 'status' => 200, 'ua' => $ua]);
        $visits->add(['time' => '2026-10-07T20:03:00+02:00', 'method' => 'GET', 'path' => '/', 'query' => 'utm_source=newsletter', 'status' => 200, 'ua' => $ua]);
        $visits->add(['time' => '2026-10-07T20:04:00+02:00', 'method' => 'GET', 'path' => '/', 'query' => 'utm_source=instagram', 'status' => 200, 'ua' => 'facebookexternalhit/1.1']);
        self::assertSame(2, $visits->counted);
        $this->db->run("INSERT INTO sync_runs (source, trigger, started_at, ok) VALUES ('downloads', 'cron', ?, 1)", [Clock::nowIso()]);
        $page = (new Social($this->db))->page(['from' => '2026-10-01', 'to' => '2026-10-08'], ['from' => '2026-09-23', 'to' => '2026-09-30'], 'all', $this->stamps());
        self::assertSame(2.0, $page['visits']['value']);
        self::assertSame(1.0, $page['groups']['visits']);
    }

    /** @return array<string, array{at: null, via: null}> */
    private function stamps(): array
    {
        return ['instagram' => ['at' => null, 'via' => null], 'facebook' => ['at' => null, 'via' => null], 'youtube_shorts' => ['at' => null, 'via' => null]];
    }

    private function call(App $app, string $method, string $path, array $query = [], ?array $body = null, string $csrf = '', int $expect = 200): array
    {
        $response = $app->handle(new Request($method, $path, $query, $csrf === '' ? [] : ['x-csrf-token' => $csrf], $body === null ? '' : (string) json_encode($body)));
        self::assertSame($expect, $response->status, $response->body);
        return $response->body === '' ? [] : (array) json_decode($response->body, true);
    }
}
