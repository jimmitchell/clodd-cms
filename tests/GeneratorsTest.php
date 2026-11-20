<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\FeedGenerator;
use CMS\OgImageGenerator;
use CMS\Page;
use CMS\SitemapGenerator;

/**
 * The three generators split out of Builder in 1.58.1.
 *
 * Builder's own output tests still cover what reaches disk; these pin the
 * contracts Builder now relies on — what each generator hands back, and when
 * it hands back nothing.
 */
final class GeneratorsTest extends TempSiteTestCase
{
    // ── SitemapGenerator ──────────────────────────────────────────────────────

    public function testTheSitemapIsNotRenderedWithoutASiteUrl(): void
    {
        $this->publishedPost('a-post');

        $this->assertNull(
            (new SitemapGenerator($this->db, $this->db->getAllSettings()))->render(),
            'A sitemap of relative URLs is not a sitemap; Builder writes nothing when this is null.'
        );
    }

    public function testTheSitemapListsTheHomepagePublishedPostsAndPages(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com/');
        $this->db->upsertSetting('timezone', 'UTC');
        $this->publishedPost('a-post', '2026-03-04 09:00:00');
        $this->publishedPost('a-draft', null, 'draft');

        $page         = new Page($this->db);
        $page->title  = 'About';
        $page->slug   = 'about';
        $page->status = 'published';
        $page->save();

        $xml = (new SitemapGenerator($this->db, $this->db->getAllSettings()))->render();

        $this->assertNotNull($xml);
        $this->assertStringContainsString('<loc>https://example.com/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/2026/03/04/a-post/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/about/</loc>', $xml);
        $this->assertStringNotContainsString('a-draft', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'The sitemap must be well-formed XML.');
    }

    // ── FeedGenerator ─────────────────────────────────────────────────────────

    /**
     * Builder writes whatever forTerm() returns and removes FeedGenerator::FILES
     * from a deleted term's directory. If the two ever disagree, a format is
     * either never written or never cleaned up.
     */
    public function testATermGetsExactlyTheFeedsTheCleanupKnowsAbout(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');
        $post = $this->publishedPost('in-the-term');
        $term = ['id' => 1, 'name' => 'Things', 'slug' => 'things'];

        $feeds = (new FeedGenerator($this->db, $this->db->getAllSettings()))
            ->forTerm('category', $term, [$post]);

        $this->assertSame(array_values(FeedGenerator::FILES), array_keys($feeds));
        foreach ($feeds as $file => $content) {
            $this->assertStringContainsString('in-the-term', $content, "{$file} is missing the term's post.");
        }
    }

    // ── OgImageGenerator ──────────────────────────────────────────────────────

    public function testANoteGetsNoCard(): void
    {
        $note = $this->publishedPost('a-note', title: '', postKind: 'aside');

        $og = new OgImageGenerator(
            $this->root . '/output',
            $this->root . '/content/media',
            $this->root . '/output/fonts/og',
            $this->db->getAllSettings(),
            null
        );

        $this->assertSame('', $og->forPost($note));
        $this->assertNull($note->og_image_hash, 'A note must not be stamped as having a card.');
    }
}
