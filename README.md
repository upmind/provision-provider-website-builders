# [Upmind Provision Providers](https://github.com/upmind-automation) - Website Builders

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-website-builders.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-website-builders)

This provision category contains the common functions used in provisioning flows for website builders and related tools.

- [Installation](#installation)
- [Usage](#usage)
  - [Quick-start](#quick-start)
- [Supported Providers](#supported-providers)
- [Functions](#functions)
  - [create()](#create)
  - [getInfo()](#getInfo)
  - [login()](#login)
  - [changePackage()](#changePackage)
  - [suspend()](#suspend)
  - [unSuspend()](#unSuspend)
  - [terminate()](#terminate)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-website-builders
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarize yourself with by reading the usage section in the README.

### Quick-start

The easiest way to see this provision category in action and to develop/test changes is to install it in [upmind/provision-workbench](https://github.com/upmind-automation/provision-workbench#readme).

Alternatively you can start using it for your business immediately with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.

**If you wish to develop a new Provider, please refer to the [WORKFLOW](WORKFLOW.md) guide.**

## Supported Providers

The following providers are currently implemented:
  - [BaseKit](https://apidocs.basekit.com/api/integration-guide/#api-method-overview)
  - [Duda](https://developer.duda.co/docs/partner-api-introduction)
  - [Website.com](https://www.website.com/)
  - [Weebly](https://www.weebly.com/)
  - [Yola (Topline)](https://api.topline.cloud/#api-command-reference)

## Functions

### create()

Creates a site builder account and returns the `account_identifier` (e.g., a domain name) which can be used to identify the account in subsequent requests, and other account information.

### getInfo()

Gets information about a site builder account such as the main domain name, whether or not it is suspended, usage data etc.

### login()

Obtains a signed URL which a user can be redirected to which automatically logs the customer into their account.

### changePackage()

Update the product/package a site builder account is set to.

### suspend()

Suspends services for a site builder account.

### unSuspend()

Un-suspends services for a site builder account.

### terminate()

Completely delete a site builder account.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

 - [Harry Lewis](https://github.com/uphlewis)
 - [Codeline](https://github.com/CodelineNL)
 - [Roussetos Karafyllakis](https://github.com/RoussKS)
 - [All Contributors](../../contributors)

## License

GNU General Public License version 3 (GPLv3). Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.
