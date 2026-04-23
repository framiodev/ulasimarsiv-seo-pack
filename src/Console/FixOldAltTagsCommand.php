<?php

namespace UlasimArsiv\SeoPack\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;

class FixOldAltTagsCommand extends AbstractCommand
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('seo:fix-old-alt')
             ->setDescription('Eski mesajlardaki [ulasimarsiv-image] alt etiketlerini toplu olarak günceller.');
    }

    protected function fire()
    {
        $this->info('Eski mesajlar taranıyor...');

        $query = CommentPost::where('content', 'like', '%ulasimarsiv-image%')
            ->orWhere('content', 'like', '%upl-image-preview%');
        $count = $query->count();
        $processed = 0;
        $updated = 0;

        $forumTitle = $this->settings->get('forum_title') ?: 'Ulaşım Arşiv';

        $query->chunk(100, function ($posts) use (&$processed, &$updated, $forumTitle) {
            foreach ($posts as $post) {
                $processed++;
                $originalContent = $post->content;
                $content = $originalContent;

                if (strpos($content, '[ulasimarsiv-image') !== false || strpos($content, '[upl-image-preview') !== false) {
                    $discussionTitle = $post->discussion ? $post->discussion->title : '';
                    
                    preg_match_all('/-\s*\*\*(.*?)\*\*/s', $content, $matches);
                    $newAltText = '';
                    if (!empty($matches[1])) {
                        $cleanParts = array_map('trim', $matches[1]);
                        $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
                    }

                    $pattern = '/(\[(?:ulasimarsiv-image|upl-image-preview).*?\])/s';
                    $content = preg_replace_callback($pattern, function($m) use ($newAltText, $discussionTitle, $forumTitle) {
                        $tag = $m[1];
                        $tagName = strpos($tag, '[ulasimarsiv-image') !== false ? '[ulasimarsiv-image' : '[upl-image-preview';

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
                                $filename = ucwords(str_replace(['-', '_'], ' ', $filename));
                            }
                            
                            $forumUrl = 'forum.ulasimarsiv.com';
                            $finalAlt = ($filename ? $filename . ' - ' : '') . $discussionTitle . ' - ' . $forumUrl;
                        }

                        $finalAlt = str_replace(['"', '{TEXT?}'], '', $finalAlt);
                        $finalAlt = Str::limit($finalAlt, 150);

                        if (preg_match('/alt=/', $tag)) {
                            return preg_replace('/alt=["\']?([^"\'\]]*)["\']?/i', 'alt="' . $finalAlt . '"', $tag);
                        } else {
                            return str_replace(']', ' alt="' . $finalAlt . '"]', $tag);
                        }

                    }, $content);

                    if ($content !== $originalContent) {
                        $post->content = $content;
                        $post->save();
                        $updated++;
                    }
                }
            }
            $this->info("$processed mesaj kontrol edildi...");
        });

        $this->info("İşlem tamamlandı: $processed mesaj tarandı, $updated mesaj güncellendi.");
    }
}
