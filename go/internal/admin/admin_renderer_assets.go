package admin

// rendererCSS is the admin panel stylesheet. Keep it identical to
// AdminRenderer::CSS in the PHP edition.
const rendererCSS = `:root { --bg: #F5F6F8; --surface: #FFFFFF; --border: #E3E5EA; --line: #EEF0F3; --text: #12141A; --text-2: #3A3F4B; --muted: #5C6270; --accent: #2052B8; --accent-hover: #173F8F; --accent-bg: #EBF0FA; --ok: #1E7A46; --ok-bg: #EAF5EE; --warn: #7A4E00; --warn-bg: #FFF7E6; --warn-border: #F0DDB0; --danger: #A12B27; --danger-bg: #FDF0EF; --danger-border: #F0C9C7; --mono: "Geist Mono", ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace; }
* { box-sizing: border-box; }
body { font-family: "Geist", "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.45; -webkit-font-smoothing: antialiased; }
[hidden] { display: none !important; }
.hidden { display: none !important; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
a { color: var(--accent); }
a:hover { color: var(--accent-hover); }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.shell { display: flex; flex-wrap: wrap; min-height: 100vh; align-items: stretch; }
.sidebar { flex: 1 1 232px; max-width: 260px; background: var(--surface); border-right: 1px solid var(--border); padding: 20px 14px; display: flex; flex-direction: column; gap: 22px; }
.brand { display: flex; align-items: center; gap: 10px; padding: 0 8px; font-weight: 700; font-size: 15px; color: var(--text); text-decoration: none; }
.brand-mark { width: 28px; height: 28px; border-radius: 8px; background: var(--accent); color: #FFFFFF; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex: none; }
.sidebar nav { display: flex; flex-direction: column; gap: 18px; }
.nav-group { display: flex; flex-direction: column; gap: 2px; }
.nav-heading { padding: 0 10px 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
.nav-link { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; color: var(--text-2); font-weight: 500; text-decoration: none; }
.nav-link svg { flex: none; }
.nav-link:hover { background: var(--bg); color: var(--text); }
.nav-link[aria-current="page"] { background: var(--accent-bg); color: var(--accent-hover); font-weight: 600; }
.sidebar-foot { margin-top: auto; border-top: 1px solid var(--border); padding: 14px 8px 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sidebar-foot .link-button { font-size: 13px; }
.content { flex: 999 1 560px; min-width: 0; padding: 32px 36px 56px; }
.page { max-width: 1040px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
h1 { font-size: 24px; font-weight: 700; letter-spacing: -0.02em; margin: 0; color: var(--text); }
h2 { font-size: 16px; font-weight: 600; margin: 0 0 12px; color: var(--text); }
h3 { font-size: 14px; font-weight: 600; margin: 18px 0 8px; }
.lead { color: var(--muted); font-size: 14px; line-height: 1.6; margin: 6px 0 0; }
.page > .lead { margin: -8px 0 20px; }
.muted, .bucket-meta { color: var(--muted); font-size: 13px; }

.panel, form { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; margin: 0 0 20px; }
form.plain { background: none; border: 0; padding: 0; margin: 0; border-radius: 0; }
.panel-flush { padding: 0; overflow: hidden; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.panel-flush .panel-head { padding: 16px 20px 0; }
.panel-head h2 { margin: 0; }
.panel-highlight { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-bg); }

label { display: flex; align-items: center; gap: 16px; margin: 14px 0; font-size: 14px; font-weight: 500; color: var(--text); }
label.checkbox-label { gap: 8px; }
label.stacked { display: flex; flex-direction: column; align-items: stretch; gap: 6px; margin: 8px 0; font-size: 13px; }
input, select { flex: 1; width: 100%; height: 40px; padding: 0 12px; border: 1px solid #D5D8DF; border-radius: 8px; font: inherit; color: var(--text); background: var(--surface); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
input[type=file] { height: auto; padding: 8px; }
input:focus, select:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-bg); }
input[type=checkbox] { flex: none; width: 16px; height: 16px; padding: 0; accent-color: var(--accent); }
input:disabled { background: var(--bg); color: var(--muted); }

button, .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 38px; background: var(--accent); color: #FFFFFF; border: 1px solid transparent; border-radius: 8px; padding: 0 16px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s ease, transform 0.1s ease; }
button:hover, .button:hover { background: var(--accent-hover); color: #FFFFFF; }
button:active { transform: scale(0.98); }
button:disabled { opacity: 0.55; cursor: not-allowed; }
.secondary, .dialog-secondary, .snippet-actions button, .row-actions button, .row-actions a, .tab, .icon-btn { background: var(--surface); color: var(--text); border: 1px solid var(--border); font-weight: 500; }
.secondary:hover, .dialog-secondary:hover, .snippet-actions button:hover, .row-actions button:hover, .row-actions a:hover, .tab:hover, .icon-btn:hover { background: var(--bg); color: var(--text); }
button.danger, .button.danger { background: var(--danger); color: #FFFFFF; }
button.danger:hover { background: #7E211E; color: #FFFFFF; }
.danger-outline { background: var(--surface); color: var(--danger); border: 1px solid var(--danger-border); }
.danger-outline:hover { background: var(--danger-bg); color: var(--danger); }
.row-actions button.danger, .icon-btn.danger { background: var(--surface); color: var(--danger); border: 1px solid var(--border); }
.row-actions button.danger:hover, .icon-btn.danger:hover { background: var(--danger-bg); color: var(--danger); }
.link-button { background: none; border: 0; padding: 0; min-height: 0; color: var(--text-2); font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; }
.link-button:hover { background: none; color: var(--text); text-decoration: underline; text-underline-offset: 3px; }
.danger-text { color: var(--danger) !important; }

.error, .notice, .warning, .dialog-error { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 10px 14px; border-radius: 9px; font-size: 14px; line-height: 1.5; margin-bottom: 16px; border: 1px solid; }
.error, .dialog-error { background: var(--danger-bg); border-color: var(--danger-border); color: #8E2420; }
.notice { background: var(--ok-bg); border-color: #CFE6D8; color: #1A5E37; }
.warning { background: var(--warn-bg); border-color: var(--warn-border); color: var(--warn); }
.warning .link-button { color: var(--warn); flex: none; }

code { font-family: var(--mono); font-size: 13px; color: var(--text-2); word-break: break-all; }
.strong-code, .key-details code, .keys-table code { color: var(--text); }
.code-box { display: inline-block; font-family: var(--mono); font-size: 15px; font-weight: 500; color: var(--text); background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; }
pre { background: var(--bg); border: 1px solid var(--border); color: var(--text); border-radius: 8px; overflow: auto; padding: 14px 16px; font-family: var(--mono); font-size: 13px; line-height: 1.65; white-space: pre-wrap; margin: 12px 0; }
.snippet-actions, .update-actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0; }

details { margin-top: 16px; border-top: 1px solid var(--line); padding-top: 2px; }
details summary { list-style: none; display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; color: var(--text); padding: 12px 0; user-select: none; }
details summary::-webkit-details-marker { display: none; }
details summary::before { content: ""; flex: none; width: 0; height: 0; border-left: 5px solid var(--muted); border-top: 4px solid transparent; border-bottom: 4px solid transparent; transition: transform 0.15s ease; }
details[open] summary::before { transform: rotate(90deg); }

fieldset { border: 0; padding: 0; margin: 0 0 12px; }
legend { font-size: 15px; font-weight: 600; padding: 0; margin-bottom: 4px; }
.field { display: grid; grid-template-columns: 220px 1fr; gap: 6px 16px; margin: 18px 0; align-items: start; }
.field > .field-label { display: block; margin: 0; padding-top: 10px; font-size: 14px; font-weight: 500; }
.field > div > label.checkbox-label { margin: 10px 0 0; }
.help { font-size: 13px; color: var(--muted); line-height: 1.55; margin: 6px 0 0; }
.help code, .lead code, .muted code { font-size: 12px; }
.help-list { padding-left: 20px; line-height: 1.7; }
.help-list li { margin: 4px 0; }
.badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; vertical-align: middle; }
.badge-rw { background: var(--ok-bg); color: #1A5E37; }
.badge-read { background: var(--accent-bg); color: var(--accent-hover); }
.badge-env { background: #F1F2F5; color: var(--text-2); font-weight: 500; }
.key-details { display: grid; grid-template-columns: 120px 1fr; gap: 10px 16px; align-items: center; }
.key-details dt { color: var(--muted); font-size: 13px; }
.key-details dd { margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.inline-form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.inline-form label.stacked { flex: 1 1 200px; margin: 0; }
.secret-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 6px; font-size: 13px; }
.secret-row .link-button { font-size: 13px; }
.row-details { margin: 0 0 6px; border: 0; padding: 0; }
.row-details summary { padding: 2px 0; font-weight: 500; font-size: 13px; }
.row-details summary::before { display: none; }
.row-details form { margin: 8px 0 12px; display: flex; flex-direction: column; gap: 8px; min-width: 220px; padding: 12px; }
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 16px 0 4px; }
.tab { min-height: 32px; font-size: 13px; padding: 0 12px; }
.tab.active { background: var(--text); color: #FFFFFF; border-color: var(--text); }

.checklist { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.step { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border-radius: 10px; border: 1px solid #BFD0F0; background: var(--surface); }
.step.done { border-color: #D5EBDD; background: #F6FBF8; }
.step-mark { flex: none; width: 22px; height: 22px; border-radius: 99px; background: var(--accent); color: #FFFFFF; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.step.done .step-mark { background: var(--ok); }
.step-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.step-body span { font-size: 13px; color: var(--muted); }
.progress { height: 6px; background: var(--line); border-radius: 99px; margin: 0 0 16px; overflow: hidden; }
.progress > span { display: block; height: 100%; background: var(--accent); border-radius: 99px; }
.endpoint-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.split { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
.split > .main-col { flex: 3 1 440px; min-width: 0; }
.split > .side-col { flex: 2 1 300px; min-width: 0; }
.health { list-style: none; margin: 0; padding: 0; }
.health li { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--line); }
.health li:first-child { border-top: 0; }
.dot { flex: none; margin-top: 5px; width: 9px; height: 9px; border-radius: 99px; background: #8A909C; }
.dot-ok { background: var(--ok); }
.dot-warn { background: #C77A00; }
.dot-bad { background: var(--danger); }
.health-body { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.health-title { display: flex; justify-content: space-between; gap: 8px; }
.health-title span { font-size: 13px; color: var(--text-2); }
.health-body > span { font-size: 12px; color: var(--muted); word-break: break-all; }
.share { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
.share-bar { width: 80px; height: 6px; background: var(--line); border-radius: 99px; overflow: hidden; }
.share-bar > span { display: block; height: 100%; background: var(--accent); }

.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.toolbar-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.toolbar-grow { flex: 1 1 280px; }
.selection-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: var(--text); color: #FFFFFF; border-radius: 10px; padding: 6px 6px 6px 16px; margin-bottom: 12px; }
.selection-bar > span { flex: 1; font-weight: 600; }
.selection-bar .link-button { color: #FFFFFF; padding: 0 10px; }
.breadcrumbs { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; font-size: 15px; }
.breadcrumbs a { color: var(--muted); text-decoration: none; }
.breadcrumbs a:last-child { color: var(--text); font-weight: 700; }
.breadcrumbs span { color: #9AA0AB; }
.files-layout { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
.bucket-rail { flex: 1 1 200px; max-width: 240px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px; }
.bucket-rail ul { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.bucket-rail a { display: flex; justify-content: space-between; gap: 8px; padding: 7px 8px; border-radius: 7px; color: var(--text-2); text-decoration: none; }
.bucket-rail a span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bucket-rail a span:last-child { color: var(--muted); font-size: 12px; flex: none; }
.bucket-rail a:hover { background: var(--bg); }
.bucket-rail a[aria-current="page"] { background: var(--accent-bg); color: var(--accent-hover); font-weight: 600; }
.files-main { flex: 999 1 480px; min-width: 0; }
.bucket-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.bucket-card { border: 1px solid var(--border); border-radius: 10px; padding: 16px; background: var(--surface); display: flex; flex-direction: column; gap: 4px; }
.bucket-card h3 { margin: 0 0 4px; font-size: 15px; }
.bucket-card h3 a { color: var(--text); text-decoration: none; }
.bucket-card p { margin: 0; }
.bucket-card .row-actions { margin-top: 10px; }
.empty-state { padding: 24px; text-align: center; }
.files-table-wrap { overflow-x: auto; }
.files-table { width: 100%; border-collapse: collapse; }
.files-table th, .files-table td { padding: 9px 12px; border-top: 1px solid var(--line); text-align: left; vertical-align: middle; }
.files-table th { font-size: 12px; font-weight: 600; color: var(--muted); background: #FAFBFC; border-top: 0; }
.files-table td a { color: var(--text); text-decoration: none; }
.files-table tbody tr:hover { background: #FAFBFC; }
.files-table tbody tr.row-clickable { cursor: pointer; }
.files-table tbody tr.is-active { background: var(--accent-bg); box-shadow: inset 3px 0 0 var(--accent); }
.files-table .num { color: var(--muted); white-space: nowrap; }
.file-name { display: flex; align-items: center; gap: 10px; font-weight: 500; }
.file-icon { width: 30px; height: 30px; border-radius: 6px; flex: none; display: inline-flex; align-items: center; justify-content: center; background: var(--line); color: var(--text-2); font-size: 9px; font-weight: 700; overflow: hidden; }
.file-icon.is-image { background: #DCE6F6; }
.preview-thumb { width: 30px; height: 30px; object-fit: cover; display: block; }
.status-line { margin-bottom: 10px; font-size: 13px; }
.status-line.error-text { color: var(--danger); }
.row-actions { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
.icon-btn { width: 32px; height: 32px; min-height: 32px; padding: 0; border-radius: 7px; vertical-align: middle; }
[data-tooltip] { position: relative; }
[data-tooltip]::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%) translateY(4px); background: var(--text); color: #FFFFFF; font-size: 11px; font-weight: 500; padding: 4px 8px; border-radius: 4px; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease; z-index: 50; }
[data-tooltip]:hover::after { opacity: 1; transform: translateX(-50%) translateY(0); }
.drawer { position: fixed; top: 0; right: 0; bottom: 0; z-index: 35; width: min(380px, 100%); overflow-y: auto; background: var(--surface); border-left: 1px solid var(--border); padding: 20px 22px; box-shadow: -16px 0 40px rgba(18, 20, 26, 0.12); }
.drawer-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.drawer-head h2 { margin: 0; font-size: 15px; word-break: break-all; }
.drawer-close { width: 32px; height: 32px; min-height: 32px; padding: 0; background: transparent; color: var(--muted); border: 0; font-size: 18px; }
.drawer-close:hover { background: var(--bg); color: var(--text); }
.drawer-preview { width: 100%; max-height: 220px; object-fit: contain; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); }
.drawer-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.drawer dl { margin: 0; display: grid; grid-template-columns: 80px 1fr; gap: 8px 12px; font-size: 13px; }
.drawer dt { color: var(--muted); }
.drawer dd { margin: 0; word-break: break-all; }

.dialog-panel { width: min(480px, calc(100% - 32px)); max-width: none; border-radius: 14px; color: var(--text); background: var(--surface); border: 1px solid var(--border); padding: 24px; box-shadow: 0 24px 60px rgba(18, 20, 26, 0.2); }
.dialog-panel::backdrop { background: rgba(18, 20, 26, 0.35); }
.dialog-panel[open] { animation: dialog-in 0.15s ease-out; }
@keyframes dialog-in { from { opacity: 0; transform: translateY(6px) scale(0.98); } }
@media (prefers-reduced-motion: reduce) { .dialog-panel[open] { animation: none; } }
.dialog-close { position: absolute; right: 12px; top: 12px; width: 32px; height: 32px; min-height: 32px; padding: 0; background: transparent; color: var(--muted); border-radius: 50%; font-size: 18px; line-height: 1; }
.dialog-close:hover { background: var(--bg); color: var(--text); }
.dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }

.auth { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; padding: 48px 16px; }
.auth .brand { font-size: 17px; }
.auth .brand-mark { width: 32px; height: 32px; font-size: 13px; }
.auth-card { width: 100%; max-width: 400px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 28px; box-shadow: 0 1px 2px rgba(18, 20, 26, 0.04), 0 12px 32px rgba(18, 20, 26, 0.06); }
.auth-card.wide { max-width: 520px; }
.auth-card h1 { font-size: 20px; }
.auth-card .lead { margin: 6px 0 20px; }
.auth-card form { background: none; border: 0; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; }
.auth-card form > label:not(.checkbox-label) { flex-direction: column; align-items: stretch; gap: 6px; margin: 0; }
.auth-card form > button[type=submit] { min-height: 44px; margin-top: 4px; }
.auth-card details { margin-top: 18px; padding-top: 4px; }
.auth-card .field { grid-template-columns: 1fr; margin: 0; gap: 6px; }
.auth-card .field > .field-label { padding-top: 0; }
.auth-foot { margin: 0; max-width: 400px; text-align: center; color: var(--muted); font-size: 13px; }
.password-row { display: flex; align-items: center; border: 1px solid #D5D8DF; border-radius: 8px; padding: 0 4px 0 0; background: var(--surface); }
.password-row:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-bg); }
.password-row input { border: 0; box-shadow: none !important; }
.password-row button { min-height: 32px; padding: 0 10px; background: transparent; color: var(--text-2); border: 0; font-weight: 500; font-size: 13px; }
.password-row button:hover { background: var(--bg); color: var(--text); }
.copy-row { display: flex; gap: 6px; align-items: stretch; }
.copy-row code { flex: 1; min-width: 0; font-size: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 7px; padding: 8px; }

.error, .notice { justify-content: flex-start; }
.error svg { flex: none; margin-top: 2px; }
.stack { display: flex; flex-direction: column; gap: 6px; }
.stack > label { margin: 0; }
.updates { border-top: 1px solid var(--line); margin-top: 4px; }
.updates h3 { margin-top: 14px; }
.updates p { margin: 6px 0; }
.drawer-inner { display: flex; flex-direction: column; gap: 14px; }
.file-name { display: flex; align-items: center; gap: 10px; color: var(--text); font-weight: 500; text-align: left; }
.file-name:hover { color: var(--text); }

label.stacked input, label.stacked select, .auth-card form > label:not(.checkbox-label) input { flex: none; }
label > span.field-label { flex: 0 0 220px; }
.auth-card label > span.field-label { flex: none; }
.icon-btn { display: inline-flex; align-items: center; justify-content: center; }
.health-body code { display: block; color: var(--text); margin-bottom: 2px; }

/* Keep navigation in view while long lists scroll. Only where the columns sit side by side. */
@media (min-width: 800px) {
  .sidebar { position: sticky; top: 0; align-self: flex-start; height: 100vh; overflow-y: auto; }
}
@media (min-width: 1024px) {
  .bucket-rail { position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto; }
}

@media (max-width: 760px) {
  .sidebar { max-width: none; flex-basis: 100%; border-right: 0; border-bottom: 1px solid var(--border); padding: 14px; gap: 12px; }
  .sidebar nav { flex-direction: row; flex-wrap: wrap; gap: 4px; }
  .nav-group { flex-direction: row; flex-wrap: wrap; }
  .nav-heading { display: none; }
  .sidebar-foot { margin-top: 0; padding-top: 10px; }
  .content { padding: 20px 16px 40px; }
  .field { grid-template-columns: 1fr; }
  .field > .field-label { padding-top: 0; }
  .key-details { grid-template-columns: 1fr; }
  .bucket-rail { max-width: none; }
  .files-table thead { display: none; }
  .files-table, .files-table tbody, .files-table tr, .files-table td { display: block; width: 100%; }
  .files-table tr { padding: 10px 0; border-top: 1px solid var(--line); }
  .files-table td { border-top: 0; padding: 4px 12px; }
}`
