<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Data\Configuration;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Helper\DudaApi;

/**
 * Duda provider.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;

    protected ?DudaApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Duda')
            ->setDescription('Create, manage and log into Duda site builder accounts')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/duda-logo@2x.png');
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

            $siteId = $this->api()->createSite((string)$params->domain_name, (string)$params->package_reference, $params->language_code ?? 'en');

            return $this->getAccountInfo($siteId, 'Website created');
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
            return $this->getAccountInfo((string)$params->account_reference);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getAccountInfo(string $siteId, ?string $message = null): AccountInfo
    {
        $accountInfo = $this->api()->getInfo($siteId);

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

            $url = $this->api()->login((string)$params->site_builder_user_id, (string)$params->account_reference);

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
            $this->api()->changePackage((string)$params->account_reference, $params->package_reference);

            return $this->getAccountInfo((string)$params->account_reference, 'Package changed');
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
            $this->api()->suspend((string)$params->account_reference);

            return $this->getAccountInfo((string)$params->account_reference, 'Account suspended');
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
            $this->api()->unsuspend((string)$params->account_reference);

            return $this->getAccountInfo((string)$params->account_reference, 'Account unsuspended');
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
            $this->api()->terminate((string)$params->account_reference);

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
        if (($e instanceof RequestException) && $e->hasResponse()) {
            $response = $e->getResponse();

            $body = trim($response === null ? '' : $response->getBody()->__toString());
            $responseData = json_decode($body, true);

            $errorMessage = $responseData['message'] ?? $response->getReasonPhrase();

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData],
                [],
                $e
            );
        }

        throw $e;
    }

    public function api(): DudaApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->password}");

        $client = new Client([
            'base_uri' => 'https://api.duda.co/',
            RequestOptions::HEADERS => [
                'User-Agent' => 'upmind/provision-provider-website-builders v1.0',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => ['Basic ' . $credentials],
            ],
            RequestOptions::TIMEOUT => 30, // seconds
            RequestOptions::CONNECT_TIMEOUT => 5, // seconds
            'handler' => $this->getGuzzleHandlerStack()
        ]);

        return $this->api = new DudaApi($client, $this->configuration);
    }
}
