<?php

namespace UlasimArsiv\SeoPack;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;

class FixAltTagsController implements RequestHandlerInterface
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute('actor');
        $actor->assertAdmin();

        $query = CommentPost::where('content', 'like', '%ulasimarsiv-image%')
            ->orWhere('content', 'like', '%upl-image-preview%');
        
        // Chunk kullanarak çok fazla post olduğunda belleği şişirmesini engelliyoruz
        $processed = 0;
        $updated = 0;
        $forumTitle = $this->settings->get('forum_title') ?: 'Ulaşım Arşiv';

        $query->chunk(200, function ($posts) use (&$processed, &$updated, $forumTitle) {
            foreach ($posts as $post) {
                $processed++;
                $originalContent = $post->content;
                $content = $originalContent;

                if (strpos($content, '[ulasimarsiv-image') !== false || strpos($content, '[upl-image-preview') !== false) {
                    $discussionTitle = $post->discussion ? $post->discussion->title : '';
                    
                    preg_match_all('/\*\*(.*?)\*\*/s', $content, $matches);
                    $newAltText = '';
                    if (!empty($matches[1])) {
                        $cleanParts = array_map('trim', $matches[1]);
                        $cleanParts = array_map(function($item) {
                            return str_replace(['"', "'", '[', ']'], '', $item);
                        }, $cleanParts);
                        $cleanParts = array_values(array_filter($cleanParts));
                        $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
                    }

                    $pattern = '/(\[(?:ulasimarsiv-image|upl-image-preview).*?\])/s';
                    $content = preg_replace_callback($pattern, function($m) use ($newAltText, $discussionTitle, $forumTitle) {
                        $tag = $m[1];
                        
                        // Alt etiketi zaten var ve içi doluysa DOKUNMA
                        if (preg_match('/alt=["\']([^"\']{3,})["\']/i', $tag)) {
                            return $tag;
                        }

                        if (!empty($newAltText)) {
                            $finalAlt = $newAltText;
                        } else {
                            $imgUrl = '';
                            if (preg_match('/url=["\']?([^"\' \]]+)["\']?/i', $tag, $urlMatches)) {
                                $imgUrl = $urlMatches[1];
                            }
                            
                            $filename = '';
                            if (!empty($imgUrl)) {
                                $filename = pathinfo(parse_url($imgUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                                $filename = ucwords(str_replace(['-', '_', '%20'], ' ', $filename));
                            }
                            
                            $forumUrl = 'forum.ulasimarsiv.com';
                            $finalAlt = ($filename ? $filename . ' - ' : '') . $discussionTitle . ' - ' . $forumUrl;
                        }

                        $finalAlt = str_replace(['"', '{TEXT?}'], '', $finalAlt);
                        $finalAlt = Str::limit(trim($finalAlt), 150);

                        if (preg_match('/alt=["\']/i', $tag)) {
                            return preg_replace('/alt=["\']([^"\'\]]*)["\']/i', 'alt="' . $finalAlt . '"', $tag);
                        } elseif (preg_match('/alt=/i', $tag)) {
                            return preg_replace('/alt=([^ \]]*)/i', 'alt="' . $finalAlt . '"', $tag);
                        } else {
                            return str_replace(']', ' alt="' . $finalAlt . '"]', $tag);
                        }

                    }, $content);

                    if ($content !== $originalContent) {
                        $post->setContentAttribute($content);
                        $post->save();
                        $updated++;
                    }
                }
            }
        });

        return new JsonResponse([
            'message' => "Başarılı! $processed mesaj tarandı, $updated adet resmin alt etiketi onarıldı."
        ]);
    }
}
