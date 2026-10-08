<?php

declare(strict_types=1);

namespace Cockpit\Tests;

use Cockpit\App;
use Cockpit\Clock;
use Cockpit\Config;
use Cockpit\Crypto;
use Cockpit\Db;
use Cockpit\Http\Request;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

/** HTTP contract of the API, YouTube reply flow included. TEST DATA ONLY. */
final class ApiTest extends TestCase
{
    private Db $db;
    private FakeHttp $http;
    private App $app;
    private string $csrf;

    protected function setUp(): void
    {
        Clock::freeze('2026-10-08T12:00:00+02:00');
        $this->db = Support::db();
        Support::seedEpisodes($this->db);
        $key = base64_encode(str_repeat('k', 32));
        $config = Config::fromArray([
            'APP_ENV' => 'test', 'DEV_AUTH_USER' => 'markus', 'ADMIN_TOKEN_SECRET' => 'test-secret', 'CRON_KEY' => str_repeat('c', 32),
            'ENCRYPTION_KEY' => $key, 'GOOGLE_CLIENT_ID' => 'id', 'GOOGLE_CLIENT_SECRET' => 'secret',
            'GOOGLE_REDIRECT_URI' => 'https://monteur-podcast.de/podcast-admin/analytics/api/youtube/callback',
        ]);
        $this->http = new FakeHttp();
        $this->app = new App($config, $this->db, $this->http);
        $this->csrf = $this->call('GET', '/session')['csrf'];

        // A connected YouTube account with a valid access token.
        $crypto = new Crypto($key);
        $this->db->run(
            "INSERT INTO connections (platform, access_token_enc, refresh_token_enc, expires_at, updated_at) VALUES ('youtube', ?, ?, ?, ?)",
            [$crypto->encrypt('access'), $crypto->encrypt('refresh'), '2026-10-08T13:00:00+02:00', Clock::nowIso()],
        );
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function call(string $method, string $path, array $query = [], ?array $body = null, bool $csrf = true, int $expect = 200): array
    {
        $headers = $csrf && isset($this->csrf) ? ['x-csrf-token' => $this->csrf] : [];
        $response = $this->app->handle(new Request($method, $path, $query, $headers, $body === null ? '' : (string) json_encode($body)));
        self::assertSame($expect, $response->status, $response->body);
        return $response->body === '' ? [] : (array) json_decode($response->body, true);
    }

    private function youtubeComment(): int
    {
        $this->db->run(
            "INSERT INTO comments (platform, external_id, author_name, text, posted_at, status, video_id, episode_id) VALUES ('youtube', 'yt-c-1', 'Hörerin', 'Wie hoch ist die Kaution?', '2026-10-07T18:00:00+02:00', 'new', 'vid1', 1)",
        );
        return $this->db->lastId();
    }

    public function testHealthIsPublicAndEverythingElseNeedsLogin(): void
    {
        self::assertTrue($this->call('GET', '/health')['ok']);
        $app = new App(Config::fromArray(['APP_ENV' => 'prod', 'ADMIN_TOKEN_SECRET' => 'x', 'ADMIN_SESSION_KEY' => 'admin']), $this->db, $this->http);
        $response = $app->handle(new Request('GET', '/overview'));
        self::assertSame(401, $response->status);
        self::assertStringContainsString('loginUrl', $response->body);
    }

    public function testWritesNeedCsrfToken(): void
    {
        $id = $this->youtubeComment();
        $this->call('PATCH', "/comments/{$id}", [], ['status' => 'done'], csrf: false, expect: 403);
        self::assertSame('done', $this->call('PATCH', "/comments/{$id}", [], ['status' => 'done'])['status']);
    }

    public function testOverviewShapeAndMissingReasons(): void
    {
        $data = $this->call('GET', '/overview', ['range' => '7']);
        self::assertSame(['from' => '2026-10-02', 'to' => '2026-10-08'], $data['range']);
        self::assertSame(['from' => '2026-09-25', 'to' => '2026-10-01'], $data['previousRange']);
        self::assertNull($data['reach']['value']);
        self::assertCount(4, $data['reach']['parts']);
        foreach ($data['reach']['parts'] as $part) {
            self::assertNotEmpty($part['stamp']['missing'], "{$part['platform']} needs a reason");
        }
        self::assertCount(7, $data['daily']);
    }

    public function testRangeValidation(): void
    {
        $this->call('GET', '/overview', ['from' => '2026-10-05', 'to' => '2026-10-01'], expect: 400);
        $data = $this->call('GET', '/overview', ['from' => '2026-10-01', 'to' => '2026-12-31']);
        self::assertSame('2026-10-08', $data['range']['to'], 'future end is cut to today');
    }

    public function testYoutubeReplyIsSentAndMarksAnswered(): void
    {
        $id = $this->youtubeComment();
        $this->http->routes['POST https://www.googleapis.com/youtube/v3/comments'] = ['status' => 200, 'body' => '{"id":"yt-reply-9"}'];
        $comment = $this->call('POST', "/comments/{$id}/reply", [], ['text' => 'Zwei Monatsmieten, mehr dazu in Folge 5.']);
        self::assertSame('answered', $comment['status']);
        self::assertSame('Zwei Monatsmieten, mehr dazu in Folge 5.', $comment['replies'][0]['text']);
        $sent = json_decode((string) end($this->http->requests)['body'], true);
        self::assertSame(['snippet' => ['parentId' => 'yt-c-1', 'textOriginal' => 'Zwei Monatsmieten, mehr dazu in Folge 5.']], $sent);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reply.sent'"));
    }

    public function testFailedYoutubeReplyKeepsTextAndSaysWhy(): void
    {
        $id = $this->youtubeComment();
        $this->http->routes['POST https://www.googleapis.com/youtube/v3/comments'] = ['status' => 403, 'body' => '{"error":{"errors":[{"reason":"quotaExceeded"}]}}'];
        $error = $this->call('POST', "/comments/{$id}/reply", [], ['text' => 'Hallo'], expect: 502);
        self::assertStringContainsString('Tageskontingent', $error['error']);
        self::assertSame('failed', $this->db->value('SELECT state FROM reply_queue'));
        self::assertSame('Hallo', $this->db->value('SELECT text FROM reply_queue'));
        self::assertSame('new', $this->db->value('SELECT status FROM comments WHERE id = ?', [$id]));
    }

    public function testAppleReviewsCannotBeAnswered(): void
    {
        $this->db->run("INSERT INTO comments (platform, external_id, author_name, text, rating, posted_at) VALUES ('apple', 'de:1', 'A', 'Top', 5, '2026-10-07T18:00:00+02:00')");
        $error = $this->call('POST', '/comments/' . $this->db->lastId() . '/reply', [], ['text' => 'Danke'], expect: 422);
        self::assertSame('Antwort bei Apple nicht möglich.', $error['error']);
    }

    public function testInboxFilterAndNewCounter(): void
    {
        $this->youtubeComment();
        $this->db->run("INSERT INTO comments (platform, external_id, author_name, text, rating, posted_at) VALUES ('apple', 'de:1', 'A', 'Top', 5, '2026-10-06T18:00:00+02:00')");
        $all = $this->call('GET', '/comments');
        self::assertSame(2, $all['total']);
        self::assertSame(2, $all['newCount']);
        self::assertSame('youtube', $all['items'][0]['platform'], 'newest first');
        self::assertSame('api', $all['items'][0]['replyMode']);
        self::assertSame(1, $this->call('GET', '/comments', ['platform' => 'apple'])['total']);
        self::assertSame(1, $this->call('GET', '/comments', ['episode' => '1'])['total']);
    }

    public function testCronNeedsKeyAndSyncButtonRejectsUnknownSources(): void
    {
        $this->call('GET', '/cron', ['key' => 'wrong'], expect: 403);
        $this->call('POST', '/sync/backup', expect: 404);
        $this->call('POST', '/sync/nope', expect: 404);
        $this->http->routes['GET https://monteur-podcast.de/feed.xml'] = ['status' => 200, 'body' => (string) file_get_contents(__DIR__ . '/fixtures/feed.xml')];
        $result = $this->call('POST', '/sync/feed');
        self::assertTrue($result['ok']);
    }

    public function testImportEndpointsAndAliasConfirmation(): void
    {
        $content = (string) json_encode([
            'source' => 'spotify', 'kind' => 'metrics', 'captured_at' => '2026-10-08T08:31:00+02:00',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-07'],
            'episodes' => [['title' => 'Unbekannter Titel', 'metrics' => ['plays' => 5]]],
        ]);
        $preview = $this->call('POST', '/import/preview', [], ['type' => 'json', 'content' => $content]);
        self::assertTrue($preview['ok']);
        self::assertSame(['Unbekannter Titel'], $preview['summary']['unknownEpisodes']);
        self::assertTrue($this->call('POST', '/import/commit', [], ['type' => 'json', 'content' => $content])['imported']);
        $automation = $this->call('GET', '/automation');
        self::assertCount(1, $automation['pendingAliases']);
        $this->call('POST', '/aliases/' . $automation['pendingAliases'][0]['id'], [], ['episodeId' => 2]);
        self::assertSame([], $this->call('GET', '/automation')['pendingAliases']);
        $episodes = $this->call('GET', '/episodes', ['from' => '2026-10-01', 'to' => '2026-10-07']);
        $byId = array_column($episodes['rows'], null, 'id');
        self::assertSame(5, (int) $byId[2]['values']['spotify']);
        self::assertSame(0, (int) $byId[1]['values']['spotify']);
        self::assertNull($byId[1]['values']['youtube'], 'no YouTube data → null, not 0');
    }

    public function testSpotifyMappingNeedsLeadMetric(): void
    {
        $this->call('PUT', '/import/spotify-mapping', [], ['metrics' => ['listeners' => 'Hörer']], expect: 422);
        $saved = $this->call('PUT', '/import/spotify-mapping', [], ['date' => 'Datum', 'metrics' => ['plays' => 'Streams']]);
        self::assertSame('Streams', $saved['metrics']['plays']);
    }

    public function testEtagAnswers304(): void
    {
        $response = $this->app->handle(new Request('GET', '/health'));
        $again = $this->app->handle(new Request('GET', '/health', [], ['if-none-match' => $response->headers['ETag']]));
        self::assertSame(304, $again->status);
        self::assertSame('noindex, nofollow', $again->headers['X-Robots-Tag']);
    }
}
