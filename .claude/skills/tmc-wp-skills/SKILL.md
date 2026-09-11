---
name: tmc-wp-skills
description: "Use before or while running any WordPress agent skill (wordpress-router, wp-project-triage, wp-plugin-development, wp-rest-api, wp-performance) in the tecteb-seller repository. Maps the upstream skills' assumed `skills/…` paths onto this project's `.claude/skills/…` layout, and corrects the project detection that reports this plugin as kind=unknown."
compatibility: "Project-local. Upstream skills are vendored unmodified from WordPress/agent-skills @ d87ee69; this skill adapts them without editing them."
---

# WordPress skills in this repository

The five WordPress skills in `.claude/skills/` are **unmodified upstream
copies** (`.claude/skills/UPSTREAM.md`). Two of their assumptions do not hold
here. Fixing them upstream-side would mean editing vendored files, so the
corrections live here instead.

## 1. Paths: `skills/…` means `.claude/skills/…`

Upstream text was written for a checkout of the agent-skills repository, where
the skills sit in a top-level `skills/` directory. A project install puts them
under `.claude/skills/`. Every path an upstream skill names must be read with
that prefix:

| upstream text | actually in this repo |
|---|---|
| `skills/wp-project-triage/scripts/detect_wp_project.mjs` | `.claude/skills/wp-project-triage/scripts/detect_wp_project.mjs` |
| `skills/wordpress-router/references/decision-tree.md` | `.claude/skills/wordpress-router/references/decision-tree.md` |
| `skills/wp-plugin-development/references/…` | `.claude/skills/wp-plugin-development/references/…` |

A bare `node skills/…` command from the repo root fails with
`Cannot find module`. Prefix it.

## 2. Detection: this repo IS a WordPress plugin

`detect_wp_project.mjs` reports `kind: ["unknown"]` here. It looks for a plugin
header with `/^\s*Plugin Name:/im`, and this plugin — like most — writes its
header inside a docblock:

```php
/**
 * Plugin Name:       Tecteb Marketplace Core
```

The leading ` * ` stops the match. WordPress itself accepts this form, so the
repo is a plugin whatever the script says. It also reports
`tests.hasPlaywright: false` because it only inspects a root `package.json`;
Playwright lives in `tools/browser/package.json`.

`wp-plugin-development`'s own `scripts/detect_plugins.mjs` shares the regex and
reports `count: 0` here for the same reason. Both scripts are wrong about this
repo in the same way; neither is wrong about anything else.

**Run the wrapper instead of the upstream script directly:**

```bash
node .claude/skills/tmc-wp-skills/scripts/triage.mjs
```

It runs the upstream detector unchanged, then merges the corrections and lists
them under `corrections` so nothing is silently overwritten. Add `--raw` to see
the untouched upstream output.

## 3. Routing for this repo

Per `.claude/skills/wordpress-router/references/decision-tree.md`, kind
`wp-plugin` routes to **`wp-plugin-development`** (hooks, admin screens,
Settings API, activation/uninstall, security), plus `wp-rest-api` for
`/tmc/v1/health` and `wp-performance` for autoloaded options and query work.
`wp-block-development`, `wp-block-themes` and the theme skills do not apply:
this plugin ships no blocks and no theme.

## 4. Guardrails the skills do not know about

The upstream skills suggest general WordPress practice. In this repository the
owner's standing constraints win:

- No installation on `tecteb.com` or `staging.tecteb.com`, no SSH, no real
  credentials (`CLAUDE.md`, «قاعده عدم deploy»).
- The disposable WordPress at `/home/user/wp-disposable` is the only site that
  may be touched. Bring it up with MariaDB on 127.0.0.1:3306 and
  `php -S 127.0.0.1:8080 -t /home/user/wp-disposable`.
- Tests that were not executed stay `Not Run`; they are never marked passed.
- `wp-performance` will suggest WP-CLI `profile`/`doctor` packages: those need
  network installs that this environment blocks. Say so rather than reporting
  a measurement that never ran.
