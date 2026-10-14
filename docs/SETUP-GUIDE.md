# Urban Emirates — Setup guide (Hostinger + WordPress)

This gets **urbanemirates.com** live. Do the steps in order. Things only you can do (passwords, payments, API keys) are marked **🔑 You**.

You need two files from the `dist` folder of this project:

- `urbanemirates-theme.zip` — the look of the site
- `urbanemirates-jobs.zip` — all the features (jobs, CV builder, accounts, emails)

Never upload anything from the `dev` folder. It is only for testing on a computer.

---

## 1. Install WordPress on urbanemirates.com

1. Log in to **hPanel** (hostinger.com).
2. **Websites → Add website → WordPress**. Choose the domain **urbanemirates.com**.
3. **🔑 You:** create the WordPress admin username and a strong password. Save them in your password manager.
4. If Hostinger offers to install extra plugins or a theme, skip them or remove them afterwards. We use only our own theme and plugin.
5. In hPanel → **Security → SSL**, make sure SSL is **Active** for urbanemirates.com (so the site opens with `https://`).
6. In hPanel → **Advanced → PHP Configuration**, choose **PHP 8.2 or 8.3**.

## 2. Upload our theme and plugin

1. Open **https://urbanemirates.com/wp-admin** and log in.
2. **Appearance → Themes → Add New Theme → Upload Theme** → choose `urbanemirates-theme.zip` → **Install Now** → **Activate**.
3. **Plugins → Add New Plugin → Upload Plugin** → choose `urbanemirates-jobs.zip` → **Install Now** → **Activate Plugin**.
4. Delete any other plugins you don't need (Plugins → select → Delete). Hello Dolly and Akismet are not needed.

Activating the plugin automatically creates these pages: **Jobs, Job Alerts, Job Scam Safety, Sign in, My account, CV builder, Privacy Policy, Terms of Use**.

## 3. Basic WordPress settings

1. **Settings → General**: Site Title `Urban Emirates`, Tagline `Checked Gulf jobs. Free CV builder.`, Timezone **Kolkata** (or **Dubai** — the daily emails use this time). Leave "Anyone can register" **unticked** (our sign-in creates job-seeker accounts by itself).
2. **Settings → Reading**: Your homepage displays → **Your latest posts** (our theme draws the home page itself).
3. **Settings → Permalinks**: choose **Post name** → Save. (Needed for links like `/job/staff-nurse/`.)

## 4. Connect Claude (the AI)

1. **🔑 You:** go to **console.anthropic.com**, sign in, add credit, and set a **monthly spend limit** (Settings → Limits). Start with something like $30–50.
2. **🔑 You:** create an API key (Settings → API keys → Create key). Copy it.
3. In WordPress: **Urban Emirates → Settings** → paste the key into **Claude API key** → **Save Changes**.
4. Scroll down and press **Run test**. You should see "✅ Claude is connected and working."

**Model choice (Settings):** *Claude Opus 5.5* gives the most careful scam checks. *Claude Sonnet 5.5* or *Claude Haiku 5.5* cost much less. Rough costs with Opus at medium effort: about 3–5 US cents per job checked and about 2 cents per CV AI action. Each person can use AI help 20 times a day (you can change this in Settings).

## 5. Email that reaches the inbox

Sign-in codes must arrive quickly, so send them from a real mailbox:

1. **🔑 You:** in hPanel → **Emails**, create a mailbox such as `hello@urbanemirates.com` (Hostinger plans usually include one free).
2. In **Urban Emirates → Settings → Email sending**:
   - SMTP host: `smtp.hostinger.com`
   - Port: `465 (SSL)`
   - Mailbox: `hello@urbanemirates.com`
   - **🔑 You:** Mailbox password
   - Contact email: the address people can write to about privacy (can be the same)
3. **Save Changes**, then press **Send test email** and check your inbox.
4. In hPanel → **Emails → (your mailbox) → DNS / Email deliverability**, make sure **SPF, DKIM and DMARC** are set up (Hostinger has a one-click fix). This keeps emails out of spam.

## 6. Bot protection (Cloudflare Turnstile, free)

1. **🔑 You:** create a free account at **dash.cloudflare.com**.
2. **Turnstile → Add site** → name `Urban Emirates`, domain `urbanemirates.com`, mode **Managed** → Create.
3. Copy the **Site key** and **Secret key** into **Urban Emirates → Settings → Bot protection** → Save.

Until you do this, forms are still protected by a hidden trap field and rate limits.

## 7. Make background jobs reliable (recommended)

WordPress runs scheduled tasks (job checking, daily emails, removing old jobs) only when someone visits the site. To make them run every 5 minutes even at night:

1. In hPanel → **Advanced → Cron Jobs**, add a new cron job:
   - Type: **PHP** (or "Custom")
   - Command: `wp-cron.php` in your site folder, e.g. `/home/USERNAME/domains/urbanemirates.com/public_html/wp-cron.php` (hPanel shows your exact path)
   - Every **5 minutes**
2. Optional: in hPanel → **File Manager**, open `public_html/wp-config.php` and add this line above `/* That's all, stop editing! */`:
   `define( 'DISABLE_WP_CRON', true );`

## 8. Menus, WhatsApp and first jobs

1. The header and footer menus work automatically. To change them: **Appearance → Menus** (create a menu and set its location to *Main menu* or *Footer menu*).
2. **🔑 You:** create a **WhatsApp Channel** on your phone (Updates tab → Channels → Create channel). Copy its link into **Urban Emirates → Settings → WhatsApp Channel link**.
3. Add your first jobs: see `ADMIN-GUIDE.md`.

## 9. Before you tell people about the site

- [ ] Run **Claude test** and **Send test email** — both green.
- [ ] Open the site on your phone: home, a job, Apply, Sign in, build a CV, download PDF and Word.
- [ ] Sign up for job alerts with your own email and confirm the link works.
- [ ] Read **Privacy Policy** and **Terms of Use** (Pages → edit). **Have a lawyer review them.**
- [ ] Add at least 20–30 real jobs so the site doesn't look empty.

## Later: Google AdSense

When the site has real content and visitors, apply at **adsense.google.com**. After approval, paste ad unit codes into **Urban Emirates → Settings → Ads**. In AdSense → *Blocking controls*, block categories such as job agencies, visa services, immigration and "get rich quick" so scam ads can't appear on a scam-free job site. Update the Privacy Policy (Advertising section) before ads go live.

## Keeping secrets safer (optional)

Instead of pasting keys into Settings, you can add them to `wp-config.php` (File Manager), above the "stop editing" line:

```php
define( 'KJ_CLAUDE_API_KEY', 'sk-ant-...' );
define( 'KJ_SMTP_PASSWORD', 'your-mailbox-password' );
```

The Settings page then shows "Set in wp-config.php".
