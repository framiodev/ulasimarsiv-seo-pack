<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Flarum\Http\UrlGenerator;
use Google\Client;
use Flarum\Formatter\Formatter;
use Google\Service\Indexing;

class BatchIndexController implements RequestHandlerInterface
{
    protected $settings;
    protected $url;
    protected $db;
    protected $formatter;

    public function __construct(SettingsRepositoryInterface $settings, UrlGenerator $url, ConnectionInterface $db, Formatter $formatter)
    {
        $this->settings = $settings;
        $this->url = $url;
        $this->db = $db;
        $this->formatter = $formatter;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $lastIndex = (int) $this->settings->get('ulasimarsiv-seo.last_batch_index', 0);
            $batchSize = 50;

            $keyFile = storage_path('service_account.json');
            if (!file_exists($keyFile)) {
                return new JsonResponse(['status' => 'error', 'message' => 'service_account.json dosyası eksik.'], 500);
            }

            $client = new Client();
            $client->setAuthConfig($keyFile);
            $client->addScope('https://www.googleapis.com/auth/indexing');
            $indexingService = new Indexing($client);

            $query = CommentPost::whereNull('hidden_at')
                ->where(function($q) {
                    $q->where('content', 'like', '%ulasimarsiv-image%')
                      ->orWhere('content', 'like', '%upl-image-preview%')
                      ->orWhere('content', 'like', '%[img]%')
                      ->orWhere('content', 'like', '%<img%');
                });

            $totalCount = $query->count();
            
            $posts = $query->skip($lastIndex)
                ->take($batchSize)
                ->with('discussion')
                ->get();

            if ($posts->isEmpty()) {
                $this->settings->set('ulasimarsiv-seo.last_batch_index', 0);
                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'Tüm görsel içerikli mesajlar tarandı! Başa dönüldü.',
                    'done' => true,
                    'total' => $totalCount
                ]);
            }

            $indexedCount = 0;
            $baseUrl = $this->url->to('forum')->base();

            foreach ($posts as $post) {
                if (!$post->discussion) continue;

                $url = $baseUrl . '/d/' . $post->discussion->id . '-' . $post->discussion->slug . '/' . $post->number;
                
                $this->fixAltTags($post);
                
                $urlNotification = new Indexing\UrlNotification();
                $urlNotification->setUrl($url);
                $urlNotification->setType('URL_UPDATED');

                try {
                    $indexingService->urlNotifications->publish($urlNotification);
                    $indexedCount++;
                } catch (\Exception $e) {
                    continue; 
                }
            }

            $newIndex = $lastIndex + $posts->count();
            $this->settings->set('ulasimarsiv-seo.last_batch_index', $newIndex);

            return new JsonResponse([
                'status' => 'success',
                'message' => "Sıradaki $indexedCount görsel içerikli mesaj Google'a bildirildi.",
                'next_skip' => $newIndex,
                'total' => $totalCount
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'API Hatası: ' . $e->getMessage()], 200);
        }
    }

    protected function fixAltTags($post) {
        $xml = $post->getAttributes()['content'];

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