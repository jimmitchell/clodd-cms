<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Custom CommonMark renderer for inline images.
 *
 * The markup itself is built by ImageTag, which the post templates also use, so
 * a Markdown image and a photo-post image get identical treatment: lazy/async,
 * width/height to prevent layout shift, and a <picture>/WebP source with a
 * srcset for local /media/ files. External URLs get lazy/async only.
 */
class ImageRenderer implements NodeRendererInterface
{
    public function __construct(private readonly string $mediaDir) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        Image::assertInstanceOf($node);

        $url   = $node->getUrl();
        $title = $node->getTitle() ?? '';
        $alt   = strip_tags((string) $childRenderer->renderNodes($node->children()));

        $attrs = [];
        if ($title !== '') {
            $attrs['title'] = $title;
        }

        // Body images are laid out at the article measure, so below that width
        // the narrow variant is the right pick.
        $attrs['sizes'] = '(max-width: 44rem) 100vw, 44rem';

        return ImageTag::render($this->mediaDir, $url, $alt, $attrs);
    }
}
