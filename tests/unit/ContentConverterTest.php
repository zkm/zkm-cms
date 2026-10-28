<?php

use App\Libraries\WordPress\ContentConverter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ContentConverterTest extends CIUnitTestCase
{
    private ContentConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new ContentConverter('https://www.zachschneider.com');
    }

    public function testStripsBlockCommentsAndKeepsMore(): void
    {
        $html = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->\n\n<!-- wp:image {\"id\":5,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image\"><img src=\"https://www.zachschneider.com/wp-content/uploads/2021/06/a.png\" alt=\"\"/></figure>\n<!-- /wp:image -->";

        $out = $this->converter->convert($html)['html'];

        $this->assertStringNotContainsString('wp:', $out);
        $this->assertStringContainsString('<!--more-->', $out);
        $this->assertStringContainsString('src="/uploads/2021/06/a.png"', $out);
    }

    public function testRewritesSiteLinksToRootRelative(): void
    {
        $out = $this->converter->localUrls('<a href="https://www.zachschneider.com/tech/skywatch/">x</a> <a href="http://zachschneider.com">home</a> <a href="https://example.com/">other</a>');

        $this->assertSame('<a href="/tech/skywatch/">x</a> <a href="/">home</a> <a href="https://example.com/">other</a>', $out);
    }

    public function testTurnsYouTubeEmbedIntoIframe(): void
    {
        $html = "<!-- wp:embed {\"url\":\"https://youtu.be/rtp5gNhBZgo\",\"type\":\"video\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtu.be/rtp5gNhBZgo\n</div></figure>\n<!-- /wp:embed -->";

        $out = $this->converter->convert($html)['html'];

        $this->assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/rtp5gNhBZgo"', $out);
    }

    public function testEmbedSources(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/xLViZUvoGrE', ContentConverter::embedSource('http://www.youtube.com/v/xLViZUvoGrE&hl=en'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/6bVa6jn4rpE', ContentConverter::embedSource('https://www.youtube.com/watch?v=6bVa6jn4rpE'));
        $this->assertSame('https://player.vimeo.com/video/22439234', ContentConverter::embedSource('https://vimeo.com/22439234'));
        $this->assertNull(ContentConverter::embedSource('https://example.com/video'));
    }

    public function testFluentFormShortcodeBecomesContactTemplate(): void
    {
        $result = $this->converter->convert("<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n[fluentform id=\"3\"]\n<!-- /wp:shortcode -->");

        $this->assertTrue($result['contact_form']);
        $this->assertSame('<p>Hi</p>', $result['html']);
    }

    public function testClassicPostsGetParagraphs(): void
    {
        $out = $this->converter->convert("First line\nsecond line\n\nNew paragraph\n\n<ul>\n<li>item</li>\n</ul>")['html'];

        $this->assertStringContainsString("<p>First line<br />\nsecond line</p>", $out);
        $this->assertStringContainsString('<p>New paragraph</p>', $out);
        $this->assertStringNotContainsString('<p><ul>', $out);
    }

    public function testTexturizeCurlsQuotesOutsideCode(): void
    {
        $out = ContentConverter::texturize('<p>"Hi," it\'s the \'90s... <code>"raw"</code> <a href="/x">\'q\'</a></p>');

        $this->assertSame('<p>“Hi,” it’s the ’90s… <code>"raw"</code> <a href="/x">‘q’</a></p>', $out);
    }
}
