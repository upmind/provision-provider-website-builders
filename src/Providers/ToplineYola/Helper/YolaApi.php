<?php

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\ToplineYola\Helper;

use GuzzleHttp\Client;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\ToplineYola\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class YolaApi
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
            $requestParams['body'] = json_encode($body);
        }

        $expiry = time() + (5 * 60);
        $authKey = $this->configuration->auth_key;

        if (!$body) {
            $contentType = "application/json";
            $baseString = $method . "\n" . "\n" . $contentType . "\n" . $expiry . "\n";
        } else {
            $contentType = "application/json;charset=utf-8";
            $baseString = $method . "\n" . md5($requestParams['body']) . "\n" . $contentType . "\n" . $expiry . "\n";
            $requestParams['headers']['Content-Length'] = (string)strlen($requestParams['body']);
        }
        $signature = base64_encode(hash_hmac('sha1', $baseString, $authKey, true));

        $requestParams['headers']['Content-Type'] = $contentType;
        $requestParams['headers']['SBS-Expires'] = (string)$expiry;
        $requestParams['headers']['SBS-Signature'] = $signature;

        $response = $this->client->request($method, "/{$this->configuration->brand_id}" . $command, $requestParams);
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createUser(CreateParams $params): string
    {
        [$firstName, $lastName] = array_pad(explode(' ', $params->customer_name, 2), 2, null);

        $body = [
            'userID' => $params->customer_id ?? null,
            'domain' => $params->domain_name,
            'email' => $params->customer_email,
            'firstName' => $firstName,
            'lastName' => $lastName ?? 'UNKNOWN',
            'language' => $params->language ?? 'en',
        ];

        $response = $this->makeRequest("/users", null, $body, 'POST');
        return (string)$response['detail']['userID'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getInfo(string $userId, string $domainId): array
    {
        $response = $this->makeRequest("/accounts/$userId", ['extras' => "subs"]);

        foreach ($response['detail']['domains'] as $d) {
            if ($d['domainID'] == $domainId || $d['domain'] == $domainId) {
                $domain = $d;
                foreach ($domain['subs'] as $subscription) {
                    if (in_array($subscription['status'], [1, 2, 8])) {
                        $planId = $subscription['planID'];
                    }
                }
            }
        }

        if (!isset($domain)) {
            throw ProvisionFunctionError::create("DomainId $domainId not found");
        }

        return [
            'site_builder_user_id' => $userId,
            'account_reference' => $domain['domainID'] ?? $domainId,
            'domain_name' => $domain['domain'] ?? "",
            'package_reference' => $planId ?? "-",
            'suspended' => $domain['status'] == 3,
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function suspend(string $userId, string $domainId): void
    {
        $this->makeRequest("/accounts/$userId/domains/$domainId/suspend", null, null, "PUT");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unsuspend(string $userId, string $domainId): void
    {
        $this->makeRequest("/accounts/$userId/domains/$domainId/reactivate-all", null, null, "PUT");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function terminate(string $userId, string $domainId): void
    {
        $this->makeRequest("/accounts/$userId/domains/$domainId", null, null, "DELETE");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteUser(string $userId): void
    {
        $this->makeRequest("/accounts/$userId", null, null, "DELETE");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function changePackage($domainId, string $planId): void
    {
        $body = [
            'planID' => $planId,
        ];

        $response = $this->makeRequest("/users/$domainId", ['extras' => "subs"]);
        foreach ($response['detail']['subs'] as $subscription) {
            if (in_array($subscription['status'], [1, 2, 8])) {
                $subscriptionId = $subscription['ID'];
            }
        }

        if (isset($subscriptionId)) {
            $this->makeRequest("/users/$domainId/subscriptions/$subscriptionId", null, $body, 'DELETE');
        }

        $this->makeRequest("/users/$domainId/subscriptions", null, $body, 'POST');
    }

    public function getPlan(string $package): array
    {
        $plans = $this->makeRequest("/plans")['detail']['planIDs'];

        $findBy = [
            'planID',
            'planName',
            'shortName',
        ];
        if (is_numeric($package)) {
            array_unshift($findBy, 'ID');
        }

        foreach ($findBy as $key) {
            foreach ($plans as $plan) {
                if (trim((string)$plan[$key]) === $package) {
                    return $plan;
                }
            }
        }

        throw ProvisionFunctionError::create("Package $package not found");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function login(string $domainId): string
    {
        $response = $this->makeRequest("/users/$domainId/sso/yola", ['noredir' => 1]);

        return $response['detail']['ssoURL'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function addDomain(string $userId, string $domainName)
    {
        $body = [
            'domain' => $domainName,
        ];

        $this->makeRequest("/accounts/$userId/domains", null, $body, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getDomainId(string $userId, string $domainName)
    {
        $response = $this->makeRequest("/accounts/$userId");

        foreach ($response['detail']['domains'] as $d) {
            if ($d['domain'] == $domainName) {
                return $d['domainID'];
            }
        }

        return null;
    }
}
