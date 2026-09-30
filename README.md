# HydraTrack

A water-intake tracker that lets you set goals in the containers you actually drink
from — "3 tumblers plus 2 glasses" — while measuring everything in ounces.

- **Today** — progress ring (ounces vs. today's target), a check-mark circle per
  expected container, one-tap quick-log buttons with Undo, manual ounce entry, and
  today's entries.
- **Goals** — any number of goals at once: a number of containers, or a number of
  ounces; per-weekday or every day; start/end dates; active/inactive.
- **Containers** — reusable cups/glasses/bottles with sizes, icons, and a quick-log flag.
- **History** — ounces vs. target for 30 / 60 / 90 days, 1 year (weekly), or all time,
  with goal-met days marked, plus the daily records.
- **Stats** — current and best logging and goal streaks, averages, lifetime totals.

Built on the MyDataWorld stack: static HTML/CSS/JS on GitHub Pages, a single PHP
API (`api/api.php`) on seniorfamily.org, shared MySQL tables prefixed `hydra_`,
and My Apps Hub single sign-on. See [SETUP.md](SETUP.md).

Based on `Water_Intake_Tracker_Development_Spec.md`. Where the spec left a choice
open:

- Days with no target don't break or count toward the goal streak.
- A direct-ounce goal ("20 oz a day") is credited with water no container goal
  claimed: entered ounces, containers with no goal, and containers beyond a goal.
- Extra containers past a goal's quantity stay on that goal (shown as green checks).
- Logging is for today; entries can be removed from Today's list.
