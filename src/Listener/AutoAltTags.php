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

    public function whenPostCreated(Posted $event)
    {
        $this->updateAltTags($event->post);
    }

    public function whenPostRevised(Revised $event)
    {
        $this->updateAltTags($event->post);
    }

    protected function updateAltTags($post)
    {
        try {
            // Post veya içerik yoksa veya zaten null ise işlem yapma
            if (!$post || !isset($post->content) || $post->content === null) {
                return;
            }

            $xml = (string) $post->content;

            // Hem küçük hem büyük harf kontrolü
            if (empty($xml) || !preg_match('/(ulasimarsiv-image|upl-image-preview)/i', $xml)) {
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';

            // Künye bilgisini çek
            $cleanText = strip_tags($xml);
            preg_match_all('/\*\*(.*?)\*\*/s', $cleanText, $matches);
            
            $newAltText = '';
            if (!empty($matches[1])) {
                $cleanParts = array_map(function($item) {
                    return trim(str_replace(['"', "'", '[', ']'], '', $item));
                }, $matches[1]);
                $cleanParts = array_values(array_filter($cleanParts));
                $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
            }

            $changed = false;

            // XML güncelleme
            $newXml = preg_replace_callback('/<(ulasimarsiv-image|upl-image-preview)\s([^>]+)>/i', function($m) use ($newAltText, $discussionTitle, &$changed) {
                $tagName = $m[1];
                $attrs = $m[2];

                $finalAlt = !empty($newAltText) ? $newAltText : ($discussionTitle . ' - forum.ulasimarsiv.com');

                $finalAlt = str_replace(['"', '[', ']', '{', '}', '<', '>'], '', $finalAlt);
                $finalAlt = trim(Str::limit($finalAlt, 120));
                $safeAlt = htmlspecialchars($finalAlt, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                if (preg_match('/alt="[^"]*"/i', $attrs)) {
                    $newAttrs = preg_replace('/alt="[^"]*"/i', 'alt="' . $safeAlt . '"', $attrs);
                } else {
                    $newAttrs = rtrim($attrs) . ' alt="' . $safeAlt . '"';
                }

                if ($newAttrs !== $attrs) $changed = true;

                return '<' . $tagName . ' ' . $newAttrs . '>';
            }, $xml);

            if ($changed && !empty($newXml)) {
                // DB yerine Eloquent kullan: Flarum'un Revised hatasını önler
                $post->content = $newXml;
                
                // Save işlemini sessizce yap (event döngüsüne girmemesi için gerekirse)
                // Ama burada Revised zaten tetiklendiği için normal save yeterli olmalı
                $post->save();
            }

        } catch (Throwable $e) {
            @file_put_contents(storage_path('logs/seo-error.log'), '['.date('Y-m-d H:i:s').'] Post Error: '.$e->getMessage().PHP_EOL, FILE_APPEND);
        }
    }
}