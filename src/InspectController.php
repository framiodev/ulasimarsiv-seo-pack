<?php

namespace UlasimArsiv\SeoPack;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Google\Client;
use Google\Service\SearchConsole;

class InspectController implements RequestHandlerInterface
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $urlToCheck = $params['url'] ?? '';

        try {
            $keyFile = storage_path('service_account.json');
            $client = new Client();
            $client->setAuthConfig($keyFile);
            $client->addScope('https://www.googleapis.com/auth/webmasters.readonly');
            $service = new SearchConsole($client);

            $siteUrl = 'https://forum.ulasimarsiv.com/'; 

            $inspectRequest = new SearchConsole\InspectUrlIndexRequest();
            $inspectRequest->setInspectionUrl($urlToCheck);
            $inspectRequest->setSiteUrl($siteUrl);

            $response = $service->urlInspection_index->inspect($inspectRequest);
            $indexStatus = $response->getInspectionResult()->getIndexStatusResult();

            return new JsonResponse([
                'status' => 'success',
                'indexed' => ($indexStatus->getVerdict() === 'PASS'),
                'message' => $indexStatus->getVerdict() === 'PASS' ? '🟢 İndekste' : '🔴 Yok'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }
}