# Affiliate IG Automation

A standalone PHP 8 + SQLite admin tool for creating, reviewing, rendering, and publishing affiliate-marketing Instagram Reel/post drafts. It is intentionally approval-first: generated content is saved as drafts, must be manually reviewed/approved, then rendered and published only when ready.

## Features

- Login-protected mobile-friendly admin UI with `noindex,nofollow` metadata.
- SQLite schema and first-admin setup in `install.php`.
- Encrypted-at-rest API settings when PHP Sodium or OpenSSL is available.
- Product, campaign, and prompt-template management.
- OpenAI draft generation with compliance guardrails and affiliate disclosure defaults.
- Pexels-first / Pixabay-fallback stock video sourcing.
- FFmpeg vertical 1080x1920 rendering with readable hook and CTA overlays.
- Safe Meta Graph API publishing flow for approved + rendered drafts.
- Cron-friendly generation, rendering, and publishing entry points.

## Requirements

- PHP 8.1+ with PDO SQLite, cURL, JSON, mbstring, and Sodium or OpenSSL recommended.
- SQLite 3.
- FFmpeg available on the server path, or set `FFMPEG_BIN` in the environment.
- A web server capable of serving PHP files.
- For Instagram publishing, rendered files must be reachable through public HTTPS. Set `PUBLIC_STORAGE_URL` to the public URL that maps to this app's `/storage` directory.

## Setup

1. Upload the repository to your server.
2. Ensure these directories are writable by PHP:
   - `data/`
   - `storage/videos/`
   - `storage/renders/`
   - `storage/logs/`
3. Visit `/install.php` and create the first administrator.
4. Log in and open **Settings**.
5. Add API credentials as needed:
   - OpenAI API key and model.
   - Pexels API key.
   - Pixabay API key.
   - Instagram app/client credentials, business account/page ID, and access token.
6. Create at least one active product and one active campaign.
7. Generate drafts from the Dashboard or via cron.

No fake API keys are included. Saved secrets are never displayed in full after saving.

## Cron examples

```bash
php /path/to/app/cron-generate.php
php /path/to/app/cron-render.php
php /path/to/app/cron-publish.php
```

Recommended schedule:

- `cron-generate.php`: every 1-4 hours, respecting the daily draft limit.
- `cron-render.php`: every 10-30 minutes if you approve drafts frequently.
- `cron-publish.php`: only after you have confirmed Instagram setup and explicitly enabled auto-publish.

## Publishing safety

- Auto-publish defaults to **off**.
- Drafts must be approved before rendering/publishing.
- Captions include affiliate disclosure by default.
- Prompt instructions tell the model not to invent product guarantees or medical, legal, financial, or earnings claims.
- Each product has a configurable disclaimer field.
- Review every generated draft before approval.

## Instagram / Meta notes

The publisher uses the Meta Graph API media container flow for Reels. Meta access tokens expire; add a proper token refresh/rotation process for your account type before depending on unattended publishing. If credentials are missing, workers log a setup-needed warning instead of crashing.

## Security hardening

- Move `data/` outside the web root if your hosting allows it, and adjust `_config.php` paths.
- The included `.htaccess` files deny access on Apache, but server-level protection is preferred.
- Serve the admin over HTTPS only.
- Restrict access by IP or VPN where practical.
- Back up `data/app.sqlite` and `data/app.key` together; encrypted secrets cannot be decrypted without the key file.
