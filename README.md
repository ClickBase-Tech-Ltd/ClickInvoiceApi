# ClickInvoiceApi

Comprehensive API backend built with Laravel for ClickInvoice — an invoicing, payments, and customer management platform.

## Overview

ClickInvoiceApi is a Laravel-based backend that provides RESTful APIs for invoices, customers, payments, notifications, and related billing workflows. The codebase includes queued jobs, mail templates, importers, and integrations for payment gateways.

## Key Features

- Invoice creation, items, and PDF generation
- Customer management and profile images
- Payment gateway integrations and transaction records
- Notifications, mails and queued jobs
- Import utilities (Excel/CSV)
- Role/permission models and basic auth scaffolding

## Tech Stack

- PHP (Laravel)
- MySQL / MariaDB (or other supported relational DB)
- Redis (optional - queues/cache)
- Composer for PHP dependencies
- Node / npm / Vite for frontend assets (if applicable)

## Requirements

- PHP 8.0+ (check composer.json for exact requirement)
- Composer
- MySQL or compatible database
- Node.js & npm (for asset compilation)
- Optional: Redis for queues/cache, a mail driver for email functionality

## Quick Start (Local Development)

1. Clone the repository

	`git clone <repo-url> ClickInvoiceApi`

2. Enter the project directory

	`cd ClickInvoiceApi`

3. Install PHP dependencies

	`composer install --no-interaction --prefer-dist`

4. Copy `.env` and set environment variables

	`cp .env.example .env`

	Update the following important variables in `.env`:

	- `APP_NAME`, `APP_URL`
	- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
	- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`
	- `JWT_SECRET` (if using JWT)
	- Payment gateway keys for the active gateway(s)

5. Generate application key

	`php artisan key:generate`

6. Run database migrations and seeders

	`php artisan migrate --seed`

7. Create storage symlink (if using local storage for images)

	`php artisan storage:link`

8. Install JS dependencies and build (optional for admin/front assets)

	`npm install`

	For development:

	`npm run dev`

	For production build:

	`npm run build`


9. Serve the application

	Run the Laravel dev server on port 8002:

	`php artisan serve --host=127.0.0.1 --port=8002`

	Or use the provided composer script:

	`composer run-script serve`

    `php artisan serve --port=8002`

10. (Optional) Start queue worker

	 `php artisan queue:work --tries=3`

## Important Environment Variables

Provide values for these at minimum in your `.env` file:

- `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- `CACHE_DRIVER`, `QUEUE_CONNECTION`, `SESSION_DRIVER`
- Payment gateway keys (e.g. `REMITA_*`, `PAYMENT_GATEWAY_*` — see `config/remita.php` & `config/services.php`)
- `JWT_SECRET` (if using JWT auth)

## Database & Seeders

- Migrations are in `database/migrations` and seeders in `database/seeders`.
- If you need sample data, run `php artisan db:seed` after migrating.

## API Routes

- Primary API routes are defined in `routes/api.php` and `routes/api2.php`.
- Use an API client (Postman, HTTPie, curl) to interact with endpoints. Auth-protected routes require a valid token/session.

Example curl (login/auth flow depends on project setup):

`curl -X POST "http://127.0.0.1:8000/api/auth/login" -d '{"email":"user@example.com","password":"secret"}'`

Check controllers in `app/Http/Controllers` for available endpoints and payload structures.

## Testing

- Unit & feature tests live in `tests/Unit` and `tests/Feature`.
- Run the test suite with:

  `php artisan test`

## Common Commands

- `composer install` — install PHP dependencies
- `php artisan migrate` — run migrations
- `php artisan db:seed` — run seeders
- `php artisan queue:work` — start queue worker
- `php artisan route:list` — list routes
- `php artisan config:cache` — cache config for production

## Directory Layout (high level)

- `app/Models` — Eloquent models (Invoice, Customer, Payment, etc.)
- `app/Http/Controllers` — API controllers
- `app/Jobs` — queued jobs
- `app/Mail` — mail templates
- `app/Imports` — importers (Excel/CSV)
- `routes` — API and web routes
- `database` — migrations and seeders

## Troubleshooting

- Missing environment vars: ensure `.env` is populated and `php artisan config:clear` has been run.
- Storage issues: run `php artisan storage:link` and ensure folder permissions.
- Queue jobs not processing: ensure `queue:work` is running and `QUEUE_CONNECTION` is configured.

## Contributing

Contributions are welcome. Suggested workflow:

1. Fork the repo
2. Create a feature branch
3. Make changes and add tests where relevant
4. Submit a PR with a clear description of changes

Please follow existing project style and commit message conventions.

## License

This project does not include a license file. Add a `LICENSE` file if you intend to publish under an open-source license (e.g., MIT).

## Contact

If you have questions about the codebase or need onboarding help, open an issue or contact the maintainers.

---

Notes:

- Look in `config/` for gateway-specific config (e.g., `remita.php`).
- If you want, I can generate a lightweight API reference (OpenAPI/Swagger) for the main endpoints.

