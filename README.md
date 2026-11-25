# MyCondo

A backend API for running a condominium or gated community: monthly maintenance fees, online and manual payments, facility bookings, complaints, visitor passes with gate check-in, announcements and in-app notifications.

Built with **Laravel**, **PostgreSQL** and **Laravel Sanctum**, with a 115-test feature suite.

> This repository is the API only. A manual-testing Postman collection covering every endpoint is included.

## Features

| Area | What it does |
|---|---|
| **Units and residents** | Units with unit types (each with a monthly fee). Residents are linked to units as owner or tenant, with move-in and move-out dates. Moving out immediately ends access to that unit's data. |
| **Fees and invoices** | One invoice per unit per month, with the fee copied from the unit type at generation time. A scheduled command marks unpaid invoices overdue. |
| **Payments** | Bank transfer with proof upload, reviewed by the committee (approve or reject with a reason), or online through **Billplz** with a signed webhook. |
| **Facility bookings** | Residents book facilities such as a function hall. Opening hours, maximum duration and approval rules are enforced, and **double-booking is impossible at the database level**. |
| **Complaints** | Residents file complaints (optional photo) for their unit. The committee moves them through statuses, and the unit and committee can comment. |
| **Visitor passes** | Residents register visitors and get a pass code. Security checks visitors in and out at the gate. |
| **Announcements** | The committee posts announcements (pinned ones first). Every active resident is notified. |
| **Notifications** | In-app notifications through Laravel's `database` channel, with endpoints to list, count unread and mark as read. |
| **Activity log** | Every important action is logged with the actor. Security events are logged separately and admins can filter by category. |

## Roles

| Role | Can do |
|---|---|
| `resident` | See their own unit's invoices, pay, book facilities, file complaints, register visitors, read announcements |
| `committee` | Manage units and residents, review payments and bookings, post announcements, manage facilities, handle complaints, see the visitor log |
| `admin` |  Everything the committee can do, plus manage staff accounts (create committee and security users, deactivate or reactivate accounts, reset passwords) and read the activity log ||
| `security` | Check visitors in and out at the gate |

Every role also has its own notification inbox.

## Design decisions worth reading

**Double-booking is prevented by the database, not just by code.** The application checks for overlaps, but the real guarantee is a PostgreSQL exclusion constraint on the booking time range. If two requests race for the same slot, the database refuses the second one (error `23P01`) and the API returns `409 Conflict`. A test proves the constraint on its own, without going through the API.

**Payments are idempotent and verified.** The Billplz webhook checks the signature before doing anything. A forged callback gets `403` and is written to the activity log as a security event. A callback for an unknown bill is acknowledged and ignored, a callback that says "not paid" leaves the payment pending, and a callback with the wrong amount leaves it pending for a human to look at. A repeated callback changes nothing.

**Reviews cannot be applied twice.** Approving or rejecting a payment, approving or rejecting a booking and changing a complaint status all run inside a transaction that locks the row with `lockForUpdate()`. Two committee members clicking at once cannot both succeed, and the second gets `409`.

**One active residency per person per unit** is enforced by a partial unique index, so a duplicate cannot be created even by a race.

**Private files stay private.** Payment proofs and complaint photos are stored outside public storage and served through authorized endpoints. A neighbour cannot open them.

**Authorization is layered.** Role middleware on the route group, Gates and policies for per-record checks (for example, paying only your own unit's invoice), and queries scoped to the logged-in user (for example, a notification of another user returns `404`).

**Rate limits** protect the login (5 per minute), the Billplz webhook (60 per minute) and the gate endpoints (30 per minute).

**Notifications fire only on real changes.** A complaint set to the same status notifies nobody. Marking invoices overdue is safe to repeat, so each resident gets exactly one overdue notification. An announcement reaches each active resident once, even if they live in two units, and not the person who posted it.

**Deactivating an account takes effect immediately.** Deactivating a user (or resetting their password) also deletes their access tokens, so an open session cannot keep working until it expires. Admins cannot deactivate their own account, can only create committee and security accounts through this API (residents come through the residency flow), and every action is written to the activity log without ever logging the password.

## Notifications

All use the `database` channel and are returned in a consistent shape (`type`, `title`, `message` plus the related ids).

| Type | Sent to | When |
|---|---|---|
| `payment.approved` / `payment.rejected` | The resident who paid | The committee reviews a transfer, or Billplz confirms an online payment |
| `invoice.overdue` | Active residents of the unit | The overdue command marks the invoice overdue |
| `booking.approved` / `booking.rejected` | The resident who booked | The committee reviews the booking |
| `booking.cancelled` | The resident who booked | The committee cancels it (not when they cancel their own) |
| `complaint.status_changed` | The resident who filed it | The committee changes the status |
| `announcement.posted` | All active residents | The committee posts an announcement |

## Tech stack

- PHP 8.3+, Laravel 13
- PostgreSQL
- Laravel Sanctum (token authentication)
- Laravel Sail (Docker development environment)
- PHPUnit 12, Laravel Pint
- Billplz (sandbox) for online payments

## Getting started

Requires Docker. On Windows, run these commands from WSL.

```bash
git clone <your-repo-url> mycondo
cd mycondo

cp .env.example .env

# Install dependencies and start the containers
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html laravelsail/php83-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d

sail artisan key:generate
sail artisan migrate --seed
```

The API is then available at `http://localhost/api/v1`.

Add your Billplz sandbox credentials to `.env` to try online payments.

### Running the tests

```bash
sail artisan test
```

The suite runs against a separate PostgreSQL database, because the booking constraint is a PostgreSQL feature.

### Console commands

| Command | Purpose |
|---|---|
| `sail artisan invoices:generate --month=2026-11` | Creates one invoice per unit for the month. Safe to run twice. |
| `sail artisan invoices:mark-overdue` | Marks unpaid invoices past their due date as overdue and notifies the unit's residents. Safe to run twice. |

## Trying it out

Import `MyCondo_postman_collection.json` into Postman. It contains every endpoint grouped by role, saves the login tokens automatically, and includes a Billplz callback simulator (valid, forged, wrong amount and not paid).

1. Run the four requests in **1. Login** first.
2. Use the role folders (**Resident**, **Committee**, **Admin**, **Security**).
3. Demo resident login: `resident@mycondo.test` / `password`. The other roles are in the collection.

## API overview

Base URL: `/api/v1`. Everything except login needs a `Bearer` token.

| Method | Endpoint | Roles |
|---|---|---|
| POST | `/login` | public |
| POST | `/logout`, GET `/me` | any |
| GET | `/invoices`, `/invoices/{id}` | resident, committee |
| POST | `/invoices/{id}/payments` | resident (bank transfer with proof) |
| POST | `/invoices/{id}/pay/billplz` | resident (online) |
| GET | `/payments`, `/payments/{id}/proof` | committee |
| POST | `/payments/{id}/approve`, `/payments/{id}/reject` | committee |
| GET | `/facilities`, `/facilities/{id}/availability` | any |
| POST | `/facilities/{id}/bookings` | resident |
| GET | `/bookings` | resident (own), committee (all) |
| POST | `/bookings/{id}/cancel` | resident (own), committee |
| POST | `/bookings/{id}/approve`, `/bookings/{id}/reject` | committee |
| GET, POST | `/complaints` | resident (own unit), committee |
| GET | `/complaints/{id}`, `/complaints/{id}/photo` | unit residents, committee |
| POST | `/complaints/{id}/comments` | unit residents, committee |
| PATCH | `/complaints/{id}/status` | committee |
| POST, GET | `/visitors` | resident (register), resident and committee (list) |
| POST | `/visitors/{id}/cancel` | resident, committee |
| GET | `/gate/expected` | security |
| POST | `/gate/check-in`, `/gate/check-out` | security |
| GET | `/announcements`, `/announcements/{id}` | any |
| POST, PUT, DELETE | `/announcements` | committee |
| GET, POST, PATCH, DELETE | `/units`, `/units/{id}/residents`, `/residencies/{id}/move-out` | committee |
| POST, PUT | `/facilities`, `/facilities/{id}` | committee |
| GET | `/activity-logs` | admin |
| GET | `/users` (`?role=`, `?status=`, `?search=`) | admin |
| POST | `/users` (creates committee or security accounts) | admin |
| PATCH | `/users/{id}/deactivate`, `/users/{id}/activate` | admin |
| POST | `/users/{id}/reset-password` | admin |
| GET | `/notifications` (`?unread=1`) | any |
| GET | `/notifications/unread-count` | any |
| PATCH | `/notifications/{id}/read` | any |
| POST | `/notifications/read-all` | any |
| POST | `/api/webhooks/billplz` | Billplz (signature verified) |

## Tests

125 feature tests cover:

- **Fees and payments**: invoice visibility by unit, proof upload, one pending payment per invoice, approval and rejection, no double review
- **Billplz**: starting a payment, valid, repeated, forged, unpaid, wrong-amount and unknown-bill callbacks
- **Bookings**: opening hours, maximum duration, half-hour slots, past bookings, overlaps (including the database constraint itself), cancellation and approval rules
- **Complaints**: unit scoping, photo privacy, status flow, comments, closed complaints
- **Visitors and gate**: pass limits, single use, wrong day, cancelled passes, check-in and check-out
- **Residents**: linking, duplicates, moving out
- **Notifications**: the endpoints, plus a test for every trigger, including the cases where nothing should be sent
- **Activity log**: login events, security events, filtering, pruning after 90 days
- **Admin user management**: role-restricted access, creating staff accounts, duplicate emails, deactivation blocking login and ending sessions, no self-deactivation, password reset

## Author

Built by Muhammad Hafiz bin Ridzuan (Apiz) as a portfolio project.
