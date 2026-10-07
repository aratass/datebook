#!/usr/bin/env bash
# Runs the Datebook test suite inside the Craft test project.
#
# Pest derives test class names from the path of each test file relative to the
# project that holds vendor/, so the suite is copied into the project before it
# runs. Pass any Pest arguments, for example: ci/run-tests.sh --filter=feed
#
# Environment: DATEBOOK_PROJECT (default ci/project) points at the Craft project,
# PHP (default php) picks the PHP binary.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="${DATEBOOK_PROJECT:-$root/ci/project}"
php="${PHP:-php}"

if [ ! -f "$project/vendor/bin/pest" ]; then
  echo "No vendor/bin/pest in $project. Run composer install there first." >&2
  exit 1
fi

rm -rf "$project/tests"
cp -R "$root/tests" "$project/tests"

cd "$project"
export CRAFT_ALLOW_SUPERUSER="${CRAFT_ALLOW_SUPERUSER:-1}"
exec "$php" vendor/bin/pest "$@"
