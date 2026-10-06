# Website Audit

**Application:** FDCP Calendar (Laravel 13 + Inertia + Vue 3)
**Audit date:** 2026-09-29
**Base commit:** `47ee9fb` (working tree also carried uncommitted chat-removal and dashboard work)
**Environment audited:** local dev server at `http://localhost:8000`, MySQL, demo seed (`DemoSeeder`), Vite dev server, queue driver `database`
**Auditor:** automated code + live audit (Claude Code), reviewed against the running application

How to read this file:

- Every issue has an id `ISS-nnn`. Fixed issues have a regression test with the same id in `tests/Feature/AuditRegressionTest.php`.
- Every edge case has an id `EC-nnn` and states how it was verified: **Live** (HTTP probe against the running app), **Test** (automated test), **Browser** (headless Chrome run), or **Code** (traced in source, not executed).
- "Confirmed" means the behaviour was observed. "Recommendation" means a judgement call the team should make.

---

## 1. Executive Summary

The application is a well-structured internal calendar and booking system with a clear permission model (Spatie roles/permissions, policies, a `super_admin` gate bypass), strong booking-rule enforcement, transactional conflict checks, CSRF-protected session JSON endpoints and a passing test suite. Most of the "obvious" bypasses (direct URL access, id manipulation, calling admin endpoints as staff, editing other users' events, reading other users' personal calendars) are correctly blocked, and this was verified live.

The audit found **40 issues**. Thirty-two were fixed (16 in the first pass, 16 more in a second pass after review) and are covered by **33 new regression tests**; the remaining 8 need a product or deployment decision and are documented with reproduction steps and recommendations.

| Severity | Found | Fixed in this audit | Open |
| --- | --- | --- | --- |
| Critical | 1 | 1 | 0 |
| High | 2 | 1 | 1 (operational: seeded default password) |
| Medium | 13 | 12 | 1 (ISS-017, role-management policy) |
| Low | 24 | 18 | 6 (ISS-020, 021, 023, 031, 036, 038) |

Headline findings:

1. **Privilege escalation to super admin (Critical, fixed).** Any account holding `users.approve` (the seeded `division_admin` role) could approve a pending registration with `role=super_admin`; any `users.manage` holder could assign `super_admin` through the user editor or an invitation, and could edit or strip a super admin's roles. Reproduced live against the dev server as the demo division admin. Now blocked server-side (validation rule + request authorization) and hidden in the UI.
2. **Archived resources crashed three screens (High, fixed).** Archiving a room or vehicle made "My bookings", the admin desk and the approvals page return HTTP 500 for anyone with a booking on it.
3. **Business-rule gaps (Medium, fixed):** change requests ignored room capacity; working hours accepted closing before opening; holiday blocking used the UTC day instead of the Manila day (bookings between 00:00 and 08:00 on a holiday slipped through); a series-wide time shift silently dropped per-occurrence edits; moving one occurrence with only a start time produced an event ending before it started.
4. **Lifecycle gaps (Medium, fixed):** suspended and pending accounts could still use every settings page (change email, password, delete account); deleted divisions left their calendars live; invitations for already-existing emails crashed on acceptance; private ("Busy") events leaked their comment threads.
5. **Second pass (fixed):** JSON 403 for suspended sessions, cancel/reject serialized under lock, branded error pages, phone-width hydration warnings, working-hours wrapping, overnight holiday blocking, unit members hearing division announcements, stale change requests, profile email domain rule, archived-account Google sign-in, slug collisions, sub-daily recurrence, audit logging of role/permission/share changes, skip link, attendee bound, inactive event types on occurrences.
6. **Open items to decide on:** seeded `admin@fdcp.gov.ph` / `password` in the production seeder, how far `roles.manage` may reach, rejected bookings staying on room calendars, attendee search exposing emails, cancelling open bookings when rooms are archived or users deleted, comment moderation, production cookie/header hardening, pre-existing static-analysis debt.

Test suite: **214 passing before the audit, 263 passing after** (33 added by the audit; the rest came from concurrent booking and admin-desk work in the same working tree during the audit window). Lint, type check (`vue-tsc`), Pint and PHPStan (on touched files) pass.

---

## 2. Application Scope

### 2.1 Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3, Laravel 13, Fortify (login, registration, password reset, email verification, 2FA, passkeys), Socialite (Google), Spatie permission, Spatie activitylog, Spatie settings, `rlanvin/php-rrule` |
| Frontend | Vue 3, Inertia (SSR enabled), Pinia, Tailwind 4, reka-ui, Wayfinder route helpers, vue-sonner toasts |
| Data | MySQL (dev/prod), SQLite in-memory for tests; queue, cache and sessions on the database |
| Jobs | `SendDueReminders` every minute, `PruneExpiredInvitations` daily |

### 2.2 Functional areas audited

- Authentication: password login, Google sign-in, invitations, self-registration with admin approval, email verification, password reset, 2FA, passkeys, password confirmation.
- Calendars: organization, division (with one-level "units"), resource (one per room/vehicle) and personal calendars; person-to-person sharing; per-user visibility/color preferences.
- Events: CRUD, all-day, recurrence (RFC 5545 RRULE) with this / following / all edit scopes, attendees + RSVP, reminders, comments, private events.
- Bookings: request, approve, reject, cancel, change requests; per-type rules (lead time, max duration, advance window, active-booking cap, working hours, holidays, capacity); availability lookup.
- Admin: desk dashboard, users, pending registrations, invitations, divisions, roles, managed calendars, event types, resource types, resources, holidays, working hours, announcements, audit log, system health, general settings.
- Cross-cutting: notifications (database + mail with per-key preferences), announcements, session/CSRF handling, rate limiting, responsive layout, accessibility basics.

### 2.3 Out of scope / not exercisable here

Real email delivery (no SMTP listener locally), real Google OAuth round trip, WebAuthn passkey ceremonies, production infrastructure (TLS, proxies, backups), load testing.

---

## 3. Route / Page Inventory

Middleware key: `auth` = signed in, `verified` = email verified, `approved` = account status active, `admin` = `can:admin.access`, `guest` = not signed in.

### 3.1 Public / guest

| Route | Method | Page | Middleware | Verified |
| --- | --- | --- | --- | --- |
| `/` | GET | redirect → `/dashboard` | – | Live: 302 |
| `/login` | GET/POST | `auth/Login` | guest, throttle `login` 5/min | Live: 200; login as 5 demo users OK |
| `/register` | GET/POST | `auth/Register` | guest | Live: 200 (self-registration enabled); GET now redirects when disabled (ISS-011) |
| `/forgot-password`, `/reset-password/{token}` | GET/POST | `auth/ForgotPassword`, `auth/ResetPassword` | guest | Test (PasswordResetTest) |
| `/auth/google/redirect`, `/auth/google/callback` | GET | Socialite | guest | Code only (no credentials configured) |
| `/invitation/{token}` | GET/POST | `auth/AcceptInvitation` | guest, throttle 6/min | Test (InvitationTest, ISS-009) |
| `/up` | GET | health | – | Live: 200 |
| `/.well-known/passkey-endpoints` | GET | JSON | – | Code |

### 3.2 Signed-in (any status)

| Route | Method | Page | Middleware | Verified |
| --- | --- | --- | --- | --- |
| `/pending-approval` | GET | `auth/PendingApproval` | auth | Live: 200 for pending; active users redirected (Code) |
| `/email/verify`, `/email/verification-notification` | GET/POST | `auth/VerifyEmail` | auth | Test (EmailVerificationTest) |
| `/user/confirm-password`, `/user/confirmed-password-status` | GET/POST | `auth/ConfirmPassword` | auth | Test (PasswordConfirmationTest) |
| `/two-factor-challenge` | GET/POST | `auth/TwoFactorChallenge` | guest, throttle 5/min | Test (TwoFactorChallengeTest) |
| `/logout` | POST | – | auth | Live: 204 JSON |

### 3.3 Signed-in, verified, approved

| Route | Method | Page | Verified |
| --- | --- | --- | --- |
| `/dashboard` | GET | `Dashboard` (merged org + division + personal calendars) | Live: 200 staff; Browser 390/768/1366 |
| `/calendar/public` | GET | `PublicCalendar` (organization calendars) | Live: 200; Browser 390 |
| `/calendar/division` | GET | `DivisionCalendar` (redirects to dashboard when the user has no division) | Live: 200; Browser 390 |
| `/resources/{resourceType:slug}` | GET | `ResourceSection` (type calendar + booking + my bookings) | Live: 200 `board-room`, 404 unknown/inactive slug; Browser 390/1366 |
| `/calendars` | POST | personal calendar create | Test (PersonalCalendarTest) |
| `/calendars/{calendar}` | PATCH/DELETE | rename/recolor/delete (policy `update`/`delete`) | Live: 403 on org calendar as staff |
| `/calendars/{calendar}/shares` | POST | share personal calendar | Live: 403 on someone else's calendar |
| `/calendars/{calendar}/shares/{share}` | DELETE | revoke share | Test (CalendarShareTest) |
| `/division-units` | POST | create unit under own division | Test (DivisionUnitTest); Live: pending user redirected |
| `/division-units/{division}` | PATCH/DELETE | manage unit | Test (DivisionUnitTest) |

### 3.4 Settings (auth + verified + **approved** since ISS-007)

| Route | Method | Page | Verified |
| --- | --- | --- | --- |
| `/settings` | GET | redirect → profile | Code |
| `/settings/profile` | GET/PATCH/DELETE | `settings/Profile` (+ account deletion) | Live: 200 active, 302 pending (after fix); Browser 390 |
| `/settings/security` | GET | `settings/Security` (password, 2FA, passkeys) requires password confirmation | Live: 423 JSON without confirmation |
| `/settings/password` | PUT | password change, throttle 6/min | Test (SecurityTest) |
| `/settings/appearance` | GET | `settings/Appearance` | Code |
| `/settings/notifications` | GET/PUT | `settings/Notifications` | Live: 200 active, 302 pending (after fix); Browser 390 |

### 3.5 Admin (`auth`, `verified`, `approved`, `can:admin.access`, plus per-page permission)

| Route | Method | Permission | Page | Verified |
| --- | --- | --- | --- | --- |
| `/admin` | GET | admin.access | `admin/Dashboard` (desk) | Live: 403 staff, 200 division admin / super admin; Browser 390/1366 |
| `/admin/users` | GET | users.manage or users.approve | `admin/users/Index` | Live: 403 staff/approver, 200 division admin; Browser 390/1366 |
| `/admin/users/{user}` | PATCH | users.manage (+ super-admin guard, ISS-001) | – | Live: 403 staff, 403 division admin, 403 staff→super admin |
| `/admin/users/{user}/suspend`, `/reactivate` | POST | users.manage | – | Test (UserManagementTest) |
| `/admin/users/{user}` | DELETE | users.manage | – | Test |
| `/admin/pending-registrations` | GET | users.manage or users.approve | `admin/users/Pending` | Live: 200 division admin |
| `/admin/pending-registrations/{user}/approve` | POST | users.approve (+ AssignableRole) | – | Live: was 302 (escalation), now 422 |
| `/admin/pending-registrations/{user}` | DELETE | users.approve | – | Test |
| `/admin/invitations` | GET/POST | users.manage | `admin/invitations/Index` | Live: 403 division admin |
| `/admin/invitations/{invitation}/resend`, DELETE | POST/DELETE | users.manage | – | Test (InvitationTest) |
| `/admin/divisions` (+ store/update/destroy) | GET/POST/PATCH/DELETE | divisions.manage | `admin/divisions/Index` | Test (DivisionManagementTest, ISS-006) |
| `/admin/roles` (+ CRUD) | … | roles.manage | `admin/roles/Index` | Live: 403 division admin; Test (RoleManagementTest); Browser 390 |
| `/admin/calendars` (+ CRUD) | … | calendars.manage | `admin/calendars/Index` | Test (ManagedCalendarTest) |
| `/admin/event-types` (+ CRUD) | … | event-types.manage | `admin/event-types/Index` | Test (EventTypeTest) |
| `/admin/resource-types` (+ CRUD) | … | resource-types.manage | `admin/resource-types/Index` | Test (ISS-010) |
| `/admin/resources` (+ CRUD) | … | resources.manage | `admin/resources/Index` | Test (ISS-002) |
| `/admin/bookings` | GET | bookings.approve | `admin/bookings/Index` | Live: 403 division admin; Browser 1366 |
| `/admin/bookings/{booking}/approve`, `/reject` | POST | policy `approve` (permission + type's approver role) | – | Live: 403 cross-type, 422 double approve, 422 reject without reason |
| `/admin/edit-requests` (+ apply/reject) | GET/POST | bookings.approve / policy | `admin/edit-requests/Index` | Live: 200 approver; Test (EditRequestTest, ISS-012) |
| `/admin/holidays` (+ CRUD) | … | holidays.manage | `admin/holidays/Index` | Code |
| `/admin/working-hours` | GET/PUT | working-hours.manage | `admin/working-hours/Index` | Test (ISS-004); Browser 390 |
| `/admin/announcements` (+ CRUD) | … | announcements.manage | `admin/announcements/Index` | Test (AnnouncementTest) |
| `/admin/audit` | GET | audit.view | `admin/audit/Index` | Live: 403 staff; Browser 390 |
| `/admin/health`, `/admin/health/retry` | GET/POST | settings.manage | `admin/health/Index` | Browser 1366 |
| `/admin/settings`, `/admin/settings/general` | GET/PUT | settings.manage (hub page has no extra check) | `admin/settings/*` | Live: 403 division admin on general; Browser 390 |

---

## 4. Endpoint / API Inventory

All `/api/v1/*` routes live inside the `web` group: session auth, CSRF (`X-XSRF-TOKEN` from the cookie), `verified` + `approved`, and throttle `calendar-data` (120 requests/min per user). JSON is returned because the path matches `api/*`.

| Endpoint | Method | Authorization | Validation highlights | Verified |
| --- | --- | --- | --- | --- |
| `/api/v1/calendars` | GET | accessible set only | `types[]` in enum, `resource_type_id` int | Live 200; unknown type → 422 |
| `/api/v1/calendars/{calendar}/preference` | PUT | policy `view` | hex color, sort 0–65535 | Live 403 on inaccessible calendar |
| `/api/v1/events` | GET | ids intersected with accessible set | window ≤ 190 days, dates | Live: too large → 422; invalid → 422; foreign calendar id → `[]` |
| `/api/v1/events` | POST | `canWriteEvents` on target calendar (after-hook) | title ≤ 255, url, rrule valid, attendees ≤ 100, reminders ≤ 5 | Live 422 for org/resource/other-division calendars |
| `/api/v1/events/{event}` | PATCH | policy `update` | as above, end after start, target calendar writable | Live 403 org/booking events; 200 own-division event |
| `/api/v1/events/{event}` | DELETE | policy `delete` | – | Code + test |
| `/api/v1/events/{event}/occurrence` | PATCH | policy `update` | scope, original start, **start and end together** (ISS-005) | Live 422 start-only (after fix) |
| `/api/v1/events/{event}/occurrence` | DELETE | policy `delete` | scope | Test (RecurrenceTest) |
| `/api/v1/events/{event}/rsvp` | PUT | must be an attendee | enum | Live 403 when not invited |
| `/api/v1/events/{event}/comments` | GET/POST | can view calendar **and event not private for others** (ISS-008) | body ≤ 2000, throttle 30/min | Live 403 inaccessible/private; 200 org event |
| `/api/v1/events/comments/{comment}` | DELETE | author only | – | Live 404 unknown; test 403 other author |
| `/api/v1/event-types` | POST | event-types.manage | unique name, hex color | Live 403 staff |
| `/api/v1/users/search` | GET | any active user | q 2–100 chars | Live 200 (returns emails) |
| `/api/v1/notifications` (+ read, read-all, clear) | GET/POST/DELETE | own notifications | – | Live 404 unknown id |
| `/api/v1/resources` | GET | any | – | Code |
| `/api/v1/resources/{resource}/availability` | GET | any (no policy) | date | Live 422 bad date, 404 unknown |
| `/api/v1/bookings` | POST | any active user; rules in `BookingRuleValidator` | resource exists, end after start, purpose ≤ 2000, attendees 1–10000 | Live 201 / 422 for every rule |
| `/api/v1/bookings/mine` | GET | own bookings, limit 100 | resource_type_id | Live 200 (also after resource archive, ISS-002) |
| `/api/v1/bookings/{booking}/cancel` | POST | policy `cancel` (requester or type approver), throttle 20/min | reason ≤ 1000 | Live 403 other user; 200 approver |
| `/api/v1/bookings/{booking}/edit-requests` | POST | policy `requestEdit` (requester, approved only) | dates pair, capacity (ISS-003) | Live 403 pending; 201 approved; 422 second pending |
| `/api/v1/announcements/{announcement}/dismiss` | POST | any (must be dismissible) | – | Live 204; 404 unknown |

Fortify endpoints (login, logout, register, forgot/reset password, email verification, 2FA enable/confirm/disable/QR/recovery codes, passkey register/login, password confirm) are registered with the `web` middleware and Fortify's own rate limiters.

---

## 5. User Roles & Permissions

### 5.1 Permissions (from `RolePermissionSeeder`)

`admin.access`, `users.manage`, `users.approve`, `divisions.manage`, `roles.manage`, `calendars.manage`, `events.create-org`, `event-types.manage`, `resource-types.manage`, `resources.manage`, `bookings.approve`, `settings.manage`, `announcements.manage`, `holidays.manage`, `working-hours.manage`, `audit.view`.

### 5.2 Seeded roles

| Role | System | Permissions | Notes |
| --- | --- | --- | --- |
| `super_admin` | yes | none stored; `Gate::before` grants everything | cannot be edited (UpdateRoleRequest); accounts protected from suspend/delete |
| `division_admin` | yes | admin.access, users.approve, events.create-org | **could previously mint super admins (ISS-001)** |
| `staff` | yes | none | default for registrations, invitations, Google sign-ups |
| `board_room_approver` | no | admin.access, bookings.approve | approver role for the Boardroom type |
| `vehicle_approver` | no | admin.access, bookings.approve | approver role for the Carpool type |

### 5.3 Effective access matrix (verified live with the demo accounts)

| Capability | staff | division_admin | approver | super_admin |
| --- | --- | --- | --- | --- |
| Dashboard, public/division calendars, resource sections | yes | yes | yes | yes |
| Create events on own division calendar (and edit anyone's there) | yes | yes | yes | yes |
| Create events on organization calendars | no (422) | yes (201) | no | yes |
| Book rooms/vehicles, cancel own, request changes on approved | yes | yes | yes | yes |
| Approve/reject bookings | no | no (403) | own type only (403 cross-type) | all |
| Cancel other users' bookings | no (403) | no | own type (200) | yes |
| Admin desk | no (403) | yes | yes | yes |
| Users list / approve registrations | no | yes | no (403) | yes |
| Assign `super_admin` | no | **was yes → now 422** | no | yes |
| Edit a super admin account | no | **was possible with users.manage → now 403** | no | yes |
| Roles, calendars, settings, audit | no | no (403) | no | yes |
| Create/rename/delete units of own division | yes | yes | yes | yes |

### 5.4 Observations

- Policies check permissions, never role names; `super_admin` is handled by `Gate::before`. Good.
- `RolePolicy` lets any `roles.manage` holder grant any permission (including `users.manage`, `roles.manage`) to any non-super role, including their own. This is inherent to the design but means `roles.manage` is effectively "everything except super admin" (ISS-017). Role permission changes are not written to the audit log (ISS-035).
- The UI hides admin navigation by permission (`AppSidebar.vue`, `SettingsLayout.vue`), and the server enforces the same permissions on every route: no UI-only gating was found.

---

## 6. Major User Flows

Each flow was walked end to end. "Live" steps were executed against the dev server; "Test" steps are covered by the suite; "Browser" steps were rendered headlessly.

### 6.1 Sign-up → approval → first booking → approval → change → cancel

| Step | Result |
| --- | --- |
| Register with `@fdcp.gov.ph` email, pick division | Test: account pending, `staff` role, approvers notified |
| Try dashboard while pending | Live: 302 → `/pending-approval`; API calls also redirected (ISS-018) |
| Try settings while pending | Live: was 200 → now 302 (ISS-007) |
| Admin approves with division + role | Live/Test: active; calendar cache refreshed; `super_admin` now refused for non-super-admins (ISS-001) |
| Open Boardroom section, pick room/date/time | Browser: dialog renders at 390 px and 1366 px with availability, hours, holiday hints |
| Submit booking | Live: 201 pending; overlapping/short-notice/too-long/too-far/past → 422 with field messages |
| Approver approves | Live: 200; second approve → 422; wrong type → 403 |
| Requester submits change request | Live: 201; second → 422; over capacity → was 201, now 422 (ISS-003) |
| Approver applies change | Test: booking and its event move together; slot re-validated; deleted requester no longer crashes (ISS-012) |
| Requester cancels | Live/Test: 200; cancelled → cannot be approved (422) |

### 6.2 Login → Dashboard → Create → Edit → "Publish" (share) → View as other → Update → Delete

| Step | Result |
| --- | --- |
| Login (password) | Live: 200 JSON, session cookie + XSRF cookie set |
| Dashboard first paint | Browser: calendars and a three-month window inlined; no follow-up errors |
| Create event on personal calendar (quick create / drawer) | Code: `saving` guard, 422 errors mapped to fields, toast on other failures |
| Edit (drag/resize) | Code: optimistic update with rollback on failure |
| Share calendar with a colleague as Viewer/Editor/Manager | Test: access levels enforced; viewer cannot write |
| Colleague views | Test/Live: personal calendars isolated unless shared; private events masked as "Busy" |
| Update recurring occurrence (this/following/all) | Test: scopes work; start-only move now rejected (ISS-005); all-shift keeps overrides (ISS-013) |
| Delete | Code/Test: recurring asks scope; plain asks confirmation; 204 |

### 6.3 Interrupted workflows

| Interruption | Behaviour |
| --- | --- |
| Start booking → refresh | Dialog state is client-only; nothing persisted until submit. Restarts clean. |
| Start booking → Back | Inertia navigates away; dialog state discarded; no partial booking. |
| Start booking → second tab books the same slot | Second submit gets 422 "already booked"; dialog reloads that day's availability and keeps the picked time (Code, BookingDialog). |
| Start → session expires | Any API call returns 401/419 JSON; previously only a toast. Now the client redirects to `/login` (ISS-014). Inertia form posts already redirected. |
| Start → network failure | Booking dialog shows "Could not reach the server…"; editor shows "Something went wrong while saving." |
| Submit → double click | Booking dialog, editor, quick create, comments, admin forms all guard with `saving`/`processing`; server transitions reject repeats (422). |
| Submit → refresh | Inertia forms: POST completes server-side, redirect back; refresh re-renders fresh props. API forms: request completes; refresh reloads inlined data. |
| Submit → server error | 500 → toast "Something went wrong" (JSON `message` shown when present). Inertia forms show the framework error modal in debug. |
| Approve → same booking approved in another tab | Second approve → 422 transition error (Live). |
| Stale page: event deleted elsewhere, then edit | API 404 → toast; grid refetches on next navigation (Code). |

---

## 7. Edge Case Testing

134 distinct edge cases. Method: **Live** = executed against the dev server, **Test** = automated test in the suite, **Browser** = headless Chrome, **Code** = traced in source.

### 7.1 Authentication and session

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-001 | Login with wrong password | Test (AuthenticationTest) | 422, no session |
| EC-002 | Login throttling (5/min per email+IP) | Code (FortifyServiceProvider) | Enforced |
| EC-003 | Login as pending account | Live | Dashboard → 302 `/pending-approval` |
| EC-004 | Login as suspended account | Test (RegistrationApprovalTest) | Redirected to pending-approval page with "suspended" copy |
| EC-005 | Call `/api/v1/*` after logout | Live | 401 JSON `Unauthenticated.` |
| EC-006 | Visit `/dashboard` after logout | Live | 302 → `/login` |
| EC-007 | POST without `X-XSRF-TOKEN` | Live | 419 |
| EC-008 | Session expiry mid-use (API) | Code | Was toast only; now redirect to login (ISS-014) |
| EC-009 | Unverified email visits dashboard | Test (EmailVerificationTest) | Redirected to verification notice |
| EC-010 | Google sign-in, unknown email, self-registration off | Code | Redirect to login with error |
| EC-011 | Google sign-in with email of a soft-deleted user | Test (ISS-032) | Was a duplicate insert → 500; now redirected to login with a clear message |
| EC-012 | Invitation token unknown / expired / already accepted | Test (InvitationTest) | Redirect to login with error |
| EC-013 | Invitation for an email that already has an account | Test (ISS-009) | Was 500 on accept; now rejected cleanly |
| EC-014 | Register while self-registration disabled | Test | POST 403; GET now redirects (ISS-011) |
| EC-015 | Register with an email outside the allowed domain | Test | 422 on email |
| EC-016 | Password reset with invalid token | Test (PasswordResetTest) | Rejected |
| EC-017 | 2FA challenge brute force | Code | 5/min per login id |
| EC-018 | Security page without password confirmation | Live | 423 JSON "Password confirmation required." |
| EC-019 | Active user opens `/pending-approval` directly | Code | Redirect to dashboard |
| EC-020 | Pending user posts to `/division-units` | Live | 302 → pending-approval (blocked) |
| EC-021 | Pending user edits profile/notifications | Live | Was 200/302 success; now 302 → pending-approval (ISS-007); JSON calls now get 403 (ISS-018) |
| EC-022 | Suspended account keeps an existing session | Code | Next request redirected; session not destroyed (ISS-017) |

### 7.2 Authorization and bypass attempts

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-023 | Staff GET `/admin` | Live | 403 |
| EC-024 | Staff GET `/admin/users`, `/admin/audit` | Live | 403 |
| EC-025 | Staff PATCH `/admin/users/{self}` with `roles=[super_admin]` | Live | 403 |
| EC-026 | Staff approves a pending registration | Live | 403 |
| EC-027 | Division admin opens roles / bookings / settings | Live | 403 each |
| EC-028 | Division admin edits a user or sends an invitation | Live | 403 (needs users.manage) |
| EC-029 | Division admin approves registration with `role=super_admin` | Live | **Was 302 success (user became super admin)** → now 422 (ISS-001) |
| EC-030 | User manager assigns `super_admin` in user editor | Test | Now 422 on `roles.0` |
| EC-031 | User manager edits a super admin account | Test + Live | Now 403 |
| EC-032 | User manager invites with `role=super_admin` | Test | Now 422 |
| EC-033 | Super admin approves as `super_admin` | Test | Allowed |
| EC-034 | Room approver approves a vehicle booking | Live | 403 |
| EC-035 | Room approver opens users list | Live | 403 |
| EC-036 | Approver cancels another user's approved booking | Live | 200 (by design; requester notified) |
| EC-037 | Staff cancels another user's booking | Live | 403 |
| EC-038 | Staff requests an edit on own pending booking | Live | 403 (approved only) |
| EC-039 | Staff edits another member's event on the division calendar | Live | 200 (by design: every member can edit division events) |
| EC-040 | Staff edits an organization event / a booking's event | Live | 403 / 403 |
| EC-041 | Staff creates an event on org / resource / other-division calendar | Live | 422 "You cannot add events to this calendar." |
| EC-042 | Staff requests window with another user's personal calendar id | Live | 200, `data: []` (no existence leak) |
| EC-043 | Staff reads/posts comments on an inaccessible event | Live | 403 |
| EC-044 | Any viewer reads/posts comments on a private "Busy" event | Test + Live | Was 200 → now 403 (ISS-008) |
| EC-045 | Staff sets preference on another user's personal calendar | Live | 403 |
| EC-046 | Staff creates an event type via `/api/v1/event-types` | Live | 403 |
| EC-047 | Staff shares someone else's personal calendar | Live | 403 |
| EC-048 | Staff renames or deletes an organization calendar | Live | 403 / 403 |
| EC-049 | RSVP on an event without an invitation | Live | 403 "You are not invited to this event." |
| EC-050 | Delete another user's comment | Test | 403 |
| EC-051 | Admin suspends or deletes self / a super admin | Test | 403 |
| EC-052 | Manager-level share deletes someone else's personal calendar | Code | Allowed (by design of "manager"; noted) |
| EC-053 | Unknown resource-type slug / inactive type | Live + Test | 404 |
| EC-054 | Unknown resource in availability | Live | 404 |
| EC-055 | Unknown announcement / notification id | Live | 404 |
| EC-056 | Dismiss a non-dismissible announcement | Code | 403 |

### 7.3 Booking rules and state transitions

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-057 | Booking that starts in the past | Live | 422 |
| EC-058 | End before start | Live | 422 |
| EC-059 | Overlaps a pending booking | Live | 422 "already booked" (pending blocks too) |
| EC-060 | Exceeds max duration (480 min) | Live | 422 |
| EC-061 | Beyond max advance days (60) | Live | 422 |
| EC-062 | Below minimum lead time | Test | 422 |
| EC-063 | Attendees above capacity | Test | 422 |
| EC-064 | Attendees 9999 on a room with no capacity set | Live + Test (ISS-039) | Was 201; now 422 above 500 |
| EC-065 | Nonexistent resource id | Live | 422 |
| EC-066 | Inactive resource or type | Code | 422 "not currently bookable" |
| EC-067 | Holiday at 07:00 Manila (23:00 UTC previous day) | Test (ISS-016) | Was 201 → now 422 |
| EC-068 | Working hours closing before opening | Test (ISS-004) | Was saved → now 422 |
| EC-069 | Working-hours type, booking spanning midnight | Code | 422 "same day" |
| EC-070 | Double-click submit | Code + Live | Client guard; second server request → 422 overlap |
| EC-071 | Two concurrent identical requests | Code | The resource row is locked for the whole request, rules are re-checked inside the transaction; not load-tested (see §17) |
| EC-072 | Approve twice | Live | 422 "approved cannot become approved" |
| EC-073 | Approve a rejected booking | Live | 422 |
| EC-074 | Reject without reason / with 5 chars | Live | 422 / 422 (min 10) |
| EC-075 | Approve when another approved booking now overlaps | Code | 422 "already occupies that slot" |
| EC-076 | Cancel an already cancelled booking | Code | 422 transition |
| EC-077 | Concurrent approve + cancel | Test (ISS-019) | Was last-write-wins; now re-read under lock with a version check, the loser gets 422 |
| EC-078 | Second pending change request | Test + Live | 422 |
| EC-079 | Change request above capacity | Test (ISS-003) | Was 201 → now 422 |
| EC-080 | Apply a change whose proposed start already passed | Test (ISS-030) | 422 that tells the approver to reject and ask for a new request |
| EC-081 | Apply a change whose requester was deleted | Test (ISS-012) | Was 500 → now applied |
| EC-082 | My bookings / admin desk / approvals with an archived resource | Test (ISS-002) | Was 500 → now 200 |
| EC-083 | Delete a resource type that has archived resources | Test (ISS-010) | Was 500 → now 403 with message |
| EC-084 | Availability with bad date | Live | 422 |
| EC-085 | Rejected/cancelled bookings on the room calendar | Live | Still rendered (grey/red); do not block (ISS-020) |
| EC-086 | No user holds the approver role | Code | Request succeeds, nobody notified except super admins |
| EC-087 | Max active bookings per user cap | Code | Enforced; now counted under lock inside the booking transaction (ISS-019) |

### 7.4 Events, recurrence, calendars

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-088 | Title of 300 characters | Live | 422 (max 255) |
| EC-089 | `<script>` in title | Live + Code | Stored, rendered escaped (no `v-html` except server-generated 2FA QR) |
| EC-090 | Unicode / emoji title | Code | utf8mb4 columns; accepted |
| EC-091 | `javascript:` meeting URL | Live | 422 (must be a valid URL) |
| EC-092 | `FREQ=SECONDLY` rule | Live + Test (ISS-034) | Was accepted (500 occurrences, 320 KB for 3 days); now 422 for SECONDLY/MINUTELY/HOURLY |
| EC-093 | Invalid RRULE | Test | 422 |
| EC-094 | Window > 190 days / invalid dates / unknown type / unknown resource type | Live | 422 / 422 / 422 / `[]` |
| EC-095 | Move one occurrence with only `starts_at` | Test (ISS-005) | Was override ending before start → now 422 |
| EC-096 | Series-wide shift after editing one occurrence | Test (ISS-013) | Was edit lost → now kept |
| EC-097 | "This and following" at the first occurrence | Code + Test | Rewrites/deletes the whole series |
| EC-098 | All-day end == start; timed end == start | Test | Allowed / rejected |
| EC-099 | Toggle all-day on without sending times | Code | Times stay as stored (minor) |
| EC-100 | Private event viewed by others | Code + Test | "Busy", no details |
| EC-101 | Editor share edits another user's private event | Code | Allowed blind (response masked) |
| EC-102 | Delete a booking's event directly | Live | 403 (resource calendars are never writable) |
| EC-103 | Attendees > 100 / reminders > 5 | Code | 422 |
| EC-104 | Reminder dispatch restart-safety | Code | `reminder_dispatches` unique key + `insertOrIgnore` |
| EC-105 | Attendee search: 1 char / self / inactive users | Code + Live | 422 / excluded / excluded; emails returned (ISS-021) |
| EC-106 | Personal calendar deleted by non-owner | Test (PersonalCalendarTest) | 403 |
| EC-107 | Unit inside a unit / root division as unit | Test (DivisionUnitTest) | 403 |
| EC-108 | Delete division with members / with units | Test + Code | 403 / 403 |
| EC-109 | Delete an empty division | Test (ISS-006) | Calendar was left live → now retired |
| EC-110 | Deactivate a division | Test (ISS-006) | Members' cached access was stale ≤5 min → now immediate |
| EC-111 | Rename a unit | Code | Its calendar name/color follow |
| EC-112 | Dismiss the same announcement twice | Code | Idempotent (`firstOrCreate`) |
| EC-113 | Announcement targeted at a parent division | Test (ISS-029) | Was hidden from unit members; now shown |
| EC-114 | Mark another user's notification as read | Code | Scoped query → 404 |
| EC-115 | Notification polling (60 s) vs 120/min throttle | Code | Well within limit |
| EC-116 | Exceed the throttle | Code | 429 (not exercised) |
| EC-117 | Audit search containing `%` | Code | Plain LIKE wildcard; harmless |
| EC-118 | Users list with unknown status filter | Code | Empty list |
| EC-119 | Paginate then use Back/Forward | Code | Filters preserved via `withQueryString` |

### 7.5 Failure states and infrastructure

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-120 | Network failure on booking submit | Code | "Could not reach the server. Check your connection and try again." |
| EC-121 | 500 on event save | Code | Toast "Something went wrong while saving." |
| EC-122 | Mail server down | Live (screenshot) | Notifications land in `failed_jobs`; admin desk shows "7 failed background jobs" with a retry action |
| EC-123 | Queue worker not running | Code | Notifications and reminders silently wait (ISS-023) |
| EC-124 | DB unique constraints (booking↔event, resource↔calendar) | Code | Enforced |
| EC-125 | `abort(403)` without a message | Live | JSON `message: ""`; toast was blank → now falls back (ISS-015) |

### 7.6 Responsive and accessibility

| ID | Scenario | Method | Result |
| --- | --- | --- | --- |
| EC-126 | Dashboard at 390 / 768 / 1366 px | Browser | No horizontal page overflow; month grid readable |
| EC-127 | Booking dialog at 390 px | Browser | Bottom sheet, wheel picker, no overflow |
| EC-128 | Admin desk, users, roles, audit, settings at 390 px | Browser | No page overflow; wide tables scroll inside their wrapper |
| EC-129 | Working hours at 390 px | Browser | Was overflowing; rows now wrap (ISS-026) |
| EC-130 | Unknown page | Browser + Test | Was the framework default; now the branded error page with status 404 (ISS-024) |
| EC-131 | Sidebar layout pages at 390 px | Browser | Was warning on every sidebar page; clean after the fix (ISS-025) |
| EC-132 | Images without alt, buttons without names, inputs without labels | Browser probe | 0 / 0 / 0 on app pages (2 flagged toggles are labelled by a wrapping `<label>`) |
| EC-133 | Skip-to-content link | Browser probe | Was absent; present on every app page after the fix (ISS-037) |
| EC-134 | `prefers-reduced-motion` | Code | Honoured for scroll-to-bookings and CSS transitions |

---

## 8. Security & Bypass Audit

### 8.1 Confirmed strong points

- All application routes require a session; admin routes additionally require `admin.access` and a per-page permission. Verified live with four roles.
- Every mutating endpoint re-authorizes server-side (policies or `abort_unless`); no action relies on the UI hiding a button.
- JSON endpoints sit inside the `web` group, so CSRF is enforced (419 without token) and session cookies are `HttpOnly`, `SameSite=Lax`.
- Calendar access is computed server-side from type, division, ownership and shares; requested ids are intersected with the accessible set, so probing ids reveals nothing.
- Private events are masked in every serializer; booking events live on resource calendars that are never writable outside the booking workflow.
- Passwords: Fortify defaults in local; in production 12+ chars, mixed case, numbers, symbols, not compromised.
- Rate limits: login 5/min, 2FA 5/min, passkeys 10/min, invitations 6/min, calendar data 120/min, booking actions 20/min, comments 30/min.
- No `v-html` on user content; Vue escapes titles/descriptions/comments. Verified by storing a `<script>` title.
- Mass assignment is constrained by `#[Fillable]` lists and validated arrays.

### 8.2 Findings

| Issue | Severity | Summary |
| --- | --- | --- |
| ISS-001 | Critical | `users.approve` / `users.manage` holders could grant `super_admin` and edit super admin accounts — fixed |
| ISS-007 | Medium | Suspended/pending accounts could use settings pages — fixed |
| ISS-008 | Medium | Private event comment threads readable/writable by any calendar viewer — fixed |
| ISS-017 | Medium | `roles.manage` can grant itself every permission (role changes are now audit-logged, ISS-035) |
| ISS-022 | High (ops) | Production seeder creates `admin@fdcp.gov.ph` with password `password` |
| ISS-018 | Low | API calls from a suspended session got an HTML redirect — fixed (403 JSON); sessions are still not terminated on suspension (see ISS-017 recommendations) |
| ISS-021 | Low | Attendee search exposes every active user's email to any signed-in user |
| ISS-023 | Low | Cookie `secure` flag, HSTS/CSP headers, `APP_DEBUG` are deployment items; debug stack traces were visible in JSON errors on the dev server |
| ISS-032 | Low | Profile email change bypassed the allowed-domain rule; Google sign-in with an archived user's email crashed — fixed |

---

## 9. Business Logic Audit

| Area | Verdict | Notes |
| --- | --- | --- |
| Booking state machine | Correct | `pending → approved/rejected/cancelled`, `approved → cancelled`, terminal states final; enforced in `BookingStatus::canTransitionTo` and verified live. Reject/cancel now re-read under lock (ISS-019, fixed). |
| Conflict detection | Correct | Pending and approved bookings block; overlap check locks rows inside the creation transaction and again on approval and change application. |
| Booking rules | Correct after fixes | Lead time, max duration, advance window, active cap, working hours, holidays, capacity. Holiday check used the UTC day (ISS-016, fixed); capacity not applied to change requests (ISS-003, fixed); closes-before-opens accepted (ISS-004, fixed). |
| Approver scoping | Correct | Approval requires the permission and the type's approver role; verified cross-type 403. |
| Change requests | Correct after fixes | One pending per booking; applied changes move booking and event together and re-validate; capacity enforced (ISS-003); a stale proposal gets an explicit message (ISS-030). Purpose cannot be cleared through a change (by design of the null-means-unchanged payload). |
| Recurrence | Correct after fixes | This/following/all scopes, overrides, COUNT/UNTIL handling, 500-occurrence cap. Start-only moves (ISS-005), orphaned overrides on series shift (ISS-013) and sub-daily frequencies (ISS-034) fixed. |
| Calendar access | Correct | Division calendars visible to the division, its parent and its units; write limited to own division; personal calendars isolated unless shared; resource calendars read-only. Cache invalidation on division deactivate/re-parent was missing (ISS-006, fixed). |
| Registration & invitations | Correct after fixes | Domain restriction, approval gate, one live invitation per email, 7-day expiry, pruning after 30 days. Existing-account collision (ISS-009, fixed). |
| Notifications | Correct | Per-key mail/database preferences honoured; reminders dispatched exactly once per occurrence. Depends on a running queue worker. |
| Announcements | Correct after fix | Division targeting now includes unit members of that division (ISS-029). |
| Resource lifecycle | Gap | Archiving a resource or deleting a user leaves future bookings pending/approved (ISS-031); rejected/cancelled bookings stay on the calendar (ISS-020). |

---

## 10. UX / UI Audit

- **Navigation** is permission-aware and mirrors the server; admin settings share one persistent sidebar; breadcrumbs on admin pages. Good.
- **Forms**: every Inertia form disables its submit button while `processing`; API-backed dialogs use `saving` flags; validation errors are shown per field; server-side messages are surfaced through toasts. Confirmations exist for destructive actions (event delete, booking cancel, account delete with password).
- **Loading states**: calendar toolbar spinner while windows load; skeleton components exist; admin lists render inline. **Empty states**: agenda ("Nothing scheduled…"), my bookings, admin tables (`TableEmpty`), pending registrations.
- **Error surfaces**: field errors, toasts, framework error pages. Framework default 404/403/419 pages are unbranded with no way back (ISS-024). Blank toast on message-less 403 (ISS-015, fixed).
- **Session expiry** in a long-open tab produced repeating "Unauthenticated." toasts and a stalled notification poll; now redirects to login (ISS-014).
- **Registration** link showed even when self-registration was disabled, leading to a 403 after filling the form (ISS-011, fixed).
- **Booking dialog**: clear resource cards, availability strip with busy/pending segments, hours and holiday hints, receipt screen; at phone width becomes a bottom sheet. Cancelled/rejected bookings remain on the room calendar which can confuse readers (ISS-020).
- **Admin desk**: queue with approve/reject inline, day schedule, demand horizon, people counts, failed-jobs banner. Good.
- **Copy**: consistent, plain, action-oriented. Error messages from the booking validator are specific.

---

## 11. Data Integrity Audit

| Check | Result |
| --- | --- |
| Foreign keys | Present on every relation; bookings restrict resource deletion (soft delete used instead), events cascade from calendars, shares/preferences cascade. |
| Unique constraints | users.email, invitations.token, resources.calendar_id, bookings.event_id, (recurring_parent_id, original_starts_at), (calendar_id, user_id) shares/preferences, resource_types.name/slug, working_hours (resource, day). |
| Soft deletes | users, calendars, events, resources, bookings, divisions. Relations that must survive archiving now use `withTrashed()` (booking→resource, booking→requester, invitation→inviter) (ISS-002/012/009). |
| Orphans | Deleting a division left its calendar live (ISS-006, fixed). Archiving a resource leaves open bookings (ISS-031). Deleting a user leaves their bookings and shares (ISS-031). |
| Timezones | Events stored in UTC with the creator's timezone; recurrence expanded in the event's timezone; office rules in Asia/Manila. Holiday check was in UTC (ISS-016, fixed) and now covers every office day a booking touches (ISS-028, fixed). |
| Transactions | Booking create/approve/reject/cancel, change apply/submit, invitation issue/accept, resource create/update/delete, series split all transactional; the per-user cap is counted under lock (ISS-019, fixed). |
| Cache coherence | Accessible-calendar ids cached 5 min per user with a global version; busted on user/division/calendar changes, now also on division deactivate/re-parent/delete. |
| Activity log | Bookings, registrations, invitations, calendars/divisions/resources attribute changes, and now role create/update/delete, user role/division edits and calendar shares (ISS-035, fixed). Event edits are still unlogged. |
| Slugs | Resource-type slug derived once from the name; a colliding slug is now a validation error (ISS-033, fixed). |

---

## 12. Error & Failure-State Audit

| Condition | Backend | Frontend | Verdict |
| --- | --- | --- | --- |
| Validation error | 422 JSON with `errors` | Field messages; booking dialog refreshes availability on slot errors | Good |
| Unauthorized | 403 JSON (sometimes empty message) | Toast; empty message now falls back to "Request failed (403)" | Fixed (ISS-015) |
| Not found | 404 JSON / branded Inertia page | Toast / "Page not found" with a way back | Fixed (ISS-024) |
| Unauthenticated | 401 JSON | Now redirects to login | Fixed (ISS-014) |
| CSRF mismatch | 419 JSON; full-page 419 goes back with a toast | JSON callers redirect to login | Fixed (ISS-014, ISS-024) |
| Throttled | 429 JSON | Toast with message | Acceptable |
| Server error | 500 JSON (debug traces locally) | Generic toast | Ensure `APP_DEBUG=false` in production (ISS-023) |
| Suspended/pending user calling API | 403 JSON | Redirected to login by the client | Fixed (ISS-018) |
| Mail failure | Notification job fails; visible in System Health | Admin banner + retry | Good |
| Background exceptions | `failed_jobs` table | Retry button (`queue:retry all`) | Good |

---

## 13. Responsive / Accessibility Audit

Headless Chrome, 390 × 900 (phone), 768 × 900 (tablet) and 1366 × 900 (desktop), authenticated as staff and as super admin. Checks: page horizontal overflow, elements wider than the viewport, images without `alt`, buttons without an accessible name, inputs without a label, sub-24 px targets, landmarks, console errors, failed requests.

| Page | Widths | Overflow | Console | Notes |
| --- | --- | --- | --- | --- |
| Login | 390, 1366 | none (decorative pills clipped) | clean | Email/password labelled; "Sign up" now hidden when registration is off |
| Dashboard | 390, 768, 1366 | none | clean | `<main>` landmark; month grid usable on phone |
| Public calendar, Division calendar | 390 | none | clean | |
| Boardroom section | 390, 1366 | table scrolls inside wrapper | clean | Week view on phone shows 7 narrow columns |
| Booking dialog | 390, 1366 | none | clean | Bottom sheet on phone |
| Settings: profile, notifications | 390 | none | clean after fix | switch toggles are 32×18 px but sit inside full-width clickable labels |
| Admin desk | 390, 1366 | none | clean after fix | |
| Admin users | 390, 1366 | table scrolls inside wrapper | clean after fix | |
| Admin bookings, health | 1366 | none | clean | |
| Admin roles, audit, general settings | 390 | none / table wrapper | clean after fix | |
| Admin working hours | 390 | none after fix (rows wrap) | clean after fix | ISS-026 fixed |
| Unknown URL | 1366, 390 | none | expected 404 | Branded "Page not found" page (ISS-024 fixed) |

Accessibility notes:

- Semantic landmarks (`main`, `nav`, `header`) present on app pages; page `lang="en"`; document titles set per page.
- All icon-only buttons carry `aria-label`; images have `alt`; form inputs have labels (the two switches on General settings are labelled by wrapping `<label>` elements).
- A skip-to-content link now precedes the sidebar and targets the `main` landmark (ISS-037).
- The sub-24 px targets the probe counted are inline text links (booking times on the desk), switch toggles wrapped in full-width clickable labels, and 24 px-wide demand-horizon columns; these fall under the WCAG 2.5.8 inline and spacing exceptions, so no resizing was done.
- Dialogs and sheets come from reka-ui (focus trap, Escape, `aria-modal`); the booking dialog moves initial focus to the first real choice.
- Motion honours `prefers-reduced-motion`.
- Colour is not the only status carrier: booking status is also given as a label.

---

## 14. Issues Found

### ISS-001 — Non-super-admins could grant `super_admin` and edit super admin accounts

| Field | Details |
| --- | --- |
| Severity | Critical |
| Category | Security |
| Location | `POST /admin/pending-registrations/{user}/approve`, `PATCH /admin/users/{user}`, `POST /admin/invitations`; `ApproveUserRequest`, `UpdateUserRequest`, `StoreInvitationRequest` |
| Problem | Role inputs were validated only with `exists:roles,name`, so `super_admin` was assignable by anyone allowed to approve, edit or invite. `UpdateUserRequest` also let any `users.manage` holder change a super admin's name, division and roles. |
| Reproduction | Log in as `division.admin@fdcp.gov.ph`; `POST /admin/pending-registrations/8/approve` with `{"division_id":1,"role":"super_admin"}`. |
| Expected | 422 unless the actor is a super admin. |
| Actual | 302 success; user 8 became an active super admin (reproduced live, then reverted). |
| Impact | Full takeover of the system by any division admin or user manager; a user manager could also strip the last super admin. |
| Recommendation | Validate role assignment against the actor (`AssignableRole` rule), forbid editing super admin accounts by non-super-admins, hide the option in the UI. |
| Status | **Fixed** — `app/Rules/AssignableRole.php`, three form requests, three Vue pages. Verified live (F01 → 422, F04 → 403) and by 5 tests. |

### ISS-002 — Archived resource crashes "My bookings", the admin desk and the approvals page

| Field | Details |
| --- | --- |
| Severity | High |
| Category | Backend |
| Location | `Booking::resource()`; `BookingPresenter`, `AdminDashboard::queue()`, `BookingApprovalController::index()` |
| Problem | `Resource` uses soft deletes; the `belongsTo` excluded trashed rows, so presenters dereferenced `null`. |
| Reproduction | Admin archives a room that has a pending booking; the requester opens `/api/v1/bookings/mine`; a super admin opens `/admin`. |
| Expected | Rows render with the archived room's name. |
| Actual | HTTP 500 ("Attempt to read property on null"). |
| Impact | Any user with a booking on an archived room lost their bookings page; super admins lost the desk. |
| Recommendation | `withTrashed()` on the relation (done); consider cancelling open bookings on archive (ISS-031). |
| Status | **Fixed** — test `ISS-002`. |

### ISS-003 — Change requests ignored room capacity

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Logic |
| Location | `EditRequestService::submit()`; `POST /api/v1/bookings/{booking}/edit-requests` |
| Problem | Capacity was checked when booking, not when proposing a new attendee count. |
| Reproduction | Approved booking on a 10-seat room; submit `{"attendee_count": 11}`. |
| Expected | 422 on `attendee_count`. |
| Actual | 201; the change could be applied. |
| Impact | Approvers could unknowingly approve over-capacity meetings. |
| Recommendation | Validate against `resource.capacity` at submission (done). |
| Status | **Fixed** — test `ISS-003`. Live probe `S16` (room without capacity) documented separately as ISS-039. |

### ISS-004 — Working hours accepted a closing time before the opening time

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Backend |
| Location | `WorkingHourController::update()` |
| Problem | `closes_at` only needed the `H:i` format. |
| Reproduction | Save Monday 17:00–09:00 for a type that enforces working hours. |
| Expected | 422. |
| Actual | Saved; every booking that day failed with "Bookings are limited to 17:00 to 09:00". |
| Impact | A typo silently closes a resource. |
| Recommendation | `after:days.*.opens_at` (done). |
| Status | **Fixed** — test `ISS-004`. |

### ISS-005 — Moving one occurrence with only a start time produced a negative-length event

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Logic / Data |
| Location | `UpdateOccurrenceRequest`, `RecurrenceService::editThisOccurrence()` |
| Problem | `starts_at` and `ends_at` were independently optional; the override kept the old end. |
| Reproduction | `PATCH /api/v1/events/{series}/occurrence` with `scope=this`, `original_starts_at`, `starts_at` four hours later, no `ends_at`. |
| Expected | 422. |
| Actual | 200; override row ended before it started. |
| Impact | Corrupt occurrences on the grid (the UI always sent both, so only direct API use triggered it). |
| Recommendation | Require both fields together (done). |
| Status | **Fixed** — test `ISS-005`. |

### ISS-006 — Deleting or deactivating a division left calendar access wrong

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Data |
| Location | `DivisionObserver`, `DivisionController::destroy()` |
| Problem | No `deleted` hook: the division's calendar stayed active and orphaned. `updated` did not bust the per-user access cache, so deactivation or re-parenting was invisible for up to five minutes. |
| Reproduction | Delete an empty division; list managed calendars. Deactivate a division while a member has a primed session. |
| Expected | Calendar retired; members lose access immediately. |
| Actual | Calendar listed with no division; members kept the calendar until cache expiry. |
| Impact | Orphan data, stale visibility. |
| Recommendation | Observer `deleted` retires calendars; `updated` busts the cache on `is_active`/`parent_id` (done). |
| Status | **Fixed** — tests `ISS-006` (2). |

### ISS-007 — Suspended and pending accounts could use every settings page

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Security |
| Location | `routes/settings.php` |
| Problem | Settings groups used `auth` (+`verified`) but not `approved`. |
| Reproduction | Log in as `carla.mendoza@fdcp.gov.ph` (pending); `GET /settings/profile`, `PATCH /settings/profile`. |
| Expected | Redirect to the pending-approval page. |
| Actual | 200 / profile updated (reproduced live). A suspended user could likewise change email, password, passkeys, or delete the account. |
| Impact | Suspension did not lock the account's self-service surface. |
| Recommendation | Add `approved` to the settings groups (done). |
| Status | **Fixed** — test `ISS-007`; live F08/F09 → 302. |

### ISS-008 — Private "Busy" events exposed their comment thread

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Security / Privacy |
| Location | `EventCommentController::assertCanView()` |
| Problem | Comment access was "can view the calendar", ignoring `visibility=private`. |
| Reproduction | Creator marks an org event private and comments; another user `GET /api/v1/events/{id}/comments`. |
| Expected | 403. |
| Actual | 200 with the thread; posting also allowed. |
| Impact | The mask ("Busy") leaked through the discussion. |
| Recommendation | Forbid non-creators on private events (done). |
| Status | **Fixed** — test `ISS-008`; live F05 → 403. |

### ISS-009 — Invitation accepted for an email that already has an account → 500

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Backend |
| Location | `InvitationAcceptanceController`, `StoreInvitationRequest`, `Invitation::inviter()` |
| Problem | Invitation uniqueness ignored soft-deleted users, and acceptance never re-checked, so `users.email` unique index threw. The inviter relation also excluded archived admins (page would 500). |
| Reproduction | Invite `x@fdcp.gov.ph`; before acceptance the same address self-registers (or an archived account exists); open the invitation link and submit. |
| Expected | Friendly "invitation no longer valid". |
| Actual | Show page 200 then 500 on submit. |
| Impact | Broken onboarding, confusing error. |
| Recommendation | Treat the invitation as unusable when any account (active or archived) owns the email; keep archived inviters resolvable (done). |
| Status | **Fixed** — test `ISS-009`. |

### ISS-010 — Deleting a resource type with archived resources → 500

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Backend |
| Location | `ResourceTypeController::destroy()` |
| Problem | The guard used `resources()->exists()`, which ignores soft-deleted resources; the FK is `restrictOnDelete`. |
| Reproduction | Archive the only room of a type; delete the type. |
| Expected | 403 with guidance. |
| Actual | QueryException 500. |
| Impact | Admin dead end. |
| Recommendation | Check `withTrashed()` (done). |
| Status | **Fixed** — test `ISS-010`. |

### ISS-011 — Register page and "Sign up" link shown while self-registration is disabled

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | UX |
| Location | `FortifyServiceProvider` (register/login views), `Login.vue` |
| Problem | Only the POST was blocked (403 after filling the form). |
| Reproduction | Disable self-registration; open `/register` or click "Sign up". |
| Expected | No link; direct visit redirects with an explanation. |
| Actual | Form rendered; submit → 403 page. |
| Impact | Confusing for invited-only deployments. |
| Recommendation | Redirect the GET and pass `canRegister` to the login page (done). |
| Status | **Fixed** — test `ISS-011`. |

### ISS-012 — Applying a change request whose requester was deleted → 500

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Backend |
| Location | `EditRequestService::apply()`, `Booking::requester()` |
| Problem | `BookingRuleValidator::validate()` received `null` for a soft-deleted requester. |
| Reproduction | Approved booking with a pending change; delete the requester; admin applies the change. |
| Expected | Change applied (or a clear message). |
| Actual | TypeError 500. |
| Impact | Admin dead end on a real lifecycle path. |
| Recommendation | Resolve archived requesters (`withTrashed`, done); see ISS-031 for cancelling open bookings on delete. |
| Status | **Fixed** — test `ISS-012`. |

### ISS-013 — "All events" time shift silently lost per-occurrence edits

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Logic / Data |
| Location | `EventOccurrenceController::editAll()`, `OccurrenceExpander` |
| Problem | Overrides are keyed by `original_starts_at`; shifting the series moved the generated slots but not the keys, so overrides never matched again and were dropped from every window. |
| Reproduction | Daily series; rename one occurrence (scope this); move any occurrence with scope all. |
| Expected | The renamed occurrence keeps its title at the new time. |
| Actual | Rename vanished (row remained orphaned in the table). |
| Impact | Silent data loss for users of recurring events. |
| Recommendation | Shift override keys and times with the series (done, ordered to avoid the unique index). |
| Status | **Fixed** — test `ISS-013`. |

### ISS-014 — Expired session only produced repeating error toasts

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Frontend / UX |
| Location | `resources/js/lib/api.ts` |
| Problem | 401/419 from JSON endpoints were thrown as `ApiError`; each action toasted "Unauthenticated." / "CSRF token mismatch." and the 60-second notification poll kept failing. |
| Reproduction | Leave the dashboard open past `SESSION_LIFETIME` (120 min) or log out in another tab; click any action. |
| Expected | Return to login. |
| Actual | Toast loop, stale page. |
| Impact | Confusing recovery. |
| Recommendation | Redirect to `/login` on 401/419 (done). |
| Status | **Fixed** — verified by type check and lint; browser-level verification pending (session expiry not forced during the audit). |

### ISS-015 — Blank toast on message-less 403

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Frontend |
| Location | `api.ts`; `abort_unless(..., 403)` call sites |
| Problem | `??` kept an empty `message` string. |
| Reproduction | Comment on an inaccessible event before the fix. |
| Expected | A readable message. |
| Actual | Empty toast. |
| Impact | Silent failure from the user's perspective. |
| Recommendation | `||` fallback (done) and messages on the comment guards (done). |
| Status | **Fixed** (frontend; no automated test). |

### ISS-016 — Holiday blocking used the UTC day

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Logic |
| Location | `BookingRuleValidator::validate()` → `Holiday::blockingOn($start)` |
| Problem | `$start` is UTC; Manila is UTC+8, so 00:00–08:00 on a holiday resolved to the previous date. The availability endpoint (Manila day) showed the holiday, but the validator let the booking through. |
| Reproduction | Type with `block_holidays`; holiday on day D; book D 07:00 Asia/Manila. |
| Expected | 422 "The selected day is a holiday". |
| Actual | 201 (test fails on the old code). |
| Impact | Holiday policy bypass for morning bookings. |
| Recommendation | Evaluate the holiday in Asia/Manila (done). |
| Status | **Fixed** — test `ISS-016`. |

### ISS-017 — `roles.manage` is effectively unlimited and unlogged

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Security |
| Location | `RoleController::update()`, `RolePolicy` |
| Problem | A `roles.manage` holder can add any permission (users.manage, settings.manage…) to a role they hold; nothing is written to the activity log. |
| Reproduction | As a role manager, add all permissions to your own role. |
| Expected | Either restricted to super admins, or at least logged and confirmed. |
| Actual | Allowed silently. |
| Impact | Quiet self-escalation to everything except `super_admin`. |
| Recommendation | Treat `roles.manage` as super-admin-only in the seeded roles; log role/permission changes with `activity()`; forbid editing roles the actor holds. |
| Status | Found (recommendation). |

### ISS-018 — API calls from a pending/suspended session receive an HTML redirect

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Backend |
| Location | `EnsureUserIsApproved` |
| Problem | The middleware always redirects, even for `expectsJson()` requests. |
| Reproduction | Suspend a user who has the dashboard open; the next `/api/v1/*` call. |
| Expected | 403 JSON. |
| Actual | 302 → HTML; `fetch` follows, `payload` is `null`, components read `undefined`. |
| Impact | Confusing console errors until the next navigation. |
| Recommendation | Return `abort(403)` when the request expects JSON; combine with ISS-019's session termination. |
| Status | **Fixed** — `EnsureUserIsApproved` aborts with 403 JSON when the request expects JSON; test `ISS-018`. |

### ISS-019 — Reject/cancel run outside a lock; concurrent decisions can cross

| Field | Details |
| --- | --- |
| Severity | Medium |
| Category | Logic |
| Location | `BookingService::reject()`, `cancel()`, `BookingRuleValidator` active-cap check |
| Problem | `approve()` re-reads with `lockForUpdate` inside a transaction; `reject()` and `cancel()` check the transition on the stale model, so two overlapping requests (approve + cancel, or two cancels) can both pass and the last write wins. The per-user active-booking cap is counted before the transaction. |
| Reproduction | Fire approve and cancel for the same pending booking within the same millisecond window (not reproduced during the audit). |
| Expected | Exactly one transition succeeds. |
| Actual | Code path allows both. |
| Impact | Rare inconsistent states (cancelled booking showing approved, cap exceeded by one). |
| Recommendation | Wrap reject/cancel like approve; move the cap count inside the transaction. |
| Status | **Fixed** — reject and cancel now re-read the booking under `lockForUpdate` with a version check (this part landed through the concurrent booking work in the same working tree); the audit moved the booking rules inside the creation transaction and counts the per-user cap under lock; test `ISS-019` (stale instance cannot overwrite a newer decision). |

### ISS-020 — Rejected and cancelled bookings stay on room calendars

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | UX / Logic |
| Location | `EventWindowService`, `BookingService::reject()/cancel()` |
| Problem | The booking's event remains on the resource calendar in grey/red forever. |
| Reproduction | Reject a booking; open the Boardroom section. |
| Expected | Slot reads as free (it does not block), ideally without clutter. |
| Actual | Rejected/cancelled events still render. |
| Impact | Visual noise; readers may think the slot is taken. |
| Recommendation | Hide rejected/cancelled by default (toggle), or soft-delete the event on rejection/cancellation. |
| Status | Found. |

### ISS-021 — Attendee search exposes every active user's email

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Security / Privacy |
| Location | `UserSearchController` |
| Problem | Any signed-in user can enumerate names and emails with a two-character query. |
| Reproduction | `GET /api/v1/users/search?q=fd` (live: returned emails). |
| Expected | Acceptable for an internal directory; decide explicitly. |
| Actual | Names, emails, division returned (max 8 per query). |
| Impact | Directory scraping by a compromised staff account. |
| Recommendation | Keep, or return emails only when the query matches the email prefix. |
| Status | Found (recommendation). |

### ISS-022 — Production seeder creates a super admin with password `password`

| Field | Details |
| --- | --- |
| Severity | High (operational) |
| Category | Security |
| Location | `DatabaseSeeder` |
| Problem | The "production-safe" seeder hard-codes `admin@fdcp.gov.ph` / `password`. |
| Reproduction | `php artisan db:seed` on a fresh production database. |
| Expected | Password from an environment secret or a forced reset on first login. |
| Actual | Well-known credentials with full access. |
| Impact | Immediate takeover if the seed runs in production and the password is not changed. |
| Recommendation | Read `SEED_ADMIN_PASSWORD` from `.env`, or create the account without a usable password and send a reset link. |
| Status | Found. |

### ISS-023 — Deployment hardening items

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Security |
| Location | `.env`, `config/session.php`, response headers |
| Problem | `SESSION_SECURE_COOKIE` unset, no HSTS/CSP/X-Frame-Options middleware, `APP_DEBUG=true` locally exposed stack traces in JSON errors during probing; queue worker required for mail/reminders. |
| Reproduction | Inspect response headers; trigger a 403 with debug on. |
| Expected | Secure cookie over HTTPS, security headers, debug off, a supervised worker. |
| Actual | Not configured in the repo (environment-dependent). |
| Impact | Session theft over plain HTTP, clickjacking, information disclosure in production if misconfigured. |
| Recommendation | Production checklist: `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, security headers middleware, supervisor for `queue:work` and the scheduler. |
| Status | Found. |

### ISS-024 — Unbranded framework error pages

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | UX |
| Location | `resources/views/errors/*` (absent) |
| Problem | 404/403/419/500 show Laravel's minimal pages with no navigation. |
| Reproduction | Visit `/resources/nope` (screenshot captured). |
| Expected | Branded page with a link back to the calendar. |
| Actual | "404 | Not Found". |
| Impact | Dead end for users who mistype or follow a stale link. |
| Recommendation | Add Inertia error pages (the starter kit pattern). |
| Status | **Fixed** — `bootstrap/app.php` renders `resources/js/pages/Error.vue` (no app layout) for 403/404/503, and 500 when debug is off; full-page 419 goes back with a toast; JSON callers unchanged. Test `ISS-024`; verified headless at 1366 and 390 px. |

### ISS-025 — Vue hydration mismatch at phone widths

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Frontend |
| Location | `Sidebar.vue` (`useIsMobile` branch), SSR enabled in `config/inertia.php` |
| Problem | SSR renders the desktop sidebar; on narrow viewports the client renders the mobile `Sheet`, producing `[Vue warn]: Hydration node mismatch` and a re-render. |
| Reproduction | Load any sidebar page at 390 px with the console open. |
| Expected | No mismatch. |
| Actual | Warning on settings and admin pages at 390 px (dashboard was clean). |
| Impact | Layout flash on phones; noise in error monitoring. |
| Recommendation | Render the mobile variant client-only (`<ClientOnly>`/`onMounted` gate) or SSR both variants and toggle with CSS. |
| Status | **Fixed** — `Sidebar.vue` renders the mobile sheet only after mount and `SidebarTrigger.vue` picks its icon the same way, so server and client markup agree; verified headless: 0 hydration warnings on settings and admin pages at 390 px. |

### ISS-026 — Working hours page overflows at phone width

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Frontend / Responsive |
| Location | `admin/working-hours/Index.vue` |
| Problem | Two time inputs plus labels per row exceed 390 px. |
| Reproduction | Open the page at 390 px (harness flagged six overflowing elements). |
| Expected | Rows wrap. |
| Actual | Inputs overflow their row (page itself does not scroll horizontally). |
| Impact | Cramped editing on phones (admin page, low traffic). |
| Recommendation | `flex-wrap` on the row or stack inputs under `sm:`. |
| Status | **Fixed** — rows use a two-column grid below `sm` with the time inputs on their own wrapping line; verified headless at 390 px (no overflowing elements). |

### ISS-027 — Working hours payload not checked for one row per weekday

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Backend |
| Location | `WorkingHourController::update()` |
| Problem | `size:7` accepts seven rows for the same day. |
| Reproduction | Send `days` with `day_of_week: 0` seven times. |
| Expected | 422. |
| Actual | Accepted; upsert makes it harmless. |
| Impact | None in practice. |
| Recommendation | `distinct` on `days.*.day_of_week`. |
| Status | **Fixed** — `distinct` on `days.*.day_of_week`; test `ISS-027`. |

### ISS-028 — Holiday check only inspects the start day

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Logic |
| Location | `BookingRuleValidator` |
| Problem | For types without working-hours enforcement (multi-day vehicle trips), a booking that starts the day before a holiday and runs into it is allowed. |
| Reproduction | Carpool booking 18:00 D-1 → 10:00 D where D is a blocking holiday. |
| Expected | Decide: block, or allow with warning. |
| Actual | Allowed. |
| Impact | Policy gap for overnight trips. |
| Recommendation | Check every office day the booking touches. |
| Status | **Fixed** — `Holiday::blockingWithin()` checks every Asia/Manila day inside [start, end); test `ISS-028` (overnight into a holiday → 422, ending at midnight before it → 201). |

### ISS-029 — Division-targeted announcements skip unit members

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Logic |
| Location | `Announcement::scopeVisibleTo()` |
| Problem | Matches `division_id` exactly; members of a unit under that division are not included. |
| Reproduction | Target an announcement at a parent division; log in as a unit member. |
| Expected | Visible (units belong to the division). |
| Actual | Hidden. |
| Impact | Missed announcements. |
| Recommendation | Use `relatedDivisionIds()` or the parent id. |
| Status | **Fixed** — `Announcement::scopeVisibleTo()` includes the parent division of a unit member; test `ISS-029`. |

### ISS-030 — Change requests cannot clear a purpose and fail when applied late

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Logic / UX |
| Location | `EditRequestService::apply()` |
| Problem | `proposed_purpose ?? booking.purpose` cannot blank the purpose; the proposed slot is validated with the "not in the past" rule at apply time, so a late approver gets a validation toast. |
| Reproduction | Submit a change for tomorrow 09:00; apply it after 09:00. |
| Expected | Clear outcome (reject with reason, or apply as historical record). |
| Actual | 422 "Bookings cannot start in the past." |
| Impact | Admin confusion. |
| Recommendation | Auto-reject stale change requests or skip the past check when applying. |
| Status | **Fixed** — applying a change whose proposed time already passed returns 422 with "The proposed time has already passed. Reject this request and ask for a new one."; test `ISS-030`. Clearing the purpose through a change request stays unsupported (the payload uses null for "unchanged"). |

### ISS-031 — Archived resources and deleted users keep open bookings

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Data |
| Location | `ResourceService::delete()`, `UserController::destroy()` |
| Problem | Future pending/approved bookings survive archiving a resource or deleting a user; approvers may still approve them. |
| Reproduction | Archive a room with a pending booking; check the desk queue. |
| Expected | Open bookings cancelled with a reason and notifications. |
| Actual | They remain in the queue. |
| Impact | Bookings for rooms that no longer exist. |
| Recommendation | Cancel open bookings on archive/delete (transactional), and block approving bookings on inactive resources. |
| Status | Found. |

### ISS-032 — Profile email bypasses the domain rule; Google sign-in for archived emails crashes

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Security / Backend |
| Location | `ProfileController::update()`, `GoogleAuthController::callback()` |
| Problem | Email change ignores `allowed_email_domain`; the Google lookup ignores soft-deleted users and then inserts a duplicate. |
| Reproduction | Change your email to `x@gmail.com`; sign in with Google using an archived user's address. |
| Expected | Domain enforced; friendly "account deactivated" message. |
| Actual | Accepted; 500 (code path; not executed live). |
| Impact | Domain policy gap; crash on a rare path. |
| Recommendation | Apply `emailDomainAllowed` in `ProfileUpdateRequest`; `withTrashed()` lookup in the callback. |
| Status | **Fixed** — `ProfileUpdateRequest` enforces the allowed domain on a changed address; `GoogleAuthController` looks up archived accounts and refuses with a message instead of inserting a duplicate; tests `ISS-032` (2). |

### ISS-033 — Resource-type slug collisions → 500

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Backend |
| Location | `ResourceTypeController::store()` |
| Problem | `name` is unique but `slug` is derived; "Board-Room" and "Board Room" produce the same slug. |
| Reproduction | Create both names. |
| Expected | 422 on name. |
| Actual | QueryException on `resource_types.slug`. |
| Impact | Admin dead end. |
| Recommendation | Validate slug uniqueness. |
| Status | **Fixed** — the derived slug is validated for uniqueness on create and rename; test `ISS-033`. |

### ISS-034 — Sub-daily recurrence frequencies allowed

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Performance |
| Location | `ValidRrule` |
| Problem | `FREQ=SECONDLY/MINUTELY/HOURLY` are valid RFC rules; each series expands to the 500-occurrence cap per window. |
| Reproduction | Create an event with `rrule=FREQ=SECONDLY` (done live: 320 KB response, 0.15 s for three days). |
| Expected | Reject frequencies below DAILY. |
| Actual | Accepted. |
| Impact | Large payloads and cluttered grids if abused. |
| Recommendation | Restrict `FREQ` to DAILY/WEEKLY/MONTHLY/YEARLY. |
| Status | **Fixed** — `ValidRrule` rejects SECONDLY, MINUTELY and HOURLY; test `ISS-034`. |

### ISS-035 — Audit log gaps

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Security / Ops |
| Location | `RoleController`, `UserController::update()`, `CalendarShareController`, event controllers |
| Problem | Role permission changes, user role changes, calendar shares and event edits are not written to the activity log. |
| Reproduction | Change a role's permissions; open `/admin/audit`. |
| Expected | Entry with causer and diff. |
| Actual | Nothing. |
| Impact | Privilege changes are not traceable. |
| Recommendation | `activity()` calls in those controllers. |
| Status | **Fixed** — role create/update/delete, user division/role edits and calendar share/revoke now write activity entries with the causer and properties; test `ISS-035`. |

### ISS-036 — Static analysis debt

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Backend |
| Location | `AdminDashboard.php` (10 errors), `DemoSeeder.php` (1) |
| Problem | PHPStan level 7 reports return-type and always-true comparisons in files under active development. |
| Reproduction | `vendor/bin/phpstan analyse`. |
| Expected | Clean. |
| Actual | 11 pre-existing errors (touched files are clean). |
| Impact | CI noise. |
| Recommendation | Address alongside the dashboard work. |
| Status | Found (not touched by this audit). |

### ISS-037 — Accessibility refinements

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Frontend |
| Location | app layout, calendar toolbar, settings toggles |
| Problem | No skip-to-content link; several targets under 24 × 24 px. |
| Reproduction | Tab from the top of any page; measure chevron buttons. |
| Expected | WCAG 2.2 AA: skip link, 24 px targets. |
| Actual | Missing / small. |
| Impact | Keyboard and touch users. |
| Recommendation | Add a skip link in `AppShell`; enlarge hit areas with padding. |
| Status | **Fixed (skip link)** — "Skip to content" link in `AppSidebarLayout.vue` targeting `main#main`; verified headless on every app page. Target sizes reviewed and left as is: the flagged elements are inline links, label-wrapped switches and 24 px columns (WCAG 2.5.8 exceptions). |

### ISS-038 — No moderation of comments

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | UX |
| Location | `EventCommentController::destroy()` |
| Problem | Only the author can delete; organizers and admins cannot. |
| Reproduction | Attempt to delete another user's comment as super admin → 403. |
| Expected | Organizer/admin moderation. |
| Actual | 403. |
| Impact | Inappropriate content cannot be removed. |
| Recommendation | Allow event creator and `super_admin`. |
| Status | Found. |

### ISS-039 — Attendee count unbounded when a room has no capacity

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Logic |
| Location | `BookingService::request()` |
| Problem | Seeded rooms have no capacity; `attendee_count` up to 10000 is accepted. |
| Reproduction | Book 3rd Floor Board Room with `attendee_count: 9999` (live: 201). |
| Expected | Capacity set on every room, or a sensible upper bound. |
| Actual | Accepted. |
| Impact | Meaningless numbers on the desk. |
| Recommendation | Require capacity for room types; lower the validation max. |
| Status | **Fixed** — `attendee_count` capped at 500 on bookings and change requests (rooms with a capacity keep the stricter check); test `ISS-039`. |

### ISS-040 — Occurrence edits accept inactive event types

| Field | Details |
| --- | --- |
| Severity | Low |
| Category | Backend |
| Location | `UpdateOccurrenceRequest` |
| Problem | `event_type_id` checks existence only; store/update require `is_active`. |
| Reproduction | Set an archived type on one occurrence. |
| Expected | 422. |
| Actual | Accepted. |
| Impact | Inconsistency. |
| Recommendation | Add `->where('is_active', true)`. |
| Status | **Fixed** — occurrence edits require an active event type; test `ISS-040`. |

---

## 15. Recommended Fixes

Priority order for the items still open after both passes:

1. **ISS-022** Seeded super admin password: read it from an environment secret or force a reset on first login. (Before any production seed.)
2. **ISS-017** Decide how far `roles.manage` may reach: make it super-admin-only in the seeded roles, or forbid editing roles the actor holds. Role changes are now logged (ISS-035), so the trail exists.
3. **ISS-031** Cancel open bookings when a resource is archived or a user is deleted, and block approving bookings on inactive resources; consider terminating a user's sessions on suspension.
4. **ISS-023** Production checklist: `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, security headers, supervised queue worker and scheduler.
5. **ISS-020** Hide rejected and cancelled bookings on room calendars by default (their events are now marked cancelled by the booking work, so a filter is cheap).
6. **ISS-021** Decide whether the attendee picker should return emails or names only.
7. **ISS-038** Allow event organizers and super admins to remove comments.
8. **ISS-036** Clear the pre-existing PHPStan findings in `AdminDashboard.php` and `DemoSeeder.php` alongside the dashboard work.

---

## 16. Regression Testing

### 16.1 Automated

| Run | Result |
| --- | --- |
| Baseline before changes | `php artisan test`: 214 passed |
| New `tests/Feature/AuditRegressionTest.php` before fixes | 16 of 17 failed (as expected; the super-admin-allowed control passed) |
| After first-pass fixes | 19 tests in the file pass; ISS-016 verified to fail on the old code and pass on the fix |
| Second pass (14 more tests: ISS-018, 019, 024, 027, 028, 029, 030, 032 ×2, 033, 034, 035, 039, 040) | 33 tests in the file pass |
| Full suite after all changes | **263 passed, 1009 assertions** (214 baseline + 33 audit tests + tests added by concurrent booking and admin-desk work in the same working tree) |
| `vendor/bin/pint --test --dirty` | passed |
| `vendor/bin/phpstan analyse` on touched files | 0 errors after both passes (11 pre-existing errors elsewhere, ISS-036) |
| `npm run types:check` (`vue-tsc`) | clean |
| ESLint on changed files | clean (one unrelated parsing error from `.remember/tmp/last-ndc.ts`, a plugin temp file outside `resources/`) |
| Prettier on changed files | clean |

Test → issue mapping: `ISS-001` (5 tests), `ISS-002`, `ISS-003`, `ISS-004`, `ISS-005`, `ISS-006` (2), `ISS-007`, `ISS-008`, `ISS-009`, `ISS-010`, `ISS-011`, `ISS-012`, `ISS-013`, `ISS-016`, `ISS-018`, `ISS-019`, `ISS-024`, `ISS-027`, `ISS-028`, `ISS-029`, `ISS-030`, `ISS-032` (2), `ISS-033`, `ISS-034`, `ISS-035`, `ISS-039`, `ISS-040`. Frontend-only fixes (ISS-014, 015, 025, 026, 037) were verified with the headless browser run instead.

### 16.2 Live re-verification after fixes (dev server)

| Probe | Before | After |
| --- | --- | --- |
| Division admin approves as `super_admin` | 302 (escalated) | 422 "Only a super admin can grant the super_admin role." |
| Staff PATCH super admin account | 403 | 403 |
| Comment on inaccessible/private event | 403 with empty message | 403 "You cannot view this event." |
| Occurrence move with `starts_at` only | 200 | 422 "The ends at field is required when starts at is present." |
| Pending user `/settings/profile`, `/settings/notifications` | 200 | 302 → `/pending-approval` |
| Super admin `/admin` | 200 | 200 |

All probe side effects (escalated account, probe events, probe booking, probe change request, cancelled demo booking, dismissal) were reverted in the dev database.

### 16.3 Files changed by the audit

First pass — backend: `app/Rules/AssignableRole.php` (new), `app/Http/Requests/Admin/{ApproveUserRequest,StoreInvitationRequest,UpdateUserRequest}.php`, `app/Http/Requests/Event/UpdateOccurrenceRequest.php`, `app/Http/Controllers/Admin/{WorkingHourController,ResourceTypeController}.php`, `app/Http/Controllers/Api/{EventCommentController,EventOccurrenceController}.php`, `app/Http/Controllers/Auth/InvitationAcceptanceController.php`, `app/Models/{Booking,Invitation}.php`, `app/Observers/DivisionObserver.php`, `app/Providers/FortifyServiceProvider.php`, `app/Services/Booking/{EditRequestService,BookingRuleValidator}.php`, `routes/settings.php`. Frontend: `resources/js/lib/api.ts`, `resources/js/pages/auth/Login.vue`, `resources/js/pages/admin/users/{Index,Pending}.vue`, `resources/js/pages/admin/invitations/Index.vue`.

Second pass — backend: `app/Http/Middleware/EnsureUserIsApproved.php`, `app/Services/Booking/{BookingService,BookingRuleValidator,EditRequestService}.php`, `app/Models/{Holiday,Announcement}.php`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`, `app/Http/Controllers/Admin/{ResourceTypeController,WorkingHourController,RoleController,UserController}.php`, `app/Http/Controllers/Api/BookingController.php`, `app/Http/Requests/Event/UpdateOccurrenceRequest.php`, `app/Http/Controllers/Calendar/CalendarShareController.php`, `app/Rules/ValidRrule.php`, `bootstrap/app.php`. Frontend: `resources/js/pages/Error.vue` (new), `resources/js/app.ts`, `resources/js/components/ui/sidebar/{Sidebar,SidebarTrigger}.vue`, `resources/js/pages/admin/working-hours/Index.vue`, `resources/js/layouts/app/AppSidebarLayout.vue`.

Tests: `tests/Feature/AuditRegressionTest.php` (new, 33 tests).

Note: a concurrent session kept working in the same tree throughout (booking dialog, calendar shell, resource section, admin desk, seeders, and a rewrite of `BookingService.php` that added resource locking and version checks). The audit re-applied its one change to that file (booking rules inside the transaction) after the rewrite and did not edit the other files.

---

## 17. Remaining Risks

- **Operational secrets**: the seeded super admin password (ISS-022) is the largest remaining exposure if the production seeder is ever run as-is.
- **Role management** remains a soft boundary: `roles.manage` can self-escalate short of super admin (ISS-017); the change is now logged but not prevented.
- **Concurrency** was made safe by construction (resource lock, row locks, version checks, cap counted under lock) but not load-tested.
- **Lifecycle edges**: archived resources and deleted users keep open bookings (ISS-031); suspension does not terminate active sessions (the next request is refused).
- **Client behaviour on session loss** (ISS-014) was fixed by code but not exercised in a browser.
- **Concurrent edits**: the booking service and several calendar components were being rewritten while the audit ran; the combined tree passes the suite, but the merged result should be re-read once that work settles.
- **Third-party flows** (Google OAuth, passkeys, real mail) were reviewed in code only; the Google path has a mocked test for the archived-account case.

---

## 18. Unverified / Unable-to-Test Areas

| Area | Reason | Coverage used instead |
| --- | --- | --- |
| Google sign-in round trip | No OAuth credentials configured | Code review of `GoogleAuthController` |
| Passkey registration/login (WebAuthn) | Needs an authenticator | Fortify package; `SecurityTest` |
| Email content/delivery | No SMTP listener locally (jobs fail into `failed_jobs`) | Notification classes reviewed; `Notification::fake()` tests |
| Rate-limit exhaustion (429) | Not exercised to avoid locking demo accounts | Limiter definitions reviewed |
| True concurrency (double booking, approve vs cancel) | No load harness | Transaction/lock code reviewed (ISS-019) |
| Session expiry redirect in the browser (ISS-014) | Could not force expiry in the headless run | Type-checked code; the suspended-account variant is covered by test `ISS-018` |
| Production headers/cookies | Environment-specific | Config reviewed (ISS-023) |
| Interactive MCP browsers | Both browser MCP servers reported their profile "already in use" | Headless Chrome via DevTools protocol (24 page/width combinations) |
| Booking dialog drag/wheel gestures | Not automated | Screenshot at 390/1366; existing manual testing noted in project history |
| Screen-reader announcements | Not automated | Landmark/label/name probe only |

---

## 19. Final Checklist

| Item | Status |
| --- | --- |
| Every route inventoried with middleware and verification | Done (§3) |
| Every JSON endpoint inventoried with authorization and validation | Done (§4) |
| Roles/permissions matrix verified live | Done (§5) |
| End-to-end and interrupted workflows traced | Done (§6) |
| ≥100 distinct edge cases with results | Done: 134 (§7) |
| Direct URL / endpoint / id / payload manipulation tested | Done, live (§7.2) |
| Security & bypass findings documented | Done (§8, §14) |
| Business logic, data integrity, error states audited | Done (§9, §11, §12) |
| Responsive and accessibility pass on real renders | Done, headless Chrome (§13) |
| Issues documented with all required fields | Done: 40 issues (§14) |
| Safe fixes applied with regression tests | Done: 32 fixed over two passes, 33 tests (§16) |
| Full test suite green after fixes | Done: 263 passing |
| Probe side effects reverted in the dev database | Done |
| Open risks and unverified areas stated | Done (§17, §18) |

---

## 20. Settings & Admin Wiring Audit (2026-10-01)

A follow-up pass asking one question of every admin page and every settings page: is it wired to something real, and does that something work?

### 20.1 What was checked

| Area | Method | Result |
| --- | --- | --- |
| 61 admin/settings routes | `route:list` vs. Vue pages, hub cards, settings sidebar | Every route has a page; every hub card and sidebar link resolves to a route the same permission gates |
| Authorization | Middleware + per-controller `can()` / policies / form requests, then live GETs as super admin, division admin and staff | Consistent: 15 permission names match across seeder, backend and frontend; staff 403 on all admin pages; division admin 200 only on the dashboard, hub, users list and pending registrations |
| Page props | Every `Inertia::render` key vs. each page's `defineProps` | All match |
| Forms | Every `form.*` / `router.*` call vs. route name and HTTP verb | All match |
| General settings → behaviour | Grep of each property's consumers | `week_starts_on` used by all calendar pages; `organization_name`, `organization_acronym`, `timezone` were **never read** (ISS-041/042) |
| Registration settings → behaviour | Consumers | All three used by registration, Google sign-in, the login page and profile email changes |
| Working hours, holidays, event types, resource types, resources, divisions, roles | Consumers | All feed booking rules, editors, sections, policies and cache busting |
| Notification preferences | 13 keys vs. each notification's `via()` | All honoured except event reminders (ISS-043) |
| Audit log coverage | Admin controllers logging activity | Roles, users, invitations, bookings, calendars, divisions and resources logged; system settings were not (ISS-046) |
| Every admin and user settings page | Headless Chrome, 1366px, as super admin | Two pages rendered blank (ISS-044); the rest clean |

### 20.2 Issues found

| ID | Severity | Category | Location | Problem | Reproduction | Expected | Actual | Impact | Recommendation | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ISS-041 | High | Admin pages | `HandleInertiaRequests` shared prop `resourceTypes`; `admin/resources/Index.vue`, `admin/resource-types/Index.vue` | Both admin pages pass their own `resourceTypes` prop, which replaced the shared sidebar prop of the same name; the sidebar then read `slug` from the wrong shape and threw during render | Open `/admin/resources` or `/admin/resource-types` as an admin | Page renders with the sidebar | Entire page blank; `TypeError: Cannot read properties of undefined (reading 'toString')` | Two admin pages unusable | Rename the shared prop so no page can shadow it | Fixed (`resourceSections`); test `the sidebar resource sections survive pages that pass their own resourceTypes prop` |
| ISS-042 | Medium | Settings wiring | `GeneralSettings::$timezone`; 11 call sites hardcoding `Asia/Manila` | The admin Timezone field saved a value nothing read; working hours, holidays, booking summaries, the admin desk, the audit log date filter and new-user fallbacks all hardcoded Manila | Set timezone to `UTC` in General settings; book across the Manila/UTC midnight boundary | Rules follow the configured office timezone | Rules follow Manila regardless | Setting is misleading; cannot relocate the office | Read the setting everywhere | Fixed; `AdminDashboard::timezone()` added, old constant kept as deprecated for in-flight code; test `holiday blocking follows the configured office timezone` |
| ISS-043 | Medium | Settings wiring | `EventReminderNotification::via()`, Notification settings "Event reminders you set" | The per-user mail/in-app toggle for reminders was shown but ignored; the reminder job sent on the reminder row's channel regardless | Turn off reminder mail in Settings → Notifications, create an event with a mail reminder, run `SendDueReminders` | No reminder mail | Mail still sent | Users cannot silence reminder mail | Intersect the reminder channel with the preference | Fixed; test `the event reminder preference switches reminder channels off` |
| ISS-044 | Low | Settings wiring | `GeneralSettings::$organization_name/acronym`; labels in `AppSidebar`, `CalendarListPanel`, `PublicCalendar`, account mails | Organization name and acronym were saved but every label and mail subject hardcoded "FDCP" | Change the acronym to `TEST`, open the dashboard | Sidebar and panel labels read `TEST Events` / `TEST` | Still `FDCP` | Setting had no effect | Share `organization` with every page and read it in labels and mail | Fixed; live round trip confirmed; tests `the organization name and acronym reach every page`, `…names the calendar in account mail` |
| ISS-045 | Low | Admin UX | `admin/users/Index.vue` | A `users.approve`-only admin (division admin) can open the Users page (policy allows browsing) and sees Edit, Suspend and Delete buttons that all return 403 | Log in as `division.admin@…`, open `/admin/users`, click Edit | No actions offered | 403 on every action | Confusing dead controls | Gate the action column on `users.manage` | Fixed; headless check as division admin: 0 action buttons |
| ISS-046 | Low | Audit log | `SettingsController::update` | Changes to organization and registration settings left no audit entry | Save General settings, open Audit Log | Entry with old and new values | Nothing | Settings changes untraceable | Log `settings-updated` with before/after properties | Fixed; test `saving system settings leaves an audit log entry` |
| ISS-047 | Info | Admin UX | `routes/settings.php` `security.edit` | The Security settings page redirects to password confirmation on every visit (`RequirePassword`), so even viewing passkeys needs the password | Open `/settings/security` | — | 302 to `/user/confirm-password` | By design (2FA and passkeys are sensitive); noted so it is not mistaken for a bug | None | Open (design) |

### 20.3 Regression tests and verification

- New file `tests/Feature/Admin/SettingsWiringTest.php`: 6 tests covering ISS-041, 042, 043, 044 (2) and 046.
- Full suite after the pass: **290 passed, 1275 assertions**. Pint, ESLint, Prettier and vue-tsc clean; PHPStan reports only the pre-existing `AdminDashboard.php` debt (ISS-036).
- Headless re-check as super admin: `/admin/resources`, `/admin/resource-types`, `/admin/bookings`, `/dashboard`, `/calendar/public`, `/admin/settings/general` all load with zero console errors.
- Live: setting the acronym to `TEST` changed the shared `organization` prop on the dashboard; reverted to `FDCP`. The two probe audit entries were removed afterwards.

### 20.4 Notes for the concurrent booking-desk work

`BookingApprovalController` and `admin/bookings/Index.vue` were being rewritten by another session during this pass. The new controller referenced `AdminDashboard::TIMEZONE`; that constant now exists only as a deprecated alias of `AdminDashboard::timezone()`, which reads the setting. New code should call the method. A transient "Missing required prop: pending" warning seen on the bookings page mid-edit did not reproduce once the rewrite settled.
