<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\BrandForge;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\BrandForge\Data\Configuration;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\BrandForge\Helper\BrandForgeApi;

/**
 * BrandForge provider.
 *
 * One customer has one BrandForge account. Each order is one plan on that account.
 * `site_builder_user_id` is the account `service_id`. `account_reference` is the order's `plan_id`.
 * Lifecycle functions act on the whole account when the order's plan is its only plan, and on the plan otherwise.
 */
class Provider extends Category implements ProviderInterface
{
    private const BASE_URL = 'https://brandforge.software/api/godmode/v1/';
    private const SANDBOX_BASE_URL = 'https://staging.brandforge.software/api/godmode/v1/';

    /**
     * Plan statuses that mean the plan no longer belongs to the account.
     */
    private const ENDED_PLAN_STATUSES = ['inactive', 'cancelled', 'canceled', 'terminated', 'deleted', 'expired'];

    protected Configuration $configuration;

    protected ?BrandForgeApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('BrandForge')
            ->setDescription('Create, manage and log into BrandForge brand builder accounts');
    }

    /**
     * Create an account, or add the plan to the customer's existing account.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): AccountInfo
    {
        try {
            $planCode = $this->resolvePlanCode((string)$params->package_reference);

            if (!empty($params->site_builder_user_id)) {
                $serviceId = (string)$params->site_builder_user_id;
                $result = $this->api()->addPlan($serviceId, $planCode, (string)$params->customer_id);
                $message = 'Plan added to existing account';
            } else {
                $result = $this->api()->createAccount($this->getCreateAccountData($params, $planCode));
                $serviceId = (string)($result['service_id'] ?? '');
                $message = $this->api()->lastStatusCode() === 201
                    ? 'Account created'
                    : 'Plan added to existing account';

                // For a package the account already has, `create` returns the existing plan. Each order needs its own.
                if ($serviceId !== '' && stripos((string)$this->api()->lastMessage(), 'already') !== false) {
                    $result = $this->api()->addPlan($serviceId, $planCode, (string)$params->customer_id);
                }
            }

            $planId = (string)($result['plan']['plan_id'] ?? '');

            if ($serviceId === '' || $planId === '') {
                $this->errorResult('Account or plan ID missing from Provider API response', [
                    'response_data' => $result,
                ]);
            }

            return $this->getAccountInfo($serviceId, $planId, $message);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInfo(AccountIdentifier $params): AccountInfo
    {
        try {
            return $this->getAccountInfo(
                $this->getServiceId($params->site_builder_user_id),
                (string)$params->account_reference
            );
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function login(AccountIdentifier $params): LoginResult
    {
        try {
            $sso = $this->api()->sso($this->getServiceId($params->site_builder_user_id));

            if (empty($sso['login_url'])) {
                $this->errorResult('Login URL missing from Provider API response');
            }

            return LoginResult::create(['login_url' => $sso['login_url']])
                ->setMessage('Login URL generated');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Change the order's plan to another package. The plan keeps its `plan_id`.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $planId = (string)$params->account_reference;
            $newPlanCode = $this->resolvePlanCode((string)$params->package_reference);

            $plan = $this->getPlan($this->getPlans($serviceId), $planId);

            if (($plan['plan_code'] ?? null) === $newPlanCode) {
                return $this->getAccountInfo($serviceId, $planId, 'Plan unchanged');
            }

            $this->api()->changePackage($serviceId, $planId, $newPlanCode);

            return $this->getAccountInfo($serviceId, $planId, 'Plan changed');
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
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $planId = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planId)) {
                $this->api()->suspendAccount($serviceId);

                return $this->getAccountInfo($serviceId, $planId, 'Account suspended');
            }

            $this->api()->suspendPlan($serviceId, $planId);

            return $this->getAccountInfo($serviceId, $planId, 'Plan suspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unSuspend(UnSuspendParams $params): AccountInfo
    {
        try {
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $planId = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planId)) {
                $this->api()->unsuspendAccount($serviceId);
            }

            // The plan can still be suspended on its own, e.g. after a per-plan suspend.
            if ($this->isSuspended($this->getPlan($this->getPlans($serviceId), $planId))) {
                $this->api()->unsuspendPlan($serviceId, $planId);
            }

            return $this->getAccountInfo($serviceId, $planId, 'Plan unsuspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Terminate the whole account if the order's plan is its only plan. Otherwise terminate the plan only.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(AccountIdentifier $params): ResultData
    {
        try {
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $planId = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planId)) {
                $result = $this->api()->terminateAccount($serviceId);

                return $this->okResult('Account terminated', [], ['response_data' => $result]);
            }

            $result = $this->api()->terminatePlan($serviceId, $planId);

            return $this->okResult('Plan terminated', [], ['response_data' => $result]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getAccountInfo(
        string $serviceId,
        string $planId,
        string $message = 'Account data obtained'
    ): AccountInfo {
        $status = $this->api()->getStatus($serviceId);
        $plan = $this->getPlan($this->filterLivePlans($status['plans'] ?? []), $planId);

        return AccountInfo::create([
            'site_builder_user_id' => $serviceId,
            'account_reference' => $planId,
            'domain_name' => null,
            'package_reference' => (string)$plan['plan_code'],
            'suspended' => $this->isSuspended($plan),
            'site_count' => isset($status['workspaces']['total']) ? (int)$status['workspaces']['total'] : null,
            'extra' => [
                'active_plan_code' => $status['package']['plan_code'] ?? null,
                'plan' => [
                    'plan_id' => $plan['plan_id'] ?? null,
                    'status' => $plan['status'] ?? null,
                    'primary' => $plan['primary'] ?? null,
                    'credits' => $plan['credits'] ?? null,
                    'created_at' => $plan['created_at'] ?? null,
                ],
                'credits' => $status['credits'] ?? null,
                'workspaces' => [
                    'total' => $status['workspaces']['total'] ?? null,
                    'active' => $status['workspaces']['active'] ?? null,
                    'max' => $status['package']['max_workspaces'] ?? null,
                ],
            ],
        ])->setMessage($message);
    }

    /**
     * Get the account's current plans.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getPlans(string $serviceId): array
    {
        return $this->filterLivePlans($this->api()->getStatus($serviceId)['plans'] ?? []);
    }

    /**
     * @param mixed $plans
     *
     * @return array<int, array<string, mixed>>
     */
    protected function filterLivePlans($plans): array
    {
        return array_values(array_filter(
            is_array($plans) ? $plans : [],
            fn ($plan) => is_array($plan)
                && !in_array(strtolower((string)($plan['status'] ?? '')), self::ENDED_PLAN_STATUSES, true)
        ));
    }

    /**
     * Whether the given plan is the account's only plan.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function isOnlyPlan(string $serviceId, string $planId): bool
    {
        $plans = $this->getPlans($serviceId);
        $this->getPlan($plans, $planId);

        return count($plans) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     *
     * @return array<string, mixed>
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getPlan(array $plans, string $planId): array
    {
        foreach ($plans as $plan) {
            if ((string)($plan['plan_id'] ?? '') === $planId) {
                return $plan;
            }
        }

        $this->errorResult('Plan not found on this account', [
            'plan_id' => $planId,
        ]);
    }

    /**
     * @param array<string, mixed> $plan
     */
    protected function isSuspended(array $plan): bool
    {
        return strtolower((string)($plan['status'] ?? '')) === 'suspended';
    }

    /**
     * Get the plan code of the package with the given slug, name or ID.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function resolvePlanCode(string $packageReference): string
    {
        $packages = $this->api()->getPackages();

        foreach (['slug', 'name', 'id'] as $field) {
            foreach ($packages as $package) {
                if (
                    isset($package[$field], $package['slug'])
                    && strcasecmp((string)$package[$field], $packageReference) === 0
                ) {
                    return (string)$package['slug'];
                }
            }
        }

        $this->errorResult('Plan not found', [
            'package_reference' => $packageReference,
            'available_plan_codes' => array_column($packages, 'slug'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCreateAccountData(CreateParams $params, string $planCode): array
    {
        $nameParts = preg_split('/\s+/', trim($params->customer_name), 2) ?: [];

        return array_filter([
            'email' => $params->customer_email,
            'first_name' => $nameParts[0] ?? $params->customer_name,
            'last_name' => $nameParts[1] ?? null,
            'plan_code' => $planCode,
            'password' => $params->password,
            'domain' => $params->domain_name,
            'client_id' => (string)$params->customer_id,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param string|int|null $siteBuilderUserId
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getServiceId($siteBuilderUserId): string
    {
        if (empty($siteBuilderUserId)) {
            $this->errorResult('Site builder user ID is required');
        }

        return (string)$siteBuilderUserId;
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if (($e instanceof RequestException) && $e->hasResponse()) {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $e->getResponse();

            $body = trim($response->getBody()->__toString());
            $responseData = json_decode($body, true);

            $errorMessage = $responseData['error'] ?? $responseData['message'] ?? $response->getReasonPhrase();

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData ?? Str::limit($body, 300)],
                [],
                $e
            );
        }

        throw $e;
    }

    protected function api(): BrandForgeApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->configuration->sandbox ? self::SANDBOX_BASE_URL : self::BASE_URL,
            RequestOptions::HEADERS => [
                'User-Agent' => 'upmind/provision-provider-website-builders v1.0',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->configuration->api_key,
            ],
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 5,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new BrandForgeApi($client);
    }
}
