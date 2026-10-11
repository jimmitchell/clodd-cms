<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Post;

/**
 * The admin editor's Type select, stored and read back.
 *
 * The editor writes a type as three columns' worth of state — post_kind, a
 * post_contexts row and posts.rsvp — and reopens the post by reading them
 * back. Post::postKindForType() and Post::editorType() are the two halves, and
 * if they drift a saved Reply reopens as a Note and the next save drops its
 * target. These tests save every type the way post-edit.php does and check it
 * comes back as itself.
 */
final class PostEditorTypeTest extends TempSiteTestCase
{
    private const TARGET = 'https://example.com/elsewhere';

    /** Saves a post the way admin/post-edit.php does for this type and title. */
    private function saveAs(string $type, string $title): Post
    {
        $post               = new Post($this->db);
        $post->title        = $title;
        $post->slug         = $type . ($title !== '' ? '-titled' : '-untitled');
        $post->content      = 'Body';
        $post->status       = 'draft';
        $post->post_kind    = Post::postKindForType($type, $title);
        $post->rsvp         = $type === 'rsvp' ? 'yes' : null;
        $post->save();
        $post->saveContexts(Post::isInteractionType($type)
            ? [['kind' => Post::TYPE_CONTEXT_KIND[$type], 'url' => self::TARGET]]
            : []);

        return Post::findById($this->db, (int) $post->id);
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyTypeTitledAndNot(): iterable
    {
        foreach (array_keys(Post::EDITOR_TYPES) as $type) {
            yield "$type untitled" => [$type, ''];
            yield "$type titled"   => [$type, 'A Title'];
        }
    }

    /** @dataProvider everyTypeTitledAndNot */
    public function testEveryTypeReopensAsItself(string $type, string $title): void
    {
        $post = $this->saveAs($type, $title);

        $this->assertSame($type, $post->editorType());

        if (Post::isInteractionType($type)) {
            $this->assertSame(self::TARGET, $post->contexts[0]['url'] ?? null);
        } else {
            $this->assertSame([], $post->contexts);
        }
    }

    /**
     * Micropub's answer must match wherever PTD can tell. The one place it
     * can't is a titled note: PTD has no such thing and calls it an article,
     * which is exactly why the editor reads editorType() rather than this.
     *
     * @dataProvider everyTypeTitledAndNot
     */
    public function testMicropubAgreesExceptOnATitledNote(string $type, string $title): void
    {
        if ($type === 'article' && $title === '') {
            $this->markTestSkipped('The editor refuses an untitled article.');
        }

        $post     = $this->saveAs($type, $title);
        $expected = $type === 'note' && $title !== '' ? 'article' : $type;

        $this->assertSame($expected, $post->micropubType());

        $ids = array_map(fn(Post $p) => $p->id, Post::findForMicropub($this->db, $expected));
        $this->assertContains($post->id, $ids, "micropubTypePredicate('$expected') must select it too");
    }

    public function testAResponseIsANoteUnlessItHasATitle(): void
    {
        $this->assertSame('aside', Post::postKindForType('reply', ''));
        $this->assertSame('aside', Post::postKindForType('like', '   '));
        $this->assertSame('standard', Post::postKindForType('bookmark', 'Worth reading'));
        $this->assertSame('standard', Post::postKindForType('article', ''));
        $this->assertSame('aside', Post::postKindForType('note', 'Titled'));
        $this->assertSame('photo', Post::postKindForType('photo', 'Titled'));
    }

    public function testEveryEditorTypeIsAMicropubType(): void
    {
        $this->assertEqualsCanonicalizing(Post::MICROPUB_TYPES, array_keys(Post::EDITOR_TYPES));
    }
}
