<?php

namespace UlasimArsiv\SeoPack;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Google\Client;
use Google\Service\Indexing;

/**
 * Paylaşımlı hosting için web üzerinden tetiklenebilen
 * güvenli Google Indexing API başlatıcısı.
 *
 * URL: /api/seo/trigger-index?key=BURAYA_GIZLI_ANAHTARIN
 */
class WebAutoIndexController implements RequestHandlerInterface
{
    protected $settings;
    protected $url;

    public function __construct(SettingsRepositoryInterface $settings, UrlGenerator $url)
    {
        $this->settings = $settings;
        $this->url = $url;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Güvenlik: Gizli anahtar kontrolü
        $params    = $request->getQueryParams();
        $secretKey = $this->settings->get('ulasimarsiv-seo.trigger_secret', '');

        if (empty($secretKey) || ($params['key'] ?? '') !== $secretKey) {
            return new JsonResponse(['error' => 'Yetkisiz erişim.'], 403);
        }

        // 2. Google Client
        $keyFile = storage_path('service_account.json');
        if (!file_exists($keyFile)) {
            return new JsonResponse(['error' => 'service_account.json bulunamadı.'], 500);
        }

        $client = new Client();
        $client->setAuthConfig($keyFile);
        $client->addScope('https://www.googleapis.com/auth/indexing');
        $indexingService = new Indexing($client);

        // 3. Son 12 saatteki güncellemeleri çek
        $timeLimit = new \DateTime('-12 hours');
        $baseUrl   = rtrim($this->url->to('forum')->base(), '/');

        $urlsToNotify = [];

        $discussions = \Flarum\Discussion\Discussion::whereNull('hidden_at')
            ->where('last_posted_at', '>=', $timeLimit)
            ->where(function ($query) {
                $query->whereNull('seo_last_sent_at')
                      ->orWhereColumn('seo_last_sent_at', '<', 'last_posted_at');
            })
            ->get();

        foreach ($discussions as $disc) {
            $urlsToNotify[] = $baseUrl . '/d/' . $disc->id . '-' . $disc->slug;
            $disc->seo_last_sent_at = \Carbon\Carbon::now();
            $disc->save();
        }

        $posts = CommentPost::whereNull('hidden_at')
            ->where('created_at', '>=', $timeLimit)
            ->whereHas('discussion', function ($query) {
                $query->whereNull('seo_last_sent_at')
                      ->orWhereColumn('seo_last_sent_at', '<', 'last_posted_at');
            })
            ->with('discussion')
            ->get();

        foreach ($posts as $post) {
            if ($post->discussion) {
                $urlsToNotify[] = $baseUrl . '/d/' . $post->discussion->id
                    . '-' . $post->discussion->slug . '/' . $post->number;
                $post->discussion->seo_last_sent_at = \Carbon\Carbon::now();
                $post->discussion->save();
            }
        }

        $urlsToNotify = array_unique($urlsToNotify);

        // 4. Google'a bildir
        $success = 0;
        $errors  = [];

        foreach ($urlsToNotify as $url) {
            $urlNotification = new Indexing\UrlNotification();
            $urlNotification->setUrl($url);
            $urlNotification->setType('URL_UPDATED');

            try {
                $indexingService->urlNotifications->publish($urlNotification);
                $success++;
            } catch (\Exception $e) {
                $errors[] = ['url' => $url, 'error' => $e->getMessage()];
                @file_put_contents(
                    storage_path('logs/seo-indexing.log'),
                    '[' . date('Y-m-d H:i:s') . '] Error for ' . $url . ': ' . $e->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
            }
        }

        return new JsonResponse([
            'status'    => 'ok',
            'notified'  => $success,
            'total'     => count($urlsToNotify),
            'errors'    => count($errors),
            'timestamp' => (new \DateTime('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d H:i:s'),
        ]);
    }
}
