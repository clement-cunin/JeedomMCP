# CLAUDE.md

Guidance for Claude/autoclaude agents (and humans) working in this repo.

## Before any commit

This repo ships a versioned pre-commit hook (`.githooks/pre-commit`) that
runs `php -l` on staged `.php` files and blocks the commit if one fails to
compile. Check it is active before committing:

```sh
git config core.hooksPath
```

If that prints nothing (or something other than `.githooks`), enable it:

```sh
git config core.hooksPath .githooks
```

This is a local, per-clone setting — it is not inherited automatically, so
run this once in every fresh checkout/worktree you commit from.

## Development guide

See [docs/development.md](docs/development.md) for architecture, coding
conventions (PHP 7.3 compatibility, ACL system, tool registration checklist)
and commit conventions.
