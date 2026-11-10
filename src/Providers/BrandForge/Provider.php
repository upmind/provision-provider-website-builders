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
 * One customer has one BrandForge account with one plan, so one order.
 * `site_builder_user_id` is the account `service_id`. `account_reference` is the order's `plan_code`.
 * A second plan adds credits but does not change the account's active plan, so the provider refuses it.
 * Lifecycle functions act on the whole account. If the account has more plans from elsewhere, they act on
 * the order's plan only.
 */
class Provider extends Category implements ProviderInterface
{
    private const BASE_URL = 'https://brandforge.software/api/godmode/v1/';
    private const SANDBOX_BASE_URL = 'https://staging.brandforge.software/api/godmode/v1/';

    /**
     * Plan statuses that mean the plan no longer belongs to the account.
     */
    private const ENDED_PLAN_STATUSES = ['cancelled', 'canceled', 'terminated', 'deleted', 'expired'];

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
     * Create an account. Add the plan to an existing account only if that account has no plan.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): AccountInfo
    {
        try {
            $planCode = $this->resolvePlanCode((string)$params->package_reference);
            $serviceId = empty($params->site_builder_user_id) ? null : (string)$params->site_builder_user_id;

            if ($serviceId === null) {
                $account = $this->api()->createAccount($this->getCreateAccountData($params, $planCode));

                if (empty($account['service_id'])) {
                    $this->errorResult('Account ID missing from Provider API response', [
                        'response_data' => $account,
                    ]);
                }

                $serviceId = (string)$account['service_id'];

                if (($account['plan_code'] ?? null) === $planCode) {
                    return $this->getAccountInfo($serviceId, $planCode, 'Account created');
                }

                // The API returned an existing account for this email, with a different plan.
            }

            $plans = $this->getPlans($serviceId);

            if ($plans !== []) {
                $this->errorResult('Customer already has a plan; change the existing plan instead', [
                    'site_builder_user_id' => $serviceId,
                    'plan_codes' => array_column($plans, 'plan_code'),
                ]);
            }

            $this->api()->addPlan($serviceId, $planCode, (string)$params->customer_id);

            return $this->getAccountInfo($serviceId, $planCode, 'Plan added to existing account');
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
     * Change the plan. The API replaces "the active plan", so only single-plan accounts are supported.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $currentPlanCode = (string)$params->account_reference;
            $newPlanCode = $this->resolvePlanCode((string)$params->package_reference);

            $plans = $this->getPlans($serviceId);

            if ($this->findPlan($plans, $currentPlanCode) === null) {
                $this->errorResult('Plan not found on this account', [
                    'plan_code' => $currentPlanCode,
                ]);
            }

            if ($newPlanCode === $currentPlanCode) {
                return $this->getAccountInfo($serviceId, $currentPlanCode, 'Plan unchanged');
            }

            if (count($plans) > 1) {
                $this->errorResult('Plan change is not supported for customers with more than one plan', [
                    'plan_codes' => array_column($plans, 'plan_code'),
                ]);
            }

            $this->api()->changePackage($serviceId, $newPlanCode);

            return $this->getAccountInfo($serviceId, $newPlanCode, 'Plan changed');
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
            $planCode = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planCode)) {
                $this->api()->suspendAccount($serviceId);

                return $this->getAccountInfo($serviceId, $planCode, 'Account suspended', true);
            }

            $this->api()->suspendPlan($serviceId, $planCode);

            return $this->getAccountInfo($serviceId, $planCode, 'Plan suspended');
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
            $planCode = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planCode)) {
                $this->api()->unsuspendAccount($serviceId);

                return $this->getAccountInfo($serviceId, $planCode, 'Account unsuspended');
            }

            $this->api()->unsuspendPlan($serviceId, $planCode);

            return $this->getAccountInfo($serviceId, $planCode, 'Plan unsuspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Terminate the whole account if this is its only plan. Otherwise terminate the order's plan only.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(AccountIdentifier $params): ResultData
    {
        try {
            $serviceId = $this->getServiceId($params->site_builder_user_id);
            $planCode = (string)$params->account_reference;

            if ($this->isOnlyPlan($serviceId, $planCode)) {
                $result = $this->api()->terminateAccount($serviceId);

                return $this->okResult('Account terminated', [], ['response_data' => $result]);
            }

            $result = $this->api()->terminatePlan($serviceId, $planCode);

            return $this->okResult('Plan terminated', [], ['response_data' => $result]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @param bool $accountSuspended Skip the plan lookup, which needs SSO, and report the account as suspended
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getAccountInfo(
        string $serviceId,
        string $planCode,
        string $message = 'Account data obtained',
        bool $accountSuspended = false
    ): AccountInfo {
        $plan = $accountSuspended ? [] : $this->findPlan($this->getPlans($serviceId), $planCode);

        if ($plan === null) {
            $this->errorResult('Plan not found on this account', [
                'plan_code' => $planCode,
            ]);
        }

        $status = $this->api()->getStatus($serviceId);

        return AccountInfo::create([
            'site_builder_user_id' => $serviceId,
            'account_reference' => $planCode,
            'domain_name' => null,
            'package_reference' => $planCode,
            'suspended' => $accountSuspended || strtolower((string)($plan['status'] ?? '')) === 'suspended',
            'site_count' => isset($status['workspaces']['total']) ? (int)$status['workspaces']['total'] : null,
            'extra' => [
                'active_plan_code' => $status['package']['plan_code'] ?? null,
                'plan' => [
                    'id' => $plan['id'] ?? null,
                    'status' => $plan['status'] ?? null,
                    'primary' => $plan['primary'] ?? null,
                    'credits' => $plan['credits'] ?? null,
                    'max_workspaces' => $plan['max_workspaces'] ?? null,
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
     * Get the account's current plans. Only the SSO response lists them; its login token is discarded.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getPlans(string $serviceId): array
    {
        $plans = $this->api()->sso($serviceId)['plans'] ?? [];

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
    protected function isOnlyPlan(string $serviceId, string $planCode): bool
    {
        $plans = $this->getPlans($serviceId);

        if ($this->findPlan($plans, $planCode) === null) {
            $this->errorResult('Plan not found on this account', [
                'plan_code' => $planCode,
            ]);
        }

        return count($plans) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     *
     * @return array<string, mixed>|null
     */
    protected function findPlan(array $plans, string $planCode): ?array
    {
        foreach ($plans as $plan) {
            if (($plan['plan_code'] ?? null) === $planCode) {
                return $plan;
            }
        }

        return null;
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
