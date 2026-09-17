<?php
/**
 * Bulk Mailer - Configuration
 * Edit the values below before using the app.
 */

// ---- SMTP settings (used by PHPMailer to actually send mail) ----
define('SMTP_HOST', 'smtp.gmail.com');   // e.g. smtp.gmail.com, smtp.mailgun.org, smtp.sendgrid.net
define('SMTP_PORT', 587);                        // 587 = TLS, 465 = SSL
define('SMTP_ENCRYPTION', 'tls');                 // 'tls' or 'ssl'
define('SMTP_USERNAME', 'chiamakachidera8@gmail.com');
define('SMTP_PASSWORD', 'vjrf vtwq icjq uohn');

// ---- Default "From" identity ----
define('MAIL_FROM_EMAIL', 'chiamakachidera8@gmail.com');
define('MAIL_FROM_NAME', 'Your Company');
define('MAIL_REPLY_TO', 'chiamakachidera8@gmail.com');

// ---- Sending throttling (important: avoid provider rate limits / spam flags) ----
define('BATCH_SIZE', 20);          // emails sent per batch request
define('BATCH_DELAY_MS', 0);       // extra delay (ms) between individual sends inside a batch (0 = none)

// ---- Campaign attachments ----
define('MAX_ATTACHMENTS', 5);                          // max files per campaign
define('MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);       // max size per file (bytes; 10 MB)
define('ATTACHMENT_MIME_WHITELIST', 'pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,jpg,jpeg,png,gif'); // allowed extensions

// ---- Security ----
// Random secret used to sign unsubscribe tokens. CHANGE THIS to your own random string.
define('APP_SECRET', 'change-this-to-a-long-random-string-1234567890');

// Simple login for the admin UI (change these!)
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'change-this-password');

// ---- Paths ----
define('BASE_PATH', __DIR__);
define('DB_PATH', BASE_PATH . '/data/bulkmailer.db');
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// Public base URL of this app (used to build unsubscribe / tracking links).
// Set this to where you deploy the app, e.g. https://mail.yourdomain.com/bulkmailer
define('APP_URL', 'http://localhost:8080');

date_default_timezone_set('UTC');
