# ClickInvoice Subscription and Payment Roadmap

**Assessment date:** 2026-09-28
**Scope:** Laravel billing API, payment-provider integration, subscription lifecycle, and the Next.js customer billing flows.
**Purpose:** Prioritize fixes for current customer pain and define an observable, testable path to a dependable billing system.

## Executive Assessment

The current billing flow has several concrete failure and trust risks. The most urgent are privileged subscription operations reachable through the authenticated API group without visible role checks, a checkout write that conflicts with the checked-in payment schema, a customer cancellation handler that exits before cancelling, and webhook processing that is neither idempotent nor tied to a verified payment record. These are code-level findings, not confirmation of a live production incident.

Paystack is the only supported provider for new subscription checkout. Do not migrate, charge, or otherwise operate historical subscriptions through their retired provider identifiers; preserve their stored records as history.

## Provider Decision

**Decision: Paystack is the sole provider for new subscriptions.** A gateway change alone will not fix payment persistence, webhook replay handling, or subscription lifecycle defects, so the new path includes provider-neutral payment references and server-side transaction verification.

Paystack's official documentation supports a credible international-card pilot but does not establish universal international merchant coverage:

- The [Subscriptions documentation](https://paystack.com/docs/payments/subscriptions/) says recurring subscription methods are **Card and Direct Debit (Nigeria) only**.
- The [Payment Channels documentation](https://paystack.com/docs/payments/payment-channels/) says card payments are available across Paystack markets and lists Visa/Mastercard for all markets, while other channels are country-specific.
- Merchant onboarding, account currency, settlement currency, card processing for foreign-issued cards, fees, and recurring-card eligibility still depend on the merchant's approved country/account and must be confirmed by Paystack for ClickBase Technologies Ltd. Documentation about supported card brands is not a guarantee that every issuing country/card/transaction will be accepted.

**Pilot gates:** confirm ClickBase's approved Paystack merchant entity and settlement currencies directly with Paystack; create sandbox plans and checkout; test locally issued and foreign-issued Visa/Mastercard where the sandbox permits, 3DS/OTP and declines, recurring renewal, failed renewal, cancellation, webhook retries, and refunds. Route a limited cohort only after the pilot passes the billing release gates. Historical subscriptions are not migrated or charged through the new flow.

The local frontend sends customers to a Paystack-hosted authorization URL. The return screen polls an authenticated local API for transaction verification; it never activates a plan from callback parameters alone. The local backend initializes subscriptions using a Paystack plan code, stores pending payment references before redirect, verifies amount/currency/email/reference with Paystack, and accepts signed `charge.success` webhooks. These changes are not deployed.

**Before any deploy:** set `PAYSTACK_SECRET_KEY` in the local/staging API environment and `FRONTEND_URL` to the local app origin; provision/verify a Paystack monthly plan code for each paid plan; configure the Paystack dashboard webhook to the local tunnel/staging `/api/paystack/webhook` URL with the same secret key used for signature verification; run all API/frontend checks and sandbox scenarios; then deploy backend migrations and frontend/backend together. Do not use production keys for local tests or run real charges during verification.

### Local Implementation Status (2026-09-28)

- The subscription API now initializes a Paystack recurring checkout using the plan's Paystack plan code, persists a pending provider-neutral payment reference, and returns the Paystack authorization URL.
- A server-side verifier checks the Paystack transaction status, reference, expected plan amount, currency, and customer email before activating the subscription. The signed `charge.success` webhook calls the same idempotent verifier.
- Plan administration creates a Paystack monthly plan and stores its plan code. Changing price/name/currency creates a new Paystack plan code; it does not rewrite an existing agreement.
- Forward-only schema changes add Paystack/provider identifiers, make retired provider-only payment fields optional for new records, and permit lifecycle states used by existing code. Existing row values are preserved; no historical subscription is migrated.
- The Paystack schema migration was applied only to the local database after reviewing its SQL preview. Post-migration counts remain 61 subscriptions and 21 payments; all original statuses remain present, historical provider values remain unchanged, and new provider reference/user fields remain null. The unrelated invoice migration remains pending.
- The local plan UI uses the Paystack hosted authorization URL and a return screen that polls the authenticated verification endpoint.
- Local API test suite passed: 24 tests, 57 assertions. The migration chain passed on in-memory SQLite, Paystack routes registered, and focused frontend ESLint passed.
- Live Paystack plans were created for BASIC (NGN 6,600/month, `PLN_2dovo0znzr4lif0`) and PREMIUM (NGN 13,300/month, `PLN_8i0rr7771fvtnym`); both showed zero subscriptions at setup. The local plan catalog stores these Live codes in `paystackPlanCode` and now has a separate nullable `paystackTestPlanCode` for sandbox use.
- The local API runs with `APP_ENV=local`. Paystack requests now require `sk_test_` credentials outside production and `sk_live_` credentials only in production. Local checkout callbacks are forced to `http://localhost:3002`, regardless of a hosted `FRONTEND_URL` value.
- Interactive local checkout remains unverified. It requires a valid local Paystack test secret, corresponding sandbox plan codes, and a working local frontend/API sign-in session. Do not use production credentials or real charges for local verification. The Live secret previously configured locally must be rotated before further use.
- Focused Paystack tests cover checkout initialization, localhost callback selection, verification/idempotency, mismatch rejection, timeout cleanup, and refusal to make local requests with Live credentials. Billing plan administration and manual lifecycle endpoints now reject non-admin users; customer cancellation reports that self-service is unavailable without changing subscription state.
- The authenticated `/plans` response now reports the latest effective subscription state and dates. An `active` row whose `endDate` or `nextBillingDate` has passed is shown as expired immediately, even if the scheduled sync has not yet rewritten the database status. The plan page displays the ended date and a renewal action.
- `POST /subscriptions/{subscriptionId}/renew` is owner-scoped and starts a new Paystack checkout for expired, failed, or ended subscriptions. It does not reactivate access; the existing transaction verification path must confirm payment first. A cancelled subscription whose paid-through date is still in the future cannot be restarted early.
- The expiry/reconciliation command runs once hourly with an overlap guard; host scheduler execution is still required for persisted statuses to be updated.
- Paystack `invoice.update` renewals are now verified server-to-server by transaction reference and matched to the existing Paystack subscription, customer, original subscription price, and currency. Each successful renewal is stored once under the provider/reference unique constraint and advances `nextBillingDate` from the provider's `next_payment_date`. Replayed invoice events do not create duplicate payments or extend the period twice.
- `invoice.payment_failed` stores a minimal failure marker in subscription metadata. Paystack does not retry failed subscription charges, so the first failure enters a configurable `past_due` grace state, defaulting to three days (`PAYSTACK_RENEWAL_GRACE_DAYS`). Later failures do not restart the grace deadline. Access remains available until the original deadline; the hourly sync then expires the row and downgrades to Starter only when no other entitled subscription remains.
- During grace, customers receive Paystack's hosted subscription-management link to update their existing authorization rather than creating a second recurring subscription. At grace expiry, the hourly sync disables the provider subscription using an encrypted, hidden email token; failed disable requests are logged and retried on later hourly runs. Older subscriptions without that token require support follow-up. The new cancellation-token migration is forward-only and applied locally.
- Customers receive a payment-failed email with the grace deadline and a recovery link, plus a confirmation email after a verified initial payment or renewal. The notification is queued on Laravel's `mail` queue. The local environment currently uses the `sync` queue driver, so development/test notices execute inline; production requires configured mail transport and a monitored `mail` queue worker.
- Configure `PAYSTACK_RENEWAL_GRACE_DAYS` to adjust the default three-day grace.
- Latest API suite: 48 tests, 157 assertions. Customer plan and subscription UI lint passed.
- No changes have been deployed. The existing local frontend on port `3002` was already occupied and its sign-in view remained in an auth-loading loop during browser smoke testing; it was not stopped or replaced.

## Audit Scope and Limits

Reviewed the API routes, subscription and payment controllers/models, schema migrations, scheduled reconciliation, pricing and subscription UI, payment-result pages, and the existing API test inventory. The registered Laravel subscription route list was also checked.

No production database, customer support cases, deployment secrets, or production logs were available in this workspace. In particular, verify the live payments schema before treating the migration mismatch below as the cause of a specific production checkout failure. The API repository had pre-existing local modifications in `AuthController.php`, `IdentifyTenant.php`, `User.php`, and `routes/api.php`; this assessment does not modify them.

## Confirmed Findings

| Priority | Finding | Customer or business impact |
|---|---|---|
| P0 | Subscription listing and manual activate/deactivate/expire/assignment/bulk-action routes are in the `auth.jwt` route group, but no role middleware or controller authorization is visible on these actions. | Any authenticated user may be able to inspect other customers' subscriptions or alter subscription access and plan state. Confirm behavior against production roles immediately and restrict these routes to billing administrators. |
| P1 | The original payment schema required a provider-specific transaction ID before checkout verification. | On a migration-conformant database, checkout could fail after a provider session was created. A forward-only migration now makes the retired provider-only reference optional. |
| P1 | `SubscriptionController::cancel()` returns `$user->subscription` before its cancellation code. | The customer-facing cancel request reports a successful HTTP response while doing no cancellation. The UI then refreshes and can leave customers believing cancellation was accepted. |
| P1 | The webhook creates a new payment record for every successful `charge.completed` delivery, with no transaction/event uniqueness or idempotency guard. | Provider retries can create duplicate ledger entries and repeatedly mutate subscription state. |
| P1 | Webhook handling activates a subscription from the posted event data without a server-to-server transaction verification and does not compare verified reference, amount, currency, or transaction ownership to the pending checkout. | Payment/subscription state can diverge, and unverified or misassociated data may grant paid access. Signature validation alone does not establish that the intended checkout was paid for the expected amount. |
| P1 | The retired webhook reused a transaction identifier as a recurring-subscription identifier. | Transaction and recurring-subscription identifiers were conflated; cancellation and sync could target the wrong provider resource or fail. The handler has been removed. |
| P1 | Subscription logic writes `expired`, but the subscription migration enum permits only `active`, `pending`, `cancelled`, and `failed`. | Expiry and reconciliation can fail or persist an invalid enum value, depending on the production database and SQL mode. |
| P1 | The manual lifecycle endpoints accept arbitrary subscription IDs and update that subscription and its owner's `currentPlan`; they have no visible ownership or administrator check. | Besides administrative misuse, this is a direct cross-account privilege escalation risk if reachable by regular authenticated users. |
| P2 | The old sync command queried a retired provider by customer email rather than matching the stored provider subscription ID. | Duplicate or overlapping reconciliation could add load and apply an email-matched status to the wrong local subscription. The active sync now handles local expiry and Paystack disable requests only. |
| P2 | The cancellation UI offers renewal at `/subscriptions/{id}/renew`, but no corresponding API route is registered. | Renewal from the subscription-management screen cannot complete. |
| P2 | Redirect destinations are inconsistent: checkout redirects to `/dashboard/my-subscriptions`; `verifyRedirect` constructs `/subscription/success` or `/subscription/failed`; the app result pages live under `/dashboard/subscription/...`. The customer result screens were changed in this pass to avoid claiming success from URL parameters, but they still do not poll authoritative server state. | Customers can land on the wrong page or remain unsure while webhook processing is delayed. |
| P2 | Cancellation and downgrade code is inconsistent: one webhook branch writes `plan_id`, while the user model and other paths use `currentPlan`. | Customer access may not be downgraded consistently. |
| P2 | Payment status and failure events are incomplete: the webhook has no `charge.failed` processing, and the customer UI has no confirmed server-side payment status flow. | Failed or delayed payments can remain pending with weak recovery guidance; support has little consistent payment history to diagnose complaints. |

Relevant implementation anchors: [SubscriptionController](../app/Http/Controllers/SubscriptionController.php), [PaystackWebhookController](../app/Http/Controllers/PaystackWebhookController.php), [SyncSubscriptions](../app/Console/Commands/SyncSubscriptions.php), [Subscription model](../app/Models/Subscription.php), [Payment model](../app/Models/Payment.php), [subscription migration](../database/migrations/2025_12_27_173627_create_subscriptions_table.php), [payment migration](../database/migrations/2025_12_27_173950_create_payments_table.php), [API routes](../routes/api.php), [pricing checkout](../../ClickInvoiceFrontend/src/components/ecommerce/Plans.tsx), and [subscription management UI](../../ClickInvoiceFrontend/src/components/tables/MySubscriptions.tsx).

## Current Flow

1. The pricing page loads the authenticated catalog and the user's latest subscription status. If the status request fails, the public catalog may still show pricing, but cannot establish the user's current subscription.
2. A paid-plan selection posts to `/subscribe/{planId}`. The API selects the sandbox plan code outside production and the Live plan code in production, persists a pending subscription/payment, and initializes Paystack hosted checkout.
3. After checkout, the return page submits the reference to the authenticated verification endpoint. The API verifies the transaction with Paystack and checks reference, amount, currency, and customer email before activating the subscription.
4. The `charge.success` webhook verifies the initial transaction and backfills the subscription code from the signed event if the verify response omits it. For recurring cycles, the signed `invoice.update` event is verified by transaction reference, matched to the provider subscription, and recorded idempotently; `invoice.payment_failed` moves the subscription to `past_due` for the configured three-day grace without exposing payment authorization data.
5. `subscriptions:sync` expires rows after `endDate`, or `nextBillingDate` when no end date is set. It is scheduled hourly and again daily at 01:00; the deployed/local scheduler must actually be running. The Plan screen derives expiry from dates so stale `active` rows are not presented as current.
6. A customer can restart an ended plan through `POST /subscriptions/{subscriptionId}/renew`, which starts a new checkout and waits for payment verification. A self-service cancellation path is not yet supported.

The database has no explicit durable checkout-attempt state, no visible unique provider transaction constraint, and no webhook event inbox. The old customer UI exposed cancellation and renewal actions even though cancellation exited early and renewal had no route. The simplified customer billing screen now directs plan changes and cancellation to support until a secure, provider-correct self-service cancellation endpoint is implemented; renewal is not offered as a fake action.

## Delivery Roadmap

### Phase 0: Contain Access and Checkout Failures

**Target: immediately, before expanding billing features.**

- Add administrator authorization to subscription listing, manual activation/deactivation/expiry, assignment, and bulk actions. Verify ordinary users receive `403`; verify authorized billing admins retain intended access.
- Inspect the production `payments` and `subscriptions` schema. Add a forward-only migration that allows a pending payment to exist without a provider transaction ID, or redesign the write sequence so the payment row is created only when its required fields exist. Do not edit old migrations that may already have run in production.
- Add the `expired` lifecycle state through a forward migration or normalize expiry into a schema-supported state before the scheduled command runs.
- Keep new subscription cancellation provider-correct, owner-scoped, and consistent with the agreed cancellation policy.
- Make provider initiation and local persistence failure-safe: retain an auditable failed checkout attempt, and provide a compensating cancellation/reconciliation path if the provider session succeeds but local persistence fails.
- Remove raw customer/provider payloads and secrets from routine logs; log stable internal IDs and redacted diagnostic fields instead.

**Exit criteria:** no billing-admin action is available to an ordinary user; fresh and upgraded databases can create pending checkout records; cancellation has a tested, visible result; no checkout attempt disappears without an auditable state.
- Persist an immutable checkout attempt with a cryptographically random, unique `tx_ref`, user, plan/price/currency snapshot, status, and timestamps before sending the customer to the provider.
- On a successful webhook, validate the signature using configured secrets, then verify the transaction server-to-server. Match provider transaction ID, `tx_ref`, expected amount/currency, customer, and the pending checkout before recording success or granting access.
- Make webhook ingestion idempotent with a unique provider event/transaction key and a short database transaction. Duplicate events should return success without duplicating payments or replaying side effects.
- Update the existing pending payment row (or create one exactly once) rather than creating a second successful payment row. Record provider response data in a redacted or access-controlled form.
- Handle successful, failed, cancelled, reversed/refunded, and recurring charge events explicitly. Unknown event types should be safely acknowledged and observable.
- Keep Paystack HTTP calls behind the small `PaystackClient` service with timeouts, normalized errors, and Laravel configuration access. Keep provider-specific parsing out of controllers.

- Match reconciliation by provider subscription ID and local account identity. Use pagination, backoff, structured outcomes, and a lock/overlap guard. Schedule the job once and emit metrics for mismatches instead of silently changing state.
- Ensure only the current eligible subscription controls entitlements. Replace ambiguous `hasOne` access with a deterministic active-subscription query and guard concurrent checkouts with an idempotency key and database constraint where appropriate.
- Keep `users.currentPlan` synchronized as a derived compatibility field during migration; subscription records should become the entitlement source of truth.

**Exit criteria:** every state transition has one owner and audit record; renewals extend entitlement only after verified payment; cancellation honors the documented policy; reconciliation is repeatable and does not create or cancel the wrong subscription.
- Remove or implement the renewal action. Add customer-visible payment history with amount, currency, date, outcome, and receipt/reference, while keeping provider diagnostics private.
- Provide clear recovery for declined, timed-out, and abandoned checkouts. Retrying should not create duplicate active subscriptions or duplicate charges.
- Send transactional receipts and lifecycle notices for payment confirmation, failed renewal, cancellation confirmation, and upcoming renewal, with delivery outcomes observable by support.

**Exit criteria:** the UI agrees with server state after success, failure, delay, cancellation, and retry; a customer can inspect payments and understand the next billing date; no UI message claims a payment or cancellation succeeded based only on a redirect.
- Establish a daily reconciliation report for provider settlements versus the internal ledger and a documented manual recovery procedure with an audit trail.
- Track customer complaint categories and conversion/drop-off before and after each release; use this to prioritize additional provider methods, pricing experiments, or a billing-provider abstraction.

**Exit criteria:** billing support can diagnose a payment without database edits; financial discrepancies alert promptly; release checks cover the customer-critical provider paths; complaint and recovery rates are measurable.

- **Lifecycle:** renewal success/failure, retry/grace policy, cancel at period end, immediate cancel, expiry, manual admin action, and competing active subscriptions.
- **Customer UI:** delayed webhook, failed and abandoned checkout, refresh/back navigation, cancellation confirmation, and missing/unknown status.
- **Database:** fresh install and production-like upgrade migrations on the same database engine/version used in production.
- **Staging release:** complete one real provider sandbox checkout and cancellation end to end before enabling the changes for customers.

- Which countries/currencies and billing intervals must be supported in the first reliability release?
- Which countries are the current payment complaints coming from, and are they card declines, checkout initialization errors, or post-payment activation delays?
- Can Paystack approve ClickBase's merchant entity for the target markets and settlement currencies, and what card/recurring acceptance rates and fees do they commit to for those markets?
- What refund/chargeback policy and support escalation process should the system encode?
