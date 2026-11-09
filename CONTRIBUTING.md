# Contributing

Bug reports, fixes and documentation improvements are all welcome.

## Reporting a bug

Open an issue with the plugin, CampTix, WordPress and PHP versions, what you did, what you expected and what happened instead. If the attendee's CampTix log has related entries, include them, with names and phone numbers removed.

For security problems, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Setting up

You need PHP 7.4 or newer with the zip extension, [Composer](https://getcomposer.org/), and a WordPress site with CampTix to try your changes on.

```bash
git clone https://github.com/YOUR-USERNAME/shashura-mobile-money-for-CampTix.git
cd shashura-mobile-money-for-CampTix
composer install
```

To take test payments, choose the Sandbox environment in the plugin settings and use sandbox credentials from your Shashura dashboard. [Getting your Shashura API credentials](README.md#getting-your-shashura-api-credentials) in the README explains where to find them.

## Before you open a pull request

- Run `composer test`. All tests should pass.
- Run `composer lint`. The files you changed shouldn't gain any new problems. Some older files haven't been cleaned up yet, so don't worry about problems you didn't add.
- If you changed which files go into the plugin, run `composer build` and then `php bin/check-zip.php`.
- If your change touches checkout, payments, refunds or webhooks, try it on a site with CampTix as well.

## Pull requests

- Branch from `main`, and keep each pull request to one fix or feature.
- Say which issue it fixes (`Closes #123`) and how you tested it.
- When you change payment, refund or webhook logic, add or update a test in `tests/`.
- Leave the version number, the stable tag and the changelog alone. The maintainers update them when they make a release.
- Use test data in screenshots and examples, never a real person's name or phone number.

Code follows the WordPress Coding Standards as set up in `phpcs.xml.dist`. New functions, classes and hooks use the `shasmomo` prefix, and translatable strings use the `shashura-mobile-money-for-camptix` text domain.
