# tapao/auto-ai-moderation

OpenAI-powered auto-moderation for Flarum. Scans posts, discussion titles, usernames, avatars, uploads, bios, and polls using the OpenAI Moderation API (`omni-moderation-latest`).

## Features

- **Text + Image moderation** in a single API call (`omni-moderation-latest`)
- **12 harm categories** with per-category thresholds and configurable actions (flag / hide / delete / warn)
- **Async queue mode** — dispatch to Laravel queue for high-traffic forums
- **Trust score system** — high-trust users skip moderation; low-trust users always get synchronous checks
- **Admin review queue** — approve or reject flagged items from the admin panel
- **Bridge extensions** — integrates with flarum/flags, fof/polls, fof/user-bio, fof/upload, blomstra/flarum-ext-upload, fof/profile-image-crop
- **Webhook notifications** — POST JSON to any endpoint on every flagged item
- **Retrospective scan** — Artisan command to scan existing content

## Requirements

- Flarum >= 1.8
- PHP >= 8.0
- OpenAI API key

## Installation

```bash
composer require tapao/auto-ai-moderation
php flarum migrate
php flarum assets:publish
```

No Node.js required for installation — the compiled JS is included in the package.

## Development (frontend changes only)

```bash
cd js
npm install
npm run dev    # watch mode
npm run build  # production build — commit js/dist/admin.js afterward
npm test       # verify the distributed admin bundle loads on Flarum 2
```

The Flarum 2 frontend requires `flarum-webpack-config` 3.x, which resolves core
imports through `flarum.reg`. The 2.x compiler emits `flarum.core.compat` imports
and prevents the settings page from loading on Flarum 2. After frontend changes,
include the rebuilt `js/dist/admin.js` and `js/dist/admin.js.map` in the package.

After deploying an updated extension, run `php flarum cache:clear` from the forum
directory and hard-refresh the admin panel to discard cached JavaScript.

## Configuration

Go to **Admin → Extensions → ModerationAI** and enter your OpenAI API key.

### Artisan scan

```bash
php flarum moderationai:scan --type=post --limit=500 --since=2024-01-01
```

## License

MIT
