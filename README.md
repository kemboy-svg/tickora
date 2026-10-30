# Tickora

**Every Event. One Ticket.** A multi-organiser event ticketing marketplace: customers discover events and buy tickets, organisers publish events and manage sales, gate staff scan QR codes, and platform admins run trust, payments and payouts.

Laravel 13 · PHP 8.3+ · Vue 3 · Inertia · TypeScript · Tailwind CSS · PostgreSQL · Redis

## Getting started

Requirements: PHP 8.3+ with `pdo_pgsql` and `intl`, Composer, Node 22, PostgreSQL 16. Locally, cache and queues use the database; in production set `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` and run Redis.

```bash
createuser tickora --pwprompt          # any local password; put it in .env
createdb -O tickora tickora
createdb -O tickora tickora_test       # used by the test suite

composer setup                         # install, .env, key, migrate, build
php artisan db:seed                    # local demo data (never in production)
composer dev                           # app, queue worker, logs and Vite
```

Seeded accounts (password `password`):

| Email                        | Role                                  |
| ---------------------------- | ------------------------------------- |
| `admin@tickora.test`         | Platform admin                        |
| `finance@tickora.test`       | Platform finance                      |
| `kavish@kazproductions.test` | Owner, Kaz Productions (MUR)          |
| `nadia@kazproductions.test`  | Manager, Kaz Productions              |
| `priya@kazproductions.test`  | Gate staff, assigned to _Sega Nights_ |
| `wanjiru@nairobilive.test`   | Owner, Nairobi Live (KES)             |
| `amara@example.test`         | Customer                              |

## Checks

```bash
composer test       # Pint, PHPStan (level 7) and the PHPUnit suite on PostgreSQL
composer ci:check   # the above plus frontend lint and type-check, as CI runs it
```

## How the code is organised

A modular monolith: one Laravel app, one PostgreSQL database, business rules in actions and domain services rather than controllers.

| Where                         | What                                                                                                    |
| ----------------------------- | ------------------------------------------------------------------------------------------------------- |
| `app/Enums`                   | Statuses with explicit state machines (`canTransitionTo`), organiser and platform roles, permissions    |
| `app/Support/Money.php`       | Integer minor units + ISO 4217 code. No floats; half-away-from-zero rounding; lossless `allocate()`     |
| `app/Support/TicketToken.php` | 256-bit QR tokens. Only the SHA-256 hash is stored                                                      |
| `app/Contracts/Payments`      | `PaymentGateway`: the only way code talks to a payment provider                                         |
| `app/Services/Payments/Fake`  | Sandbox provider with idempotent sessions and HMAC-signed webhooks; refuses to run in production        |
| `app/Models/Concerns`         | `HasPublicId` (ULIDs in URLs, never internal IDs) and `BelongsToOrganizer` (`visibleTo()` tenant scope) |
| `app/Policies`                | All authorisation, always scoped to the resource's own organiser                                        |
| `app/Actions`                 | Team, staff-assignment and admin actions; each authorises and writes the audit trail                    |
| `app/Services/Audit`          | `AuditLogger`, which scrubs secrets from metadata                                                       |

### Rules worth knowing

- **Money** is always `*_minor` integer columns plus a `currency` column. One currency per event, and therefore per order.
- **Tenancy**: organiser-side queries go through `Model::visibleTo($user)`, and every action is authorised by a policy. A role in one organiser grants nothing in another.
- **Gate staff** see and scan only events they are individually assigned to. Removing a member revokes their assignments.
- **Publishing** is two steps: organisers submit for review, platform moderators publish, and only for verified organisers.
- **Refunds** are requested by one person and approved by another, from platform finance.
- **The database backs the rules up**: CHECK constraints on statuses, amounts and capacity; one active owner per organiser; an append-only `ledger_entries` table; immutable `audit_logs`; unique webhook event IDs.
- **Privileged areas** use the `two-factor` route middleware. Suspended users are signed out on their next request.

## Status

Done:

1. **Foundation**: schema, roles, policies, audit trail, payment contract and CI.
2. **Organisers, events and discovery**: organiser onboarding and workspace switching, event drafts with timezone-aware scheduling, ticket types, submit → review → publish, cancellation, the admin review queue and organiser verification, and the public homepage, discovery filters, event pages and organiser profiles.
3. **Checkout and inventory**: tickets are held for 10 minutes by locking the ticket type rows and recounting availability in the same transaction, so two buyers can never get the last ticket (proved by a test that races separate processes against PostgreSQL). Server-side pricing with a customer booking fee, optional tax on the fee and promo codes; confirming creates a pending order with name and price snapshots and extends the hold to 15 minutes for payment. Expired holds stop counting immediately and are swept every minute by `php artisan tickora:expire-holds` (run `php artisan schedule:work` locally). Organisers manage promo codes under **Promo codes**.
4. **Payment and tickets (sandbox)**: confirming a checkout goes to the payment provider; the signed webhook is verified, logged once per event and re-confirmed with the provider before the order is completed. Completion is idempotent and issues one ticket per seat with its own QR code, records promo use, credits the organiser's ledger and emails a confirmation. Declined cards can retry while the hold lasts; a payment that arrives after the tickets sold out is refunded automatically. Locally the sandbox provider shows its own test payment screen (Pay / Decline).

**Stripe and Paystack.** Platform admins connect them under **Admin → Payment settings**: paste the secret key (and, for Stripe, the webhook signing secret), switch the provider on, and copy the webhook URL shown into the provider's dashboard. Keys are stored encrypted and never shown again. Choose the default provider there, and override it per event on **Event review → Live events**; a provider can only be picked for an event whose currency it supports (Stripe: MUR, KES, ZAR, USD, EUR and more; Paystack: NGN, GHS, ZAR, KES, USD, XOF). Customers pay on Stripe Checkout or Paystack's payment page; when they come back, Tickora confirms the payment with the provider directly, so orders complete even before (or without) the webhook, which is how it works on a development machine that providers can't reach.

Also done: **event photos**: up to 8 photos per event, added while creating the event or later on its edit page; the first is the cover on listings and the event page, with a gallery for the rest. Uploads are checked, re-encoded (stripping camera metadata such as GPS location) and scaled to at most 2400 px. Run `php artisan storage:link` once so the `public` disk is served; set `TICKORA_MEDIA_DISK` to an S3-compatible disk in production.

Next, per the implementation plan:

5. Daily payment reconciliation, customer refund requests and approvals
6. Ticket PDFs and the atomic check-in (scanner) API
7. Attendee lists and export, payouts, and the rest of the admin console
8. Load and security testing, monitoring, backups and launch runbooks

**Dashboards.** `/dashboard` shows a customer's tickets, holds and orders; `/organizer/dashboard` shows the current organiser's sales, tickets per day, upcoming events and recent orders (owners and managers); `/admin` is the platform overview for staff (sales and fees per currency, review and verification queues, top organisers). Figures come straight from paid orders. The local seed adds demo paid orders that match the seeded sold counts, so the dashboards have data: sign in as `admin@tickora.test`, `kavish@kazproductions.test` (organiser) or `amara@example.test` (customer); every seeded password is `password`.

**Admin and two-factor.** The admin area (`/admin`, `/admin/events`, `/admin/organizers`) requires two-factor authentication in production. Locally it is off by default (`TICKORA_REQUIRE_TWO_FACTOR=false`) so you can open the admin pages straight away; set it to `true` to test the production behaviour, where admins without two-factor are sent to Settings → Security with an explanation.

Payment provider choice and onboarding depend on the platform's legal entity, countries and settlement currencies. Confirm these before committing to a provider or a launch date.
