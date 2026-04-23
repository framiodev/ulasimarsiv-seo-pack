<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Http\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Google\Client;
use Google\Service\AnalyticsData;
use Google\Service\SearchConsole;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

class DashboardStatsController implements RequestHandlerInterface
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
        $stats = [
            'ga4' => ['active_users' => 0, 'screen_views' => 0, 'top_pages' => []],
            'gsc' => ['clicks' => 0, 'impressions' => 0, 'top_queries' => []],
            'img_gsc' => ['clicks' => 0, 'impressions' => 0],
            'local' => [
                'total_discussions' => 0,
                'indexed_discussions' => 0,
                'pending_discussions' => 0
            ],
            'status' => 'success'
        ];

        try {
            $keyFile = storage_path('service_account.json');
            if (!file_exists($keyFile)) {
                return new JsonResponse(['status' => 'error', 'message' => 'API Anahtarı (JSON) bulunamadı.']);
            }

            $client = new Client();
            $client->setAuthConfig($keyFile);
            $client->addScope('https://www.googleapis.com/auth/analytics.readonly');
            $client->addScope('https://www.googleapis.com/auth/webmasters.readonly');

            // --- 1. LOCAL STATS ---
            $stats['local']['total_discussions'] = Discussion::count();
            $stats['local']['indexed_discussions'] = Discussion::whereNotNull('seo_last_sent_at')->count();
            $stats['local']['pending_discussions'] = $stats['local']['total_discussions'] - $stats['local']['indexed_discussions'];

            // --- 2. GOOGLE ANALYTICS 4 ---
            $propertyId = $this->settings->get('ulasimarsiv-seo.ga_id_number');
            if ($propertyId) {
                $analytics = new AnalyticsData($client);
                $reportRequest = new AnalyticsData\RunReportRequest([
                    'property' => 'properties/' . $propertyId,
                    'dateRanges' => [new AnalyticsData\DateRange(['startDate' => '7daysAgo', 'endDate' => 'today'])],
                    'dimensions' => [new AnalyticsData\Dimension(['name' => 'pagePath'])],
                    'metrics' => [
                        new AnalyticsData\Metric(['name' => 'activeUsers']),
                        new AnalyticsData\Metric(['name' => 'screenPageViews'])
                    ],
                    'limit' => 5
                ]);

                try {
                    $response = $analytics->properties->runReport('properties/' . $propertyId, $reportRequest);
                    foreach ($response->getRows() as $row) {
                        $stats['ga4']['active_users'] += (int)$row->getMetricValues()[0]->getValue();
                        $stats['ga4']['screen_views'] += (int)$row->getMetricValues()[1]->getValue();
                        $stats['ga4']['top_pages'][] = [
                            'path' => $row->getDimensionValues()[0]->getValue(),
                            'views' => $row->getMetricValues()[1]->getValue()
                        ];
                    }
                } catch (\Exception $e) {}
            }

            // --- 3. GOOGLE SEARCH CONSOLE ---
            $siteUrl = rtrim($this->url->to('forum')->base(), '/') . '/';
            $imgUrl = 'https://images.ulasimarsiv.com/';
            
            $searchConsole = new SearchConsole($client);

            // A. Web Performansı
            try {
                $query = new SearchConsole\SearchAnalyticsQueryRequest();
                $query->setStartDate(date('Y-m-d', strtotime('-7 days')));
                $query->setEndDate(date('Y-m-d'));
                $query->setDimensions(['query']);
                $query->setRowLimit(5);
                $gscData = $searchConsole->searchanalytics->query($siteUrl, $query);
                if ($gscData->getRows()) {
                    foreach ($gscData->getRows() as $row) {
                        $stats['gsc']['clicks'] += $row->getClicks();
                        $stats['gsc']['impressions'] += $row->getImpressions();
                        $stats['gsc']['top_queries'][] = [
                            'keyword' => $row->getKeys()[0],
                            'clicks' => $row->getClicks(),
                            'position' => round($row->getPosition(), 1)
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // B. Görsel Performansı
            try {
                $imgQuery = new SearchConsole\SearchAnalyticsQueryRequest();
                $imgQuery->setStartDate(date('Y-m-d', strtotime('-7 days')));
                $imgQuery->setEndDate(date('Y-m-d'));
                $imgQuery->setSearchType('image');
                $imgData = $searchConsole->searchanalytics->query($siteUrl, $imgQuery);
                if ($imgData->getRows()) {
                    foreach ($imgData->getRows() as $row) {
                        $stats['img_gsc']['clicks'] += $row->getClicks();
                        $stats['img_gsc']['impressions'] += $row->getImpressions();
                    }
                }
            } catch (\Exception $e) {}

        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return new JsonResponse($stats);
    }
}