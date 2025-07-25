# Changelog

All notable changes to the package will be documented in this file.

## v2.5.11 - 2025-07-24

- Update Duda configuration to split `sso_target_destination` into `unpublished_sso_target_destination` and `published_sso_target_destination`

## v2.5.10 - 2025-07-21

- Update Duda createSite() to not automatically publish upon create
- Fix Duda createSite() language normalisation

## v2.5.9 - 2025-07-17

- Update Duda create() to use the new `template` configuration property if set

## v2.5.8 - 2025-07-15

- Update Duda login() to use the new `sso_target_destination` configuration property

## v2.5.7 - 2025-07-11

- Fix DudaApi::getSupportedLanguage() use of Str::replace() for older laravel versions
- Update Duda API error handling to return body if cannot be decoded as json

## v2.5.6 - 2025-07-11

- Update Duda create() to create or re-use a duda account
- Update Duda's AccountInfo result data set to include permissions
- Update Duda changePackage() to sync permissions
- Update Duda unsuspend() to skip free sites
- Update Duda terminate() to suspend by default unless configured to delete

## v2.5.5 - 2025-07-03

- Implement Duda provider

## v2.5.4 - 2024-10-02

- Implement Yola provider via Topline Cloud Services

## v2.5.3 - 2024-09-20

- Fix string casts in Weebly provider

## v2.5.2 - 2024-09-20

- Implement Weebly provider

## v2.5.1 - 2024-08-19

- Update website.com exception handler to return a formatted error response for connection exceptions
- Increase website.com API timeout from 10 to 30 seconds

## v2.5.0 - 2024-02-08

- Add new result data fields for website.com
  - `ip_address`
  - `is_published`
  - `has_ssl`

## v2.4.1 - 2024-01-20

- Fix website.com undefined clientId index errors

## v2.4.0 - 2023-10-27

- Add `site_builder_user_id` parameter and return value for create and subsequent function calls
- Implement Website.com provider

## v2.3.1 - 2023-04-17

- Return `extra` array in AccountInfo after create()

## v2.3.0 - 2023-04-06

- Fix: BaseKit createUser() add language code fall-back
- Fix: BaseKit create() explicitly cast billing_cycle_months to int
- NEW: Add optional `extra` array to CreateParams, send as metadata in BaseKit createUser()

## v2.2.1 - 2022-10-18

- Fix BaseKit provider to not implement LogsDebugData twice

## v2.2.0 - 2022-10-14

- Update to `upmind/provision-provider-base` v3.0.0
- Add icon to Category AboutData
- Add logo_url to Providers' AboutData

## v2.1.1 - 2022-05-30

Fix BaseKit `login()` where no auto_login_redirect_url has been configured

## v2.1 - 2022-05-30

Make `domain_name` an optional parameter + return data value, improve BaseKit
`login()`, add BaseKit request/response debug logging

## v2.0.1 - 2022-05-30

Rename Helpers util file
## v2.0 - 2022-05-10

Initial public release
