<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string|integer|null $site_builder_user_id
 * @property-read string|integer $customer_id
 * @property-read string $customer_name
 * @property-read string $customer_email
 * @property-read string|null $domain_name
 * @property-read string|integer $package_reference
 * @property-read integer $billing_cycle_months
 * @property-read string|null $password
 * @property-read string|null $language_code E.g., `en`
 * @property-read string|null $permissions Comma-separated list of permissions, e.g. `PUBLISH,EDIT`
 * @property-read mixed[]|null $extra Extra metadata to be passed to the provider
 */
class CreateParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'site_builder_user_id' => ['nullable'],
            'customer_id' => ['required'],
            'customer_name' => ['required', 'string'],
            'customer_email' => ['required', 'email'],
            'domain_name' => ['nullable', 'domain-name'],
            'package_reference' => ['required'],
            'billing_cycle_months' => ['required', 'integer'],
            'password' => ['nullable', 'string'],
            'language_code' => ['nullable', 'string'],
            'permissions' => ['nullable', 'string'],
            'extra' => ['nullable', 'array'],
        ]);
    }
}
