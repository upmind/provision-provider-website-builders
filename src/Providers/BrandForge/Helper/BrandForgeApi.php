<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\BrandForge\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

/**
 * BrandForge provisioning API client.
 *
 * @link https://api-docs.brandforge.com/provisioning-api
 */
class BrandForgeApi
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<int, array<string, mixed>> Packages with `id`, `name`, `slug` and `product_id`
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getPackages(): array
    {
        return $this->request('GET', 'provision/packages');
    }

    /**
     * Create an account. The API returns the existing account if the email is already in use by this reseller.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Account with `service_id`, `email`, `status` and `plan_code`
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function createAccount(array $data): array
    {
        return $this->request('POST', 'provision/create', [], $data);
    }

    /**
     * Add a plan to an account. The account keeps its existing plans.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function addPlan(string $serviceId, string $planCode, ?string $clientId = null): array
    {
        return $this->request('POST', 'provision/add_plan', [], array_filter([
            'service_id' => $serviceId,
            'plan_code' => $planCode,
            'client_id' => $clientId,
        ], fn ($value) => $value !== null));
    }

    /**
     * Replace the active plan of an account.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changePackage(string $serviceId, string $planCode): array
    {
        return $this->request('POST', 'provision/change_package', [], [
            'service_id' => $serviceId,
            'plan_code' => $planCode,
        ]);
    }

    /**
     * Suspend the whole account.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function suspendAccount(string $serviceId): array
    {
        return $this->request('POST', 'provision/suspend', [], [
            'service_id' => $serviceId,
        ]);
    }

    /**
     * Reactivate the whole account.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function unsuspendAccount(string $serviceId): array
    {
        return $this->request('POST', 'provision/unsuspend', [], [
            'service_id' => $serviceId,
        ]);
    }

    /**
     * Cancel the whole account.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function terminateAccount(string $serviceId): array
    {
        return $this->request('POST', 'provision/terminate', [], [
            'service_id' => $serviceId,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function suspendPlan(string $serviceId, string $planCode): array
    {
        return $this->request('POST', 'provision/suspend_plan', [], [
            'service_id' => $serviceId,
            'plan_code' => $planCode,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function unsuspendPlan(string $serviceId, string $planCode): array
    {
        return $this->request('POST', 'provision/unsuspend_plan', [], [
            'service_id' => $serviceId,
            'plan_code' => $planCode,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function terminatePlan(string $serviceId, string $planCode): array
    {
        return $this->request('POST', 'provision/terminate_plan', [], [
            'service_id' => $serviceId,
            'plan_code' => $planCode,
        ]);
    }

    /**
     * Issue a short-lived login URL. The response is also the only source of the account's plan list.
     *
     * @return array<string, mixed> Data with `token`, `login_url` and `plans`
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function sso(string $serviceId): array
    {
        return $this->request('POST', 'provision/sso', [], [
            'service_id' => $serviceId,
        ]);
    }

    /**
     * @return array<string, mixed> Data with `package`, `credits` and `workspaces`
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getStatus(string $serviceId): array
    {
        return $this->request('GET', 'provision/status', [
            'service_id' => $serviceId,
        ]);
    }

    /**
     * Send a request and return the `data` property of the response envelope.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $options = [];

        if ($query) {
            $options[RequestOptions::QUERY] = $query;
        }

        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        $response = $this->client->request($method, $path, $options);
        $responseBody = trim($response->getBody()->__toString());
        $result = json_decode($responseBody, true);

        if (!is_array($result)) {
            throw ProvisionFunctionError::create('Unexpected Provider API response')
                ->withData([
                    'http_code' => $response->getStatusCode(),
                    'response' => Str::limit($responseBody, 300),
                ]);
        }

        if (($result['success'] ?? true) === false) {
            throw ProvisionFunctionError::create(
                sprintf('Provider API Error: %s', $result['error'] ?? $result['message'] ?? 'Unknown error')
            )->withData([
                'response_data' => $result,
            ]);
        }

        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }
}
