# Urban Emirates (urbanemirates.com)

Checked Gulf jobs. Free CV builder. A job site for the Konkani community, built as our own WordPress theme and plugin.

| Folder | What it is |
|---|---|
| `urbanemirates-jobs/` | The plugin: job paste → Claude scam check → review, search, job pages, alerts, accounts (email-code sign in), CV builder with AI help and PDF/Word downloads, matched job emails, settings |
| `urbanemirates-theme/` | The theme: header, footer, home page, page templates (Direction D design) |
| `docs/` | `SETUP-GUIDE.md` (go live on Hostinger) and `ADMIN-GUIDE.md` (daily use) |
| `dev/` | Local testing only (WordPress Playground blueprint, fake Claude answers, `smoke-test.sh`). Never upload. |
| `dist/` | Upload-ready zips, built from the two folders above (not in git) |

Local test site: start `wp-local` (PHP 7.4) or `wp-local-83` from `.claude/launch.json`, then `bash dev/smoke-test.sh http://127.0.0.1:9400`.
