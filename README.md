# Bulk Mailer

A self-hosted bulk email application built in PHP with PHPMailer — contact
management, list segmentation, campaign composition with merge tags, a
throttled sending engine, and one-click unsubscribe handling. Think of it as
a lightweight, self-hosted alternative to Gammadyne Mailer that runs on any
PHP web host.

## Features

- **Contacts** — add manually or import in bulk from CSV (with column mapping)
- **Lists** — segment contacts into groups and target campaigns to specific segments
- **Campaigns** — compose subject + HTML body with merge tags:
  `{name}`, `{email}`, `{unsubscribe_link}`, and any custom field from your CSV
- **Test sends** — send a preview to yourself before launching to your whole list
- **Throttled batch sending** — sends in small batches (default 20 at a time)
  with a live progress bar, so you don't hit your SMTP provider's rate limits
  or time out the web server on large lists. Pause/resume anytime.
- **One-click unsubscribe** — signed, tamper-proof links baked into every email;
  no login required for recipients to unsubscribe
- **Login-protected admin area** with CSRF protection on every form
- **SQLite storage** — zero database server setup; the app creates its own
  `data/bulkmailer.db` file on first run

## Requirements

- PHP 7.4+ (PHP 8.x recommended) with the `pdo_sqlite`, `openssl`, and
  `filter` extensions (all standard on virtually every host)
- An SMTP account to send through (your own mail server, or a transactional
  provider like Mailgun, SendGrid, Amazon SES, Postmark, or even Gmail's
  SMTP for small volumes)
- Any web server (Apache, Nginx, or PHP's built-in server for local testing)

## Setup

1. **Upload the whole `bulkmailer/` folder** to your web host (or a
   subdirectory of it).

2. **Edit `config.php`** and fill in:
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`
     — your SMTP provider's credentials
   - `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`
   - `APP_SECRET` — replace with your own long random string (used to sign
     unsubscribe links so they can't be forged)
   - `ADMIN_USER`, `ADMIN_PASS` — change from the defaults before going live
   - `APP_URL` — the public URL where you deployed this app (used to build
     unsubscribe links, e.g. `https://mail.yourdomain.com/bulkmailer`)
   - `BATCH_SIZE` / `BATCH_DELAY_MS` — tune based on your SMTP provider's
     rate limits (see "Throttling" below)

3. **Make sure `data/` and `uploads/` are writable** by the web server
   (e.g. `chmod 755 data uploads`, or `775` if your host requires a group
   write permission).

4. **Visit the app in your browser** and log in with the admin credentials
   you set in `config.php`. The SQLite database and tables are created
   automatically on first load — nothing else to run.

5. **Add contacts** (manually or via CSV import), **create a list**, then
   **create a campaign** and send a test email to yourself before launching.

## Throttling & deliverability

Sending hundreds or thousands of emails at once can get your sending IP or
domain flagged as spam, and most SMTP providers cap how many emails you can
send per second/minute/hour. This app protects you by:

- Queuing every recipient as a `pending` row when you click "Start Sending"
- Processing only `BATCH_SIZE` emails per request (default 20)
- The campaign page polls a batch endpoint every ~1.2 seconds via JavaScript
  until the queue is empty, updating a live progress bar

**Tune `BATCH_SIZE` and `BATCH_DELAY_MS` in `config.php`** to match your
provider's limits. As a rule of thumb, check your SMTP provider's
documentation for their per-minute/per-hour sending limits and set values
comfortably below that ceiling.

### Sending from a cron job instead (recommended for very large lists)

The browser-polling approach requires the campaign page to stay open. For
lists in the tens of thousands, or to send reliably without keeping a
browser tab open, you can instead drive sending from a cron job:

1. Click "Start Sending" in the UI as usual (this queues the contacts).
2. Set up a cron entry that calls the batch script directly on a schedule, e.g.:

   ```
   * * * * * /usr/bin/php /path/to/bulkmailer/send_batch.php <campaign_id> >> /path/to/bulkmailer/send.log 2>&1
   ```

   Replace `<campaign_id>` with the numeric ID shown in the campaign's URL
   (`campaign_view.php?id=5` → use `5`). This runs once per minute and sends
   one batch each time; increase `BATCH_SIZE` if you want more per run.
3. You can still open the campaign page in a browser to watch progress —
   the polling script and the cron job both operate on the same queue safely.

## Merge tags

Use these anywhere in your subject line or email body:

| Tag | Renders as |
|---|---|
| `{name}` | The contact's name |
| `{email}` | The contact's email address |
| `{unsubscribe_link}` | A signed, one-click unsubscribe URL unique to that contact |
| `{anything_else}` | Any custom field you included as a column when importing your CSV |

**Always include `{unsubscribe_link}` in your footer.** It's required by
anti-spam law in most jurisdictions (CAN-SPAM, GDPR, etc.) and keeps your
sender reputation healthy with mailbox providers.

## CSV import format

Any CSV with a header row works. On upload, you'll be shown a preview and
asked which column is the email address and (optionally) which is the name.
Any additional columns aren't currently auto-imported as custom merge
fields — if you need per-contact custom fields beyond name/email, they can
be added by extending the `custom_fields` JSON column in `contacts` (see
"Extending" below).

## Security notes

- Change `ADMIN_USER`, `ADMIN_PASS`, and especially `APP_SECRET` before
  deploying — the defaults are placeholders only.
- The `data/`, `uploads/`, `includes/`, and `lib/` folders each ship with an
  `.htaccess` denying direct web access (Apache). If you're on Nginx or another
  server, add equivalent rules to block direct requests to those paths and to
  `*.db` files, since `data/bulkmailer.db` contains your contacts' email addresses.
- All admin forms are protected with CSRF tokens.
- Unsubscribe links are HMAC-signed with `APP_SECRET` so they can't be
  guessed or forged to unsubscribe other people's addresses.

## Extending

The codebase is intentionally simple and readable so you can adapt it:

- **Open/click tracking**: add a `tracking_pixel.php` that logs a hit and
  returns a 1x1 GIF, then insert `<img src="...">` into `render_template()`'s
  output; add a `link_click.php` redirect wrapper for click tracking.
- **Bounce handling**: most SMTP providers offer webhooks for bounces/complaints;
  add an endpoint that receives those and sets `contacts.status = 'bounced'`.
- **Scheduled sends**: add a `scheduled_at` column to `campaigns` and a cron
  script that flips `draft` → `sending` when the time arrives.
- **Multiple templates**: add a `templates` table and a picker in
  `campaign_new.php`.

## File structure

```
bulkmailer/
├── config.php              # SMTP + app settings — EDIT THIS
├── index.php                # Dashboard
├── login.php / logout.php   # Admin auth
├── contacts.php             # Contact list, add, delete, search/filter
├── import_contacts.php      # CSV upload + column mapping + import
├── lists.php                # List management
├── campaigns.php             # All campaigns
├── campaign_new.php         # Compose a new campaign + test send
├── campaign_view.php        # Campaign detail, start/pause/resume, live progress
├── send_batch.php           # Sends one batch (called by AJAX or cron)
├── unsubscribe.php          # Public unsubscribe landing page
├── includes/                # Shared PHP (db, auth, helpers, mailer, layout)
├── lib/PHPMailer/            # PHPMailer library (bundled, MIT licensed)
├── assets/style.css          # Styling
├── data/                    # SQLite database lives here (auto-created)
└── uploads/                 # Temporary CSV upload storage
```

## License

This app's own code is provided as-is for your use. PHPMailer is bundled
under its MIT license (see `lib/PHPMailer/` — sourced from the official
PHPMailer/PHPMailer GitHub repository, v6.9.1).
