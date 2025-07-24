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
 * @property-read string|int|null $template The default template name or id to use when creating sites
 * @property-read string|null $unpublished_sso_target_destination The target destination for the SSO link before site is published
 * @property-read string|null $published_sso_target_destination The target destination for the SSO link after is published
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
            'template' => ['nullable'],
            /** @link https://developer.duda.co/reference/authentication-get-sso-link */
            'unpublished_sso_target_destination' => ['nullable', 'string', 'in:' . implode(',', [
                '',
                'EDITOR',
                'RESET_SITE',
                'SWITCH_TEMPLATE',
                'SWITCH_TEMPLATE_WITH_AI',
                'RESET_BASIC',
                'STORE_MANAGEMENT',
                'SITE_OVERVIEW',
                'STATS',
                'SITE_SEO_OVERVIEW',
            ])],
            'published_sso_target_destination' => ['nullable', 'string', 'in:' . implode(',', [
                '',
                'EDITOR',
                'RESET_SITE',
                'SWITCH_TEMPLATE',
                'SWITCH_TEMPLATE_WITH_AI',
                'RESET_BASIC',
                'STORE_MANAGEMENT',
                'SITE_OVERVIEW',
                'STATS',
                'SITE_SEO_OVERVIEW',
            ])],
            'default_permissions' => ['nullable', 'string'],
            'delete_on_terminate' => ['boolean'],
        ]);
    }
}
