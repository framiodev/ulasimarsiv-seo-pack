<?php

/**
 * Ulaşım Arşiv SEO Pack - Ana Yapılandırma Dosyası
 * Flarum 2.0 Uyumlu Versiyon.
 */

namespace UlasimArsiv\SeoPack;

use Flarum\Extend;

// Kontrolcüler
use UlasimArsiv\SeoPack\InjectSeoTags;
use UlasimArsiv\SeoPack\SitemapController;
use UlasimArsiv\SeoPack\BatchIndexController;
use UlasimArsiv\SeoPack\InspectController;
use UlasimArsiv\SeoPack\ContentListController;
use UlasimArsiv\SeoPack\DashboardStatsController;
use UlasimArsiv\SeoPack\WebAutoIndexController;
use UlasimArsiv\SeoPack\ImageIndexController;

// Konsol Komutları
use UlasimArsiv\SeoPack\Console\AutoIndexCommand;
use UlasimArsiv\SeoPack\Console\FixOldAltTagsCommand;

// Dinleyiciler
use UlasimArsiv\SeoPack\Listener\SendToGoogleConsole;
use UlasimArsiv\SeoPack\Listener\AutoAltTags;

// Flarum Olayları
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;

return [
    (new Extend\Locales(__DIR__.'/resources/locale')),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),

    (new Extend\Routes('forum'))
        ->get('/sitemap.xml', 'ulasimarsiv.seo.sitemap', SitemapController::class)
        ->get('/sitemap_index.xml', 'ulasimarsiv.seo.sitemap.alt', SitemapController::class),

    (new Extend\Routes('api'))
        ->get('/seo/batch', 'seo.batch.index', BatchIndexController::class)
        ->get('/seo/inspect', 'seo.inspect', InspectController::class)
        ->get('/seo/content', 'seo.content', ContentListController::class)
        ->get('/seo/stats', 'seo.dashboard.stats', DashboardStatsController::class)
        ->get('/seo/images', 'seo.images.index', ImageIndexController::class)
        ->get('/seo/trigger-index', 'seo.trigger.index', WebAutoIndexController::class),

    (new Extend\Frontend('forum'))
        ->content(InjectSeoTags::class),

    (new Extend\Event())
        ->listen(Started::class, [SendToGoogleConsole::class, 'whenDiscussionStarted'])
        ->listen(Posted::class, [SendToGoogleConsole::class, 'whenPostCreated'])
        ->listen(Revised::class, [SendToGoogleConsole::class, 'whenPostRevised']),

    (new Extend\Console())
        ->command(AutoIndexCommand::class)
        ->command(FixOldAltTagsCommand::class),
];