<?php

namespace App\Controllers;

use App\Filters\AdminAuth;
use App\Libraries\ContentRenderer;
use App\Libraries\Permalinks;
use App\Libraries\SiteCache;
use App\Models\ContactMessageModel;
use App\Models\MediaModel;
use App\Models\PostModel;
use App\Models\RedirectModel;
use App\Models\SettingModel;
use App\Models\TermModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The public site. URLs match the old WordPress site; see Permalinks.
 */
class Site extends BaseController
{
    private const CACHE_TTL = 3600;

    protected $helpers = ['site', 'form'];

    private Permalinks $links;
    private ContentRenderer $renderer;
    private SettingModel $settings;

    public function initController($request, $response, $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->links    = new Permalinks();
        $this->renderer = new ContentRenderer();
        $this->settings = model(SettingModel::class);
    }

    public function home(): ResponseInterface
    {
        // Old WordPress short links: /?p=123 and /?page_id=123.
        $wpId = (int) ($this->request->getGet('p') ?? $this->request->getGet('page_id') ?? 0);

        if ($wpId > 0) {
            $post = model(PostModel::class)->where('wp_id', $wpId)->where('status', 'published')->first();

            return $post ? $this->redirectTo($this->links->post($post)) : $this->notFound();
        }

        $frontId = $this->settings->value('front_page_id');

        if ($frontId !== '' && ($page = model(PostModel::class)->find($frontId)) && $page['status'] === 'published') {
            return $this->cached('/', fn () => $this->singleView($page, true));
        }

        return $this->cached('/', fn () => $this->listView(null, 1, '/', true));
    }

    public function resolve(string $path = ''): ResponseInterface
    {
        $raw      = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $segments = array_values(array_filter(explode('/', $raw), static fn ($s) => $s !== ''));
        $path     = '/' . implode('/', $segments) . '/';

        $redirect = model(RedirectModel::class)->where('from_path', $path)->first();

        if ($redirect !== null) {
            model(RedirectModel::class)->update($redirect['id'], ['hits' => $redirect['hits'] + 1]);

            if ((int) $redirect['code'] === 410) {
                return $this->notFound()->setStatusCode(410);
            }

            return $this->redirectTo($redirect['to_path'], (int) $redirect['code']);
        }

        // .../page/2/
        $pageNumber = 1;
        $count      = count($segments);

        if ($count >= 2 && $segments[$count - 2] === 'page' && ctype_digit($segments[$count - 1])) {
            $pageNumber = max(1, (int) $segments[$count - 1]);
            $segments   = array_slice($segments, 0, -2);
        }

        $base = '/' . implode('/', $segments) . ($segments ? '/' : '');

        // Category and tag archives.
        if (in_array($segments[0] ?? '', ['category', 'tag'], true) && count($segments) >= 2) {
            $term = model(TermModel::class)->where('taxonomy', $segments[0])->where('slug', end($segments))->first();

            if ($term === null) {
                return $this->notFound();
            }

            // A category or tag whose posts are all archived (private, draft or deleted)
            // is gone, like the posts: 410 rather than an empty page with a 200.
            if (model(PostModel::class)->publishedPosts((int) $term['id'])->countAllResults() === 0) {
                return $this->notFound()->setStatusCode(410);
            }

            return $this->canonical($raw, $this->links->termUrl($term), $pageNumber)
                ?? $this->cached($path, fn () => $this->listView($term, $pageNumber, $this->links->termUrl($term)));
        }

        if ($segments === []) {
            return $this->notFound();
        }

        // One author, so WordPress author archives go to the blog.
        if ($segments[0] === 'author') {
            $blog = model(PostModel::class)->find($this->settings->value('posts_page_id'));

            return $this->redirectTo($blog ? $this->links->post($blog) : '/');
        }

        $slug  = end($segments);
        $posts = model(PostModel::class);
        // Drafts and private posts are visible to a valid admin session only.
        $admin = visitor_session('user_id') !== null && AdminAuth::currentUser() !== null;

        $candidates = $posts->where('slug', $slug)->orderBy('type')->findAll();

        // Posts need at least a category segment in front of the slug; pages can be top level.
        foreach ($candidates as $post) {
            if ($post['type'] === 'post' && count($segments) < 2) {
                continue;
            }

            if ($post['status'] !== 'published' && ! $admin) {
                continue;
            }

            $url = $this->links->post($post);

            if ((string) $post['id'] === $this->settings->value('posts_page_id')) {
                return $this->canonical($raw, $url, $pageNumber)
                    ?? $this->cached($path, fn () => $this->listView(null, $pageNumber, $url));
            }

            if ($pageNumber > 1) {
                continue;
            }

            if ($response = $this->canonical($raw, $url)) {
                return $response;
            }

            if ($post['status'] !== 'published') {
                return $this->response->setBody($this->singleView($post));
            }

            return $this->cached($path, fn () => $this->singleView($post));
        }

        return $this->notFound();
    }

    public function feed(): ResponseInterface
    {
        $xml = SiteCache::remember('feed', self::CACHE_TTL, function () {
            $posts = model(PostModel::class)->publishedPosts()->findAll(20);
            $items = [];

            foreach ($posts as $post) {
                $items[] = [
                    'title'       => $post['title'],
                    'url'         => $this->absolute($this->links->post($post)),
                    'date'        => date(DATE_RSS, strtotime($post['published_at'])),
                    'description' => $this->renderer->excerpt($post),
                    'content'     => $this->absoluteUrls($this->renderer->html($post)),
                    'categories'  => array_column(model(PostModel::class)->terms((int) $post['id']), 'name'),
                ];
            }

            return view('site/feed', ['settings' => $this->settings->allSettings(), 'items' => $items, 'home' => $this->absolute('/')], ['debug' => false]);
        });

        return $this->response->setContentType('application/rss+xml', 'UTF-8')->setBody($xml);
    }

    public function sitemap(): ResponseInterface
    {
        $xml = SiteCache::remember('sitemap', self::CACHE_TTL, function () {
            $rows = model(PostModel::class)->where('status', 'published')->where('published_at <=', date('Y-m-d H:i:s'))
                ->orderBy('type')->orderBy('published_at', 'DESC')->findAll();
            $urls = [];

            foreach ($rows as $post) {
                $urls[] = ['loc' => $this->absolute($this->links->post($post)), 'lastmod' => date('c', strtotime($post['updated_at'] ?? $post['published_at']))];
            }

            return view('site/sitemap', ['urls' => $urls], ['debug' => false]);
        });

        return $this->response->setContentType('application/xml', 'UTF-8')->setBody($xml);
    }

    /**
     * WordPress's sitemap address, still remembered by search engines.
     */
    public function oldSitemap(): ResponseInterface
    {
        return $this->redirectTo('/sitemap.xml');
    }

    /**
     * Browsers ask for /favicon.ico on their own; send them to the site icon.
     */
    public function favicon(): ResponseInterface
    {
        $icons = $this->siteIcons($this->settings->value('site_icon_id'));

        return $icons !== [] ? redirect()->to($icons['32'], 301) : $this->response->setStatusCode(404);
    }

    public function robots(): ResponseInterface
    {
        $body = "User-agent: *\nDisallow: /admin/\n\nSitemap: " . $this->absolute('/sitemap.xml') . "\n";

        return $this->response->setContentType('text/plain', 'UTF-8')->setBody($body);
    }

    /**
     * The contact form. Messages are stored, then emailed to the contact address.
     */
    public function contact(): ResponseInterface
    {
        $back = self::localPath((string) $this->request->getPost('return'), '/contact/');

        // Bots fill in the hidden field.
        if ((string) $this->request->getPost('website') !== '') {
            return $this->redirectTo($back . '?sent=1#contact-form', 303);
        }

        $throttler = service('throttler');

        if (! $throttler->check('contact-' . md5($this->request->getIPAddress()), 5, HOUR)) {
            return redirect()->to($back . '?error=1#contact-form', 303)->with('contact_errors', ['Too many messages from your address. Please try again later.']);
        }

        $rules = [
            'name'    => ['label' => 'Name', 'rules' => 'required|max_length[200]'],
            'email'   => ['label' => 'Email', 'rules' => 'required|valid_email|max_length[191]'],
            'message' => ['label' => 'Message', 'rules' => 'required|min_length[2]|max_length[10000]'],
        ];

        if (! $this->validate($rules)) {
            return redirect()->to($back . '?error=1#contact-form', 303)->withInput()->with('contact_errors', $this->validator->getErrors());
        }

        $data = [
            'name'       => self::oneLine((string) $this->request->getPost('name')),
            'email'      => trim((string) $this->request->getPost('email')),
            'message'    => trim((string) $this->request->getPost('message')),
            'ip'         => $this->request->getIPAddress(),
            'user_agent' => substr((string) $this->request->getUserAgent(), 0, 255),
        ];

        $messages = model(ContactMessageModel::class);
        $id       = $messages->insert($data);

        $email = service('email');
        $email->setTo($this->settings->value('contact_email'));
        $email->setReplyTo($data['email'], $data['name']);
        $email->setSubject('Contact form: ' . mb_substr($data['name'], 0, 80));
        $email->setMessage("From: {$data['name']} <{$data['email']}>\n\n{$data['message']}\n");

        if ($email->send(false)) {
            $messages->update($id, ['emailed' => 1]);
        } else {
            log_message('error', 'Contact form email failed: ' . $email->printDebugger(['headers']));
        }

        return $this->redirectTo($back . '?sent=1#contact-form', 303);
    }

    /**
     * A path on this site, or $default. Rejects anything a browser could read as
     * another host: //host, /\\host, schemes, and control characters.
     */
    public static function localPath(string $path, string $default): string
    {
        return preg_match('#^/(?![/\\\\])[^\\x00-\\x1f\\x7f]*$#', $path) === 1 ? $path : $default;
    }

    /**
     * Text safe for an email header: no line breaks or other control characters.
     */
    public static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\\x00-\\x1f\\x7f]+/u', ' ', $text) ?? '');
    }

    public function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody($this->render('site/not_found', [
            'title'     => 'Page not found',
            'bodyClass' => 'error404',
            'noindex'   => true,
        ]));
    }

    private function singleView(array $post, bool $isFront = false): string
    {
        $postModel = model(PostModel::class);
        $media     = $post['featured_media_id'] ? model(MediaModel::class)->find($post['featured_media_id']) : null;
        $terms     = $postModel->terms((int) $post['id']);
        $isAbout   = $post['type'] === 'page' && in_array($post['slug'], ['about', 'about-2'], true);
        $site      = $this->settings->allSettings();

        $classes = array_merge(
            [$isAbout ? 'is-about-page' : null, 'post-' . $post['id'], $post['type'], 'type-' . $post['type'], 'status-' . ($post['status'] === 'published' ? 'publish' : $post['status'])],
            $post['type'] === 'post' ? ['format-standard'] : [],
            $media ? ['has-post-thumbnail'] : [],
            ['hentry'],
            array_map(static fn ($t) => ($t['taxonomy'] === 'tag' ? 'tag-' : 'category-') . $t['slug'], $terms)
        );

        $og = null;

        if ($media && ! $isFront) {
            $large = (json_decode($media['sizes'] ?? '[]', true) ?: [])['large'] ?? ['path' => $media['path'], 'width' => $media['width'], 'height' => $media['height']];
            $og    = ['url' => $this->absolute('/uploads/' . $large['path']), 'width' => $large['width'], 'height' => $large['height'], 'alt' => $media['alt'] ?: $post['title']];
        }

        return $this->render('site/single', [
            'post'         => $post,
            'html'         => lazy_images($this->renderer->html($post), $media ? 0 : 1),
            'media'        => $isAbout ? null : $media,
            'url'          => $this->links->post($post),
            'classes'      => implode(' ', array_filter($classes)),
            'title'        => $isFront ? null : $post['title'],
            'description'  => $this->renderer->description($post, $site['tagline']),
            'canonical'    => $this->links->post($post),
            'ogType'       => $post['type'] === 'post' ? 'article' : 'website',
            'ogImage'      => $og,
            'isHome'       => $isFront,
            'bodyClass'    => $isFront ? 'home page-template-default page' : ($post['type'] === 'post' ? 'post-template-default single single-post' : 'page-template-default page'),
            'published'    => $post['type'] === 'post' ? $post['published_at'] : null,
            'modified'     => $post['type'] === 'post' ? $post['updated_at'] : null,
            'noindex'      => $post['status'] !== 'published',
        ]);
    }

    private function listView(?array $term, int $page, string $baseUrl, bool $isFront = false): string
    {
        $perPage = max(1, (int) $this->settings->value('posts_per_page', '10'));
        $model   = model(PostModel::class)->publishedPosts($term ? (int) $term['id'] : null);
        $total   = $model->countAllResults(false);
        $posts   = $model->findAll($perPage, ($page - 1) * $perPage);
        $media   = model(MediaModel::class);
        $items   = [];

        foreach ($posts as $i => $post) {
            $terms   = model(PostModel::class)->terms((int) $post['id']);
            $image   = $post['featured_media_id'] ? $media->find($post['featured_media_id']) : null;
            $items[] = [
                'post'    => $post,
                'url'     => $this->links->post($post),
                'excerpt' => $this->renderer->excerpt($post),
                'image'   => $image ? featured_image($image, 'attachment-large size-large wp-post-image', $i === 0 && $page === 1) : null,
                'classes' => implode(' ', array_merge(
                    ['post-' . $post['id'], 'post', 'type-post', 'status-publish', 'format-standard'],
                    $image ? ['has-post-thumbnail'] : [],
                    ['hentry'],
                    array_map(static fn ($t) => ($t['taxonomy'] === 'tag' ? 'tag-' : 'category-') . $t['slug'], $terms)
                )),
            ];
        }

        $pages = (int) ceil($total / $perPage);

        if ($page > 1 && $page > $pages) {
            throw PageNotFoundException::forPageNotFound();
        }

        $pageUrl = static fn (int $n) => $n === 1 ? $baseUrl : $baseUrl . 'page/' . $n . '/';
        $title   = $term ? $term['name'] : (($posts && ! $isFront) ? 'blog' : null);

        return $this->render('site/list', [
            'items'       => $items,
            'term'        => $term,
            'title'       => $title,
            'description' => $term && $term['description'] ? strip_tags($term['description']) : null,
            'canonical'   => $pageUrl($page),
            'isHome'      => $isFront || $term === null,
            'bodyClass'   => $term ? 'archive ' . ($term['taxonomy'] === 'tag' ? 'tag tag-' : 'category category-') . $term['slug'] : 'blog',
            'newer'       => $page > 1 ? $pageUrl($page - 1) : null,
            'older'       => $page < $pages ? $pageUrl($page + 1) : null,
        ]);
    }

    /**
     * Wrap a page in the site layout.
     */
    private function render(string $view, array $data): string
    {
        $site = $this->settings->allSettings();

        $data += [
            'site'        => $site,
            'title'       => null,
            'description' => null,
            'canonical'   => null,
            'ogType'      => 'website',
            'ogImage'     => null,
            'isHome'      => false,
            'bodyClass'   => '',
            'published'   => null,
            'modified'    => null,
            'noindex'     => false,
        ];

        $data['documentTitle'] = $data['title'] !== null ? $data['title'] . ' – ' . $site['site_name'] : $site['site_name'] . ' – ' . $site['tagline'];
        $data['description'] ??= $site['tagline'];
        $data['ogImage'] ??= ['url' => $this->absolute('/theme/images/social-card.jpg'), 'width' => 1200, 'height' => 630, 'alt' => $site['site_name'] . ' – ' . $site['tagline']];
        $data['canonicalUrl'] = $data['canonical'] !== null ? $this->absolute($data['canonical']) : null;
        $data['primaryMenu']  = menu_links('primary');
        $data['socialMenu']   = menu_links('social');
        $data['icons']        = $this->siteIcons($site['site_icon_id']);
        $data['currentPath']  = $data['canonical'];
        $data['content']      = view($view, $data);

        return view('layouts/site', $data);
    }

    /**
     * Favicon links from the site icon's resized copies (WordPress made 32, 180, 192 and 270px).
     *
     * @return array<string, string> size label => URL
     */
    private function siteIcons(string $mediaId): array
    {
        $media = $mediaId !== '' ? model(MediaModel::class)->find($mediaId) : null;

        if ($media === null) {
            return [];
        }

        $sizes = json_decode($media['sizes'] ?? '[]', true) ?: [];
        $icons = [];

        foreach (['32', '180', '192', '270'] as $px) {
            $icons[$px] = '/uploads/' . ($sizes['site_icon-' . $px]['path'] ?? $media['path']);
        }

        return $icons;
    }

    /**
     * Serve a public page from the cache when nobody is signed in to the admin.
     */
    private function cached(string $key, callable $build): ResponseInterface
    {
        $anonymous = visitor_session('user_id') === null && $this->request->getUri()->getQuery() === '';
        $tribute   = config('Tribute')?->isActive() ? 'tribute:' : '';
        $html      = $anonymous ? SiteCache::remember('page:' . $tribute . $key, self::CACHE_TTL, $build) : $build();

        return $this->response->setBody($html);
    }

    /**
     * Redirect to the canonical URL when the request path differs (e.g. no trailing slash,
     * wrong category, the front page's own slug).
     */
    private function canonical(string $requested, string $url, int $page = 1): ?ResponseInterface
    {
        $target = $page > 1 ? $url . 'page/' . $page . '/' : $url;

        return rtrim($requested, '/') . '/' === $target && str_ends_with($requested, '/') ? null : $this->redirectTo($target);
    }

    private function redirectTo(string $path, int $code = 301): ResponseInterface
    {
        $query = $this->request->getUri()->getQuery();
        $keep  = $query !== '' && ! str_contains($path, '?') && ! preg_match('/(^|&)(p|page_id)=/', $query);

        return redirect()->to($this->absolute($path) . ($keep ? '?' . $query : ''), $code);
    }

    private function absolute(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : rtrim(config('App')->baseURL, '/') . $path;
    }

    private function absoluteUrls(string $html): string
    {
        return preg_replace('#(href|src)=(["\'])/(?!/)#', '$1=$2' . rtrim(config('App')->baseURL, '/') . '/', $html) ?? $html;
    }
}
