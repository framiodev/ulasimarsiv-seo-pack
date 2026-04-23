<?php

namespace UlasimArsiv\SeoPack\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Http\UrlGenerator;
use Google\Client;
use Google\Service\Indexing;

class AutoIndexCommand extends AbstractCommand
{
    protected $settings;
    protected $url;

    public function __construct(SettingsRepositoryInterface $settings, UrlGenerator $url)
    {
        $this->settings = $settings;
        $this->url = $url;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('seo:auto-index')
             ->setDescription('Görsel içerikli mesajları otomatik olarak Google Indexing API\'ye gönderir.');
    }

    protected function fire()
    {
        $keyFile = storage_path('service_account.json');
        if (!file_exists($keyFile)) {
            $this->error('service_account.json dosyası bulunamadı.');
            return;
        }

        $client = new Client();
        $client->setAuthConfig($keyFile);
        $client->addScope('https://www.googleapis.com/auth/indexing');
        $indexingService = new Indexing($client);

        $timeLimit = new \DateTime('-24 hours');
        
        $discussions = \Flarum\Discussion\Discussion::whereNull('hidden_at')
            ->where('last_posted_at', '>=', $timeLimit)
            ->where(function ($query) {
                $query->whereNull('seo_last_sent_at')
                      ->orWhereColumn('seo_last_sent_at', '<', 'last_posted_at');
            })
            ->get();

        $urlsToNotify = [];
        $baseUrl = rtrim($this->url->to('forum')->base(), '/');

        foreach ($discussions as $discussion) {
            $urlsToNotify[] = $baseUrl . '/d/' . $discussion->id . '-' . $discussion->slug;
            $discussion->seo_last_sent_at = \Carbon\Carbon::now();
            $discussion->save();
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
                $urlsToNotify[] = $baseUrl . '/d/' . $post->discussion->id . '-' . $post->discussion->slug . '/' . $post->number;
                $post->discussion->seo_last_sent_at = \Carbon\Carbon::now();
                $post->discussion->save();
            }
        }

        $urlsToNotify = array_unique($urlsToNotify);
        $count = 0;

        foreach ($urlsToNotify as $url) {
            $urlNotification = new Indexing\UrlNotification();
            $urlNotification->setUrl($url);
            $urlNotification->setType('URL_UPDATED');

            try {
                $indexingService->urlNotifications->publish($urlNotification);
                $count++;
            } catch (\Exception $e) {
                @file_put_contents(storage_path('logs/seo-indexing.log'), '['.date('Y-m-d H:i:s').'] Indexing Error for '.$url.': '.$e->getMessage().PHP_EOL, FILE_APPEND);
            }
        }

        $this->info("Son 24 saatteki $count adet URL Google'a başarıyla bildirildi.");
    }
}