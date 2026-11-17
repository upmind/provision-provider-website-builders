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

    protected ?int $lastStatusCode = null;

    protected ?string $lastMessage = null;

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
     * Create an account with its first plan. If the email already has an account, the API returns HTTP 200
     * instead of 201. It adds the plan, or returns the existing plan if the account already has this package.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Data with `service_id`, the new `plan` and all `plans`
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
     * @return array<string, mixed> Data with the new `plan` and all `plans`
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
     * Change one plan to another package. The plan keeps its `plan_id`.
     *
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changePackage(string $serviceId, string $planId, string $planCode): array
    {
        return $this->request('POST', 'provision/change_package', [], [
            'service_id' => $serviceId,
            'plan_id' => $planId,
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
    public function suspendPlan(string $serviceId, string $planId): array
    {
        return $this->request('POST', 'provision/suspend_plan', [], [
            'service_id' => $serviceId,
            'plan_id' => $planId,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function unsuspendPlan(string $serviceId, string $planId): array
    {
        return $this->request('POST', 'provision/unsuspend_plan', [], [
            'service_id' => $serviceId,
            'plan_id' => $planId,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function terminatePlan(string $serviceId, string $planId): array
    {
        return $this->request('POST', 'provision/terminate_plan', [], [
            'service_id' => $serviceId,
            'plan_id' => $planId,
        ]);
    }

    /**
     * Issue a short-lived login URL.
     *
     * @return array<string, mixed> Data with `token` and `login_url`
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
     * @return array<string, mixed> Data with `plans`, `package`, `credits` and `workspaces`
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
     * HTTP status code of the last response.
     */
    public function lastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    /**
     * `message` property of the last response envelope.
     */
    public function lastMessage(): ?string
    {
        return $this->lastMessage;
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

        $this->lastStatusCode = null;
        $this->lastMessage = null;
        $response = $this->client->request($method, $path, $options);
        $this->lastStatusCode = $response->getStatusCode();
        $responseBody = trim($response->getBody()->__toString());
        $result = json_decode($responseBody, true);

        if (!is_array($result)) {
            throw ProvisionFunctionError::create('Unexpected Provider API response')
                ->withData([
                    'http_code' => $response->getStatusCode(),
                    'response' => Str::limit($responseBody, 300),
                ]);
        }

        $this->lastMessage = is_string($result['message'] ?? null) ? $result['message'] : null;

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
