<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Auth;
use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\XmlRpcServer;
use PHPUnit\Framework\TestCase;

/**
 * Scheduling a post over XML-RPC.
 *
 * The bug these pin: a post scheduled for the future through a WordPress client
 * published immediately. Both write paths read only the *local* date key
 * ('dateCreated' / 'post_date') and ignored the GMT variant, so a client that
 * sends only the GMT field left the date null — the caller then defaulted to
 * now, and 'now' is never in the future, so the status landed on 'published'.
 *
 * Scheduling through the admin UI was always fine; this is the XML-RPC path
 * only. Asserting on the resolved status rather than the parsed date, because
 * the status is what the author actually noticed.
 */
final class XmlRpcSchedulingTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private XmlRpcServer $server;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);

        $config = [
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ];

        // A site timezone deliberately behind UTC. It is what makes the two
        // date keys resolve differently, which is the whole point here.
        $this->db->upsertSetting('timezone', 'America/New_York');

        $this->server = new XmlRpcServer(
            $this->db,
            new Auth($config, $this->db),
            $config,
            new Builder($config, $this->db),
        );
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    /**
     * Both apply*Struct methods are private — they are internals of a method
     * dispatch that would otherwise need a full authenticated request built
     * around it, and the status assignment is what is under test.
     */
    private function apply(string $method, Post $post, array $struct): void
    {
        $ref = new \ReflectionMethod(XmlRpcServer::class, $method);
        $ref->setAccessible(true);

        $args = $method === 'applyStruct'
            ? [$post, $struct, true, 'America/New_York']   // $publish = true
            : [$post, $struct, 'America/New_York'];

        $ref->invokeArgs($this->server, $args);
    }

    private function post(): Post
    {
        $post        = new Post($this->db);
        $post->title = 'Scheduled by a WordPress client';
        $post->slug  = 'scheduled-by-a-wordpress-client';

        return $post;
    }

    /** An hour out, formatted the way an XML-RPC client sends a GMT date. */
    private function futureGmt(): string
    {
        return gmdate('Ymd\TH:i:s', time() + 3600);
    }

    private function futureLocal(): string
    {
        return (new \DateTime('@' . (time() + 3600)))
            ->setTimezone(new \DateTimeZone('America/New_York'))
            ->format('Ymd\TH:i:s');
    }

    // ── metaWeblog (dateCreated / date_created_gmt) ───────────────────────────

    public function testMetaWeblogSchedulesFromTheGmtDateAlone(): void
    {
        $post = $this->post();
        $this->apply('applyStruct', $post, [
            'title'            => $post->title,
            'date_created_gmt' => $this->futureGmt(),
        ]);

        $this->assertSame('scheduled', $post->status);
    }

    public function testMetaWeblogStillSchedulesFromTheLocalDate(): void
    {
        $post = $this->post();
        $this->apply('applyStruct', $post, [
            'title'       => $post->title,
            'dateCreated' => $this->futureLocal(),
        ]);

        $this->assertSame('scheduled', $post->status);
    }

    public function testMetaWeblogPublishesWithNoDateAtAll(): void
    {
        $post = $this->post();
        $this->apply('applyStruct', $post, ['title' => $post->title]);

        $this->assertSame('published', $post->status);
    }

    // ── wp.newPost (post_date / post_date_gmt) ────────────────────────────────

    public function testWpNewPostSchedulesFromTheGmtDateAlone(): void
    {
        $post = $this->post();
        $this->apply('applyWpPostStruct', $post, [
            'post_title'    => $post->title,
            'post_status'   => 'future',
            'post_date_gmt' => $this->futureGmt(),
        ]);

        $this->assertSame('scheduled', $post->status);
    }

    public function testWpNewPostStillSchedulesFromTheLocalDate(): void
    {
        $post = $this->post();
        $this->apply('applyWpPostStruct', $post, [
            'post_title'  => $post->title,
            'post_status' => 'future',
            'post_date'   => $this->futureLocal(),
        ]);

        $this->assertSame('scheduled', $post->status);
    }

    public function testWpNewPostPublishesAPastDate(): void
    {
        $post = $this->post();
        $this->apply('applyWpPostStruct', $post, [
            'post_title'    => $post->title,
            'post_status'   => 'publish',
            'post_date_gmt' => gmdate('Ymd\TH:i:s', time() - 3600),
        ]);

        $this->assertSame('published', $post->status);
    }

    public function testADraftStaysADraftEvenWithAFutureGmtDate(): void
    {
        $post = $this->post();
        $this->apply('applyWpPostStruct', $post, [
            'post_title'    => $post->title,
            'post_status'   => 'draft',
            'post_date_gmt' => $this->futureGmt(),
        ]);

        $this->assertSame('draft', $post->status);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
