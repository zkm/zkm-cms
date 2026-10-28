<?php

namespace App\Libraries\WordPress;

/**
 * Converts stored WordPress post content into the plain HTML zkm-cms keeps:
 *
 * - drops block-editor comments (<!-- wp:paragraph --> etc.), keeping <!--more-->
 * - turns YouTube and Vimeo embed blocks into iframes (WordPress did that at render time)
 * - adds paragraphs to classic-editor posts, as wpautop() did
 * - curls quotes and makes ellipses, as wptexturize() did at render time
 * - rewrites links to the old site to root-relative URLs, and /wp-content/uploads/ to /uploads/
 * - removes the Fluent Forms shortcode (the contact page uses the built-in form instead)
 */
class ContentConverter
{
    /** @var list<string> */
    private array $hosts;

    /**
     * @param string $siteUrl The WordPress home URL, e.g. https://www.zachschneider.com
     */
    public function __construct(string $siteUrl)
    {
        $host        = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';
        $bare        = preg_replace('/^www\./', '', $host);
        $this->hosts = array_values(array_unique([$host, $bare, 'www.' . $bare]));
    }

    /**
     * @return array{html: string, contact_form: bool}
     */
    public function convert(string $content): array
    {
        $hasBlocks = str_contains($content, '<!-- wp:');
        $content   = str_replace(["\r\n", "\r"], "\n", $content);

        $content = preg_replace_callback(
            '#<!-- wp:embed (\{.*?\}) -->(.*?)<!-- /wp:embed -->#s',
            fn (array $m) => $this->embed(json_decode($m[1], true)['url'] ?? '', $m[2]),
            $content
        ) ?? $content;

        $contactForm = (bool) preg_match('/\[fluentform\b[^\]]*\]/', $content);
        $content     = preg_replace('/\[fluentform\b[^\]]*\]/', '', $content) ?? $content;

        if ($hasBlocks) {
            $content = preg_replace('#<!--\s+/?wp:.*?-->\n?#s', '', $content) ?? $content;
            // An empty paragraph block left over from the editor.
            $content = preg_replace('#<p>\s*</p>\n?#', '', $content) ?? $content;
        } else {
            $content = self::autop($content);
        }

        $content = self::texturize($content);
        $content = $this->localUrls($content);
        $content = preg_replace("/\n{3,}/", "\n\n", trim($content)) ?? $content;

        return ['html' => $content, 'contact_form' => $contactForm];
    }

    /**
     * Point links at the old site to root-relative URLs, and uploads to /uploads/.
     */
    public function localUrls(string $content): string
    {
        $hosts = implode('|', array_map(static fn ($h) => preg_quote($h, '#'), $this->hosts));

        $content = preg_replace('#(?:https?:)?//(?:' . $hosts . ')/wp-content/uploads/#i', '/uploads/', $content) ?? $content;

        return preg_replace_callback(
            '#(href|src)=(["\'])(?:https?:)?//(?:' . $hosts . ')(/[^"\']*)?\2#i',
            static fn (array $m) => $m[1] . '=' . $m[2] . (($m[3] ?? '') !== '' ? $m[3] : '/') . $m[2],
            $content
        ) ?? $content;
    }

    /**
     * Rewrite a single URL to the old site (e.g. a featured image path) the same way.
     */
    public function localUrl(string $url): string
    {
        return substr($this->localUrls('src="' . $url . '"'), 5, -1);
    }

    private function embed(string $url, string $inner): string
    {
        $src = self::embedSource($url);

        if ($src === null) {
            return $url !== '' ? '<p><a href="' . self::attr($url) . '">' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '</a></p>' : $inner;
        }

        $caption = preg_match('#<figcaption[^>]*>.*?</figcaption>#s', $inner, $m) ? $m[0] : '';

        return '<figure class="wp-block-embed"><div class="zkm-embed"><iframe src="' . self::attr($src)
            . '" title="Embedded video" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>'
            . $caption . '</figure>';
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The iframe URL for a YouTube or Vimeo link, or null for other providers.
     */
    public static function embedSource(string $url): ?string
    {
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|v/|embed/|shorts/)|youtu\.be/)([\w-]{11})#', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }

    /**
     * A small version of WordPress's wpautop(): blank lines become paragraphs and
     * single line breaks become <br>, except around and inside block-level elements.
     */
    public static function autop(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $keep = [];
        $text = preg_replace_callback('#<(pre|script|style|object)\b.*?</\1>#is', static function (array $m) use (&$keep) {
            $key        = '<!--keep-' . count($keep) . '-->';
            $keep[$key] = $m[0];

            return "\n\n{$key}\n\n";
        }, $text) ?? $text;

        $block = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|form|map|area|blockquote|address|p|h[1-6]|hr|fieldset|legend|section|article|aside|header|footer|nav|figure|figcaption|details|summary|embed|iframe)';
        $text  = preg_replace('#(<' . $block . '[\s/>])#i', "\n\n$1", $text) ?? $text;
        $text  = preg_replace('#(</' . $block . '>)#i', "$1\n\n", $text) ?? $text;

        $out = [];

        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            if (preg_match('#^(?:</?' . $block . '[\s/>]|<!--keep-)#i', $chunk)) {
                $out[] = $chunk;
            } else {
                $out[] = '<p>' . preg_replace('/\n/', "<br />\n", $chunk) . '</p>';
            }
        }

        return strtr(implode("\n\n", $out), $keep);
    }

    /**
     * A small version of WordPress's wptexturize(): curly quotes, apostrophes,
     * ellipses and dashes in text, leaving tags and code alone.
     */
    public static function texturize(string $html): string
    {
        $parts = preg_split('#(<!--.*?-->|<[^>]*>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $skip  = 0;

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                if (preg_match('#^<(/?)(pre|code|kbd|samp|script|style|textarea|tt)\b#i', $part, $m)) {
                    $skip = max(0, $skip + ($m[1] === '/' ? -1 : 1));
                }

                continue;
            }

            if ($skip === 0) {
                $parts[$i] = self::texturizeText($part);
            }
        }

        return implode('', $parts);
    }

    public static function texturizeText(string $text): string
    {
        $text = str_replace(['&#039;', '&#39;', '&quot;'], ["'", "'", '"'], $text);

        $text = str_replace(['---', ' -- ', '...'], ['—', ' — ', '…'], $text);
        $text = preg_replace('/(?<=\d)--(?=\d)/', '–', $text) ?? $text;

        // Apostrophes inside words and in decades ('90s), then opening and closing quotes.
        $text = preg_replace("/(?<=\\p{L}|\\d)'(?=\\p{L})/u", '’', $text) ?? $text;
        $text = preg_replace("/'(?=\\d0s)/u", '’', $text) ?? $text;
        $text = preg_replace("/(?<=^|[\\s(\\[{\"“—–-])'/u", '‘', $text) ?? $text;
        $text = str_replace("'", '’', $text);
        $text = preg_replace('/(?<=^|[\\s(\\[{‘—–-])"/u', '“', $text) ?? $text;

        return str_replace('"', '”', $text);
    }
}
