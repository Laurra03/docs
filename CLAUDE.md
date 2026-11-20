# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-hosted, web-based [Typst](https://typst.app) document editor. Users log in via PocketID (OIDC), create and edit Typst documents in a CodeMirror 5 editor, and compile them to PDF in-browser. Deployed at `https://docs.fireants.dev`.

## Stack

- **Backend**: Plain PHP (no framework), MySQLi
- **Auth**: OIDC via PocketID at `https://accounts.fireants.dev`
- **Editor**: CodeMirror 5 with a custom Typst syntax mode (defined inline in `editor.php`)
- **Compiler**: `/bin/typst` CLI on the server
- **CSS**: Served via `css/dark_mode.php` (PHP file with `Content-Type: text/css` header) - all component styles for both pages live here

## Database

- Host: `127.0.0.1`, user: `docs`, password: `NSjY9bHbtkxsjyWuepo8uPud`, database: `documents`
- MariaDB runs inside the container; credentials are hardcoded in `config.php`
- Tables (auto-created via `docker/init.sql` on first boot):
  - `typst_documents` - `id`, `owner` VARCHAR(255), `title`, `created_at`, `updated_at`
  - `document_shares` - `id`, `document_id`, `token` VARCHAR(64) UNIQUE, `access` ENUM('view','edit'), `created_at`
  - `git_user_settings` - `user_sub` VARCHAR(255) PK, `commit_name`, `commit_email`
- `owner` is the OIDC `sub` claim
- Document content is stored on disk at `data/{id}/main.typ`; extra `.typ` files and uploaded assets also live under `data/{id}/`

## Auth flow

`auth/session.php` provides `currentUser()` (reads the session once, then releases the session lock so a slow compile does not block other requests) and `requireAuth()` for pages (redirects to `/auth/login.php?return_to=…`).

Login (`auth/login.php`) redirects to the OIDC provider with `state` + PKCE. The callback (`auth/callback.php`) exchanges the code for tokens, fetches userinfo, stores `user_sub` + `user_name` + `user_email` in the PHP session and redirects to `return_to`. Session cookie is `SameSite=Lax; Secure; HttpOnly`, 14-day lifetime.

## Access control and paths (`lib.php`)

Every API endpoint starts with `require lib.php; apiInit();` and then exactly one of:

- `requireDoc($id, 'view'|'edit'|'owner')` - resolves the document and the caller's access. Owner = session user owns it. Otherwise a `share_token` POST/GET param grants `view` or `edit` (also for logged-in non-owners). `'owner'` is session-only: sharing, git, delete, duplicate, rename.
- `requireUser()` - session-only, for endpoints not tied to a document (new, import, clone, SSH key, git settings).

All user-supplied file names go through `docPath()` / `safeRelPath()`, which reject `..`, absolute paths, anything under `.git` (writing there = command execution via git config) and symlinked components (cloned repos can contain links pointing outside the project). New names are additionally limited to `[A-Za-z0-9._-]`. Never build a `data/{id}/…` path by hand.

`apiInit()` also enforces POST, rejects cross-origin `Origin` headers and turns uncaught exceptions into a JSON 500. Use `jsonOut()` / `fail()` for responses and `db()` / `dbRow()` / `dbExec()` for queries.

## API endpoints (all POST unless noted, return JSON `{ok: bool, ...}`)

| File | Action |
|---|---|
| `api/new.php` | Insert new doc, return `{ok, id}` |
| `api/save.php` | Update title + write `main.typ` by `id` + owner |
| `api/delete.php` | Delete doc by `id` + owner |
| `api/rename.php` | Rename doc title |
| `api/duplicate.php` | Duplicate a doc |
| `api/compile.php` | Mirror project into `/tmp`, compile via `typst watch`, return `{ok, pdf, warnings}` (pdf is base64) |
| `api/file_list.php` | List extra `.typ` files for a doc |
| `api/file_get.php` | Get content of an extra file |
| `api/file_save.php` | Save content of an extra file |
| `api/file_new.php` | Create a new extra file |
| `api/file_delete.php` | Delete an extra file |
| `api/file_rename.php` | Rename/move a file or folder (`image_move.php` is an alias) |
| `api/asset_save.php` | Save an uploaded text asset (e.g. editable font/bib file) |
| `api/image_list.php` | List uploaded assets for a doc |
| `api/image_serve.php` | Serve an asset file (GET) |
| `api/image_upload.php` | Upload an asset file |
| `api/image_delete.php` | Delete an asset file |
| `api/image_move.php` | Rename/move an asset file |
| `api/download_zip.php` | Download entire project as ZIP (GET) |
| `api/upload_project.php` | Upload a ZIP to populate a project |
| `api/share_get.php` | Get current share link info for a doc (owner only) |
| `api/share_create.php` | Create or update share link + access level; `regenerate=1` replaces the token (owner only) |
| `api/share_delete.php` | Delete share link (owner only) |
| `api/git_*.php` | Git integration (owner only); all commands run through `runGit()` in `git_helpers.php` |

All endpoints enforce access via `requireDoc()` / `requireUser()` (see above).

## Theme

Dark/light is driven by a `darkmode` cookie. **The logic is inverted**: cookie value `"1"` → light mode; anything else (including absent) → dark mode. The `$isDark` variable being `true` means dark mode is active.

## Editor keyboard shortcuts

- `Ctrl-S` - save + compile
- `Ctrl-Enter` - compile only

Auto-save fires 2 seconds after the last keystroke.

## Compile pipeline

`api/compile.php` keeps one persistent `typst watch` process per document. Workspace: `/tmp/typst_proj_{id}/` with `src/` (symlinks to `data/{id}/` plus the unsaved editor buffers from the request), `out.pdf`, `watch.{pid,log,state}`, `lock`, `last_used`. Per request it takes the lock, syncs `src/` (pruning files that no longer exist), rewrites the entry file last (that triggers the compile), then polls the watcher log for the last `compiled …` line. A timeout kills the watcher. `docker/reaper.sh` (started by the entrypoint) removes workspaces idle for 20 minutes.

## Sharing

Documents can be shared via a token-based link: `https://docs.fireants.dev/shared/{token}` (nginx rewrites to `shared.php?t={token}`, which redirects to `/editor/{id}?token={token}`).

- **`shared.php`** - looks up the token, redirects to the full editor with `?token=` in the URL
- **`editor.php`** - accepts `?token=TOKEN` for anyone who is not the owner (logged in or not); validates the token against the document ID, sets `$shareToken` and `$shareAccess` PHP vars
- **Access levels**: `view` (read-only editor, no saves, file buttons hidden, settings greyed out) or `edit` (full editor)
- **Token threading**: `XMLHttpRequest.prototype.send` is monkey-patched in the browser to append `share_token` to every POST (string or FormData body) when `SHARE_TOKEN` is set; GET-based endpoints (image serve, ZIP download) have the token appended at URL construction
- **`requireDoc($id, 'edit')`** on all write endpoints rejects view-only tokens with 403; guests cannot change the title, sharing or git state
- In guest mode the user avatar/menu is replaced by a light/dark mode toggle button

## File storage

All project files live on disk under `data/{id}/`:
- `main.typ` - the primary source file
- Additional `.typ` files and uploaded assets (images, fonts, PDFs, etc.) in subdirectories as created by the user

The `file_list.php` API scans this directory recursively for `.typ` files. Images/assets are listed separately via `image_list.php`.

## Configuration

OIDC constants are loaded via `getenv()` in `config.php`; set them in `.env` (loaded by Docker Compose via `env_file: .env`). To register a new OIDC app go to `https://accounts.fireants.dev`. DB credentials are hardcoded in `config.php`.

## Docker / Deployment

The app runs as a single container (nginx + php-fpm + MariaDB + typst) managed by `docker-compose.yml`.

```
docker compose up -d --build
```

- Port mapping: host `8023` → container `80`
- Volumes:
  - `./app_data` → `/var/www/html/data` (document files, persisted)
  - `./app_ssh_keys` → `/var/www/html/ssh_keys` (SSH keys, persisted)
  - `db_data` named volume → `/var/lib/mysql` (database, persisted)
- `docker/entrypoint.sh` initializes MariaDB, applies `docker/init.sql` (idempotent), starts php-fpm, then nginx
- `docker/nginx.conf` — nginx config (URL rewrites, security headers). Only whitelisted PHP entry points execute: a new top-level page or a new directory of PHP files must be added to the `location` regexes. `/data/`, `/ssh_keys/`, `/docker/` are never served.
- `docker/php.ini` — upload/POST limits (64M, keep in sync with nginx), error logging, session settings
- `docker/init.sql` — schema DDL, runs on every container start (all statements use `IF NOT EXISTS`)
