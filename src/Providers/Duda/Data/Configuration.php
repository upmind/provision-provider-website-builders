<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Duda\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Duda API credentials.
 *
 * @property-read string $username username
 * @property-read string $password password
 * @property-read string|null $default_permissions Comma-separated default permissions to use when creating accounts
 * @property-read bool|null $delete_on_terminate Whether to delete the account on termination
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'default_permissions' => ['nullable', 'string'],
            'delete_on_terminate' => ['boolean'],
        ]);
    }
}
