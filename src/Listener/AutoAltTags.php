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
        $logFile = storage_path('logs/seo-pack.log');
        $log = function($msg) use ($logFile) {
            @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] ' . $msg . PHP_EOL, FILE_APPEND);
        };

        try {
            if (!$post) return;
            $log("İşlem başlıyor: Post ID " . $post->id);

            $content = $post->content;
            if (empty($content)) {
                $log("HATA: İçerik boş.");
                return;
            }

            // Etiket kontrolü
            if (strpos($content, '[ulasimarsiv-image') === false && strpos($content, '[upl-image-preview') === false) {
                $log("Bilgi: İçerikte desteklenen BBCode bulunamadı.");
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';

            // Künye bilgisini çek (Tırnaklı veya tırnaksız her türlü **...** yapısını yakala)
            preg_match_all('/\*\*(.*?)\*\*/s', $content, $matches);
            $newAltText = '';
            if (!empty($matches[1])) {
                $cleanParts = array_map(function($item) {
                    return trim(str_replace(['"', "'"], '', $item));
                }, $matches[1]);
                
                // Sadece anlamlı (boş olmayan) kısımları al
                $cleanParts = array_values(array_filter($cleanParts));
                $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
                $log("Bulunan Künye: " . $newAltText);
            }

            $changed = false;

            // BBCode güncelleme
            $content = preg_replace_callback('/\[(ulasimarsiv-image|upl-image-preview)\s([^\]]+)\]/', function($m) use ($newAltText, $discussionTitle, &$changed, $log) {
                $tagName = $m[1];
                $attrs = $m[2];

                if (!empty($newAltText)) {
                    $finalAlt = $newAltText;
                } else {
                    $finalAlt = $discussionTitle . ' - forum.ulasimarsiv.com';
                }

                // Karakter temizliği
                $finalAlt = str_replace(['"', '[', ']', '{', '}', '<', '>'], '', $finalAlt);
                $finalAlt = trim(Str::limit($finalAlt, 120));

                if (strpos($attrs, 'alt=') !== false) {
                    $newAttrs = preg_replace('/alt="[^"]*"/', 'alt="' . $finalAlt . '"', $attrs);
                } else {
                    $newAttrs = $attrs . ' alt="' . $finalAlt . '"';
                }

                if ($newAttrs !== $attrs) {
                    $changed = true;
                    $log("Etiket güncellendi: " . $finalAlt);
                }

                return '[' . $tagName . ' ' . $newAttrs . ']';
            }, $content);

            if ($changed && !empty($content)) {
                $table = $post->getTable();
                $log("Veritabanına yazılıyor: " . $table . " (ID: " . $post->id . ")");
                app('db')->table($table)->where('id', $post->id)->update(['content' => $content]);
                $log("Yazma işlemi tamamlandı.");
            } else {
                $log("Bilgi: Herhangi bir değişiklik yapılmadı.");
            }

        } catch (Throwable $e) {
            $log("KRİTİK HATA: " . $e->getMessage());
        }
    }
}