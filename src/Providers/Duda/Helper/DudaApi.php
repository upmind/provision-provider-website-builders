<?php

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
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
     * Get plan data by the given plan name or ID.
     *
     * @link https://developer.duda.co/reference/site-plans-list-site-plans
     */
    public function getPlan(string $plan): array
    {
        $plans = $this->makeRequest('sites/multiscreen/plans');

        foreach ($plans as $p) {
            if (is_numeric($plan) && (int)$p['planId'] === (int)$plan) {
                return $p;
            }

            if (strtolower($p['planName']) === strtolower($plan)) {
                return $p;
            }
        }

        throw ProvisionFunctionError::create("Plan '$plan' not found")
            ->withData(['response' => $plans]);
    }

    /**
     * @param string $siteId
     * @return array Site info
     * @throws GuzzleException
     */
    public function getInfo(string $accountName, string $siteId): array
    {
        $account = $this->getAccountData($accountName);
        $site = $this->makeRequest("sites/multiscreen/$siteId");
        $plan = $this->makeRequest("sites/multiscreen/$siteId/plan");
        $permissions = $this->makeRequest("accounts/{$accountName}/sites/{$siteId}/permissions");

        $isPublished = $site['publish_status'] == 'PUBLISHED';

        return [
            'site_builder_user_id' => $account['account_name'],
            'account_reference' => $siteId,
            'domain_name' => $site['site_domain'] ?? $site['site_default_domain'],
            'package_reference' => $plan['planName'] ?? "unknown",
            'suspended' => null, // Duda API does not explicitly support suspension - uses is_published instead
            'ip_address' => null,
            'is_published' => $isPublished,
            'has_ssl' => null,
            'permissions' => implode(',', $permissions['permissions'] ?? []),
        ];
    }

    /**
     * Get information about the given account identifier.
     *
     * @link https://developer.duda.co/reference/accounts-object
     */
    public function getAccountData(string $accountName): array
    {
        return $this->makeRequest("accounts/$accountName");
    }

    /**
     * Create a new account for the given customer.
     *
     * @link https://developer.duda.co/reference/accounts-create-account
     */
    public function createAccount(
        string $customerEmail,
        ?string $customerName,
        ?string $languageCode
    ): array {
        @[$firstName, $lastName] = explode(' ', (string)$customerName, 2);

        $body = [
            'account_name' => $customerEmail,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'lang' => $this->getSupportedLanguage($languageCode),
            'account_type' => 'CUSTOMER',
        ];

        $this->makeRequest('accounts/create', null, $body, 'POST');

        return $this->getAccountData($customerEmail);
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
     * Change the package of the given site and update the permissions of the given account.
     *
     * @param string $accountName Unique account identifier
     * @param string $siteId
     * @param integer $planId
     * @param string[] $permissions
     *
     * @return void
     *
     * @throws GuzzleException
     */
    public function changePackage(string $accountName, string $siteId, int $planId, array $permissions): void
    {
        $this->setSitePermissions($siteId, $accountName, $permissions);
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
            'target' => $this->configuration->sso_target_destination ?: 'EDITOR',
        ];

        try {
            $response = $this->makeRequest("accounts/sso/$userId/link", $query);
        } catch (ClientException $e) {
            if (!$this->configuration->sso_target_destination || !Str::contains($e->getMessage(), $this->configuration->sso_target_destination)) {
                throw $e;
            }

            // Try again without the custom target destination
            $query['target'] = 'EDITOR';
            $response = $this->makeRequest("accounts/sso/$userId/link", $query);
        }

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
     * @param string $accountName Unique account identifier
     * @param string $domain
     * @param integer $planId
     * @param string|null $lang
     * @param string[] $permissions
     *
     * @return string Site id
     *
     * @throws GuzzleException
     *
     * @link https://developer.duda.co/reference/sites-create-site
     */
    public function createSite(
        string $accountName,
        string $domain,
        int $planId,
        ?string $lang,
        array $permissions
    ): string {
        $body = [
            'template_id' => 0,
            'lang' => $lang,
            'site_data' => [
                'site_domain' => $domain
            ],
        ];

        $site = $this->makeRequest("sites/multiscreen/create", null, $body, 'POST');
        $siteId = $site['site_name'];

        $this->setSitePermissions($siteId, $accountName, $permissions);

        $this->unsuspend($siteId);
        $this->changePlan($siteId, $planId);

        return $siteId;
    }


    /**
     * @param string[] $permissions
     *
     * @link https://developer.duda.co/reference/client-permissions-grant-site-access
     */
    public function setSitePermissions(
        string $siteId,
        string $accountName,
        array $permissions
    ): void {
        $body = [
            'permissions' => $this->getSupportedPermissions($permissions),
        ];

        $this->makeRequest("accounts/{$accountName}/sites/{$siteId}/permissions", null, $body, 'POST');
    }

    /**
     * List the permissions of the given user for the given site.
     *
     * @return string[]
     *
     * @link https://developer.duda.co/reference/client-permissions-get-client-permissions
     */
    public function getSitePermissions(string $siteId, string $accountName): array
    {
        $response = $this->makeRequest("accounts/{$accountName}/sites/{$siteId}/permissions");

        return $response['permissions'] ?? [];
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

    /**
     * Return a supported language code based on the given language, or a fallback language if empty or not supported.
     *
     * @link https://developer.duda.co/reference/getting-started-with-the-duda-api#good-to-know
     */
    private function getSupportedLanguage(?string $languageCode): string
    {
        $fallbackLang = 'en';
        $lang = str_replace('-', '_', strtolower($languageCode ?: $fallbackLang));

        $supportedLanguages = [
            'ar',
            'nl',
            'en',
            'en_gb',
            'fr',
            'de',
            'id',
            'it',
            'ja',
            'pl',
            'pt',
            'es',
            'es_ar',
            'tr',
        ];

        if (in_array($lang, $supportedLanguages)) {
            return $lang;
        }

        if (Str::contains($lang, '_')) {
            return $this->getSupportedLanguage(Str::before($lang, '_'));
        }

        return $fallbackLang; // Default to English if no match found
    }

    /**
     * Normalise and return the intersection of the given permissions with the supported permissions.
     *
     * @param string[] $permissions
     *
     * @return string[] Normalised permissions
     *
     * @link https://developer.duda.co/reference/client-permissions-object
     */
    private function getSupportedPermissions(array $permissions): array
    {
        $permissions = (new Collection($permissions))
            ->map(function ($permission) {
                return strtoupper(str_replace(' ', '_', trim((string)$permission)));
            })
            ->filter()
            ->values()
            ->toArray();

        $supportedPermissions = [
            'STATS_TAB',
            'EDIT',
            'ADD_FLEX',
            'E_COMMERCE',
            'PUBLISH',
            'REPUBLISH',
            'DEV_MODE',
            'INSITE',
            'SEO',
            'BACKUPS',
            'CUSTOM_DOMAIN',
            'RESET',
            'BLOG',
            'PUSH_NOTIFICATIONS',
            'LIMITED_EDITING',
            'SITE_COMMENTS',
            'CONTENT_LIBRARY',
            'EDIT_CONNECTED_DATA',
            'MANAGE_CONNECTED_DATA',
            'USE_APP',
            'CLIENT_MANAGE_FREE_APPS',
            'AI_ASSISTANT',
            'MANAGE_DOMAIN',
            'CONTENT_LIBRARY_EXTERNAL_DATA_SYNC',
            'SEO_OVERVIEW'
        ];

        return array_values(array_intersect($permissions, $supportedPermissions));
    }
}
