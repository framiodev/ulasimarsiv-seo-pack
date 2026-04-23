<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Flarum\Http\UrlGenerator;

class ContentListController implements RequestHandlerInterface
{
    protected $settings;
    protected $url;

    public function __construct(SettingsRepositoryInterface $settings, UrlGenerator $url)
    {
        $this->settings = $settings;
        $this->url = $url;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $type = isset($params['type']) ? $params['type'] : 'discussion';
        
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $items = [];
        $total = 0;

        $baseUrl = $this->url->to('forum')->base();

        if ($type === 'page') {
            if (class_exists('FoF\Pages\Page')) {
                $total = \FoF\Pages\Page::where('is_hidden', false)->count();
                $query = \FoF\Pages\Page::where('is_hidden', false)
                    ->orderBy('id', 'asc')
                    ->skip($offset)
                    ->take($limit)
                    ->get();

                foreach ($query as $p) {
                    $items[] = [
                        'id' => $p->id,
                        'title' => $p->title,
                        'url' => $baseUrl . '/p/' . $p->id . '-' . $p->slug
                    ];
                }
            }
        } else {
            $total = Discussion::whereNull('hidden_at')->count();
            $query = Discussion::whereNull('hidden_at')
                ->orderBy('id', 'asc')
                ->skip($offset)
                ->take($limit)
                ->get();

            foreach ($query as $d) {
                try {
                    $firstPost = \Flarum\Post\Post::find($d->first_post_id);
                    $description = 'Ulaşım Arşiv otobüs, kamyon ve ağır vasıta paylaşımları...';
                    if ($firstPost) {
                        $raw = preg_replace('/\[.*?\]/s', '', $firstPost->content);
                        $description = \Illuminate\Support\Str::limit(strip_tags($raw), 150);
                    }

                    $items[] = [
                        'id' => $d->id,
                        'title' => $d->title,
                        'url' => $baseUrl . '/d/' . $d->id . '-' . $d->slug,
                        'description' => $description,
                        'comment_count' => $d->comment_count ?? 0,
                        'last_posted_at' => $d->last_posted_at ? $d->last_posted_at->format('Y-m-d H:i') : null,
                        'seo_last_sent_at' => (isset($d->seo_last_sent_at) && $d->seo_last_sent_at && $d->seo_last_sent_at !== '0000-00-00 00:00:00') ? \Carbon\Carbon::parse($d->seo_last_sent_at)->format('Y-m-d H:i') : null
                    ];
                } catch (\Exception $e) {
                    $items[] = [
                        'id' => $d->id ?? 0,
                        'title' => 'HATA: ' . $e->getMessage(),
                        'url' => '#',
                        'comment_count' => 0,
                        'last_posted_at' => null,
                        'seo_last_sent_at' => null
                    ];
                }
            }
        }

        return new JsonResponse([
            'items' => $items,
            'total_pages' => ceil($total / $limit),
            'current_page' => $page,
            'debug_total' => $total
        ]);
    }
}