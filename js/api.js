// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/hydratrack-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// The browser's time zone rides along on every request so the server files
// each drink under the user's own local date, not the server's.
function browserTimeZone() {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
}

// ---- Login (My Apps Hub SSO token, or a manual fallback) ----
// Every action except login/logout checks this token against the shared
// sessions table plus an app_access grant for 'hydratrack'.

const TOKEN_KEY = 'htToken';
function getToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
function saveToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) { /* private mode */ } }
function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

async function readJson(res) {
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  let data = null;
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  if (!res.ok) {
    const err = new Error((data && data.error) || `Request failed: ${res.status}`);
    err.userMessage = data && data.error;
    throw err;
  }
  return data;
}

async function fetchData(action, params) {
  if (!isConfigured()) throw new Error('not-configured');
  const qs = new URLSearchParams({ action, tz: browserTimeZone(), ...(params || {}) });
  const res = await fetch(`${CONFIG.API_URL}?${qs}`, { headers: authHeaders() });
  return readJson(res);
}

async function postAction(action, payload) {
  if (!isConfigured()) throw new Error('not-configured');
  const res = await fetch(CONFIG.API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain', ...authHeaders() },
    body: JSON.stringify({ action, tz: browserTimeZone(), ...(payload || {}) }),
  });
  return readJson(res);
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

// Renders a login-required / access-denied card into `container`. "no-token"
// includes a manual login form for a bookmarked visit that skipped the Hub's
// SSO handoff -- normally you never see it. "denied" means the login is fine
// but has no app_access grant for HydraTrack yet.
function renderGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `<div class="card"><p class="note">Your login isn't set up for HydraTrack yet. Ask to be granted the app in My Apps Hub, or open it again from there.</p></div>`;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="note">Normally you won't see this — open HydraTrack from My Apps Hub and it logs you in automatically.</p>
      <label for="g-email">Email</label>
      <input type="email" id="g-email" autocomplete="username">
      <label for="g-pw">Password</label>
      <input type="password" id="g-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="g-btn">Log In</button></p>
      <div id="g-msg"></div>
    </div>`;
  const email = document.getElementById('g-email');
  const pw = document.getElementById('g-pw');
  const msg = document.getElementById('g-msg');
  const btn = document.getElementById('g-btn');
  async function attempt() {
    const username = email.value.trim();
    const password = pw.value;
    if (!username || !password) return;
    btn.disabled = true;
    msg.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username, password }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msg.innerHTML = `<p class="note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        btn.disabled = false;
        return;
      }
      saveToken(data.token);
      window.location.reload();
    } catch (e) {
      msg.innerHTML = `<p class="note">Something went wrong. Please try again.</p>`;
      btn.disabled = false;
    }
  }
  btn.addEventListener('click', attempt);
  pw.addEventListener('keydown', ev => { if (ev.key === 'Enter') attempt(); });
}

function handleError(container, err) {
  const m = err && err.message;
  if (m === 'not-authorized') renderGate(container, getToken() ? 'denied' : 'no-token');
  else if (m === 'not-configured') container.innerHTML = `<p class="note">App isn't configured yet.</p>`;
  else container.innerHTML = `<p class="note">Couldn't load right now — check your connection and refresh.</p>`;
}

// For errors after the page has loaded (a save that failed): a short message
// in the page's #status box instead of replacing the whole page.
function showStatus(msg) {
  const el = document.getElementById('status');
  if (!el) return;
  el.textContent = msg || '';
  el.style.display = msg ? 'block' : 'none';
}

function errorText(err, fallback) {
  if (err && err.message === 'not-authorized') return 'Your login has expired — open HydraTrack again from My Apps Hub.';
  return (err && err.userMessage) || fallback;
}

// My Apps Hub launches this app with ?token=... -- adopt it as the login and
// strip it from the URL. Run-once.
let _ssoDone = null;
function captureSso() {
  if (_ssoDone) return _ssoDone;
  _ssoDone = (async () => {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    saveToken(token);
  })();
  return _ssoDone;
}

// ---- Formatting helpers shared by the pages ----

// 24 -> "24", 12.5 -> "12.5", 7.333 -> "7.3"
function fmtOz(n) {
  const v = Math.round((Number(n) || 0) * 10) / 10;
  return Number.isInteger(v) ? String(v) : v.toFixed(1);
}

const WEEKDAY_SHORT = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// [1..7] -> "Every day" / "Mon–Fri" / "Weekends" / "Mon, Wed, Fri"
function weekdaysLabel(days) {
  const d = (days || []).slice().sort((a, b) => a - b).join(',');
  if (d === '1,2,3,4,5,6,7') return 'Every day';
  if (d === '1,2,3,4,5') return 'Mon–Fri';
  if (d === '6,7') return 'Weekends';
  if (!d) return 'No days';
  return (days || []).slice().sort((a, b) => a - b).map(n => WEEKDAY_SHORT[n]).join(', ');
}

// "2026-09-30" -> Date at local midnight (no UTC day-shift)
function parseDate(ymd) {
  const [y, m, d] = String(ymd).split('-').map(Number);
  return new Date(y, m - 1, d);
}

function fmtDate(ymd, opts) {
  return parseDate(ymd).toLocaleDateString(undefined, opts || { month: 'short', day: 'numeric' });
}

// A goal's display title: its own name, else "3 × Blue Tumbler" / "20 oz a day".
function goalTitle(g) {
  if (g.Name) return g.Name;
  if (g.ContainerName) return `${fmtOz(g.Quantity ?? g.TargetQuantity)} × ${g.ContainerName}`;
  return `${fmtOz(g.TargetOunces)} oz`;
}
