# Mini S3 UI/UX Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign Mini S3's admin interface (installer, login, dashboard, and config screens) using a warm, clean editorial minimalist aesthetic with modern typography, pastel semantic statuses, and interactive scale/transition transitions.

**Architecture:** We will modify the single layout renderer (`src/Admin/AdminRenderer.php`) to introduce modern styles, custom styling variables, transition effects, clear focus rings, and replace the dark headers and contrast areas with clean, document-inspired design tokens. No framework changes are needed.

**Tech Stack:** PHP, Tailwind-less Semantic HTML & Custom CSS.

---

### Task 1: Refactor Main CSS Stylesheet in AdminRenderer

**Files:**
- Modify: `src/Admin/AdminRenderer.php`
- Test: `tests/unit/admin-renderer.php` (if it exists, we will run the PHP linting and unit tests to ensure no syntax/compilation issues are introduced)

- [ ] **Step 1: Replace layout method CSS rules**
Modify the `<style>` tag within the `layout()` method of `src/Admin/AdminRenderer.php`. Use the following exact style content to upgrade fonts, colors, transitions, and layout padding.

Replace the old `<style>` string:
```php
. '<style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f7f7f8;color:#17202a}header{background:#101827;color:white;padding:16px 20px;display:flex;gap:20px;align-items:center;justify-content:space-between;flex-wrap:wrap}header a{color:white;text-decoration:none;margin-right:14px}main{max-width:920px;margin:24px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}.card,.panel,form{background:white;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}label{display:block;margin:14px 0;font-weight:600}input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px}input[type=checkbox]{width:auto}button{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;font-weight:700}.error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}.notice{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}code{word-break:break-all}pre{background:#0f172a;color:#e2e8f0;border-radius:10px;overflow:auto;padding:14px;white-space:pre-wrap}.snippet-actions{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}.hidden{display:none}</style>'
```

With the new styles:
```php
. '<style>'
            . 'body { font-family: "SF Pro Display", "Geist Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #FBFBFA; color: #111111; -webkit-font-smoothing: antialiased; }'
            . 'header { background: #FFFFFF; border-bottom: 1px solid #EAEAEA; padding: 18px 24px; display: flex; gap: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; }'
            . 'header strong { font-size: 16px; font-weight: 600; color: #111111; letter-spacing: -0.01em; }'
            . 'header nav { display: flex; gap: 18px; }'
            . 'header a { color: #787774; text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s ease, text-decoration 0.2s ease; }'
            . 'header a:hover { color: #111111; text-decoration: underline; text-underline-offset: 4px; }'
            . 'main { max-width: 920px; margin: 32px auto; padding: 0 24px; }'
            . 'h1 { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 24px; color: #111111; }'
            . 'h2 { font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 14px; color: #111111; }'
            . '.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }'
            . '.card, .panel, form { background: #FFFFFF; border: 1px solid #EAEAEA; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: none; }'
            . '.card { margin-bottom: 0; display: flex; flex-direction: column-reverse; justify-content: flex-end; gap: 4px; }'
            . '.card h2 { font-size: 32px; font-weight: 600; margin: 0; line-height: 1; letter-spacing: -0.02em; color: #111111; }'
            . '.card p { font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; margin: 0; }'
            . 'label { display: block; margin: 18px 0 6px 0; font-size: 14px; font-weight: 500; color: #111111; }'
            . 'input, select { box-sizing: border-box; width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid #EAEAEA; border-radius: 6px; font-family: inherit; font-size: 14px; color: #111111; background: #FFFFFF; transition: border-color 0.2s ease, box-shadow 0.2s ease; }'
            . 'input:focus, select:focus { border-color: #111111; outline: none; box-shadow: 0 0 0 1px #111111; }'
            . 'input[type=checkbox] { width: auto; margin-top: 0; margin-right: 8px; vertical-align: middle; }'
            . 'button { background: #111111; color: #FFFFFF; border: 0; border-radius: 6px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s ease, transform 0.1s ease; }'
            . 'button:hover { background: #333333; }'
            . 'button:active { transform: scale(0.98); }'
            . '.error { background: #FDEBEC; border: 1px solid #FDEBEC; color: #9F2F2D; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }'
            . '.notice { background: #EDF3EC; border: 1px solid #EDF3EC; color: #346538; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }'
            . 'code { font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; color: #787774; word-break: break-all; }'
            . 'pre { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; border-radius: 6px; overflow: auto; padding: 16px; font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; line-height: 1.6; white-space: pre-wrap; margin: 12px 0; }'
            . '.snippet-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0; }'
            . '.snippet-actions button { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 12px; }'
            . '.snippet-actions button:hover { background: #EAEAEA; }'
            . 'details { margin-top: 18px; border-top: 1px solid #EAEAEA; padding-top: 12px; }'
            . 'details summary { font-weight: 600; cursor: pointer; color: #111111; font-size: 14px; outline: none; }'
            . '.hidden { display: none; }'
            . '</style>'
```

- [ ] **Step 2: Run linter/tests to check syntax correctness**
Run: `php -l src/Admin/AdminRenderer.php`
Expected: `No syntax errors detected in src/Admin/AdminRenderer.php`

- [ ] **Step 3: Run integration/unit tests**
Run: `php tests/unit/admin-renderer.php`
Expected: Tests pass successfully.

- [ ] **Step 4: Commit**
```bash
git add src/Admin/AdminRenderer.php
git commit -m "style(admin): refactor main stylesheet to premium minimalist theme"
```

---

### Task 2: Refine Code Snippets and Credentials Display

**Files:**
- Modify: `src/Admin/AdminRenderer.php`
- Test: `tests/unit/admin-renderer.php`

- [ ] **Step 1: Check updatesPanel and connectionConfig structure**
Review the connectionConfig method markup in `src/Admin/AdminRenderer.php`. 
Ensure the heading and tags use the new styles. We should make sure the snippet buttons match the minimal aesthetic.

- [ ] **Step 2: Run integration tests and check compilation**
Run: `php -l src/Admin/AdminRenderer.php`
Expected: `No syntax errors detected in src/Admin/AdminRenderer.php`

- [ ] **Step 3: Run full php unit test suite**
Run: `php tests/unit/admin-renderer.php`
Expected: Success

- [ ] **Step 4: Commit**
```bash
git add src/Admin/AdminRenderer.php
git commit -m "style(admin): update snippets and layouts in dashboard renderer"
```
