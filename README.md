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

Next, per the implementation plan:

3. Inventory reservations with row locking, checkout and pricing snapshots, expiry job
4. A real payment provider adapter, signed webhook endpoint, reconciliation and refunds
5. Ticket issuance, email/PDF delivery and the atomic check-in API
6. Organiser dashboards, reports, attendee export and the admin console
7. Load and security testing, monitoring, backups and launch runbooks

The admin area (`/admin/events`, `/admin/organizers`) requires two-factor authentication: sign in as `admin@tickora.test` and enable it under Settings → Security first.

Payment provider choice and onboarding depend on the platform's legal entity, countries and settlement currencies. Confirm these before committing to a provider or a launch date.
