<?php

namespace UlasimArsiv\SeoPack\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;
use Throwable;

class AutoAltTags
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function whenPostSaving(\Flarum\Post\Event\Saving $event)
    {
        $this->updateAltTags($event->post);
    }

    protected function updateAltTags($post)
    {
        try {
            if (!$post || !isset($post->content) || $post->content === null) {
                return;
            }

            // Flarum'da `$post->content` ham Markdown / BBCode metnini verir.
            $markdown = (string) $post->content;

            if (empty($markdown) || !preg_match('/\[(ulasimarsiv-image|upl-image-preview)/i', $markdown)) {
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';

            // Künye bilgisini Markdown üzerinden çek
            preg_match_all('/\*\*(.*?)\*\*/s', $markdown, $matches);
            
            $newAltText = '';
            if (!empty($matches[1])) {
                $cleanParts = array_map(function($item) {
                    return trim(str_replace(['"', "'", '[', ']'], '', $item));
                }, $matches[1]);
                $cleanParts = array_values(array_filter($cleanParts));
                $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
            }

            $changed = false;

            // BBCode güncelleme (XML DEĞİL)
            $newMarkdown = preg_replace_callback('/\[(ulasimarsiv-image|upl-image-preview)\s+([^\]]+)\]/i', function($m) use ($newAltText, $discussionTitle, &$changed) {
                $tagName = $m[1];
                $attrs = $m[2];

                $finalAlt = !empty($newAltText) ? $newAltText : ($discussionTitle . ' - forum.ulasimarsiv.com');
                $finalAlt = str_replace(['"', '[', ']', '{', '}', '<', '>'], '', $finalAlt);
                $finalAlt = trim(Str::limit($finalAlt, 120));
                // Markdown içinde kullanacağımız için sadece çift tırnakları encode etmek yeterli
                $safeAlt = htmlspecialchars($finalAlt, ENT_QUOTES, 'UTF-8');

                if (preg_match('/alt="[^"]*"/i', $attrs)) {
                    $newAttrs = preg_replace('/alt="[^"]*"/i', 'alt="' . $safeAlt . '"', $attrs);
                } else {
                    $newAttrs = rtrim($attrs) . ' alt="' . $safeAlt . '"';
                }

                if ($newAttrs !== $attrs) $changed = true;

                return '[' . $tagName . ' ' . $newAttrs . ']';
            }, $markdown);

            if ($changed && !empty($newMarkdown)) {
                // Flarum'un KENDİ parser'ını kullanarak Markdown'ı tekrar geçerli ve güvenli XML'e dönüştür
                $post->setContentAttribute($newMarkdown);
            }

        } catch (Throwable $e) {
            @file_put_contents(storage_path('logs/seo-error.log'), '['.date('Y-m-d H:i:s').'] Post Error: '.$e->getMessage().PHP_EOL, FILE_APPEND);
        }
    }
}