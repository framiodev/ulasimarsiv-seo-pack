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

            // Flarum 2.0'da hem veritabanında hem de modelde 'content' ham Markdown'ı tutar.
            $content = $post->content;

            if (empty($content) || (strpos($content, '[ulasimarsiv-image') === false && strpos($content, '[upl-image-preview') === false)) {
                return;
            }

            $discussionTitle = $post->discussion ? $post->discussion->title : '';
            $forumUrl = 'forum.ulasimarsiv.com';

            // Künye bilgisini çek (Varan | 06 AH 3256 gibi)
            preg_match_all('/-\s*\*\*(.*?)\*\*/s', $content, $matches);
            $newAltText = '';
            if (!empty($matches[1])) {
                $cleanParts = array_map('trim', $matches[1]);
                // İlk iki kalın yazılı metni al ve birleştir
                $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
            }

            $changed = false;

            // BBCode içindeki alt="..." kısmını güncelle
            $content = preg_replace_callback('/\[(ulasimarsiv-image|upl-image-preview)\s([^\]]+)\]/', function($m) use ($newAltText, $discussionTitle, $forumUrl, &$changed) {
                $tagName = $m[1];
                $attrs = $m[2];

                if (!empty($newAltText)) {
                    $finalAlt = $newAltText;
                } else {
                    preg_match('/url="([^"]+)"/', $attrs, $urlM);
                    $url = $urlM[1] ?? '';
                    $path = parse_url($url, PHP_URL_PATH);
                    $filename = $path ? ucwords(str_replace(['-', '_'], ' ', pathinfo($path, PATHINFO_FILENAME))) : '';
                    $finalAlt = ($filename ? $filename . ' - ' : '') . $discussionTitle . ' - ' . $forumUrl;
                }

                // Karakter temizliği
                $finalAlt = str_replace(['"', '[', ']', '{', '}'], '', $finalAlt);
                $finalAlt = Str::limit($finalAlt, 120);

                // Eğer zaten alt etiketi varsa onu değiştir, yoksa ekle (ama bizde hep var)
                if (strpos($attrs, 'alt=') !== false) {
                    $newAttrs = preg_replace('/alt="[^"]*"/', 'alt="' . $finalAlt . '"', $attrs);
                } else {
                    $newAttrs = $attrs . ' alt="' . $finalAlt . '"';
                }

                if ($newAttrs !== $attrs) $changed = true;

                return '[' . $tagName . ' ' . $newAttrs . ']';
            }, $content);

            if ($changed && !empty($content)) {
                $table = $post->getTable();
                // Veritabanını sessizce güncelle (Model save() kullanmıyoruz ki tekrar tetiklenmesin)
                app('db')->table($table)->where('id', $post->id)->update(['content' => $content]);
            }
        } catch (Throwable $e) {
            if (file_exists(storage_path('logs'))) {
                @file_put_contents(storage_path('logs/seo-error.log'), '['.date('Y-m-d H:i:s').'] Error: '.$e->getMessage().PHP_EOL, FILE_APPEND);
            }
        }
    }
}