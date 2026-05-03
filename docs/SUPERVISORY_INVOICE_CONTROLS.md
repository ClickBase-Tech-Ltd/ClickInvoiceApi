# Supervisory invoice controls — see canonical spec

The full **end-to-end** tracker (product intent, phases, API drafts, frontend files, progress log) lives in the frontend repo:

**`/Applications/ClickInvoiceFrontend/docs/SUPERVISORY_INVOICE_CONTROLS.md`**

Edit that file as the single source of truth; optionally add backend-only notes below.

---

## Backend-local notes

- Migration: `2026_05_03_120000_supervisory_invoice_controls.php`
- Services: `App\Services\InvoiceAuthorizationService`, `App\Services\InvoiceTotalsService`
- New routes (also mirrored in `routes/api2.php`): `capabilities`, `void`, `items` patch — see `routes/api.php` authenticated invoice block.

_Product checklist:_ canonical doc **ClickInvoiceFrontend/docs/SUPERVISORY_INVOICE_CONTROLS.md** — Section **“Promoting a tenant supervisor”** for `tenant_staff.role = manager`.
