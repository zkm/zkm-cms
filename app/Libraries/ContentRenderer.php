<?php

namespace App\Libraries;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Turns a post body into HTML and derives excerpts and descriptions from it.
 *
 * Imported WordPress posts are stored as HTML; new posts are Markdown.
 * Either way the body is written by the site's own admin, so raw HTML is allowed.
 */
class ContentRenderer
{
    private ?GithubFlavoredMarkdownConverter $markdown = null;

    public function html(array $post): string
    {
        $body = (string) ($post['body'] ?? '');

        if (($post['format'] ?? 'markdown') === 'markdown') {
            $this->markdown ??= new GithubFlavoredMarkdownConverter([
                'html_input'         => 'allow',
                'allow_unsafe_links' => false,
            ]);
            $body = $this->markdown->convert($body)->getContent();
        }

        // WordPress leaves an anchor where the "read more" break was.
        return str_replace('<!--more-->', '<span id="more-' . (int) ($post['id'] ?? 0) . '"></span>', $body);
    }

    /**
     * The summary shown in post lists: the hand-written excerpt, or the first 55 words.
     */
    public function excerpt(array $post): string
    {
        if (trim((string) ($post['excerpt'] ?? '')) !== '') {
            return esc(trim($post['excerpt']));
        }

        $text    = self::plainText($this->html($post));
        $trimmed = self::trimWords($text, 55, '');

        return esc($trimmed) . ($trimmed !== $text ? ' [&hellip;]' : '');
    }

    /**
     * Plain-text description for meta tags (about 30 words).
     */
    public function description(array $post, string $fallback = ''): string
    {
        $text = trim((string) ($post['meta_description'] ?? ''));

        if ($text === '') {
            $text = trim((string) ($post['excerpt'] ?? '')) ?: self::plainText($this->html($post));
        }

        $text = $text !== '' ? self::trimWords($text, 30, '…') : $fallback;

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function plainText(string $html): string
    {
        $html = preg_replace('#<(script|style|figcaption|iframe)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function trimWords(string $text, int $words, string $more): string
    {
        $parts = preg_split('/\s+/u', trim($text), $words + 1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) > $words) {
            array_pop($parts);

            return implode(' ', $parts) . $more;
        }

        return implode(' ', $parts);
    }
}
