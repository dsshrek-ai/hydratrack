# Setup Guide

HydraTrack is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `hydratrack`.

## 1. Register the app with My Apps Hub

In **phpMyAdmin**, select the MyDataWorld database, open the **SQL** tab, and run
the "NEW APP: HydraTrack" block appended to **My Apps Hub's** own
`api/schema.sql` (it adds the `hydratrack` row to the `apps` table). Safe to
re-run — it's `ON DUPLICATE KEY UPDATE`.

## 2. Create this app's tables

Still in phpMyAdmin's SQL tab, run everything in [`api/schema.sql`](api/schema.sql).
It's safe to run even if `users` / `sessions` already exist (those use
`CREATE TABLE IF NOT EXISTS`). It adds eight `hydra_*` tables.

## 3. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` (same credentials as your other MyDataWorld
   apps).
2. Upload `api/api.php` and `api/config.php` via FTP / File Manager to
   `seniorfamily.org/hydratrack-api/` (so the endpoint is
   `https://seniorfamily.org/hydratrack-api/api.php`).

## 4. Point the site at your API

`js/api.js` already has:

```js
const CONFIG = {
  API_URL: "https://seniorfamily.org/hydratrack-api/api.php",
};
```

Change it only if you upload the API somewhere else.

## 5. Publish

Push this folder to the GitHub repo `dsshrek-ai/hydratrack`, then
Settings → Pages → Deploy from a branch → `main` / `/ (root)`. The app goes live
at `https://dsshrek-ai.github.io/hydratrack/`.

## 6. Grant yourself access

1. Sign up through **My Apps Hub** with your email if you haven't already.
2. In the Hub's `admin.html`, grant your account **HydraTrack**, or run:

   ```sql
   INSERT INTO app_access (user_id, app_id)
   SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'hydratrack'
   WHERE u.username = 'you@example.com'
   ON DUPLICATE KEY UPDATE user_id = user_id;
   ```

## 7. First use

Open HydraTrack from My Apps Hub, then:

1. **Containers** → add the things you drink from (e.g. Blue Tumbler, 24 oz).
2. **Goals** → add goals (e.g. 3 × Blue Tumbler every day, 2 × Kitchen Glass Mon–Fri).
3. **Today** → tap a container to log it; Undo appears for 8 seconds.

Tracking starts the first day you open the app; History and streaks run from there.

## How days and history work

- The browser sends its time zone with every request, so each drink is filed under
  your **local** date. It's saved per user and updated if you travel.
- There's one `hydra_daily_summary` row per day from your first day to today. Days
  you don't open the app are filled in the next time you do (with 0 oz).
- A day's target is a **snapshot**: changing or deactivating a goal updates today and
  later, never past days. Editing a container's size updates goals that use it from
  today on; drinks already logged keep their original ounces.
- **Goal streak** skips days with no target (they don't count or break it). Neither
  streak breaks just because today isn't over yet.
- `hydra_user_stats` is a cache, recalculated from the daily summaries on every
  Today/Stats load.

## Single sign-on

The app is seeded with `sso_enabled = 1`, so launching it from My Apps Hub skips
the login screen (`?token=...` handoff). `js/api.js` captures the token, saves it
as the Bearer credential, and strips it from the URL.

## Usage logging

Every successful action upserts a row into the shared `app_usage_log`
(`app_key = 'hydratrack'`), one row per day.
