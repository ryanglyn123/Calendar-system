# Google Calendar integration

What it does, how it is built, and how to set up the Google side.

## What users get

- **Their Google calendars inside the app** (My Calendar, under "Google Calendar" in the side panel): read-only, visible only to the person who connected them. Each calendar can be shown or hidden from Settings → Google Calendar.
- **Approved bookings in Google**: when a Boardroom or Carpool booking is approved, a copy goes into a separate "FDCP Bookings" calendar in the requester's Google account. An approved change moves it; cancelling or rejecting removes it. Pending requests never reach Google.
- **Clash hints while booking**: the booking dialog shows when the requester is busy in Google Calendar (a hint only; it never blocks a booking).
- **Public holidays from Google**: imported into Admin → Holidays, shown on every calendar, still editable by admins.

The booking system stays the source of truth. Nothing done in Google can approve, move, or cancel a booking: edits or deletions made to a booking's Google copy are shown on the booking ("Edited in Google", "Not in Google") with a button to put the booking's version back.

## How it works (for maintainers)

| Piece | Where |
|---|---|
| OAuth connect / disconnect, calendar choice | `Settings\GoogleCalendarController`, `Services\Google\GoogleAccounts` |
| Tokens (encrypted at rest, refreshed under a lock) | `GoogleAccount` model, `Services\Google\GoogleTokens` |
| REST client for Calendar API v3 (all Google calls) | `Services\Google\GoogleCalendarClient` |
| Event mirroring: full sync, then incremental with sync tokens; 410 → full resync + sweep | `Services\Google\GoogleEventSync`, `GoogleEventMapper`, `GoogleMirror` |
| Push notifications + renewal; polling fallback | `GoogleCalendarWebhookController`, `Services\Google\GoogleChannels`, `Jobs\MaintainGoogleSync` (every 5 min) |
| Booking copies | `Services\Google\GoogleBookingSync`, `Jobs\PushBookingToGoogle` (queued from `BookingService` and `EditRequestService` after commit) |
| Holidays | `Services\Google\GoogleHolidaySync`, `Jobs\SyncGoogleHolidays` (02:15 daily), `Services\Event\HolidayOccurrences` |
| Free/busy hint | `Services\Google\GoogleFreeBusy`, `GET /api/v1/google/busy` |
| Admin diagnostics | Admin → System Health → "Google Calendar" |

Design notes:

- A synced Google calendar is a `calendars` row of type `google`, owned by the user, so the existing calendar list, visibility toggles, colors and recurrence expansion all apply. `CalendarAccessService` lets only the owner see it; nobody can write to it. Super admins included: the event write endpoints refuse mirrored events outright.
- Each mirrored event is linked to its Google id (`google_events`), so updates and deletions never match on titles. Booking copies carry a private extended property (`fdcpBookingId`) and a fixed event id (`fdcpbk00000123`), so a retried insert cannot duplicate and a copy is never mirrored back.
- Recurring series map onto the app's existing model: series row with an RRULE, moved instances as overrides, cancelled instances and EXDATEs as cancelled overrides. RDATE/EXRULE are not expanded.
- Jobs run at most once at a time per calendar or booking, and a change arriving mid-sync queues exactly one follow-up. Rate limits and outages back off exponentially, honouring Retry-After.
- Admins only ever see counts and states, never anyone's calendar names or events. Mirror calendars stay out of the audit log.

## Scopes and why each is needed

| Scope | Why |
|---|---|
| `openid`, `email`, `profile` | Identify the Google account (shown on the settings page; one Google account can link to only one person). |
| `https://www.googleapis.com/auth/calendar.calendarlist.readonly` | Discover the user's calendars and watch the list for changes. |
| `https://www.googleapis.com/auth/calendar.events.readonly` | Read events on the user's calendars (including shared and holiday calendars) to show them here. Read-only: the app never edits them. |
| `https://www.googleapis.com/auth/calendar.app.created` | Create the separate "FDCP Bookings" calendar and write booking copies only there. The app cannot touch the user's other calendars. |
| `https://www.googleapis.com/auth/calendar.freebusy` | Busy times for the clash hint while booking. Times only, no event details. |

Deliberately not requested: `calendar` and `calendar.events` (write access to every calendar), the ACL scopes, and the settings scope (each calendar's time zone comes with the calendar list). Users may grant only some scopes; the missing features switch off and the settings page offers "Grant access".

## Google Cloud Console setup

You need: the app's public base URL (`APP_URL`, e.g. `https://calendar.fdcp.gov.ph`) and access to Google Cloud Console with the account that should own the project.

### 1. Project

1. Go to <https://console.cloud.google.com/> and create a project (e.g. "FDCP Calendar"), or reuse the one that already holds the sign-in client.
2. If `fdcp.gov.ph` is a Google Workspace domain, create the project **inside the fdcp.gov.ph organization**. That allows the "Internal" audience below, which needs no Google verification.

### 2. Enable the API

APIs & Services → Library → **Google Calendar API** → Enable.

That is the only API needed. Google sign-in uses OAuth/OpenID and needs no extra API.

### 3. OAuth consent screen (Google Auth Platform)

Google Auth Platform → **Branding**:

- App name: `FDCP Calendar`
- User support email and developer contact: an fdcp.gov.ph address
- App home page and privacy policy: your `APP_URL` pages (required for External)
- Authorized domains: the production domain (e.g. `fdcp.gov.ph`)

Google Auth Platform → **Audience**:

- **Internal** (recommended when the project is in the fdcp.gov.ph Workspace organization): only fdcp.gov.ph accounts, no verification, no user cap.
- **External** (only if staff use personal Gmail accounts):
  - While in **Testing**, add each user under *Test users* (maximum 100). Refresh tokens then expire after 7 days, so users must reconnect weekly.
  - **Publish** the app to stop that. Because the calendar scopes are sensitive, Google's verification is required for external users. Use the justifications in the table above.

Google Auth Platform → **Data Access** → Add or remove scopes, then add exactly:

```
openid
https://www.googleapis.com/auth/userinfo.email
https://www.googleapis.com/auth/userinfo.profile
https://www.googleapis.com/auth/calendar.calendarlist.readonly
https://www.googleapis.com/auth/calendar.events.readonly
https://www.googleapis.com/auth/calendar.app.created
https://www.googleapis.com/auth/calendar.freebusy
```

### 4. OAuth client (Web application)

Google Auth Platform → **Clients** → Create client → Application type **Web application**. One client serves both sign-in and the calendar connection.

**Authorized redirect URIs** (exact match, including scheme and port):

```
https://YOUR-DOMAIN/auth/google/callback
https://YOUR-DOMAIN/settings/google-calendar/callback
```

For local development, add these too:

```
http://localhost:8000/auth/google/callback
http://localhost:8000/settings/google-calendar/callback
```

Authorized JavaScript origins are not needed (the flow is server-side).

Copy the **Client ID** and **Client secret**. Keep the secret out of git, chat, and tickets.

### 5. API key (holiday import only)

APIs & Services → Credentials → Create credentials → **API key**, then edit it:

- API restrictions: **Restrict key** → Google Calendar API only.
- Application restrictions: **IP addresses** → the server's public outbound IP. The key is used only server-side.

Without a key everything else still works; only the holiday import stays off.

### 6. Server configuration

In the server's `.env`:

```
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
GOOGLE_CALENDAR_REDIRECT_URI="${APP_URL}/settings/google-calendar/callback"
GOOGLE_CALENDAR_WEBHOOK_URL="${APP_URL}/webhooks/google-calendar"
GOOGLE_CALENDAR_API_KEY=...
```

Then run `php artisan migrate --force` and `php artisan config:cache` (or `config:clear`).

Both of these must run on the server, as already documented in the README:

- the **queue worker** (`php artisan queue:work`): all syncing and booking copies happen in jobs;
- the **scheduler** (`* * * * * php artisan schedule:run`): channel renewal, polling, nightly holiday import.

### 7. Push notifications (instant updates)

Google pushes "something changed" notifications to `GOOGLE_CALENDAR_WEBHOOK_URL`. This needs:

- a **public HTTPS** address with a valid, publicly trusted certificate (no self-signed certificates, no hostname mismatch);
- the reverse proxy passing `POST /webhooks/google-calendar` through with its `X-Goog-*` headers.

No login is involved: each notification proves itself with a per-channel secret token. Google's current push guide does not mention domain verification, so nothing is needed in Search Console.

If the server is only reachable inside the office network or over plain HTTP, leave it as is: the app detects that and polls each calendar every 10 minutes using sync tokens (cheap). Admin → System Health shows which mode is active.

### 8. Holiday calendars

Admin → Holidays → **Sources** defaults to the Philippines: `en.philippines#holiday@group.v.calendar.google.com`.

To find another region's id, open Google Calendar → Settings → the holiday calendar (e.g. "Holidays in …") → *Integrate calendar* → *Calendar ID*.

Press **Import now** once after setup; afterwards it runs nightly. Google labels days "Public holiday", "Special non-working holiday", "Observance"; these labels are not a documented API contract, so check the imported types once. An admin's edit or deletion is kept on later imports.

### 9. Quotas

Projects created on or after **1 May 2026** get Google's newer Calendar API limits. At the time of writing these are 10,000 requests per minute per project and 600 per minute per user, plus a daily billing threshold.

This app stays far below them: incremental syncs return only changes, and push or 10-minute polling replaces repeated full downloads. You can watch usage under APIs & Services → Google Calendar API → **Quotas & System Limits**. Google has announced that quota increases will later require billing.

### 10. Google Workspace admin (if applicable)

If Workspace API controls restrict third-party apps, a Workspace admin must allow this OAuth client: Admin console → Security → Access and data control → API controls → Manage third-party app access → add the client ID → **Trusted**. Marking it Trusted also lifts Google's unverified-app limits for fdcp.gov.ph users.

### 11. Check it works

1. Admin → System Health → "Google Calendar": sign-in credentials **Set**; the redirect URI shown there must match the one in step 4.
2. Settings → Google Calendar → **Connect Google Calendar** → consent → back on the page, calendars appear within a few seconds.
3. Create an event in Google Calendar; it appears on My Calendar within moments with push, or within 10 minutes without.
4. Approve a test booking for yourself; it appears in "FDCP Bookings" in Google. Cancel it; it disappears.
5. Admin → Holidays → **Import now**; holidays appear on the calendar.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `redirect_uri_mismatch` on connect | The callback URL in step 4 differs from `GOOGLE_CALENDAR_REDIRECT_URI` (scheme, host, port or path). |
| "Access blocked: app not verified" or "not authorized" | External app in Testing and the user is not a test user, or a Workspace admin has not trusted the client. |
| Users must reconnect every week | External app still in **Testing** (7-day refresh tokens). Publish it, or use Internal. |
| "Reconnect" shown on the settings page | The grant was revoked, expired, or blocked by an admin. Reconnecting fixes it. |
| Changes take ~10 minutes | No public HTTPS webhook, so the app is polling (by design). |
| Holidays never import | `GOOGLE_CALENDAR_API_KEY` missing, the key is restricted to another API, or the server's IP is not allowed. |
