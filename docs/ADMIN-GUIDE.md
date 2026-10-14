# Urban Emirates — Admin guide (daily use)

Everything is in WordPress admin under **Urban Emirates** (left menu).

## Your daily routine (15–20 minutes)

### 1. Collect jobs
Open good Gulf jobs on LinkedIn, Naukri Gulf, Bayt, GulfTalent, company career pages or WhatsApp forwards. For each job copy:
- the **link** (from the address bar), and
- the **job description** (select all the text and copy).

### 2. Paste them — Urban Emirates → Add Jobs (paste)
Paste the link and the description. For more jobs, put a line with `---` between them. Up to 30 at once. Press **Check with Claude & add to review**.

Keep the page open. Claude reads each job, fills in the details (title, company, country, salary, visa/accommodation, requirements) and rates it:

| Rating | Meaning |
|---|---|
| 🟢 Looks safe | Named employer, realistic pay, no fees |
| 🟡 Check carefully | Something is missing (company, salary) or unclear |
| 🔴 Likely scam | Asks for money, personal WhatsApp only, unrealistic pay… |

If you close the page, the rest are processed in the background within a few minutes.

### 3. Review — Urban Emirates → Review
- **Publish all green jobs** in one click, or publish one by one.
- For yellow jobs, read "Why" and "Missing". Click **Edit** to fix details, then publish — or **Reject**.
- Reject red jobs unless you have personally checked the employer.
- **Queue & problems** tab: jobs that failed (e.g. text was not a job). Try again or delete.

Duplicates (same link, or same title + company + country) are skipped automatically.

### 4. Share — Urban Emirates → WhatsApp Post
A ready message with today's new jobs. Press **Copy message** and paste it into your WhatsApp Channel and groups.

## Automatic things (no work for you)
- **Every morning** at the time set in Settings: job-alert subscribers and signed-in users who asked for job emails get new matching jobs.
- **Every day**: jobs past their deadline, or older than 30 days (Settings), are hidden from the site.
- **Every 5 minutes**: unfinished job checks continue; emails go out in small batches.

## Other screens
- **All Jobs** — every job. Edit a job's details in the *Job details* box under the summary.
- **Subscribers** — email-only alert sign-ups (people with accounts are managed in Users).
- **Settings** — Claude key and model, AI limit for the CV builder, auto-publish, job expiry, email sending, Turnstile, WhatsApp link, ads.

## Users
WordPress → **Users** lists job seekers (role *Job seeker*). They sign in with an email code and can download or delete their own data from My account. Staff (you, editors) sign in with a password at `/wp-admin`. To give a volunteer access to add and review jobs, create a user with the **Editor** role.

## Costs to watch
- Claude: check usage at console.anthropic.com. To spend less, switch the model to Sonnet or Haiku in Settings, or lower the CV AI limit.
- The dashboard numbers at the top of **Review** show live jobs, jobs to review, apply clicks and subscribers.

## If something looks wrong
- **Claude errors** → Settings → Run test. Check the API key and that your Anthropic account has credit.
- **Emails not arriving** → Settings → Send test email. Check the mailbox password and SPF/DKIM in hPanel.
- **Jobs not processing in the background** → set up the Cron Job (Setup guide step 7).
