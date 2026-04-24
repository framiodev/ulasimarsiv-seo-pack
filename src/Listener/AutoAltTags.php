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
            if (!$post) return;

            // Flarum 2.0'da veritabanındaki 'content' sütunu XML saklar.
            $xml = $post->content;

            if (empty($xml) || (strpos($xml, 'ULASIMARSIV-IMAGE') === false && strpos($xml, 'UPL-IMAGE-PREVIEW') === false)) {
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';

            // Künye bilgisini çek (XML içindeki metinden ayıkla)
            // Flarum XML içinde metinler genellikle sarmalanmış haldedir, biz ham halini bulmaya çalışalım
            $cleanText = strip_tags($xml);
            preg_match_all('/\*\*(.*?)\*\*/s', $cleanText, $matches);
            
            $newAltText = '';
            if (!empty($matches[1])) {
                $cleanParts = array_map(function($item) {
                    return trim(str_replace(['"', "'"], '', $item));
                }, $matches[1]);
                $cleanParts = array_values(array_filter($cleanParts));
                $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
            }

            $changed = false;

            // XML içindeki büyük harfli etiketleri güncelle
            $xml = preg_replace_callback('/<(ULASIMARSIV-IMAGE|UPL-IMAGE-PREVIEW)\s([^>]+)>/', function($m) use ($newAltText, $discussionTitle, &$changed) {
                $tagName = $m[1];
                $attrs = $m[2];

                if (!empty($newAltText)) {
                    $finalAlt = $newAltText;
                } else {
                    $finalAlt = $discussionTitle . ' - forum.ulasimarsiv.com';
                }

                // Karakter temizliği ve XML güvenliği
                $finalAlt = str_replace(['"', '[', ']', '{', '}', '<', '>'], '', $finalAlt);
                $finalAlt = trim(Str::limit($finalAlt, 120));
                $safeAlt = htmlspecialchars($finalAlt, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                if (strpos($attrs, 'alt=') !== false) {
                    $newAttrs = preg_replace('/alt="[^"]*"/', 'alt="' . $safeAlt . '"', $attrs);
                } else {
                    $newAttrs = $attrs . ' alt="' . $safeAlt . '"';
                }

                if ($newAttrs !== $attrs) $changed = true;

                return '<' . $tagName . ' ' . $newAttrs . '>';
            }, $xml);

            if ($changed && !empty($xml)) {
                $table = $post->getTable();
                app('db')->table($table)->where('id', $post->id)->update(['content' => $xml]);
            }

        } catch (Throwable $e) {
            if (file_exists(storage_path('logs'))) {
                @file_put_contents(storage_path('logs/seo-error.log'), '['.date('Y-m-d H:i:s').'] Error: '.$e->getMessage().PHP_EOL, FILE_APPEND);
            }
        }
    }
}