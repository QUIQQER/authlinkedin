![QUIQQER Auth LinkedIn](bin/images/Readme.png)

# LinkedIn authentication for QUIQQER

`quiqqer/authlinkedin` adds LinkedIn OpenID Connect authentication to QUIQQER. It provides both a primary authenticator and a registration option for `quiqqer/frontend-users`.

## Requirements

- PHP 8.2 or newer
- QUIQQER Core 2.24 or newer
- `quiqqer/frontend-users` 2.8 or newer
- A LinkedIn developer application with OpenID Connect enabled

## Installation

Install the package through the QUIQQER package manager or with Composer:

```bash
composer require quiqqer/authlinkedin
```

Run the QUIQQER setup after installation so the package database table, settings, and providers are registered.

## Configuration

Open the frontend-users settings in the QUIQQER administration and select the LinkedIn authentication section. Enter the Client ID and Client Secret from the LinkedIn Developer Portal.

Register this package URL as an authorized redirect URL for the LinkedIn application:

```text
https://your-domain.example/opt/quiqqer/authlinkedin/bin/oauth_callback.php
```

The exact `/opt/` path may differ if the installation uses a custom QUIQQER optional-package URL.

## Usage

After configuration, the LinkedIn button is available in the QUIQQER login and frontend registration flows. A successful registration links the LinkedIn subject identifier to the created QUIQQER user.

## Development

Initialize and run the package-local quality tools with:

```bash
composer dev:init
composer test
```

The test command runs PSR-12 checks, PHPStan level 8, and PHPUnit.

## Support

- Issues: https://dev.quiqqer.com/quiqqer/authlinkedin/issues
- Source: https://dev.quiqqer.com/quiqqer/authlinkedin
- Email: support@pcsg.de

## License

GPL-3.0-or-later
