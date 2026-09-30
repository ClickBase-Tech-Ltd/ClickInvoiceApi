# ClickInvoice API Deployment

Production API deployments run from this repository's `main` branch. A push to GitHub runs the Laravel test suite first, then uploads a versioned release over SSH. Frontend deployment is separate and is not triggered by this workflow.

## GitHub Setup

Configure these Actions secrets in the repository (or its `production` environment):

| Secret | Value |
| --- | --- |
| `DEPLOY_HOST` | `168.231.114.123` |
| `DEPLOY_USER` | `root`, matching the current production release permissions |
| `DEPLOY_SSH_KEY` | Private Ed25519 key dedicated to GitHub Actions deployment |
| `DEPLOY_KNOWN_HOSTS` | Pinned SSH host-key line for the server |

Install the matching public key in the deploy account's `authorized_keys`. Obtain the host key fingerprint through a trusted server console, then store the pinned `known_hosts` line in GitHub; do not disable SSH host verification.

The `production` environment currently has no environment secrets configured. Add all four values above there (or configure them as repository Actions secrets). `DEPLOY_SSH_KEY` must be the private key whose public key is installed on the server. `DEPLOY_KNOWN_HOSTS` must contain the pinned known-hosts line for `168.231.114.123`. The workflow now validates these values and explicitly selects that key and host pin for each SSH connection.

## Release Behavior

The workflow tests with PHP 8.2 and SQLite, then uploads the API source and production Composer dependencies to `/var/www/ClickInvoiceApi/releases/api-<commit-sha>`. It links the existing production `.env` and storage, caches Laravel configuration, and verifies Paystack routes are registered without retired provider routes.

Before running pending migrations, it creates a compressed MySQL backup under `/var/backups/clickinvoice-api`, validates the gzip and dump completion marker, then runs `php artisan migrate --force`. The workflow changes only the `api.clickinvoice.app` nginx document root, validates nginx, and reloads nginx gracefully. It checks that the public subscription plan catalog returns valid JSON with HTTP 200 and that an unsigned Paystack webhook request returns HTTP 401. A failed nginx validation or smoke check restores the previous document root.

Migrations must remain forward-only and backward-compatible with the currently serving release. Database schema changes are not automatically reversed during a code rollback.

## Quick deploy

### Fast path

After committing the backend changes on `main`, deploy with one command:

```bash
git push origin main
```

The API workflow runs tests, prepares the production release, creates and verifies a database backup, migrates, switches nginx, and runs smoke checks. It excludes local `node_modules` from the upload; these files are not needed by the Laravel runtime.

For a normal code change, the push is the only deploy command. If the branch is not `main`, switch to `main` and fast-forward first, then push.

### Alternatives

1. From the GitHub Actions page, manually run **Deploy ClickInvoice API** on `main`.
2. With GitHub CLI installed and authenticated, trigger it from the repo:
   ```bash
   gh workflow run deploy-api.yml --ref main
   ```

The workflow performs the production release flow defined in this repo: run tests, create a versioned release under `/var/www/ClickInvoiceApi/releases/api-<commit-sha>`, back up MySQL, run `php artisan migrate --force`, switch nginx to the new `public/`, and run the Paystack/plan smoke checks.

If the workflow fails, stop and inspect the job logs before retrying. Do not bypass the backup + migration + smoke test gate for production.

## Production safety checks

The live backend must satisfy all of these before calling the release successful:

- `GET https://api.clickinvoice.app/api/subscription-plans` returns valid JSON with the expected NGN plan prices
- `POST https://api.clickinvoice.app/api/paystack/webhook` without a valid signature returns `401`
- `paystackPlanCode` values are populated for the active paid plans in the production database
- the configured secret key is the correct environment key (`sk_live_...` in production, `sk_test_...` locally)

For ClickInvoice production, the live Paystack plans should match:

- `BASIC = ₦6,600.00` with `PLN_2dovo0znzr4lif0`
- `PREMIUM = ₦13,300.00` with `PLN_8i0rr7771fvtnym`

Check the job from **GitHub → Actions → Deploy ClickInvoice API** and confirm the workflow completes before treating the site as live.


cd /Applications/ClickInvoice/ClickInvoiceApi

git status
git add .
git commit -m "Update backend before production deploy"
git push origin main


OR

cd /Applications/ClickInvoice/ClickInvoiceApi && git add . && git commit -m "Update backend before production deploy" && git push origin main

# If Git asks for your username/password

git remote -v
git remote set-url origin git@github.com:ClickBase-Tech-Ltd/ClickInvoiceApi.git

git push origin main

# DEPLOY FROM GITHUB TO LIVE SERVER

ssh -i ~/.ssh/clickinvoice_api_deploy_20260929 root@168.231.114.123

cd /var/www/ClickInvoiceApi
git pull origin main
composer install --no-interaction --prefer-dist --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan optimize
systemctl reload nginx
systemctl reload php8.2-fpm


# If you want a single deploy command

ssh -i ~/.ssh/clickinvoice_api_deploy_20260929 root@168.231.114.123 "
cd /var/www/ClickInvoiceApi &&
git pull origin main &&
composer install --no-interaction --prefer-dist --no-dev &&
php artisan migrate --force &&
php artisan config:cache &&
php artisan route:cache &&
php artisan optimize &&
systemctl reload nginx &&
systemctl reload php8.2-fpm
"