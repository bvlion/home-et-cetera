#!/usr/bin/env bash
#
# Production update deploy, executed on the XServer host over SSH stdin by
# .github/workflows/cadence-xs-deploy.yaml. Not intended to be executed directly on the
# GitHub Actions runner.
#
# Required environment variables (exported by the caller before this script
# runs, values are never taken from argv so they do not appear in `ps`):
#   DEPLOY_PATH            Absolute path to the repository checkout.
#   DEPLOY_COMPOSER_PATH   Absolute path to the cadence-xs-dedicated composer.phar.
#   DEPLOY_PUBLIC_PATH     Absolute path to the public_html directory.
#   TAG_NAME               Pushed tag name (e.g. cadence-xs-v1.0.0).
#   EXPECTED_COMMIT        Commit SHA the pushed tag must resolve to.

set -euo pipefail

PHP_BIN=/opt/php-8.5.5/bin/php

cd "$DEPLOY_PATH"

if [ -n "$(git status --porcelain --untracked-files=no -- cadence-xs/)" ]; then
    echo "Deploy aborted: cadence-xs working tree has unexpected tracked changes." >&2
    exit 1
fi

git fetch origin --force "refs/tags/${TAG_NAME}:refs/tags/${TAG_NAME}"

RESOLVED_COMMIT="$(git rev-list -n 1 "refs/tags/${TAG_NAME}^{commit}")"

if [ "$RESOLVED_COMMIT" != "$EXPECTED_COMMIT" ]; then
    echo "Deploy aborted: tag ${TAG_NAME} resolves to an unexpected commit." >&2
    exit 1
fi

# Update only the service, including removal of files deleted in the target.
git restore --source="$RESOLVED_COMMIT" --staged --worktree -- cadence-xs/
# Record the deployed commit and align the index without writing other paths.
git reset --mixed --quiet "$RESOLVED_COMMIT"

cd "$DEPLOY_PATH/cadence-xs"

"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install --no-dev --optimize-autoloader --classmap-authoritative

"$PHP_BIN" bin/migrate.php

cp "$DEPLOY_PATH/cadence-xs/public/.htaccess" "$DEPLOY_PUBLIC_PATH/.htaccess"

echo "Deployed tag=${TAG_NAME} commit=${RESOLVED_COMMIT}"
