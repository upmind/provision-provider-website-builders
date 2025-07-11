<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string|integer|null $site_builder_user_id
 * @property-read string|integer $account_reference
 * @property-read string|null $domain_name
 * @property-read string|integer $package_reference
 * @property-read integer $billing_cycle_months
 * @property-read string|null $permissions Comma-separated list of permissions, e.g. `PUBLISH,EDIT`
 */
class ChangePackageParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'site_builder_user_id' => ['nullable'],
            'account_reference' => ['required'],
            'domain_name' => ['nullable', 'domain_name'],
            'package_reference' => ['required'],
            'billing_cycle_months' => ['required', 'integer'],
            'permissions' => ['nullable', 'string'],
        ]);
    }
}
