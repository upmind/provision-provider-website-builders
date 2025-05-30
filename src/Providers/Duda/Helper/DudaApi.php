<?php

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class DudaApi
{
    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function makeRequest(string $command, ?array $params = null, ?array $body = null, ?string $method = 'GET'): ?array
    {
        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $body = json_encode($body);
            $requestParams['body'] = $body;
        }

        $response = $this->client->request($method, "/api/$command", $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === "") {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        return $parsedResult;
    }

    /**
     * @param string $siteId
     * @return array Site info
     * @throws GuzzleException
     */
    public function getInfo(string $siteId): array
    {
        $site = $this->makeRequest("sites/multiscreen/$siteId");
        $plan = $this->makeRequest("sites/multiscreen/$siteId/plan");

        $isPublished = $site['publish_status'] == 'PUBLISHED';

        return [
            'site_builder_user_id' => $site['account_name'],
            'account_reference' => $siteId,
            'domain_name' => $site['site_domain'],
            'package_reference' => $plan['planName'] ?? "unknown",
            'suspended' => !$isPublished,
            'ip_address' => null,
            'is_published' => $isPublished,
            'has_ssl' => null,
        ];
    }

    /**
     * @param string $siteId
     * @return void
     * @throws GuzzleException
     */
    public function suspend(string $siteId): void
    {
        $this->makeRequest("sites/multiscreen/unpublish/$siteId", null, null, 'POST');
    }

    /**
     * @param string $siteId
     * @return void
     * @throws GuzzleException
     */
    public function unsuspend(string $siteId): void
    {
        $this->makeRequest("sites/multiscreen/publish/$siteId", null, null, 'POST');
    }

    /**
     * @param string $siteId
     * @param string $planId
     * @return void
     * @throws GuzzleException
     */
    public function changePackage(string $siteId, string $planId): void
    {
        if (!is_numeric($planId)) {
            $planId = $this->getPlanId($planId);
        }

        $this->changePlan($siteId, (int)$planId);
    }


    /**
     * @param string $userId
     * @param string $siteId
     * @return string Login URL
     * @throws GuzzleException
     */
    public function login(string $userId, string $siteId): string
    {
        $query = [
            'site_name' => $siteId,
            'target' => 'EDITOR',
        ];

        $response = $this->makeRequest("accounts/sso/$userId/link", $query);

        return $response['url'];
    }

    /**
     * @param string $siteId
     * @return void
     * @throws GuzzleException
     */
    public function terminate(string $siteId): void
    {
        $this->makeRequest("sites/multiscreen/$siteId", null, null, 'DELETE');
    }

    /**
     * @param string $domain
     * @param string $planId
     * @param string $lang
     * @return string Site id
     * @throws GuzzleException
     */
    public function createSite(string $domain, string $planId, string $lang): string
    {
        if (!is_numeric($planId)) {
            $planId = $this->getPlanId($planId);
        }

        $body = [
            'template_id' => 3,
            'lang' => $lang,
            'site_data' => [
                'site_domain' => $domain
            ],
        ];

        $site = $this->makeRequest("sites/multiscreen/create", null, $body, 'POST');
        $siteId = $site['site_name'];

        $this->changePlan($siteId, (int)$planId);
        $this->unsuspend($siteId);

        return $siteId;
    }

    /**
     * @param string $planName
     * @return int Plan id
     * @throws GuzzleException
     */
    private function getPlanId(string $planName): int
    {
        $response = $this->makeRequest('sites/multiscreen/plans');

        foreach ($response as $s) {
            if (strtolower($s['planName']) == strtolower($planName)) {
                return $s['planId'];
            }
        }

        throw ProvisionFunctionError::create("Plan $planName not found")
            ->withData([
                'response' => $response,
            ]);
    }

    /**
     * @param string $siteId
     * @param int $planId
     * @return void
     * @throws GuzzleException
     */
    private function changePlan(string $siteId, int $planId): void
    {
        $this->makeRequest("sites/multiscreen/$siteId/plan/$planId", null, null, 'POST');
    }
}
