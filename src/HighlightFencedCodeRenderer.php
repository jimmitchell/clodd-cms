<?php

declare(strict_types=1);

namespace CMS;

use Highlight\Highlighter;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

class HighlightFencedCodeRenderer implements NodeRendererInterface
{
    private Highlighter $hl;

    public function __construct()
    {
        $this->hl = new Highlighter();
        $this->hl->setAutodetectLanguages([
            'php', 'javascript', 'typescript', 'python', 'bash', 'shell',
            'html', 'css', 'json', 'yaml', 'sql', 'go', 'rust',
        ]);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        FencedCode::assertInstanceOf($node);

        $code  = $node->getLiteral();
        $words = $node->getInfoWords();
        $lang  = $words[0] ?? '';

        try {
            if ($lang !== '') {
                $result  = $this->hl->highlight($lang, $code);
                $langClass = 'language-' . htmlspecialchars($result->language, ENT_QUOTES, 'UTF-8');
                $inner   = $result->value;
            } else {
                $result  = $this->hl->highlightAuto($code);
                $langClass = 'language-' . htmlspecialchars($result->language, ENT_QUOTES, 'UTF-8');
                $inner   = $result->value;
            }
        } catch (\Exception) {
            // Unknown language — fall back to plain escaped output.
            return '<div class="code-block"><pre><code class="language-'
                . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '">'
                . Xml::escape($code)
                . "</code></pre></div>\n";
        }

        // The .code-block wrapper is emitted here rather than left to theme.js.
        // Inserting it on load moved the <pre> a step down the tree, which
        // changed which element the .prose > * + * margin landed on and reflowed
        // everything below by a few pixels. theme.js reuses this wrapper.
        return '<div class="code-block code-block--dark"><pre class="syntax-hl"><code class="hljs '
            . $langClass . '">'
            . $inner
            . "</code></pre></div>\n";
    }
}
