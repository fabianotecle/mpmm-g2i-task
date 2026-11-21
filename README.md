# My Phone My Money (MpMM)

**Public engineering snapshot · PHP / Laravel · MIT License**

My Phone My Money is a conversational financial application. Its backend coordinates customer journeys, payments, transfers, PIX-related flows, financial movements, and restricted revolving-credit operations. The same API-oriented domain logic supports web-based conversation flows and integrations with external messaging and payment providers.

This repository is an independent, sanitized snapshot of an active application, prepared for an engineering task. It was derived from private source commit `637261733ddff926eb537b2e3fa50b0ea0cddc8a` and has a separate Git history. Private assets, operational defaults, and customer seed data were excluded. **It is not a production deployment or a service for handling real funds.**

## Technology

- PHP 8.2+ and Laravel 12
- Laravel Sanctum for administrative authentication
- Eloquent ORM and database migrations
- PHPUnit for unit and feature tests
- Vite for bundled web resources
- Simulated payment providers for local and automated testing

See `composer.json`, `composer.lock`, `package.json`, and `phpunit.xml` for exact dependencies and test configuration.

## Repository map

| Path | Responsibility |
| --- | --- |
| `app/Services/Ledger/` | Customer balances and movement records |
| `app/Services/RotativeWallet/` | Revolving-credit grants, lots, debts, transfers, and release eligibility |
| `app/Services/Payments/` | Payment providers and reconciliation |
| `app/Services/Conversation/` | Conversational journeys and transaction flows |
| `app/Services/CashOut/` | Withdrawal-related business rules |
| `app/Services/WhatsApp/` | Messaging integration |
| `app/Http/Controllers/Api/` | HTTP entry points |
| `routes/api.php` | API routes and middleware |
| `database/migrations/` | Persisted domain schema |
| `tests/Unit/`, `tests/Feature/` | Existing automated coverage |

The financial domain distinguishes ordinary balances from restricted revolving-credit balances. Credit grants, debts, lots, and movement records participate in eligibility and transfer flows. Review the existing implementation and migrations for the precise rules; this README is an orientation, not a substitute for the code.

## Local setup

Use a disposable development environment, never real customer data or production credentials.

Prerequisites: PHP 8.2+ with extensions required by the locked dependencies, Composer, and a supported database driver. Node.js/npm are needed only if building web assets.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

The example environment selects SQLite. Create the local SQLite database file before running migrations if it does not exist:

```bash
touch database/database.sqlite
php artisan migrate
php artisan serve
```

On Windows, create `database/database.sqlite` using your preferred shell or file manager instead of `touch`.

For optional web assets:

```bash
npm install
npm run build
```

Do not configure real payment, banking, or WhatsApp credentials for an assessment run. The default example uses simulated payment providers. The public seeder does **not** create an administrator; administrative accounts require explicit provisioning.

## Tests

```bash
php artisan test
```

`phpunit.xml` uses an in-memory SQLite database, simulated payment providers, and a synchronous queue. The test base blocks non-mocked outbound HTTP requests.

**Known baseline limitations:** At the time this snapshot was prepared, two existing tests failed on PHP 8.5.6: `PhoneNumberNormalizerTest` and `StatementPdfTest`. They were reproduced before and after the public security changes. Other PHP versions and full-suite results have not been independently verified here. Treat this as an explicit baseline limitation rather than silently excluding tests.

## Security and integration notes

- Administrative API access is authenticated and role-restricted; revolving-credit management also has a separate configured manager check.
- Local financial document URLs are signed and expire after seven days by default (`DOCUMENTS_LINK_TTL_MINUTES`). For externally delivered links, `DOCUMENTS_PUBLIC_URL` must point to the application's public entry point and `APP_KEY` must remain stable for the link lifetime.
- WhatsApp document delivery can use media upload; external provider document URLs are separate from locally signed document links.
- No real provider credentials are included. Keep `.env` and other secrets outside version control.
- This snapshot has undergone a targeted security review, but that review is not a comprehensive security certification.

## License and provenance

Distributed under the [MIT License](LICENSE), subject to the rights of the contributors and any separately licensed third-party components.

The snapshot is intended for source inspection, local development, and reproducible engineering assessment. Refer to a specific commit SHA when using it as a task baseline.
