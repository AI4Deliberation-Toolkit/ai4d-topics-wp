# AI4D Topics — WordPress Plugin

WordPress plugin that calls the AI4D topic-extraction backend on article publish, stores topics in post meta, and renders them on the public article page.

**Spec:** see `docs/superpowers/specs/2026-05-24-wp-plugin-topic-extraction-design.md` in the `ai4deliberation-backend` repo.

## Requirements

- WordPress ≥ 5.0
- PHP ≥ 7.4
- A reachable topic toolkit exposing `POST /api/topics/extract-conversation` and `GET /api/topics` (direct mode), or the AI4D gateway with the toolkit registered as `topic-toolkit` (`POST /api/v1/tools/topic-toolkit/extract`, `GET /api/v1/tools/topic-toolkit/taxonomy`).

## Install (production)

1. Build a zip of the plugin folder: `bash scripts/build-zip.sh` (or `git archive --format=zip -o ai4d-topics.zip HEAD`).
2. In WP admin: Plugins → Add New → Upload Plugin → choose the zip → activate.
3. Go to Settings → AI4D Topics and configure:
   - Connection mode (default: Direct to the toolkit) and its URL: the Toolkit URL (e.g. https://api.example.com), or, via the gateway, the Gateway URL and key (see [Direct or via the gateway](#direct-or-via-the-gateway))
   - Post type (default: `post`)
   - Include tag (leave empty unless articles have a specific tag)
   - Exclude tags (default: `deliberation`)
   - Display shape, top-N, header (defaults are sensible)
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
- [ ] Open Settings → AI4D Topics; defaults populate; mode is Direct; the Toolkit URL field is empty by default.
- [ ] Set the Toolkit URL and save.
- [ ] Publish a test article (matching post type, no excluded tags).
  - Check the article edit screen metabox — should show topics within 1-3 seconds.
  - Check the public article page — topics appear just above the article body.
  - Check `Tools → AI4D Topics Log` — a `success` event for the post.
- [ ] Simulate backend unavailability:
  - Block outbound network or use a non-existent Toolkit URL.
  - Publish another article.
  - Verify article page still renders cleanly (no topics block, no errors).
  - Check the article edit screen — metabox shows the failure reason.
  - Check `Tools → AI4D Topics Log` — a `failure` event.
- [ ] Click "Re-extract topics" on a previously-failed article (after restoring backend) — verify it succeeds.
- [ ] Open `Tools → AI4D Topics Bulk`; choose "All eligible articles" → click **Preview** — the count excludes `deliberation`-tagged posts. Click **Start** — the progress bar reaches 100% / `done`; confirm a `deliberation`-tagged post got no topics written.
- [ ] Deactivate the plugin — public article pages still load; topic blocks vanish; post meta is preserved.
- [ ] Reactivate — previously extracted topics re-appear on public pages.
- [ ] Uninstall (Plugins → Delete) with default `keep_meta` mode — plugin folder removed; post meta preserved.

## Configuration reference

| Setting | Default | Notes |
|---|---|---|
| Connection mode | Direct | `direct` (to the toolkit) or `gateway` (via the AI4D gateway); key `connection_mode` |
| Toolkit URL | (empty) | Direct mode. Full base URL, no path (e.g. `https://api.example.com`); trailing slash stripped automatically; key `backend_url` |
| Toolkit key | (empty) | Direct mode, optional (the toolkit has no key yet); sent as `X-API-Key` when set |
| Gateway URL | (empty) | Via the gateway. Full base URL, no path (e.g. `https://gw.example.com`, not `…/api/v1`); trailing slash stripped automatically |
| Gateway key | (empty) | Via the gateway. The platform key issued by the gateway; sent as `X-Gateway-Key` |
| Post type | `post` | Single post type to watch |
| Include tag | (empty) | If set, post must have this tag to be processed |
| Exclude tags | `deliberation` | Comma-separated; takes precedence over Include tag |
| Allowed statuses | `publish` | Status transitions that trigger extraction |
| Allowed authors | (any) | Empty = any author; otherwise list of user IDs |
| Dry-run mode | off | Logs would-have-processed events without API calls |
| Display shape | `compact` | One of: `compact`, `hierarchy`, `chips`, `parents_only` |
| Top N (chips) | 5 | Range 1-20 |
| Show header | yes | Toggle the header above the topics block |
| Header text | `Θεματικές ενότητες` | Plain text only |
| On uninstall | `keep_meta` | Or `remove_everything` |

Keys are write-only: the settings page never shows a stored key, only whether one is set. Saving with the key field empty keeps the stored key; tick **Clear stored key** to remove it. A key may contain only printable ASCII characters, no spaces or line breaks; an invalid key is not saved and the stored one is kept.

## Direct or via the gateway

- **Direct to the toolkit** (the default, and what an upgraded site uses): extraction calls `POST {Toolkit URL}/api/topics/extract-conversation`, the topic list calls `GET {Toolkit URL}/api/topics`.
- **Via the AI4D gateway**: the same two calls go to `POST {Gateway URL}/api/v1/tools/topic-toolkit/extract` and `GET {Gateway URL}/api/v1/tools/topic-toolkit/taxonomy`, with the gateway key. Extraction on publish, bulk re-extract and the topic list all go through the gateway; nothing talks to the toolkit directly.

Both groups of settings are kept whichever mode is selected, so switching never clears a URL or a key, and it applies from the next call (even in the middle of a bulk run). With the gateway selected but its URL or key empty, extraction is skipped with `gateway_not_configured`; it never falls back to direct.

**The way back:** select **Direct to the toolkit** and save. Nothing has to be re-entered.

## Bulk re-extract

`Tools → AI4D Topics Bulk` re-extracts topics across many articles at once — e.g. after changing the Toolkit URL, switching the connection mode, or a taxonomy-version bump. Two modes:

- **Already-extracted articles only** — refresh every post that already has stored topics.
- **All eligible articles** — backfill the whole catalog.

Both honor the same eligibility rules as on-publish extraction, so excluded (e.g. `deliberation`-tagged) articles are never enqueued or modified. Click **Preview** first to see how many articles will be processed vs. excluded by tag. **Start** runs the batch with a live progress bar; the run is durable — you can close the tab and **Resume** later, or **Cancel** at any time. Admin-only (`manage_options`); per-article failures are logged to `Tools → AI4D Topics Log` and never overwrite an article's existing topics.

With **Dry-run mode** enabled, a bulk run logs only and makes no backend calls or meta writes (the page shows a warning), and the success/skip counts are approximate.

## Troubleshooting

- **Toolkit URL not configured** (`backend_url_not_configured`, direct mode) in the log — set it in Settings.
- **Error codes via the gateway** (in the log, the metabox and the posts column):
  - `gateway_not_configured` — via the gateway is selected but the gateway URL or key is empty.
  - `gateway_UNAUTHORIZED` — the gateway key is wrong or revoked.
  - `gateway_TOOL_TIMEOUT` — the toolkit didn't answer the gateway in time.
  - `gateway_TOOL_ERROR_<n>` — the toolkit refused or failed, with status n.
  - `gateway_<status>` — the gateway or proxy answered without the gateway's error shape (e.g. `gateway_502` when the pod is down).
  - `gateway_http_request_failed` — no answer within 35 s, or the gateway is unreachable.
- **Topics never appear** — check the include/exclude tag rules; confirm Dry-run is off; check the log for `failure` events.
- **PHP errors on activation** — verify PHP version ≥ 7.4 and WordPress ≥ 5.0.
- **Article page broken after activation** — should never happen (see spec § Safety invariants). If it does, deactivate the plugin and open an issue with the PHP error log.
- **Bulk re-extract shows "Stopped after repeated errors"** — the backend was unreachable for several consecutive articles; check the active mode's URL (and key) and `Tools → AI4D Topics Log`, then click **Resume** to continue from where it stopped.

## License

Copyright © 2026 Konnektable Technologies Ltd.

Licensed under the European Union Public Licence v.1.2.
