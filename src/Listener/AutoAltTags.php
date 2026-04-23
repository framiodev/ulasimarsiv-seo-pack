<?php

namespace UlasimArsiv\SeoPack\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

use Flarum\Formatter\Formatter;

class AutoAltTags
{
    protected $settings;
    protected $db;
    protected $formatter;

    public function __construct(SettingsRepositoryInterface $settings, ConnectionInterface $db, Formatter $formatter)
    {
        $this->settings = $settings;
        $this->db = $db;
        $this->formatter = $formatter;
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
        if (!$post) return;

        $xml = $post->content;

        if (strpos($xml, 'ULASIMARSIV-IMAGE') === false && strpos($xml, 'UPL-IMAGE-PREVIEW') === false) {
            return;
        }

        $discussionTitle = $post->discussion ? $post->discussion->title : '';
        $forumUrl = 'forum.ulasimarsiv.com';

        $formatter = $this->formatter;
        $sourceText = $formatter->unparse($xml);

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
            $table = $post->getTable();
            $this->db->table($table)->where('id', $post->id)->update(['content' => $xml]);
        }
    }
}