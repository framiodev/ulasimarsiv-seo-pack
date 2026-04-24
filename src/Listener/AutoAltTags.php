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

            // Hem küçük hem büyük harf kontrolü (ulasimarsiv-image veya ULASIMARSIV-IMAGE)
            if (empty($xml) || !preg_match('/(ulasimarsiv-image|upl-image-preview)/i', $xml)) {
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';

            // Künye bilgisini çek (XML içindeki metinden ayıkla)
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

            // XML içindeki etiketleri güncelle (Case-insensitive /i eklendi)
            $xml = preg_replace_callback('/<(ulasimarsiv-image|upl-image-preview)\s([^>]+)>/i', function($m) use ($newAltText, $discussionTitle, &$changed) {
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

                // alt="..." kısmını bul ve değiştir veya ekle
                if (preg_match('/alt="[^"]*"/i', $attrs)) {
                    $newAttrs = preg_replace('/alt="[^"]*"/i', 'alt="' . $safeAlt . '"', $attrs);
                } else {
                    $newAttrs = rtrim($attrs) . ' alt="' . $safeAlt . '"';
                }

                if ($newAttrs !== $attrs) $changed = true;

                return '<' . $tagName . ' ' . $newAttrs . '>';
            }, $xml);

            if ($changed && !empty($xml)) {
                $table = $post->getTable();
                app('db')->table($table)->where('id', $post->id)->update(['content' => $xml]);
            }

        } catch (Throwable $e) {
            // Hata kaydı
            @file_put_contents(storage_path('logs/seo-error.log'), '['.date('Y-m-d H:i:s').'] Post '.$post->id.' Error: '.$e->getMessage().PHP_EOL, FILE_APPEND);
        }
    }
}