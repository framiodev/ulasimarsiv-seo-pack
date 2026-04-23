<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Post\Post;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SitemapController implements RequestHandlerInterface
{
    protected $url;
    protected $settings;

    public function __construct(UrlGenerator $url, SettingsRepositoryInterface $settings)
    {
        $this->url = $url;
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->settings->get('ulasimarsiv-seo.enable_sitemap')) {
            return new TextResponse('Sitemap is disabled by admin.', 404);
        }

        if (ob_get_length()) ob_end_clean();

        $baseUrl = rtrim($this->url->to('forum')->base(), '/');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;

        $posts = Post::where('type', 'comment')
            ->whereNull('hidden_at')
            ->where(function($query) {
                $query->where('content', 'LIKE', '%[ulasimarsiv-image%')
                      ->orWhere('content', 'LIKE', '%img%')
                      ->orWhere('content', 'LIKE', '%http%');
            })
            ->orderBy('created_at', 'desc')
            ->limit(10000)

            ->get();

        foreach ($posts as $post) {
            if (!$post->discussion) continue;

            if ($post->number == 1) {
                $url = $baseUrl . '/d/' . $post->discussion->id . '-' . $post->discussion->slug;
            } else {
                $url = $baseUrl . '/d/' . $post->discussion->id . '-' . $post->discussion->slug . '/' . $post->number;
            }
            
            $imgSrcs = $this->extractAllImageSrcs($post, $baseUrl);

            if (!empty($imgSrcs)) {
                $cleanTitle = trim(preg_replace('/\[.*?\]/', '', $post->discussion->title));
                $cleanTitle = strip_tags($cleanTitle);
                
                $altText = null;
                if (preg_match('/\[ulasimarsiv-image[^\]]+alt=(?:"([^"]*)"|([^\]]+))/i', $post->content, $altMatches)) {
                    $altText = trim($altMatches[1] ?: $altMatches[2]);
                } elseif (preg_match('/!\[(.*?)\]\(.*?\)/i', $post->content, $altMatches)) {
                    $altText = trim($altMatches[1]);
                }

                $finalTitle = $cleanTitle;
                if (!empty($altText) && $altText !== $cleanTitle) {
                    $finalTitle = $altText . ' - ' . $cleanTitle;
                }

                $cleanTitleEncoded = htmlspecialchars($finalTitle, ENT_XML1, 'UTF-8');
                $loc = htmlspecialchars($url, ENT_XML1, 'UTF-8');
                
                $xml .= '<url>' . PHP_EOL;
                $xml .= "<loc>$loc</loc>" . PHP_EOL;
                $xml .= '<lastmod>' . $post->created_at->format('c') . '</lastmod>' . PHP_EOL;
                
                $ageInDays = $post->created_at->diffInDays(\Carbon\Carbon::now());
                $changefreq = 'weekly';
                if ($ageInDays <= 1) $changefreq = 'hourly';
                elseif ($ageInDays <= 7) $changefreq = 'daily';
                elseif ($ageInDays > 30) $changefreq = 'monthly';
                
                $xml .= "<changefreq>$changefreq</changefreq>" . PHP_EOL;
                
                foreach ($imgSrcs as $imgSrc) {
                    $imgSrcEncoded = str_replace(' ', '%20', $imgSrc);
                    $imgSrcEncoded = str_replace(['/thumb_', '/mini_'], '/', $imgSrcEncoded);
                    $imgLoc = htmlspecialchars($imgSrcEncoded, ENT_XML1, 'UTF-8');

                    $xml .= '<image:image>' . PHP_EOL;
                    $xml .= "<image:loc>$imgLoc</image:loc>" . PHP_EOL;
                    $xml .= "<image:title>$cleanTitleEncoded</image:title>" . PHP_EOL;
                    $xml .= '</image:image>' . PHP_EOL;
                }
                
                $xml .= '</url>' . PHP_EOL;
            }
        }

        $xml .= '</urlset>';

        return new TextResponse($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate'
        ]);
    }

    private function extractAllImageSrcs($post, $baseUrl) {
        $images = [];

        if (preg_match_all('/\[ulasimarsiv-image[^\]]+url=["\']?([^"\'\]]+\.(?:jpg|jpeg|png|webp))["\']?/i', $post->content, $matches)) {
            foreach ($matches[1] as $match) {
                $images[] = $match;
            }
        }

        $content = $post->contentHtml ?? $post->content;
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $found) {
                if (strpos($found, 'http') === false) {
                    $images[] = $baseUrl . '/' . ltrim($found, '/');
                } else {
                    $images[] = $found;
                }
            }
        }

        if (preg_match_all('/!\[.*?\]\((.*?)\)/i', $post->content, $matches)) {
            foreach ($matches[1] as $found) {
                if (strpos($found, 'http') === false) {
                    $images[] = $baseUrl . '/' . ltrim($found, '/');
                } else {
                    $images[] = $found;
                }
            }
        }

        if (preg_match_all('/\[img\](.*?)\[\/img\]/i', $post->content, $matches)) {
            foreach ($matches[1] as $found) {
                if (strpos($found, 'http') === false) {
                    $images[] = $baseUrl . '/' . ltrim($found, '/');
                } else {
                    $images[] = $found;
                }
            }
        }

        return array_unique($images);
    }
}