# AI4D Topics — WordPress Plugin

WordPress plugin that calls the AI4D topic-extraction backend on article publish, stores topics in post meta, and renders them on the public article page.

**Spec:** see `docs/superpowers/specs/2026-05-24-wp-plugin-topic-extraction-design.md` in the `ai4deliberation-backend` repo.

## Requirements

- WordPress ≥ 5.0
- PHP ≥ 7.4
- A reachable AI4D backend exposing `POST /api/topics/extract-conversation` and `GET /api/topics`.

## Install (production)

1. Build a zip of the plugin folder: `bash scripts/build-zip.sh` (or `git archive --format=zip -o ai4d-topics.zip HEAD`).
2. In WP admin: Plugins → Add New → Upload Plugin → choose the zip → activate.
3. Go to Settings → AI4D Topics and configure:
   - Backend URL (e.g. https://api.example.com)
   - Post type (default: `post`)
   - Include tag (leave empty unless articles have a specific tag)
   - Exclude tags (default: `deliberation`)
   - Display shape, top-N, position, header (defaults are sensible)
4. **Optional but recommended for first run on a new site:** enable Dry-run mode, publish a test article, check `Tools → AI4D Topics Log` to verify the plugin would have processed it correctly, then disable Dry-run.

## Development setup

This project runs entirely inside Docker — no need to install PHP, Composer, or MySQL on the host machine.

**Prerequisites:** Docker + Docker Compose.

```bash
git clone <repo-url> ~/Documents/konnekt/ai4d-topics-wp
cd ~/Documents/konnekt/ai4d-topics-wp
make install            # Build PHP container + install Composer deps (one-time)
make install-wp-tests   # Set up WP test framework (one-time)
make test               # Run the test suite
make shell              # Open a bash shell inside the PHP container
make down               # Stop containers
make clean              # Nuke everything (containers, volumes, vendor/)
```

For interactive in-browser testing (separate from the test suite), `@wordpress/env` is an option but is not required:

```bash
npm install -g @wordpress/env
echo '{"core": "WordPress/WordPress#trunk", "plugins": ["."]}' > .wp-env.json
wp-env start
```

## Smoke-test checklist

Run on every fresh deployment:

- [ ] Activate the plugin in WP admin; no PHP warnings appear.
- [ ] Open Settings → AI4D Topics; defaults populate; backend URL field is empty by default.
- [ ] Set backend URL and save.
- [ ] Publish a test article (matching post type, no excluded tags).
  - Check the article edit screen metabox — should show topics within 1-3 seconds.
  - Check the public article page — topics appear at the configured position.
  - Check `Tools → AI4D Topics Log` — a `success` event for the post.
- [ ] Simulate backend unavailability:
  - Block outbound network or use a non-existent backend URL.
  - Publish another article.
  - Verify article page still renders cleanly (no topics block, no errors).
  - Check the article edit screen — metabox shows the failure reason.
  - Check `Tools → AI4D Topics Log` — a `failure` event.
- [ ] Click "Re-extract topics" on a previously-failed article (after restoring backend) — verify it succeeds.
- [ ] Deactivate the plugin — public article pages still load; topic blocks vanish; post meta is preserved.
- [ ] Reactivate — previously extracted topics re-appear on public pages.
- [ ] Uninstall (Plugins → Delete) with default `keep_meta` mode — plugin folder removed; post meta preserved.

## Configuration reference

| Setting | Default | Notes |
|---|---|---|
| Backend URL | (empty) | Full base URL; trailing slash stripped automatically |
| Post type | `post` | Single post type to watch |
| Include tag | (empty) | If set, post must have this tag to be processed |
| Exclude tags | `deliberation` | Comma-separated; takes precedence over Include tag |
| Allowed statuses | `publish` | Status transitions that trigger extraction |
| Allowed authors | (any) | Empty = any author; otherwise list of user IDs |
| Dry-run mode | off | Logs would-have-processed events without API calls |
| Display shape | `hierarchy` | One of: `hierarchy`, `chips`, `parents_only` |
| Top N (chips) | 5 | Range 1-20 |
| Position | `end` | One of: `end`, `before` (of post body) |
| Show header | yes | Toggle the header above the topics block |
| Header text | `Θεματικές ενότητες` | Plain text only |
| On uninstall | `keep_meta` | Or `remove_everything` |

## Troubleshooting

- **"Backend URL not configured"** in the log — set it in Settings.
- **Topics never appear** — check the include/exclude tag rules; confirm Dry-run is off; check the log for `failure` events.
- **PHP errors on activation** — verify PHP version ≥ 7.4 and WordPress ≥ 5.0.
- **Article page broken after activation** — should never happen (see spec § Safety invariants). If it does, deactivate the plugin and open an issue with the PHP error log.

## License

Copyright © 2026 Konnektable Technologies Ltd.

Licensed under the European Union Public Licence v.1.2.
