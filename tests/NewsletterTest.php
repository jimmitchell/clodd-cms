<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\EmailOctopus;
use CMS\Newsletter;
use CMS\Post;
use CMS\ShortcodeRenderer;

/**
 * Emailing new articles through EmailOctopus.
 *
 * Most of what can go wrong here goes wrong silently and in someone else's
 * inbox: the archive emailed on the first run, a note sent as if it were an
 * article, the same subscriber queued twice after a retry. So the choice of
 * *what* to send and *to whom* is pinned in detail; the HTTP underneath is
 * answered by a fake.
 */
final class NewsletterTest extends TempSiteTestCase
{
    /** @var list<array{0:string,1:string,2:?array}> requests the fake client saw */
    private array $requests = [];

    /** @var list<array{code:int, body:string}> scripted replies, in order */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->upsertSetting('site_url', 'https://example.test');
        $this->db->upsertSetting('newsletter_enabled_from', $this->ago(60 * 24));
    }

    // ── The client ───────────────────────────────────────────────────────────

    public function testSubscribeAddsTheReaderAsPending(): void
    {
        $this->replies = [['code' => 201, 'body' => '{}']];

        $this->assertTrue($this->client()->subscribe('reader@example.test'));
        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertSame('/lists/list-1/contacts', $this->requests[0][1]);
        $this->assertSame(['email_address' => 'reader@example.test', 'status' => 'pending'], $this->requests[0][2]);
    }

    /**
     * Already on the list is success — and nothing more is sent. An upsert
     * here would demote a confirmed subscriber to pending.
     */
    public function testAnAddressAlreadyOnTheListIsLeftAlone(): void
    {
        $this->replies = [['code' => 409, 'body' => '{"type":"conflict"}']];

        $this->assertTrue($this->client()->subscribe('reader@example.test'));
        $this->assertCount(1, $this->requests);
    }

    public function testAFailedSignupIsReportedAsOne(): void
    {
        $this->replies = [['code' => 500, 'body' => 'oops']];

        $this->assertFalse($this->client()->subscribe('reader@example.test'));
    }

    public function testSubscribersAreReadAcrossEveryPage(): void
    {
        $this->replies = [
            ['code' => 200, 'body' => json_encode(['data' => [['id' => 'a'], ['id' => 'b']], 'paging' => ['next' => ['starting_after' => 'cur-1']]])],
            ['code' => 200, 'body' => json_encode(['data' => [['id' => 'c']], 'paging' => ['next' => null]])],
        ];

        $this->assertSame(['a', 'b', 'c'], $this->client()->subscribedContactIds());
        $this->assertStringContainsString('status=subscribed', $this->requests[0][1]);
        $this->assertStringContainsString('starting_after=cur-1', $this->requests[1][1]);
    }

    /** Half a list would let the sender mark an article done with half unsent. */
    public function testAPageThatFailsLosesTheWholeList(): void
    {
        $this->replies = [
            ['code' => 200, 'body' => json_encode(['data' => [['id' => 'a']], 'paging' => ['next' => ['starting_after' => 'cur-1']]])],
            ['code' => 500, 'body' => ''],
        ];

        $this->assertNull($this->client()->subscribedContactIds());
    }

    public function testConfigurationNeedsAllThreeSettings(): void
    {
        $full = ['emailoctopus_api_key' => 'k', 'emailoctopus_list_id' => 'l', 'emailoctopus_automation_id' => 'a'];
        $this->assertTrue(EmailOctopus::isConfigured($full));

        foreach (array_keys($full) as $missing) {
            $this->assertFalse(EmailOctopus::isConfigured([$missing => ''] + $full), "{$missing} missing");
        }
    }

    // ── Which article ────────────────────────────────────────────────────────

    public function testANewArticleIsPickedUpOnceItsGracePeriodIsOver(): void
    {
        $post = $this->publishedPost('fresh', $this->ago(20));

        $this->assertSame($post->id, $this->newsletter()->nextArticle()?->id);
    }

    /** The window for fixing the typo you spot the moment it goes live. */
    public function testAnArticleStillInItsGracePeriodWaits(): void
    {
        $this->publishedPost('too-fresh', $this->ago(2));

        $this->assertNull($this->newsletter()->nextArticle());
    }

    /** Without the cutoff, the first run emails the whole archive. */
    public function testNothingPublishedBeforeTheFeatureWasEnabledIsSent(): void
    {
        $this->db->upsertSetting('newsletter_enabled_from', $this->ago(30));
        $this->publishedPost('from-the-archive', $this->ago(45));

        $this->assertNull($this->newsletter()->nextArticle());
    }

    public function testNothingIsSentUntilTheFeatureHasBeenEnabled(): void
    {
        $this->db->upsertSetting('newsletter_enabled_from', '');
        $this->publishedPost('fresh', $this->ago(20));

        $this->assertNull($this->newsletter()->nextArticle());
    }

    public function testAnArticleMissedForDaysIsNotSentLate(): void
    {
        $this->db->upsertSetting('newsletter_enabled_from', $this->ago(60 * 24 * 30));
        $this->publishedPost('last-week', $this->ago(60 * 24 * (Newsletter::MAX_AGE_DAYS + 1)));

        $this->assertNull($this->newsletter()->nextArticle());
    }

    public function testNotesPhotosAndRepliesAreNeverSent(): void
    {
        $this->publishedPost('an-aside', $this->ago(20), title: '', postKind: 'aside');
        $this->publishedPost('a-photo', $this->ago(20), title: '', postKind: 'photo');
        $reply = $this->publishedPost('a-titled-reply', $this->ago(20));
        $reply->saveContexts([['kind' => 'in-reply-to', 'url' => 'https://elsewhere.test/post']]);

        $this->assertNull($this->newsletter()->nextArticle());
    }

    public function testAnArticleOptedOutIsNotSent(): void
    {
        $post = $this->publishedPost('private-ish', $this->ago(20));
        $post->newsletter_skip = 1;
        $post->save();

        $this->assertNull($this->newsletter()->nextArticle());
    }

    public function testDraftsAndScheduledPostsAreNotSent(): void
    {
        $this->publishedPost('a-draft', $this->ago(20), 'draft');
        $this->publishedPost('a-scheduled', $this->ago(20), 'scheduled');

        $this->assertNull($this->newsletter()->nextArticle());
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    public function testEverySubscriberGetsTheFieldsAndThenTheAutomation(): void
    {
        $post = $this->publishedPost('fresh', $this->ago(20));
        $fake = $this->fakeSender(['a', 'b']);

        $result = $this->newsletter($fake)->send($post);

        $this->assertSame(['subscribers' => 2, 'queued' => 2, 'skipped' => 0, 'failed' => 0, 'done' => true], $result);
        $this->assertSame(['fields:a', 'queue:a', 'fields:b', 'queue:b'], $fake->calls);
        $this->assertNotNull(Post::findById($this->db, (int) $post->id)?->newsletter_at);
        $this->assertNull($this->newsletter($fake)->nextArticle(), 'a sent article is not picked up again');
    }

    /**
     * A run that failed for some subscribers is not marked done, and the next
     * one queues only those — never the ones who already have it.
     */
    public function testARetryReachesOnlyTheSubscribersWhoWereMissed(): void
    {
        $post = $this->publishedPost('fresh', $this->ago(20));

        $flaky = $this->fakeSender(['a', 'b', 'c'], failFor: ['b']);
        $first = $this->newsletter($flaky)->send($post);
        $this->assertFalse($first['done']);
        $this->assertNull(Post::findById($this->db, (int) $post->id)?->newsletter_at);

        $healthy = $this->fakeSender(['a', 'b', 'c']);
        $second  = $this->newsletter($healthy)->send($post);

        $this->assertSame(['fields:b', 'queue:b'], $healthy->calls);
        $this->assertSame(2, $second['skipped']);
        $this->assertTrue($second['done']);
    }

    public function testAnUnreadableListSendsNothingAndIsRetried(): void
    {
        $post = $this->publishedPost('fresh', $this->ago(20));
        $fake = $this->fakeSender(null);

        $this->assertNull($this->newsletter($fake)->send($post));
        $this->assertSame([], $fake->calls);
        $this->assertSame($post->id, $this->newsletter($fake)->nextArticle()?->id);
    }

    /**
     * The contact fields hold one article at a time, so the next article waits
     * until the last one's emails have had time to render.
     */
    public function testANewArticleWaitsOutTheGapAfterTheLastSend(): void
    {
        $first = $this->publishedPost('first', $this->ago(40));
        $this->newsletter($this->fakeSender(['a']))->send($first);

        $this->publishedPost('second', $this->ago(20));

        $this->assertNull($this->newsletter()->nextArticle());

        $this->db->update('newsletter_deliveries', ['queued_at' => $this->ago(Newsletter::GAP_MINUTES + 1)], 'post_id = :id', ['id' => $first->id]);
        $this->assertSame('second', $this->newsletter()->nextArticle()?->slug);
    }

    /** A half-finished article is finished before another is started. */
    public function testAnArticleHalfSentGoesBeforeANewOne(): void
    {
        $older = $this->publishedPost('older', $this->ago(40));
        $this->newsletter($this->fakeSender(['a', 'b'], failFor: ['b']))->send($older);

        $this->publishedPost('newer', $this->ago(20));

        $this->assertSame('older', $this->newsletter()->nextArticle()?->slug);
    }

    public function testTheFieldsCarryAnAbsoluteLinkAndPicture(): void
    {
        $post = $this->publishedPost('with-a-picture', '2026-03-04 12:00:00', content: 'Words.');
        $post->excerpt            = 'A <em>short</em> summary.';
        $post->featured_image_url = '/media/2026/03/cover.jpg';

        $fields = $this->newsletter()->fieldsFor($post);

        $this->assertSame('With a picture', $fields[EmailOctopus::FIELD_TITLE]);
        $this->assertSame('https://example.test/2026/03/04/with-a-picture/', $fields[EmailOctopus::FIELD_URL]);
        $this->assertSame('A short summary.', $fields[EmailOctopus::FIELD_EXCERPT]);
        $this->assertSame('https://example.test/media/2026/03/cover.jpg', $fields[EmailOctopus::FIELD_IMAGE]);
    }

    public function testAPictureWithAnUnsafeSchemeIsDropped(): void
    {
        $post = $this->publishedPost('odd-picture', '2026-03-04 12:00:00');
        $post->featured_image_url = 'javascript:alert(1)';

        $this->assertSame('', $this->newsletter()->fieldsFor($post)[EmailOctopus::FIELD_IMAGE]);
    }

    // ── The form ─────────────────────────────────────────────────────────────

    public function testTheShortcodeRendersTheFormOnlyWhenConfigured(): void
    {
        $renderer = new ShortcodeRenderer($this->db, $this->root . '/content/media', dirname(__DIR__) . '/templates');

        $this->assertSame('', $renderer->render('<p>[subscribe]</p>'));

        $this->configure();
        $html = $renderer->render('<p>[subscribe]</p>');
        $this->assertStringContainsString('action="/subscribe.php"', $html);
        $this->assertStringContainsString('name="website"', $html, 'the honeypot is part of the form');
    }

    public function testTheFormFollowsArticlesAndNotNotes(): void
    {
        $this->configure();
        $this->config['paths']['templates'] = dirname(__DIR__) . '/templates';
        $builder = new Builder($this->config, $this->db);

        $article = $this->publishedPost('an-article', '2026-03-04 12:00:00');
        $note    = $this->publishedPost('a-note', '2026-03-04 13:00:00', title: '', postKind: 'aside');
        $builder->buildPost($article);
        $builder->buildPost($note);

        $articleHtml = (string) file_get_contents($this->outputPath('posts/2026/03/04/an-article/index.html'));
        $noteHtml    = (string) file_get_contents($this->outputPath('posts/2026/03/04/a-note/index.html'));

        $this->assertStringContainsString('action="/subscribe.php"', $articleHtml);
        $this->assertStringContainsString('value="/2026/03/04/an-article/"', $articleHtml, 'the no-JS reply links back to the article');
        $this->assertStringNotContainsString('action="/subscribe.php"', $noteHtml);
    }

    /**
     * subscribe.php is public, so it carries the invariants CLAUDE.md sets for
     * every unauthenticated entry point: no rendered errors, and the lockout
     * checked before any real work. It reads the live config.php, so the
     * ordering is pinned from the source rather than by running it.
     */
    public function testTheSignupEndpointChecksBeforeItCallsOut(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/subscribe.php');

        $displayErrors = strpos($src, "ini_set('display_errors', '0')");
        $autoload      = strpos($src, "require CMS_ROOT . '/vendor/autoload.php'");
        $lockout       = strpos($src, 'Auth::isLockedOutIn(');
        $honeypot      = strpos($src, "\$_POST['website']");
        $callOut       = strpos($src, '$client->subscribe(');

        $this->assertNotFalse($displayErrors);
        $this->assertLessThan($autoload, $displayErrors, 'display_errors must be off before anything can throw');
        $this->assertNotFalse($lockout);
        $this->assertLessThan($callOut, $lockout, 'the lockout is checked before EmailOctopus is called');
        $this->assertLessThan($callOut, $honeypot, 'the honeypot is checked before EmailOctopus is called');
        $this->assertStringContainsString('Auth::SCOPE_SUBSCRIBE', $src);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function configure(): void
    {
        $this->db->upsertSetting('emailoctopus_api_key', 'key');
        $this->db->upsertSetting('emailoctopus_list_id', 'list-1');
        $this->db->upsertSetting('emailoctopus_automation_id', 'auto-1');
    }

    /** A UTC timestamp $minutes ago, in the shape published_at is stored. */
    private function ago(int $minutes): string
    {
        return gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    }

    /** A client whose HTTP is answered from $this->replies. */
    private function client(): EmailOctopus
    {
        $test = $this;
        return new class ('key', 'list-1', 'auto-1', $test) extends EmailOctopus {
            public function __construct(string $k, string $l, string $a, private NewsletterTest $test)
            {
                parent::__construct($k, $l, $a);
            }

            protected function request(string $method, string $path, ?array $json = null): ?array
            {
                return $this->test->answer($method, $path, $json);
            }
        };
    }

    /** @internal called by the fake client */
    public function answer(string $method, string $path, ?array $json): ?array
    {
        $this->requests[] = [$method, $path, $json];
        return array_shift($this->replies);
    }

    /**
     * A client that skips HTTP altogether and records what the sender asked of it.
     *
     * @param string[]|null $contacts null = the list cannot be read
     * @param string[]      $failFor  contacts whose field write fails
     */
    private function fakeSender(?array $contacts, array $failFor = []): FakeNewsletterClient
    {
        return new FakeNewsletterClient($contacts, $failFor);
    }

    private function newsletter(?EmailOctopus $client = null): Newsletter
    {
        return new Newsletter($this->db, $client ?? $this->fakeSender([]), 'https://example.test/', '', 0);
    }
}

/** See NewsletterTest::fakeSender(). */
final class FakeNewsletterClient extends EmailOctopus
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param string[]|null $contacts
     * @param string[]      $failFor
     */
    public function __construct(private ?array $contacts, private array $failFor)
    {
        parent::__construct('key', 'list-1', 'auto-1');
    }

    public function subscribedContactIds(): ?array
    {
        return $this->contacts;
    }

    public function setFields(string $contactId, array $fields): bool
    {
        $this->calls[] = 'fields:' . $contactId;
        return !in_array($contactId, $this->failFor, true);
    }

    public function queueAutomation(string $contactId): bool
    {
        $this->calls[] = 'queue:' . $contactId;
        return true;
    }
}
