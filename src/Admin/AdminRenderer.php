<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminRenderer
{
    /** Where pages fetch sizes after they have loaded; see statAttrs(). */
    private const STATS_URL = '/_/stats';
    private const STATS_PENDING = '…';

    private const ICON_RENAME = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    private const ICON_DELETE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
    private const ICON_DOWNLOAD = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>';

    private const ICON_COPY = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';

    private const ICON_ALERT = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';

    private const NAV_ICONS = [
        '/_' => '<path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h4A1.5 1.5 0 0 1 10 4.5v6A1.5 1.5 0 0 1 8.5 12h-4A1.5 1.5 0 0 1 3 10.5Z"/><path d="M14 4.5A1.5 1.5 0 0 1 15.5 3h4A1.5 1.5 0 0 1 21 4.5v2A1.5 1.5 0 0 1 19.5 8h-4A1.5 1.5 0 0 1 14 6.5Z"/><path d="M14 13.5a1.5 1.5 0 0 1 1.5-1.5h4a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M3 17.5A1.5 1.5 0 0 1 4.5 16h4a1.5 1.5 0 0 1 1.5 1.5v2A1.5 1.5 0 0 1 8.5 21h-4A1.5 1.5 0 0 1 3 19.5Z"/>',
        '/_/files' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        '/_/keys' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9"/><path d="m17 6 3 3"/>',
        '/_/security' => '<path d="M8 7a4 4 0 1 0 8 0 4 4 0 0 0-8 0"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        '/_/settings' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><circle cx="9" cy="6" r="2" fill="#fff"/><circle cx="15" cy="12" r="2" fill="#fff"/><circle cx="8" cy="18" r="2" fill="#fff"/>',
    ];

    /** Sidebar groups: heading => [href => page title]. */
    private const NAV = [
        '' => ['/_' => 'Overview'],
        'Storage' => ['/_/files' => 'Buckets & files'],
        'Access' => ['/_/keys' => 'Access keys', '/_/security' => 'Admin account'],
        'System' => ['/_/settings' => 'Settings'],
    ];

    private const CSS = <<<'CSS'
:root { --bg: #F5F6F8; --surface: #FFFFFF; --border: #E3E5EA; --line: #EEF0F3; --text: #12141A; --text-2: #3A3F4B; --muted: #5C6270; --accent: #2052B8; --accent-hover: #173F8F; --accent-bg: #EBF0FA; --ok: #1E7A46; --ok-bg: #EAF5EE; --warn: #7A4E00; --warn-bg: #FFF7E6; --warn-border: #F0DDB0; --danger: #A12B27; --danger-bg: #FDF0EF; --danger-border: #F0C9C7; --mono: "Geist Mono", ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace; }
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
}
CSS;

    public function installer(array $values, array $errors, string $csrfToken, bool $credentialsFromEnv = false): string
    {
        $keyNote = $credentialsFromEnv
            ? '<div class="notice">Access keys come from <code>MINI_S3_CREDENTIALS_JSON</code>, so no key will be generated.</div>'
            : '';
        $body = '<h1>Set up Mini S3</h1>'
            . '<p class="lead">Create the admin account for this server. Mini S3 then creates your first access key and shows you how to connect an app.</p>'
            . '<div class="warning">Until you finish this step, anyone who can open this page can take over the server. Finish setup now.</div>'
            . $keyNote
            . $this->errorHtml($errors)
            . '<form method="post" action="/_">'
            . $this->csrfField($csrfToken)
            . $this->textField('admin_username', 'Admin username', (string) ($values['admin_username'] ?? 'admin'), 'You log in to this panel at <code>/_</code> with this name.', ['required' => true, 'autocomplete' => 'username'])
            . $this->textField('admin_password', 'Admin password', '', 'At least 8 characters. This password controls every bucket and key, so use a password manager.', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8'])
            . $this->textField('admin_password_confirm', 'Confirm password', '', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8'])
            . '<details><summary>Advanced</summary>'
            . $this->textField('data_dir', 'Data directory', (string) ($values['data_dir'] ?? ''), 'Objects are stored as plain files at <code>&lt;data directory&gt;/&lt;bucket&gt;/&lt;key&gt;</code>. PHP must be able to write here. Prefer a folder outside the web root.', ['required' => true])
            . '</details>'
            . '<button type="submit">Finish setup</button>'
            . '</form>';

        return $this->authLayout('Set up Mini S3', $body, true);
    }

    public function login(string $error, string $csrfToken): string
    {
        $resetCommand = 'php -r \'echo password_hash("new-password", PASSWORD_DEFAULT);\'';
        $errorHtml = $error === '' ? '' : '<div class="error" role="alert">' . self::ICON_ALERT . '<span>' . $this->e($error) . '</span></div>';
        $body = '<h1>Sign in to admin</h1>'
            . '<p class="lead">Manage buckets, files and access keys.</p>'
            . $errorHtml
            . '<form method="post" action="/_">'
            . $this->csrfField($csrfToken)
            . '<label>Username<input name="username" autocomplete="username" required autofocus></label>'
            . '<div class="stack"><label for="login-password">Password</label>'
            . '<div class="password-row"><input id="login-password" type="password" name="password" autocomplete="current-password" required>'
            . '<button type="button" aria-label="Show password" aria-pressed="false" data-toggle-password="login-password">Show</button></div></div>'
            . '<button type="submit">Sign in</button>'
            . '</form>'
            . '<details><summary>Forgot the password?</summary>'
            . '<p class="help">On the server, make a new hash and put it in <code>ADMIN_PASSWORD_HASH</code> in <code>config/config.php</code>:</p>'
            . '<div class="copy-row"><code>' . $this->e($resetCommand) . '</code><button type="button" class="secondary" data-copy="' . $this->e($resetCommand) . '">Copy</button></div>'
            . '</details>';

        return $this->authLayout('Sign in · Mini S3 Admin', $body, false, 'This account is only for this panel. Apps connect to S3 with access keys, not with this password.');
    }

    public function dashboard(
        array $stats,
        array $config = [],
        string $endpoint = '',
        array $updateStatus = [],
        string $flashMessage = '',
        string $csrfToken = '',
        array $warnings = []
    ): string {
        $keyCount = count((array) ($config['CREDENTIALS'] ?? []));
        $bucketCount = (int) $stats['bucket_count'];
        $body = '<p class="lead" ' . $this->statAttrs(self::STATS_URL, 'all', 'summary') . '>' . self::STATS_PENDING . '</p>'
            . $this->flashMessage($flashMessage)
            . $this->warningBanners($warnings)
            . $this->setupChecklist($keyCount, $bucketCount, $endpoint)
            . $this->connectPanel($endpoint)
            . '<div class="split">'
            . '<div class="main-col">' . $this->bucketsPanel((array) ($stats['buckets'] ?? [])) . '</div>'
            . '<div class="side-col">' . $this->healthPanel($stats, $config, $keyCount, $updateStatus) . '</div>'
            . '</div>';

        return $this->layout('Overview', $body, $csrfToken);
    }

    /**
     * @param array<string, string> $envLocked config key => environment variable
     */
    public function settings(array $values, array $errors, string $csrfToken, string $flashMessage = '', array $envLocked = []): string
    {
        $postMax = (string) ini_get('post_max_size');
        $uploadMax = (string) ini_get('upload_max_filesize');
        $debugLog = (string) ($values['auth_debug_log'] ?? '');
        $debugHelp = $debugLog === ''
            ? 'Off. To debug "SignatureDoesNotMatch" errors, set <code>AUTH_DEBUG_LOG</code> in <code>config/config.php</code> (or <code>MINI_S3_AUTH_DEBUG_LOG</code>) to a log file path outside the web root.'
            : 'On, writing to <code>' . $this->e($debugLog) . '</code>. It can only be changed in <code>config/config.php</code> or the environment, so a stolen admin session cannot write files elsewhere.';

        $body = $this->flashMessage($flashMessage)
            . $this->errorHtml($errors)
            . '<p class="lead">Server settings. Access keys are managed on the <a href="/_/keys">Access keys</a> page and the admin password on the <a href="/_/security">Admin account</a> page.</p>'
            . ($envLocked === [] ? '' : '<div class="notice">Fields marked <span class="badge badge-env">env</span> are set by environment variables. They override <code>config/config.php</code> and cannot be changed here.</div>')
            . '<form method="post" action="/_/settings">'
            . $this->csrfField($csrfToken)
            . '<fieldset><legend>Storage</legend>'
            . $this->textField('data_dir', 'Data directory', (string) ($values['data_dir'] ?? ''), 'Where objects are stored. Changing this does not move existing files: move them yourself first, or the buckets will appear empty.', ['required' => true], $envLocked['DATA_DIR'] ?? null)
            . $this->textField('max_request_size', 'Max upload size (bytes)', (string) ($values['max_request_size'] ?? ''), 'Largest single request body accepted by the S3 API. 104857600 = 100 MiB. Larger files should use multipart upload (most S3 tools do this automatically). Uploads from this admin panel are also limited by PHP: upload_max_filesize = ' . $this->e($uploadMax) . ', post_max_size = ' . $this->e($postMax) . '. A reverse proxy may have its own limit (Nginx: <code>client_max_body_size</code>).', ['type' => 'number', 'min' => '1', 'required' => true], $envLocked['MAX_REQUEST_SIZE'] ?? null)
            . '</fieldset>'
            . '<fieldset><legend>Access</legend>'
            . $this->checkboxField('public_read_all_buckets', 'Public read', !empty($values['public_read_all_buckets']), 'Allow anyone to download objects without a key', 'When on, every object in every bucket can be downloaded and listed by anyone who knows the URL. Uploads and deletes still need a key. Leave off unless this server only stores public files; for read-only apps, create a <a href="/_/keys">read-only key</a> instead.', $envLocked['PUBLIC_READ_ALL_BUCKETS'] ?? null)
            . '</fieldset>'
            . '<details><summary>Advanced</summary>'
            . $this->textField('clock_skew_seconds', 'Clock skew (seconds)', (string) ($values['clock_skew_seconds'] ?? ''), 'How far a client clock may differ from the server clock. Default 900 (15 minutes). Raise it only if clients get "RequestTimeTooSkewed"; fixing the client clock is better.', ['type' => 'number', 'min' => '1', 'required' => true])
            . $this->textField('max_presign_expires', 'Max presigned URL lifetime (seconds)', (string) ($values['max_presign_expires'] ?? ''), 'Longest validity a presigned (shareable) URL may request. Default 604800 (7 days), the S3 maximum. Lower it to limit how long a leaked link works.', ['type' => 'number', 'min' => '1', 'required' => true])
            . $this->checkboxField('allow_host_candidate_fallbacks', 'Host fallbacks', !empty($values['allow_host_candidate_fallbacks']), 'Accept signatures made for a different Host', 'Only for reverse proxies that change the Host header. It also accepts X-Forwarded-Host and Host with or without the port, which weakens signature checks. Fix the proxy instead when you can (Nginx: <code>proxy_set_header Host $http_host;</code>).', $envLocked['ALLOW_HOST_CANDIDATE_FALLBACKS'] ?? null)
            . '<div class="field"><span class="field-label">Auth debug log</span><div><p class="help">' . $debugHelp . '</p></div></div>'
            . '</details>'
            . '<button type="submit">Save settings</button>'
            . '</form>';

        return $this->layout('Settings', $body, $csrfToken);
    }

    public function security(array $values, array $errors, string $csrfToken, string $flashMessage): string
    {
        $body = $this->flashMessage($flashMessage)
            . $this->errorHtml($errors)
            . '<p class="lead">The admin account for this panel. It is separate from the S3 <a href="/_/keys">access keys</a> that apps use.</p>'
            . '<form method="post" action="/_/security">'
            . $this->csrfField($csrfToken)
            . $this->textField('admin_username', 'Admin username', (string) ($values['admin_username'] ?? 'admin'), '', ['required' => true, 'autocomplete' => 'username'])
            . $this->textField('current_password', 'Current password', '', 'Required to save any change here.', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
            . $this->textField('new_password', 'New password', '', 'At least 8 characters. Leave blank to keep the current password.', ['type' => 'password', 'autocomplete' => 'new-password', 'minlength' => '8'])
            . $this->textField('new_password_confirm', 'Confirm new password', '', '', ['type' => 'password', 'autocomplete' => 'new-password', 'minlength' => '8'])
            . '<button type="submit">Save admin account</button>'
            . '</form>';

        return $this->layout('Admin account', $body, $csrfToken);
    }

    /**
     * @param array<string, array{secret: string, access: string, label: string, created: int}> $credentials
     */
    public function keys(array $credentials, string $endpoint, string $csrfToken, string $flashMessage, string $newKey = '', bool $managedByEnv = false): string
    {
        $initialSecrets = $newKey !== '' && isset($credentials[$newKey]) ? [$newKey => (string) $credentials[$newKey]['secret']] : [];
        $selected = $newKey !== '' ? $newKey : (string) (array_key_first($credentials) ?? '');
        $templates = $this->snippetTemplates($endpoint);
        $state = [
            'csrf' => $csrfToken,
            'secrets' => (object) $initialSecrets,
            'templates' => $templates,
        ];

        $body = '<script type="application/json" id="keys-state">' . $this->jsonForScript($state) . '</script>'
            . '<div data-keys>'
            . $this->flashMessage($flashMessage)
            . '<section class="panel"><h2>What are access keys?</h2>'
            . '<p>Apps and tools (AWS CLI, rclone, Laravel, SDKs) connect to Mini S3 with an <strong>access key</strong> and its <strong>secret</strong>. Create one key per app, so you can remove one app without touching the others.</p>'
            . '<ul class="help-list">'
            . '<li><span class="badge badge-rw">Read &amp; write</span> can list, download, upload, overwrite and delete objects in every bucket.</li>'
            . '<li><span class="badge badge-read">Read only</span> can only list and download. Use it for websites, CDNs and anything that should never change data.</li>'
            . '</ul>'
            . '<p class="muted">Anyone with a secret has that key\'s access, so treat it like a password. You can view secrets again on this page at any time. Deleting a key immediately stops every app that uses it.</p>'
            . '</section>'
            . ($newKey !== '' ? $this->newKeyPanel($newKey, (string) $credentials[$newKey]['secret'], $endpoint) : '')
            . ($managedByEnv
                ? '<div class="notice">These keys come from the <code>MINI_S3_CREDENTIALS_JSON</code> environment variable. Change that variable to add or remove keys.</div>'
                : $this->createKeyForm($csrfToken))
            . $this->keysTable($credentials, $csrfToken, $managedByEnv)
            . ($credentials === [] ? '' : $this->snippetsPanel($credentials, $selected, $templates))
            . '</div>';

        return $this->layout('Access keys', $body, $csrfToken);
    }

    public function files(array $buckets, array $listing, string $currentBucket, string $currentPrefix, string $csrfToken, string $flashMessage): string
    {
        $summary = count((array) ($listing['folders'] ?? [])) . ' folders, ' . count((array) ($listing['files'] ?? [])) . ' files';
        $actions = '<button type="button" class="secondary" data-action="create-bucket">New bucket</button>'
            . ($currentBucket === '' ? '' : '<button type="button" class="secondary" data-action="create-folder">New folder</button><button type="button" data-action="upload">Upload file</button>');
        $body = '<div data-explorer data-csrf="' . $this->e($csrfToken) . '" data-bucket="' . $this->e($currentBucket) . '" data-prefix="' . $this->e($currentPrefix) . '">'
            . $this->flashMessage($flashMessage)
            . '<div class="files-layout">'
            . $this->bucketRail($buckets, $currentBucket)
            . '<div class="files-main">'
            . $this->filesBreadcrumbs($currentBucket, $currentPrefix)
            . '<div class="toolbar">'
            . '<div class="toolbar-group toolbar-grow"><input type="search" aria-label="Filter this list" placeholder="Filter this list" data-filter></div>'
            . '<div class="toolbar-group">' . $actions . '</div>'
            . '</div>'
            . ($currentBucket === '' ? $this->bucketGrid($buckets) : $this->objectTable($currentBucket, $currentPrefix, $listing, $summary))
            . '</div>'
            . ($currentBucket === '' ? '' : $this->fileDrawer())
            . '</div>'
            . $this->filesModal()
            . '</div>';

        return $this->layout('Buckets & files', $body, $csrfToken);
    }

    // --- form helpers -------------------------------------------------------

    private function csrfField(string $csrfToken): string
    {
        return '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">';
    }

    /**
     * A labelled input with help text. $help is trusted HTML written in this
     * class; values are escaped. A field locked by an environment variable is
     * disabled, so the browser does not post it and the saved value is kept.
     */
    private function textField(string $name, string $label, string $value, string $help, array $attrs = [], ?string $envVar = null): string
    {
        $attrs += ['type' => 'text'];
        if ($envVar !== null) {
            $attrs['disabled'] = true;
            unset($attrs['required']);
        }
        $attrHtml = '';
        foreach ($attrs as $attr => $attrValue) {
            if ($attrValue === true) {
                $attrHtml .= ' ' . $attr;
            } elseif ($attrValue !== false) {
                $attrHtml .= ' ' . $attr . '="' . $this->e((string) $attrValue) . '"';
            }
        }
        $id = 'field-' . $name;
        $helpId = $id . '-help';
        $describedBy = $help === '' ? '' : ' aria-describedby="' . $helpId . '"';

        return '<div class="field"><label class="field-label" for="' . $id . '">' . $this->e($label) . $this->envBadge($envVar) . '</label><div>'
            . '<input id="' . $id . '" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $attrHtml . $describedBy . '>'
            . ($help === '' ? '' : '<p class="help" id="' . $helpId . '">' . $help . '</p>')
            . '</div></div>';
    }

    private function checkboxField(string $name, string $label, bool $checked, string $caption, string $help, ?string $envVar = null): string
    {
        $disabled = $envVar !== null ? ' disabled' : '';
        $id = 'field-' . $name;

        return '<div class="field"><span class="field-label">' . $this->e($label) . $this->envBadge($envVar) . '</span><div>'
            . '<input type="hidden" name="' . $this->e($name) . '" value="0"' . $disabled . '>'
            . '<label class="checkbox-label" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . $this->e($name) . '" value="1"' . ($checked ? ' checked' : '') . $disabled . ' aria-describedby="' . $id . '-help"> ' . $this->e($caption) . '</label>'
            . '<p class="help" id="' . $id . '-help">' . $help . '</p>'
            . '</div></div>';
    }

    private function envBadge(?string $envVar): string
    {
        return $envVar === null ? '' : ' <span class="badge badge-env" title="Set by ' . $this->e($envVar) . '">env: ' . $this->e($envVar) . '</span>';
    }

    private function errorHtml(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="error" role="alert">' . $this->e((string) $error) . '</div>';
        }

        return $html;
    }

    // --- dashboard ----------------------------------------------------------

    /**
     * @param array<string, string> $warnings id => message
     */
    private function warningBanners(array $warnings): string
    {
        $html = '';
        foreach ($warnings as $id => $message) {
            $storageKey = 'miniS3:dismiss:' . $id;
            $html .= '<div class="warning" data-dismiss-key="' . $this->e($storageKey) . '">'
                . '<span>' . $this->e($message) . '</span>'
                . '<button type="button" class="link-button" data-dismiss>Dismiss</button>'
                . '</div>';
        }

        return $html;
    }

    /**
     * Shown until the server has a key and a bucket; it replaces a long help text.
     */
    private function setupChecklist(int $keyCount, int $bucketCount, string $endpoint): string
    {
        if ($keyCount > 0 && $bucketCount > 0) {
            return '';
        }
        $bucketHint = '<a href="/_/files">Create one on the Files page</a>'
            . ($endpoint === '' ? '' : ', or run <code>aws --endpoint-url ' . $this->e($endpoint) . ' s3 mb s3://my-bucket</code>');
        $steps = [
            [true, 'Admin account', 'Created during setup'],
            [$keyCount > 0, 'Create an access key', $keyCount > 0 ? $this->e($keyCount . ($keyCount === 1 ? ' key' : ' keys')) : '<a href="/_/keys">Create one per app on Access keys</a>'],
            [$bucketCount > 0, 'Create a bucket', $bucketCount > 0 ? $this->e($bucketCount . ($bucketCount === 1 ? ' bucket' : ' buckets')) : $bucketHint],
            [false, 'Connect an app', '<a href="/_/keys#connect">Copy settings for AWS CLI, rclone or Laravel</a>'],
        ];
        $done = count(array_filter($steps, static fn (array $step): bool => $step[0]));
        $items = '';
        foreach ($steps as $index => [$isDone, $title, $hint]) {
            $items .= '<li class="step' . ($isDone ? ' done' : '') . '">'
                . '<span class="step-mark" aria-hidden="true">' . ($isDone ? '&#10003;' : (string) ($index + 1)) . '</span>'
                . '<span class="step-body"><strong>' . $this->e($title) . ($isDone ? '<span class="visually-hidden"> (done)</span>' : '') . '</strong><span>' . $hint . '</span></span>'
                . '</li>';
        }

        return '<section class="panel"><div class="panel-head"><h2>Getting started</h2><span class="muted">' . $done . ' of ' . count($steps) . ' done</span></div>'
            . '<div class="progress" aria-hidden="true"><span style="width: ' . (int) round($done / count($steps) * 100) . '%"></span></div>'
            . '<ol class="checklist">' . $items . '</ol></section>';
    }

    private function connectPanel(string $endpoint): string
    {
        if ($endpoint === '') {
            return '';
        }

        return '<section class="panel"><div class="panel-head"><h2>Connect an app</h2><a href="/_/keys">Connection settings for your tools &rarr;</a></div>'
            . '<div class="endpoint-row"><span class="visually-hidden">S3 endpoint:</span><code class="code-box">' . $this->e($endpoint) . '</code>'
            . '<button type="button" class="secondary" data-copy="' . $this->e($endpoint) . '" aria-label="Copy S3 endpoint">Copy</button></div>'
            . '<p class="help">Use path-style addressing (the bucket goes in the URL path) and any region name, for example <code>us-east-1</code>. Ready-made settings for the AWS CLI, rclone, s5cmd, Laravel and JavaScript are on the <a href="/_/keys">Access keys</a> page.</p>'
            . '</section>';
    }

    /**
     * @param list<array{name: string}> $buckets
     */
    private function bucketsPanel(array $buckets): string
    {
        $head = '<div class="panel-head"><h2>Buckets</h2><a href="/_/files">Open Files &rarr;</a></div>';
        if ($buckets === []) {
            return '<section class="panel">' . $head . '<p class="muted">No buckets yet. A bucket is a top-level folder for your objects.</p></section>';
        }

        $rows = '';
        foreach ($buckets as $bucket) {
            $name = (string) $bucket['name'];
            $key = 'bucket:' . $name;
            $rows .= '<tr>'
                . '<td><a href="/_/files?bucket=' . rawurlencode($name) . '"><strong>' . $this->e($name) . '</strong></a></td>'
                . '<td class="num" ' . $this->statAttrs(self::STATS_URL, $key, 'objects') . '>' . self::STATS_PENDING . '</td>'
                . '<td class="num" ' . $this->statAttrs(self::STATS_URL, $key, 'size') . '>' . self::STATS_PENDING . '</td>'
                . '<td><span class="share"><span class="share-bar" aria-hidden="true"><span ' . $this->statAttrs(self::STATS_URL, $key, 'share', 'width') . '></span></span>'
                . '<span class="muted" ' . $this->statAttrs(self::STATS_URL, $key, 'share') . '>' . self::STATS_PENDING . '</span></span></td>'
                . '</tr>';
        }

        return '<section class="panel panel-flush">' . $head
            . '<div class="files-table-wrap"><table class="files-table"><thead><tr><th>Name</th><th>Objects</th><th>Size</th><th>Share of storage</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function healthPanel(array $stats, array $config, int $keyCount, array $updateStatus): string
    {
        $dataStatus = match ((string) $stats['status']) {
            'ok' => ['ok', 'Writable'],
            'not_writable' => ['bad', 'Not writable'],
            'missing' => ['bad', 'Missing'],
            'unreadable' => ['bad', 'Unreadable'],
            default => ['warn', (string) $stats['status']],
        };
        $publicRead = !empty($config['PUBLIC_READ_ALL_BUCKETS']);
        $hostFallbacks = !empty($config['ALLOW_HOST_CANDIDATE_FALLBACKS']);
        $debugLog = (string) ($config['AUTH_DEBUG_LOG'] ?? '') !== '';
        $items = [
            [$dataStatus[0], 'Data directory', $dataStatus[1], '<code>' . $this->e((string) $stats['data_dir']) . '</code> Every object is a plain file here; back it up like any folder.'],
            [$keyCount > 0 ? 'ok' : 'warn', 'Access keys', (string) $keyCount, $keyCount > 0 ? 'Apps connect with these.' : 'No app can connect yet.'],
            [$publicRead ? 'warn' : 'ok', 'Public read', $publicRead ? 'On' : 'Off', $publicRead ? 'Anyone with a URL can download objects.' : 'Every request needs a key.'],
            [$hostFallbacks ? 'warn' : 'ok', 'Host fallbacks', $hostFallbacks ? 'On' : 'Off', $hostFallbacks ? 'Signature checks are relaxed for proxies.' : 'Strict signature checks.'],
            [$debugLog ? 'warn' : 'muted', 'Auth debug log', $debugLog ? 'On' : 'Off', $debugLog ? 'Turn it off when you are done debugging.' : 'Turn on only to debug SignatureDoesNotMatch.'],
        ];
        $list = '';
        foreach ($items as [$tone, $title, $state, $detail]) {
            $list .= '<li><span class="dot dot-' . $tone . '" aria-hidden="true"></span><span class="health-body">'
                . '<span class="health-title"><strong>' . $this->e($title) . '</strong><span>' . $this->e($state) . '</span></span>'
                . '<span>' . $detail . '</span></span></li>';
        }

        return '<section class="panel"><h2>Server health</h2><ul class="health">' . $list . '</ul>'
            . $this->updatesPanel($updateStatus)
            . '</section>';
    }

    // --- access keys --------------------------------------------------------

    private function newKeyPanel(string $accessKey, string $secret, string $endpoint): string
    {
        return '<section class="panel panel-highlight"><h2>Your new access key</h2>'
            . '<p>Copy these into your app now. You can come back to this page to view the secret again.</p>'
            . '<dl class="key-details">'
            . '<dt>Endpoint</dt><dd><code>' . $this->e($endpoint) . '</code></dd>'
            . '<dt>Access key</dt><dd><code>' . $this->e($accessKey) . '</code> ' . $this->copyButton($accessKey, 'Copy access key') . '</dd>'
            . '<dt>Secret key</dt><dd><code>' . $this->e($secret) . '</code> ' . $this->copyButton($secret, 'Copy secret key') . '</dd>'
            . '<dt>Region</dt><dd><code>us-east-1</code> <span class="muted">(any value works)</span></dd>'
            . '</dl>'
            . '<p class="muted">Ready-made settings for common tools are under <a href="#connect">Connection settings</a> below.</p>'
            . '</section>';
    }

    private function copyButton(string $value, string $label): string
    {
        return $this->iconButton(self::ICON_COPY, $label, 'data-copy="' . $this->e($value) . '"');
    }

    private function createKeyForm(string $csrfToken): string
    {
        return '<section class="panel"><h2>Create an access key</h2>'
            . '<form method="post" action="/_/keys" class="plain inline-form">'
            . $this->csrfField($csrfToken)
            . '<input type="hidden" name="action" value="create">'
            . '<label class="stacked">Label<input name="label" maxlength="64" placeholder="e.g. Website, Backups, Laravel app"></label>'
            . '<label class="stacked">Permission<select name="access">'
            . '<option value="readwrite">Read &amp; write</option>'
            . '<option value="read">Read only</option>'
            . '</select></label>'
            . '<button type="submit">Create key</button>'
            . '</form>'
            . '<p class="muted">The access key and secret are generated for you.</p>'
            . '</section>';
    }

    private function keysTable(array $credentials, string $csrfToken, bool $managedByEnv): string
    {
        if ($credentials === []) {
            return '<section class="panel"><p class="muted">No access keys yet. Create one above to let an app connect.</p></section>';
        }

        $rows = '';
        foreach ($credentials as $accessKey => $entry) {
            $accessKey = (string) $accessKey;
            $label = (string) $entry['label'];
            $isRead = $entry['access'] === 'read';
            $created = (int) $entry['created'];
            $rows .= '<tr>'
                . '<td>' . ($label === '' ? '<span class="muted">No label</span>' : $this->e($label)) . '</td>'
                . '<td><code>' . $this->e($accessKey) . '</code> ' . $this->copyButton($accessKey, 'Copy access key')
                . '<div class="secret-row" data-secret-row="' . $this->e($accessKey) . '"><code data-secret-value hidden></code>'
                . '<button type="button" class="link-button" data-secret-toggle>Show secret</button>'
                . '<button type="button" class="link-button" data-secret-copy hidden>Copy secret</button>'
                . '</div></td>'
                . '<td>' . ($isRead ? '<span class="badge badge-read">Read only</span>' : '<span class="badge badge-rw">Read &amp; write</span>') . '</td>'
                . '<td>' . ($created > 0 ? $this->e($this->formatDate($created)) : '<span class="muted">-</span>') . '</td>'
                . '<td>' . ($managedByEnv ? '<span class="muted">env</span>' : $this->keyActions($accessKey, $label, $isRead, $csrfToken)) . '</td>'
                . '</tr>';
        }

        return '<section class="panel"><h2>Keys</h2><div class="files-table-wrap"><table class="files-table keys-table">'
            . '<thead><tr><th>Label</th><th>Access key / secret</th><th>Permission</th><th>Created</th><th>Actions</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function keyActions(string $accessKey, string $label, bool $isRead, string $csrfToken): string
    {
        $hiddenFields = $this->csrfField($csrfToken) . '<input type="hidden" name="access_key" value="' . $this->e($accessKey) . '">';

        return '<details class="row-details"><summary>Edit</summary>'
            . '<form method="post" action="/_/keys" class="plain">'
            . $hiddenFields . '<input type="hidden" name="action" value="update">'
            . '<label class="stacked">Label<input name="label" maxlength="64" value="' . $this->e($label) . '"></label>'
            . '<label class="stacked">Permission<select name="access">'
            . '<option value="readwrite"' . ($isRead ? '' : ' selected') . '>Read &amp; write</option>'
            . '<option value="read"' . ($isRead ? ' selected' : '') . '>Read only</option>'
            . '</select></label>'
            . '<button type="submit">Save</button>'
            . '</form></details>'
            . '<details class="row-details"><summary class="danger-text">Delete</summary>'
            . '<form method="post" action="/_/keys" class="plain">'
            . $hiddenFields . '<input type="hidden" name="action" value="delete">'
            . '<p class="help">Apps using this key will stop working immediately. Type <code>' . $this->e($accessKey) . '</code> to confirm.</p>'
            . '<input name="confirm" autocomplete="off" required aria-label="Type the access key to confirm">'
            . '<button type="submit" class="danger">Delete key</button>'
            . '</form></details>';
    }

    /**
     * @param array<string, array{secret: string, access: string, label: string, created: int}> $credentials
     * @param array<string, array{title: string, hint: string, body: string}> $templates
     */
    private function snippetsPanel(array $credentials, string $selected, array $templates): string
    {
        $options = '';
        foreach ($credentials as $accessKey => $entry) {
            $accessKey = (string) $accessKey;
            $text = $accessKey
                . ((string) $entry['label'] === '' ? '' : ' (' . $entry['label'] . ')')
                . ($entry['access'] === 'read' ? ' - read only' : '');
            $options .= '<option value="' . $this->e($accessKey) . '"' . ($accessKey === $selected ? ' selected' : '') . '>' . $this->e($text) . '</option>';
        }
        $tabs = '';
        foreach ($templates as $id => $template) {
            $active = $id === 'env';
            $tabs .= '<button type="button" role="tab" class="tab' . ($active ? ' active' : '') . '" aria-selected="' . ($active ? 'true' : 'false') . '" data-tab="' . $this->e($id) . '">' . $this->e($template['title']) . '</button>';
        }

        return '<section class="panel" id="connect"><h2>Connection settings</h2>'
            . '<p class="muted">Pick a key and a tool, then copy. Replace <code>your-bucket</code> with your bucket name.</p>'
            . '<div class="inline-form">'
            . '<label class="stacked">Key<select data-snippet-key>' . $options . '</select></label>'
            . '</div>'
            . '<div class="tabs" role="tablist">' . $tabs . '</div>'
            . '<p class="muted" data-snippet-hint></p>'
            . '<pre data-snippet></pre>'
            . '<div class="snippet-actions">'
            . '<button type="button" data-snippet-secret>Show secret</button>'
            . '<button type="button" data-snippet-copy>Copy</button>'
            . '</div>'
            . '</section>';
    }

    /**
     * Connection snippets with {{ACCESS_KEY}} and {{SECRET_KEY}} placeholders,
     * filled in by the browser for the selected key.
     *
     * @return array<string, array{title: string, hint: string, body: string}>
     */
    private function snippetTemplates(string $endpoint): array
    {
        $region = 'us-east-1';

        return [
            'env' => [
                'title' => 'Env vars',
                'hint' => 'Standard AWS variables, read by the AWS CLI, SDKs and most S3 tools.',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}}\nAWS_SECRET_ACCESS_KEY={{SECRET_KEY}}\nAWS_DEFAULT_REGION={$region}\nAWS_ENDPOINT_URL={$endpoint}",
            ],
            'laravel' => [
                'title' => 'Laravel',
                'hint' => 'Add to .env and use the "s3" disk (needs league/flysystem-aws-s3-v3).',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}}\nAWS_SECRET_ACCESS_KEY={{SECRET_KEY}}\nAWS_DEFAULT_REGION={$region}\nAWS_BUCKET=your-bucket\nAWS_ENDPOINT={$endpoint}\nAWS_USE_PATH_STYLE_ENDPOINT=true",
            ],
            'awscli' => [
                'title' => 'AWS CLI',
                'hint' => 'Creates a "mini-s3" profile, then lists your buckets.',
                'body' => "aws configure set aws_access_key_id {{ACCESS_KEY}} --profile mini-s3\naws configure set aws_secret_access_key {{SECRET_KEY}} --profile mini-s3\naws configure set region {$region} --profile mini-s3\naws --profile mini-s3 --endpoint-url {$endpoint} s3 ls",
            ],
            'rclone' => [
                'title' => 'rclone',
                'hint' => 'Add to rclone.conf (rclone config file shows where it is), then: rclone ls mini-s3:your-bucket',
                'body' => "[mini-s3]\ntype = s3\nprovider = Other\naccess_key_id = {{ACCESS_KEY}}\nsecret_access_key = {{SECRET_KEY}}\nendpoint = {$endpoint}\nregion = {$region}\nforce_path_style = true",
            ],
            's5cmd' => [
                'title' => 's5cmd',
                'hint' => 'Fast parallel transfers from the command line.',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}} AWS_SECRET_ACCESS_KEY={{SECRET_KEY}} AWS_REGION={$region} \\\n  s5cmd --endpoint-url {$endpoint} ls",
            ],
            'js' => [
                'title' => 'JavaScript',
                'hint' => 'AWS SDK v3 for Node.js (npm install @aws-sdk/client-s3). Keep secrets on the server, never in browser code.',
                'body' => "import { S3Client, ListObjectsV2Command } from \"@aws-sdk/client-s3\";\n\nconst s3 = new S3Client({\n  endpoint: \"{$endpoint}\",\n  region: \"{$region}\",\n  forcePathStyle: true,\n  credentials: {\n    accessKeyId: \"{{ACCESS_KEY}}\",\n    secretAccessKey: \"{{SECRET_KEY}}\",\n  },\n});\n\nconst result = await s3.send(new ListObjectsV2Command({ Bucket: \"your-bucket\" }));",
            ],
        ];
    }

    private function jsonForScript(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function layout(string $title, string $body, ?string $navCsrfToken): string
    {
        $groups = '';
        foreach (self::NAV as $heading => $links) {
            $items = $heading === '' ? '' : '<span class="nav-heading">' . $this->e($heading) . '</span>';
            foreach ($links as $href => $label) {
                $current = $label === $title ? ' aria-current="page"' : '';
                $items .= '<a class="nav-link" href="' . $href . '"' . $current . '>'
                    . '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . self::NAV_ICONS[$href] . '</svg>'
                    . $this->e($label) . '</a>';
            }
            $groups .= '<div class="nav-group">' . $items . '</div>';
        }
        $sidebar = '<aside class="sidebar"><a class="brand" href="/_"><span class="brand-mark" aria-hidden="true">S3</span>Mini S3</a>'
            . '<nav aria-label="Main">' . $groups . '</nav>'
            . '<div class="sidebar-foot"><span class="muted">Signed in</span>'
            . '<form method="post" action="/_/logout" class="plain nav-logout">' . $this->csrfField((string) $navCsrfToken) . '<button type="submit" class="link-button">Log out</button></form>'
            . '</div></aside>';

        return $this->document($title . ' · Mini S3', '<div class="shell">' . $sidebar
            . '<main class="content"><div class="page"><div class="page-head"><h1>' . $this->e($title) . '</h1></div>' . $body . '</div></main></div>');
    }

    /**
     * Centered single-card layout for pages shown before login (sign-in, setup).
     */
    private function authLayout(string $title, string $body, bool $wide, string $footnote = ''): string
    {
        return $this->document($title, '<div class="auth">'
            . '<div class="brand"><span class="brand-mark" aria-hidden="true">S3</span>Mini S3</div>'
            . '<main class="auth-card' . ($wide ? ' wide' : '') . '">' . $body . '</main>'
            . ($footnote === '' ? '' : '<p class="auth-foot">' . $this->e($footnote) . '</p>')
            . '</div>');
    }

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($title) . '</title>'
            . '<style>' . self::CSS . '</style>'
            . '<script defer src="' . $this->e(AdminAssets::scriptPath()) . '"></script>'
            . '</head><body>' . $body . '</body></html>';
    }

    private function filesBreadcrumbs(string $currentBucket, string $currentPrefix): string
    {
        if ($currentBucket === '') {
            return '<div class="breadcrumbs"><strong>All buckets</strong></div>';
        }

        $html = '<div class="breadcrumbs"><a href="/_/files">Buckets</a>';
        $html .= '<span>/</span><a href="/_/files?bucket=' . rawurlencode($currentBucket) . '">' . $this->e($currentBucket) . '</a>';
        if ($currentPrefix !== '') {
            $segments = explode('/', $currentPrefix);
            $path = '';
            foreach ($segments as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                $html .= '<span>/</span><a href="/_/files?bucket=' . rawurlencode($currentBucket) . '&prefix=' . rawurlencode($path) . '">' . $this->e($segment) . '</a>';
            }
        }

        return $html . '</div>';
    }

    private function bucketGrid(array $buckets): string
    {
        if ($buckets === []) {
            return '<div class="empty-state"><p><strong>No buckets yet.</strong></p>'
                . '<p class="muted">A bucket is a top-level folder for your objects. Click <em>New bucket</em> above, or let your app create one through the S3 API. Bucket names use lowercase letters, numbers, dots and hyphens (3 to 63 characters).</p></div>';
        }

        $html = '<div class="bucket-grid">';
        foreach ($buckets as $bucket) {
            $name = (string) ($bucket['name'] ?? '');
            $html .= '<section class="bucket-card" data-searchable="' . $this->e(strtolower($name)) . '">'
                . '<h3><a href="/_/files?bucket=' . rawurlencode($name) . '">' . $this->e($name) . '</a></h3>'
                . '<p class="bucket-meta"><span ' . $this->statAttrs(self::STATS_URL, 'bucket:' . $name, 'objects') . '>' . self::STATS_PENDING . '</span> objects</p>'
                . '<p class="bucket-meta" ' . $this->statAttrs(self::STATS_URL, 'bucket:' . $name, 'size') . '>' . self::STATS_PENDING . '</p>'
                . '<div class="row-actions">'
                . $this->iconButton(self::ICON_RENAME, 'Rename', 'data-action="rename-bucket" data-name="' . $this->e($name) . '"')
                . $this->iconButton(self::ICON_DELETE, 'Delete', 'data-action="delete-bucket" data-name="' . $this->e($name) . '"', 'danger')
                . '</div></section>';
        }

        return $html . '</div>';
    }

    private function bucketRail(array $buckets, string $currentBucket): string
    {
        if ($currentBucket === '' || $buckets === []) {
            return '';
        }
        $items = '';
        foreach ($buckets as $bucket) {
            $name = (string) ($bucket['name'] ?? '');
            $current = $name === $currentBucket ? ' aria-current="page"' : '';
            $items .= '<li><a href="/_/files?bucket=' . rawurlencode($name) . '"' . $current . '><span>' . $this->e($name) . '</span><span ' . $this->statAttrs(self::STATS_URL, 'bucket:' . $name, 'size') . '>' . self::STATS_PENDING . '</span></a></li>';
        }

        return '<nav class="bucket-rail" aria-label="Buckets"><span class="nav-heading">Buckets</span><ul>' . $items . '</ul></nav>';
    }

    private function objectTable(string $bucket, string $prefix, array $listing, string $summary): string
    {
        $folders = (array) ($listing['folders'] ?? []);
        $files = (array) ($listing['files'] ?? []);
        $folderStatsUrl = '/_/folder-stats?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($prefix);
        $html = '<div class="status-line muted" role="status" data-status>' . $this->e($summary) . '</div>';
        $html .= '<div class="selection-bar" data-selection-bar hidden><span data-selection-count></span>'
            . '<button type="button" class="danger" data-action="bulk-delete">Delete selected</button>'
            . '<button type="button" class="link-button" data-action="clear-selection">Clear</button></div>';
        $html .= '<section class="panel panel-flush"><div class="files-table-wrap"><table class="files-table"><thead><tr><th><input type="checkbox" data-select-all aria-label="Select all"></th><th>Name</th><th>Size</th><th>Modified</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';

        if ($prefix !== '') {
            $parent = dirname($prefix);
            $parent = $parent === '.' ? '' : $parent;
            $html .= '<tr><td></td><td colspan="4"><a href="/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($parent) . '" class="file-name"><span class="file-icon" aria-hidden="true">..</span>Up one level</a></td></tr>';
        }

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            $name = (string) ($folder['name'] ?? '');
            $folderUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($path);
            $html .= '<tr class="row-clickable" data-searchable="' . $this->e(strtolower($name)) . '" data-href="' . $this->e($folderUrl) . '">'
                . '<td data-stop><input type="checkbox" data-select value="' . $this->e($path) . '" aria-label="Select ' . $this->e($name) . '"></td>'
                . '<td><a href="' . $folderUrl . '" class="file-name"><span class="file-icon" aria-hidden="true">DIR</span>' . $this->e($name) . '/</a></td>'
                . '<td class="num" ' . $this->statAttrs($folderStatsUrl, $path, 'summary') . '>' . self::STATS_PENDING . '</td>'
                . '<td class="num">' . $this->e($this->formatDate((int) ($folder['modified'] ?? 0))) . '</td>'
                . '<td data-stop><div class="row-actions">'
                . $this->iconButton(self::ICON_RENAME, 'Rename', $this->objectAttrs('rename-object', $path, $name))
                . $this->iconButton(self::ICON_DELETE, 'Delete', $this->objectAttrs('delete-object', $path, $name), 'danger')
                . '</div></td></tr>';
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? '');
            $mime = (string) ($file['mime'] ?? 'file');
            $previewUrl = '';
            $icon = '<span class="file-icon" aria-hidden="true">' . $this->e($this->fileBadge($name)) . '</span>';
            if (!empty($file['is_image']) && $mime !== 'image/svg+xml') {
                $previewUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=0';
                $icon = '<span class="file-icon is-image" aria-hidden="true"><img class="preview-thumb" src="' . $previewUrl . '" alt="" loading="lazy"></span>';
            }
            $downloadUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=1';
            $details = $this->jsonForScript([
                'name' => $name,
                'path' => $path,
                'type' => $mime,
                'size' => $this->formatBytes((int) ($file['size'] ?? 0)) . ' (' . number_format((int) ($file['size'] ?? 0)) . ' bytes)',
                'modified' => $this->formatDate((int) ($file['modified'] ?? 0)),
                'preview' => $previewUrl,
                'download' => $downloadUrl,
            ]);
            $html .= '<tr class="row-clickable" data-searchable="' . $this->e(strtolower($name)) . '" data-details="' . $this->e($details) . '">'
                . '<td data-stop><input type="checkbox" data-select value="' . $this->e($path) . '" aria-label="Select ' . $this->e($name) . '"></td>'
                . '<td><button type="button" class="link-button file-name" data-action="details">' . $icon . $this->e($name) . '</button></td>'
                . '<td class="num">' . $this->e($this->formatBytes((int) ($file['size'] ?? 0))) . '</td>'
                . '<td class="num">' . $this->e($this->formatDate((int) ($file['modified'] ?? 0))) . '</td>'
                . '<td data-stop><div class="row-actions">'
                . $this->iconButton(self::ICON_DOWNLOAD, 'Download', 'href="' . $downloadUrl . '"', '', true)
                . $this->iconButton(self::ICON_RENAME, 'Rename', $this->objectAttrs('rename-object', $path, $name))
                . $this->iconButton(self::ICON_DELETE, 'Delete', $this->objectAttrs('delete-object', $path, $name), 'danger')
                . '</div></td></tr>';
        }

        if ($folders === [] && $files === []) {
            $html .= '<tr><td colspan="5" class="muted">This location is empty. Use <em>Upload file</em> or <em>New folder</em> above.</td></tr>';
        }

        return $html . '</tbody></table></div></section>';
    }

    private function fileBadge(string $name): string
    {
        $extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));

        return $extension === '' || strlen($extension) > 4 ? 'FILE' : $extension;
    }

    private function objectAttrs(string $action, string $path, string $name): string
    {
        return 'data-action="' . $action . '" data-path="' . $this->e($path) . '" data-name="' . $this->e($name) . '"';
    }

    private function fileDrawer(): string
    {
        return '<aside class="drawer" aria-label="File details" data-drawer hidden><div class="drawer-inner">'
            . '<div class="drawer-head"><h2 data-detail="name"></h2><button type="button" class="drawer-close" aria-label="Close details" data-action="close-details">&times;</button></div>'
            . '<img class="drawer-preview" data-detail-preview alt="" hidden>'
            . '<div class="drawer-actions"><a class="button" data-detail-download href="#">Download</a><button type="button" class="secondary" data-action="rename-object" data-detail-target>Rename</button></div>'
            . '<dl><dt>Type</dt><dd data-detail="type"></dd><dt>Size</dt><dd data-detail="size"></dd><dt>Modified</dt><dd data-detail="modified"></dd><dt>Key</dt><dd><code data-detail="path"></code></dd></dl>'
            . '<button type="button" class="danger-outline" data-action="delete-object" data-detail-target>Delete file&hellip;</button>'
            . '</div></aside>';
    }

    private function filesModal(): string
    {
        return '<dialog class="dialog-panel" aria-labelledby="dialog-title" data-dialog>'
            . '<button type="button" class="dialog-close" data-action="close-dialog" aria-label="Close">&times;</button>'
            . '<form method="dialog" class="plain">'
            . '<h2 id="dialog-title" data-dialog-title></h2>'
            . '<p class="muted" data-dialog-message></p>'
            . '<div class="dialog-error" role="alert" data-dialog-error hidden></div>'
            . '<label data-dialog-name-row>Name<input autocomplete="off" data-dialog-name></label>'
            . '<label data-dialog-file-row hidden>File<input type="file" data-dialog-file></label>'
            . '<div class="dialog-actions">'
            . '<button type="button" class="dialog-secondary" data-action="close-dialog">Cancel</button>'
            . '<button type="submit" data-dialog-submit>Confirm</button>'
            . '</div>'
            . '</form></dialog>';
    }

    private function flashMessage(string $message): string
    {
        if ($message === '') {
            return '';
        }
        if (str_starts_with($message, 'Error: ')) {
            return '<div class="error" role="alert">' . $this->e(substr($message, 7)) . '</div>';
        }

        return '<div class="notice" role="status">' . $this->e($message) . '</div>';
    }

    private function updatesPanel(array $status): string
    {
        if ($status === []) {
            return '';
        }

        $state = (string) ($status['state'] ?? 'unknown');
        $message = (string) ($status['message'] ?? 'Update status unavailable.');
        $current = $status['currentVersion'] ?? null;
        $latest = $status['latestVersion'] ?? null;
        $body = '<p class="muted">' . $this->e($message) . '</p>';
        if (is_string($current) && $current !== '') {
            $body .= '<p><strong>Current version:</strong> ' . $this->e($current) . '</p>';
        }
        if (is_string($latest) && $latest !== '') {
            $body .= '<p><strong>Latest version:</strong> ' . $this->e($latest) . '</p>';
        }
        $actions = '';
        if ($state !== 'unavailable') {
            $actions .= '<form method="post" action="/_/check-update" class="plain">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit" class="secondary">Check update</button>'
                . '</form>';
        }
        if ($state === 'update_available' && is_string($latest) && $latest !== '') {
            $actions .= '<form method="post" action="/_/upgrade" class="plain">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit">Upgrade to ' . $this->e($latest) . '</button>'
                . '</form>';
        }

        if ($actions !== '') {
            $body .= '<div class="update-actions">' . $actions . '</div>';
        }

        return '<div class="updates"><h3>Updates</h3>' . $body . '</div>';
    }

    /**
     * The text admin.js puts into the size placeholders of the dashboard and
     * the Files page, keyed like their data-stat-key attributes.
     *
     * @param array{object_count: int, total_bytes: int, buckets: list<array{name: string, object_count: int, total_bytes: int}>} $totals
     * @return array<string, array<string, string>>
     */
    public function statsFields(array $totals): array
    {
        $totalBytes = (int) $totals['total_bytes'];
        $fields = ['all' => ['summary' => $this->plural(count($totals['buckets']), 'bucket')
            . ' · ' . $this->plural((int) $totals['object_count'], 'object')
            . ' · ' . $this->formatBytes($totalBytes) . ' stored']];

        foreach ($totals['buckets'] as $bucket) {
            $bytes = (int) $bucket['total_bytes'];
            $share = $totalBytes > 0 ? (int) round($bytes / $totalBytes * 100) : 0;
            $fields['bucket:' . $bucket['name']] = [
                'objects' => number_format((int) $bucket['object_count']),
                'size' => $this->formatBytes($bytes),
                'share' => $share . '%',
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, array{object_count: int, total_bytes: int}> $folders keyed by folder path
     * @return array<string, array<string, string>>
     */
    public function folderStatsFields(array $folders): array
    {
        $fields = [];
        foreach ($folders as $path => $totals) {
            $fields[(string) $path] = ['summary' => $this->plural((int) $totals['object_count'], 'item') . ' · ' . $this->formatBytes((int) $totals['total_bytes'])];
        }

        return $fields;
    }

    /**
     * Attributes that mark an element as a placeholder: admin.js fetches $src
     * once per page and fills the element with that response's
     * [$key][$field]. A $style such as "width" sets that CSS property instead
     * of the text.
     */
    private function statAttrs(string $src, string $key, string $field, string $style = ''): string
    {
        return 'data-stat-src="' . $this->e($src) . '" data-stat-key="' . $this->e($key) . '" data-stat-field="' . $field . '"'
            . ($style === '' ? '' : ' data-stat-style="' . $style . '"');
    }

    private function plural(int $count, string $noun): string
    {
        return number_format($count) . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function formatDate(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
    }

    private function iconButton(string $icon, string $label, string $attrs, string $class = '', bool $asLink = false): string
    {
        $class = trim('icon-btn ' . $class);
        $tag = $asLink ? 'a' : 'button';
        $type = $asLink ? '' : ' type="button"';
        return '<' . $tag . $type . ' class="' . $this->e($class) . '" data-tooltip="' . $this->e($label) . '" aria-label="' . $this->e($label) . '" ' . $attrs . '>' . $icon . '</' . $tag . '>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
