<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Weebly;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\WebsiteBuilders\Category;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountIdentifier;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountInfo;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\ChangePackageParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\LoginResult;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\UnSuspendParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Weebly\Data\Configuration;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Weebly\Helper\WeeblyApi;

/**
 * Weebly provider.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;

    protected ?WeeblyApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Weebly')
            ->setDescription('Create, manage and log into Weebly site builder accounts')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/weebly-logo@2x.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): AccountInfo
    {
        try {
            if (empty($params->domain_name)) {
                $this->errorResult('Domain name is required!');
            }

            $plan = $this->api()->findPlan((string)$params->package_reference);
            $userId = $params->site_builder_user_id ?? $this->api()->createUser($params);

            $siteId = $this->api()->createDomain((string)$userId, $params->domain_name, $plan['plan_id'], (int)$params->billing_cycle_months);

            return $this->getAccountInfo((string)$userId, $siteId, 'Website created');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInfo(AccountIdentifier $params): AccountInfo
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            return $this->getAccountInfo((string)$params->site_builder_user_id, (string)$params->account_reference);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getAccountInfo(string $userId, string $siteId, ?string $message = null): AccountInfo
    {
        $accountInfo = $this->api()->getInfo($userId, $siteId);

        return AccountInfo::create($accountInfo)->setMessage($message ?: 'Account data obtained');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function login(AccountIdentifier $params): LoginResult
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            $url = $this->api()->login((string)$params->site_builder_user_id);

            return new LoginResult(['login_url' => $url]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            $info = $this->getAccountInfo((string)$params->site_builder_user_id, (string)$params->account_reference);
            $plan = $this->api()->findPlan((string)$params->package_reference);

            if ($info->package_reference !== $plan['name']) {
                $this->api()->changePackage(
                    (string)$params->site_builder_user_id,
                    (string)$params->account_reference,
                    $plan['plan_id'],
                    (int)$params->billing_cycle_months
                );
            }

            if ($params->domain_name && !$this->api()->domainsAreEqual($info->domain_name, $params->domain_name)) {
                $this->api()->changeDomain(
                    (string)$params->site_builder_user_id,
                    (string)$params->account_reference,
                    $params->domain_name
                );
            }

            return $this->getAccountInfo((string)$params->site_builder_user_id, (string)$params->account_reference, 'Package changed');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(AccountIdentifier $params): AccountInfo
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            $this->api()->suspend((string)$params->site_builder_user_id, (string)$params->account_reference);

            return $this->getAccountInfo((string)$params->site_builder_user_id, (string)$params->account_reference, 'Account suspended');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unSuspend(UnSuspendParams $params): AccountInfo
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            $this->api()->unsuspend((string)$params->site_builder_user_id, (string)$params->account_reference);

            return $this->getAccountInfo((string)$params->site_builder_user_id, (string)$params->account_reference, 'Account unsuspended');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(AccountIdentifier $params): ResultData
    {
        try {
            if (!isset($params->site_builder_user_id)) {
                $this->errorResult('Site builder user id is required!');
            }

            $this->api()->terminate((string)$params->site_builder_user_id, (string)$params->account_reference);

            return $this->okResult('Account Terminated');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(\Throwable $e, $params = null): void
    {
        if ($e instanceof ResponseException) {
            $response = $e->getResponse();

            $body = trim($response->getBody()->__toString());
            $responseData = json_decode($body, true);

            $errorMessage = $responseData["error"]['message'] ?? $response->getReasonPhrase();

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData],
                [],
                $e
            );
        }

        throw $e;
    }

    public function api(): WeeblyApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => 'https://api.weeblycloud.com/',
            RequestOptions::HEADERS => [
                'User-Agent' => 'upmind/provision-provider-website-builders v1.0',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Public-Key' => $this->configuration->api_key,
            ],
            RequestOptions::TIMEOUT => 30, // seconds
            RequestOptions::CONNECT_TIMEOUT => 5, // seconds
            'handler' => $this->getGuzzleHandlerStack()
        ]);

        return $this->api = new WeeblyApi($client, $this->configuration);
    }
}
