<?php

namespace UlasimArsiv\SeoPack\Listener;

use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Http\UrlGenerator;
use Google\Client;
use Google\Service\Indexing;

class SendToGoogleConsole
{
    protected $url;

    public function __construct(UrlGenerator $url)
    {
        $this->url = $url;
    }

    public function whenDiscussionStarted(Started $event)
    {
        $url = $this->url->to('forum')->route('discussion', ['id' => $event->discussion->id]);
        $this->sendToGoogle($url);
        
        $event->discussion->seo_last_sent_at = \Carbon\Carbon::now();
        $event->discussion->save();
    }

    public function whenPostCreated(Posted $event)
    {
        if ($event->post->number == 1) return;

        $baseUrl = $this->url->to('forum')->base();
        $finalUrl = $baseUrl . '/d/' . $event->post->discussion->id . '-' . $event->post->discussion->slug . '/' . $event->post->number;

        $this->sendToGoogle($finalUrl);

        $event->post->discussion->seo_last_sent_at = \Carbon\Carbon::now();
        $event->post->discussion->save();
    }

    public function whenPostRevised(Revised $event)
    {
        $baseUrl = $this->url->to('forum')->base();
        $finalUrl = $baseUrl . '/d/' . $event->post->discussion->id . '-' . $event->post->discussion->slug . '/' . $event->post->number;
        
        $this->sendToGoogle($finalUrl);

        $event->post->discussion->seo_last_sent_at = \Carbon\Carbon::now();
        $event->post->discussion->save();
    }

    protected function sendToGoogle($url)
    {
        try {
            $keyFile = storage_path('service_account.json');
            if (!file_exists($keyFile)) return;

            $client = new Client();
            $client->setAuthConfig($keyFile);
            $client->addScope('https://www.googleapis.com/auth/indexing');
            
            $service = new Indexing($client);
            $urlNotification = new Indexing\UrlNotification();
            $urlNotification->setUrl($url);
            $urlNotification->setType('URL_UPDATED');

            $service->urlNotifications->publish($urlNotification);
        } catch (\Exception $e) {
            if (isset($url)) {
                @file_put_contents(storage_path('logs/seo-indexing.log'), '['.date('Y-m-d H:i:s').'] Error for '.$url.': '.$e->getMessage().PHP_EOL, FILE_APPEND);
            }
        }
    }
}