<?php

namespace UlasimArsiv\SeoPack\Listener;

use Flarum\Post\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;

class AutoAltTags
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function whenPostSaving(Saving $event)
    {
        $this->updateAltTags($event->post);
    }

    protected function updateAltTags($post)
    {
        if (!$post) return;

        // Flarum 2.0: parsed_content XML'i döndürür
        $xml = $post->parsed_content;

        if (empty($xml) || (strpos($xml, 'ULASIMARSIV-IMAGE') === false && strpos($xml, 'UPL-IMAGE-PREVIEW') === false)) {
            return;
        }

        $discussionTitle = $post->discussion ? $post->discussion->title : '';
        $forumUrl = 'forum.ulasimarsiv.com';

        // Flarum 2.0: content doğrudan Markdown metni döndürür. Formatter'a gerek yok.
        $sourceText = $post->content;

        preg_match_all('/-\s*\*\*(.*?)\*\*/s', $sourceText, $matches);
        $newAltText = '';
        if (!empty($matches[1])) {
            $cleanParts = array_map('trim', $matches[1]);
            $newAltText = implode(' - ', array_slice($cleanParts, 0, 2));
        }

        $changed = false;

        $xml = preg_replace_callback('/<(ULASIMARSIV-IMAGE|UPL-IMAGE-PREVIEW)\s([^>]+)>/', function($m) use ($newAltText, $discussionTitle, $forumUrl, &$changed) {
            $tagName = $m[1];
            $attrs = $m[2];

            if (!empty($newAltText)) {
                $finalAlt = $newAltText;
            } else {
                preg_match('/url="([^"]+)"/', $attrs, $urlM);
                $url = html_entity_decode($urlM[1] ?? '');
                $filename = ucwords(str_replace(['-', '_'], ' ', pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)));
                $finalAlt = ($filename ? $filename . ' - ' : '') . $discussionTitle . ' - ' . $forumUrl;
            }

            $finalAlt = str_replace(['"', '{TEXT?}', '[', ']'], '', $finalAlt);
            $finalAlt = Str::limit($finalAlt, 125);
            $safeAlt = htmlspecialchars($finalAlt, ENT_XML1 | ENT_COMPAT, 'UTF-8');

            $newAttrs = preg_replace('/alt="[^"]*"/', 'alt="' . $safeAlt . '"', $attrs);
            if ($newAttrs !== $attrs) $changed = true;

            return '<' . $tagName . ' ' . $newAttrs . '>';
        }, $xml);

        if ($changed) {
            // Veritabanına manuel sorgu atmıyoruz!
            // Saving olayında olduğumuz için modeli güncelleyip bırakıyoruz, Flarum kalanı kendisi halledecek.
            $post->parsed_content = $xml;
        }
    }
}