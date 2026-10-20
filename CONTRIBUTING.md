# Contributing

Thanks for helping with IX WP EnvSync.

## Setup

```bash
composer install
composer check     # phpcs + phpunit
```

PHP 7.4 is the floor (`composer.json` pins the platform to 7.4.33). Code follows the WordPress
coding standards plus PHPCompatibility, enforced by `phpcs.xml.dist`.

## Tests

- Unit tests live in `tests/` and run with `vendor/bin/phpunit`.
- `tests/integration.sh` drives a real hub and remote end to end. Read it before running it:
  it needs two WordPress installs.
- A bug fix comes with a test that fails without the fix.

## Pull requests

1. Branch from an up-to-date `main`. Never push to `main` directly.
2. Keep one concern per PR. Name the branch `feat/…`, `fix/…`, `docs/…` or `chore/…`.
3. Commit subjects use a conventional prefix: `feat:`, `fix:`, `docs:`, `test:`, `chore:`.
4. `composer check` and the CI workflow must pass.
5. Do not put real site names, hosts or customer data in code, tests, commits or PR text.

## Releases

A release is a version bump PR (`chore: bump to X.Y.Z`: plugin header and `IXES_VERSION`),
then a `vX.Y.Z` tag on the merged commit. The tag triggers `.github/workflows/release.yml`,
which builds the zip with `git archive` and publishes a GitHub Release. Add the entry to
`CHANGELOG.md` in the same PR.

## Security

Report a vulnerability privately to the maintainer instead of opening a public issue. The
plugin handles tokens, signed requests and database rows, so treat every report as sensitive.

## License

By contributing you agree that your work is licensed under GPL-2.0-or-later.
