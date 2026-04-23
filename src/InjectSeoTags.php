<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Post\PostRepository;
use Psr\Http\Message\ServerRequestInterface;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Str;
class InjectSeoTags
{
    protected $settings;
    protected $discussions;
    protected $posts;
    protected $url;

    public function __construct(SettingsRepositoryInterface $settings, DiscussionRepository $discussions, PostRepository $posts, UrlGenerator $url)
    {
        $this->settings = $settings;
        $this->discussions = $discussions;
        $this->posts = $posts;
        $this->url = $url;
    }

    public function __invoke(Document $document, ServerRequestInterface $request)
    {
        $uri = $request->getUri()->getPath();

        $siteTitle = $this->settings->get('forum_title');
        $gaId = $this->settings->get('ulasimarsiv-seo.ga_id'); 
        
        if ($gaId) {
            $document->head[] = "<script async src='https://www.googletagmanager.com/gtag/js?id={$gaId}'></script>
            <script>window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', '{$gaId}');</script>";
        }

        // 1. TARTIŞMA SAYFASI (/d/...)
        if (preg_match('/^\/d\/(\d+)/', $uri, $matches)) {
            $discussionId = $matches[1];
            preg_match('/^\/d\/\d+(?:-[^\/]*)?\/(\d+)/', $uri, $postMatches);
            $postNumber = isset($postMatches[1]) ? (int)$postMatches[1] : null;

            $this->injectDiscussionTags($document, $request, $discussionId, $postNumber);
        } 
        // 2. ETİKET SAYFASI (/t/...)
        elseif (preg_match('/^\/t\/([^\/]+)/', $uri, $matches)) {
            $tagSlug = $matches[1];
            $this->injectTagTags($document, $request, $tagSlug);
        }
        // 3. ANA SAYFA (/ veya /all)
        elseif ($uri === '/' || $uri === '' || $uri === '/all') {
            $this->injectHomeTags($document, $request);
        }
    }

    private function injectHomeTags(Document $document, ServerRequestInterface $request)
    {
        $siteTitle = $this->settings->get('forum_title');
        $baseUrl = rtrim($this->url->to('forum')->base(), '/');
        $description = $this->settings->get('forum_description');

        $document->head[] = '<meta property="og:site_name" content="' . e($siteTitle) . '">';
        $document->head[] = '<meta property="og:type" content="website">';
        $document->head[] = '<meta property="og:url" content="' . e($baseUrl) . '">';
        $document->canonicalUrl = $baseUrl;

        if ($description) {
            $document->meta['description'] = $description;
            $document->head[] = '<meta property="og:description" content="' . e($description) . '">';
        }

        if ($logo = $this->settings->get('logo_path')) {
            $document->head[] = '<meta property="og:image" content="' . e($baseUrl . '/assets/' . $logo) . '">';
        }

        // Home Page JSON-LD (WebSite & SiteNavigation)
        $jsonLds = [];
        
        $jsonLds[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteTitle,
            'url' => $baseUrl,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $baseUrl . '/?q={search_term_string}',
                'query-input' => 'required name=search_term_string'
            ]
        ];

        // Breadcrumb
        $jsonLds[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [[
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Ana Sayfa',
                'item' => $baseUrl
            ]]
        ];

        foreach ($jsonLds as $ld) {
            $document->head[] = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
    }

    private function injectTagTags(Document $document, ServerRequestInterface $request, $tagSlug)
    {
        $baseUrl = rtrim($this->url->to('forum')->base(), '/');
        
        $tag = null;
        if (class_exists('Flarum\Tags\Tag')) {
            $tag = \Flarum\Tags\Tag::where('slug', $tagSlug)->first();
        }

        $tagName = $tag ? $tag->name : ucfirst($tagSlug);
        $title = $tagName . ' | ' . $this->settings->get('forum_title');
        
        $document->title = $title;
        $document->canonicalUrl = $baseUrl . '/t/' . $tagSlug;
        
        if ($tag && $tag->description) {
            $document->meta['description'] = $tag->description;
            $document->head[] = '<meta property="og:description" content="' . e($tag->description) . '">';
        }

        // Tag Page JSON-LD
        $jsonLds = [];
        
        // Breadcrumb
        $jsonLds[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Ana Sayfa',
                    'item' => $baseUrl
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $tagName,
                    'item' => $baseUrl . '/t/' . $tagSlug
                ]
            ]
        ];

        foreach ($jsonLds as $ld) {
            $document->head[] = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
    }

    private function injectDiscussionTags(Document $document, $request, $discussionId, $postNumber)
    {
        try {
            $discussion = $this->discussions->findOrFail($discussionId);
            $post = null;

            if ($postNumber) {
                $post = $discussion->posts()->where('number', $postNumber)->first();
            }
            
            if (!$post) {
                $post = $this->posts->findOrFail($discussion->first_post_id);
            }

            $pageTitle = $discussion->title;
            // İlk mesajdaki başlığı (e-plaka vb) sayfa başlığına ekle
            $firstPost = $this->posts->findOrFail($discussion->first_post_id);
            if (preg_match('/(?<=^|\n)-\s*(.*?)(?=$|\n)/', $firstPost->content, $matches)) {
                $pageTitle = trim($matches[1]) . ' - ' . $pageTitle;
            }
            $pageTitle .= ' | ' . $this->settings->get('forum_title');
            
            $document->title = $pageTitle;
            $document->head[] = '<meta property="og:title" content="' . e($pageTitle) . '">';
            $document->head[] = '<meta name="twitter:title" content="' . e($pageTitle) . '">';
            $document->head[] = '<meta property="og:type" content="article">';

            $baseUrl = rtrim($request->getUri()->getScheme() . '://' . $request->getUri()->getHost(), '/');
            
            // Konudaki tüm yorumları sırayla çek (En fazla 100 mesaj, performansı korumak için)
            $allPosts = $discussion->posts()
                ->where('type', 'comment')
                ->whereNull('hidden_at')
                ->orderBy('number', 'asc')
                ->limit(100)
                ->get();
                
            $allImages = [];
            foreach ($allPosts as $singlePost) {
                $imagesInPost = $this->extractAllImages($singlePost, $baseUrl);
                if (!empty($imagesInPost)) {
                    $allImages = array_merge($allImages, $imagesInPost);
                }
            }

            $description = $this->extractDescription($post);

            if (!empty($allImages)) {
                $imageUrl = str_replace(' ', '%20', $allImages[0]['url']);
                $imageUrl = str_replace(['/thumb_', '/mini_'], '/', $imageUrl);
                $document->head[] = '<meta property="og:image" content="' . e($imageUrl) . '">';
                $document->head[] = '<meta name="twitter:image" content="' . e($imageUrl) . '">';
                $document->head[] = '<meta name="twitter:card" content="summary_large_image">';
            }

            if ($description) {
                $document->meta['description'] = $description;
                $document->head[] = '<meta property="og:description" content="' . e($description) . '">';
            }

            $canonicalUrl = rtrim($this->url->to('forum')->base(), '/') . "/d/{$discussion->id}-{$discussion->slug}" . ($postNumber ? "/{$postNumber}" : "");
            
            $document->canonicalUrl = $canonicalUrl;
            $document->meta['og:url'] = $canonicalUrl;

            // JSON-LD Structured Data
            $jsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'DiscussionForumPosting',
                'headline' => $pageTitle,
                'url' => $canonicalUrl,
                'datePublished' => $discussion->created_at->toIso8601String(),
                'dateModified' => $discussion->last_posted_at ? $discussion->last_posted_at->toIso8601String() : $discussion->created_at->toIso8601String(),
                'author' => [
                    '@type' => 'Person',
                    'name' => $discussion->user ? $discussion->user->display_name : 'Ulaşım Arşiv',
                    'url' => $discussion->user ? rtrim($this->url->to('forum')->base(), '/') . "/u/" . $discussion->user->username : rtrim($this->url->to('forum')->base(), '/')
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $this->settings->get('forum_title'),
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => rtrim($this->url->to('forum')->base(), '/') . '/assets/' . $this->settings->get('logo_path')
                    ]
                ],
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $canonicalUrl
                ],
                'image' => []
            ];

            foreach ($allImages as $image) {
                $schemaUrl = str_replace(' ', '%20', $image['url']);
                $schemaUrl = str_replace(['/thumb_', '/mini_'], '/', $schemaUrl);
                
                $imgCaption = $image['alt'] ?: $pageTitle;
                $imgCaption = str_replace(['**', '  '], ['', ' '], $imgCaption); // Temizlik
                
                // MARKA VE ADRES DAMGALAMA (BRANDING)
                $brandInfo = ' - ' . $this->settings->get('forum_title') . ' (forum.ulasimarsiv.com)';
                $fullCaption = $imgCaption . $brandInfo;
                
                $imgKeywords = '';
                if (!empty($imgCaption)) {
                    $parts = preg_split('/[\|\-\/]+/', $imgCaption);
                    $parts = array_filter(array_map('trim', $parts));
                    $imgKeywords = implode(', ', $parts) . ', Ulaşım Arşiv, Otobüs Fotoğrafları';
                }

                $jsonLd['image'][] = [
                    '@type' => 'ImageObject',
                    'url' => $schemaUrl,
                    'caption' => $fullCaption,
                    'description' => $fullCaption . ' fotoğrafı ve detaylı bilgileri.',
                    'keywords' => $imgKeywords
                ];
            }

            // Breadcrumb (Discussion Page)
            $breadcrumbs = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => 'Ana Sayfa',
                        'item' => rtrim($this->url->to('forum')->base(), '/')
                    ]
                ]
            ];

            if ($discussion->tags && count($discussion->tags) > 0) {
                $pos = 2;
                foreach ($discussion->tags as $tag) {
                    $breadcrumbs['itemListElement'][] = [
                        '@type' => 'ListItem',
                        'position' => $pos++,
                        'name' => $tag->name,
                        'item' => rtrim($this->url->to('forum')->base(), '/') . '/t/' . $tag->slug
                    ];
                }
                $breadcrumbs['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $pos,
                    'name' => $discussion->title,
                    'item' => $canonicalUrl
                ];
            } else {
                $breadcrumbs['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $discussion->title,
                    'item' => $canonicalUrl
                ];
            }

            $document->head[] = '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
            $document->head[] = '<script type="application/ld+json">' . json_encode($breadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

        } catch (\Exception $e) {
            $document->head[] = '<!-- SEO PACK ERROR: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . ' -->';
        }
    }

    private function extractAllImages($post, $baseUrl) {
        $foundImages = [];
        $content = $post->content;
        
        // 1. [ulasimarsiv-image] veya [upl-image-preview] taraması
        if (preg_match_all('/\[(?:ulasimarsiv-image|upl-image-preview).*?\]/is', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                $url = '';
                $alt = '';
                
                if (preg_match('/url=["\']?(http[^"\'\]]+\.(?:jpg|jpeg|png|webp))["\']?/i', $tag, $uMatches)) {
                    $url = $uMatches[1];
                } elseif (preg_match('/url=([^\]\s]+\.(?:jpg|jpeg|png|webp))/i', $tag, $uMatches)) {
                    $url = $uMatches[1];
                }
                
                if (preg_match('/alt="([^"]+)"/i', $tag, $aMatches)) {
                    $alt = $aMatches[1];
                } elseif (preg_match('/alt=\'([^\']+)\'/i', $tag, $aMatches)) {
                    $alt = $aMatches[1];
                } elseif (preg_match('/alt=([^\]]+)/i', $tag, $aMatches)) {
                    $alt = trim(trim($aMatches[1], '"\''));
                }
                
                if ($url) {
                    $url = str_replace(['thumb_', 'mini_'], '', $url);
                    $foundImages[] = ['url' => $url, 'alt' => $alt];
                }
            }
        }

        // 2. Markdown ![alt](url) taraması
        if (preg_match_all('/!\[(.*?)\]\((.*?)\)/is', $content, $matches)) {
            foreach ($matches[2] as $index => $found) {
                if (strpos($found, 'http') === false) {
                    $found = $baseUrl . '/' . ltrim($found, '/');
                }
                $foundImages[] = ['url' => $found, 'alt' => $matches[1][$index]];
            }
        }

        // 3. BBCode [img]url[/img] taraması
        if (preg_match_all('/\[img\](.*?)\[\/img\]/is', $content, $matches)) {
            foreach ($matches[1] as $found) {
                if (strpos($found, 'http') === false) {
                    $found = $baseUrl . '/' . ltrim($found, '/');
                }
                $foundImages[] = ['url' => $found, 'alt' => ''];
            }
        }

        // 4. HTML img tag taraması
        if (empty($foundImages)) {
            $html = $post->contentHtml ?? '';
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
                foreach ($matches[1] as $found) {
                    if (strpos($found, 'http') === false) {
                        $found = $baseUrl . '/' . ltrim($found, '/');
                    }
                    $foundImages[] = ['url' => $found, 'alt' => ''];
                }
            }
        }

        return $foundImages;
    }

    private function extractDescription($post) {
        $raw = preg_replace('/\[ulasimarsiv-image.*?\]/s', '', $post->content);
        $raw = preg_replace('/\[url=.*?\](.*?)\[\/url\]/s', '$1', $raw);
        $desc = strip_tags($post->contentHtml ?? $raw);
        return Str::limit(trim(preg_replace('/\s+/', ' ', $desc)), 155);
    }
}