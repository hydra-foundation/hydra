#!/usr/bin/env bash
#
# Run the Hydra suites.
#
#   bin/test.sh                    # framework, then app
#   bin/test.sh hydra              # just the framework
#   bin/test.sh -- --filter Router # everything after -- goes to phpunit
#
# The framework is one suite in one process. Cross-package state leaks are a
# real failure mode here, so the order is randomised.
set -uo pipefail

DIR="${HYDRA_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

usage() {
    # The comment block under the shebang, so editing the header cannot
    # desynchronise --help from it.
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
    exit "${1:-0}"
}

REPOS=()
ARGS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --) shift; ARGS=("$@"); break ;;
        -h|--help) usage 0 ;;
        *) REPOS+=("$1") ;;
    esac
    shift
done
[ ${#REPOS[@]} -gt 0 ] || REPOS=(hydra app)

failed=0
for repo in "${REPOS[@]}"; do
    printf '\n\033[1m=== %s ===\033[0m\n' "$repo"
    if [ ! -x "$DIR/$repo/vendor/bin/phpunit" ]; then
        printf '\033[33mno vendor/bin/phpunit — run composer install in %s/\033[0m\n' "$repo"
        failed=$((failed + 1))
        continue
    fi
    (cd "$DIR/$repo" && ./vendor/bin/phpunit --order-by=random \
        "${ARGS[@]+"${ARGS[@]}"}") || failed=$((failed + 1))
done

[ "$failed" -eq 0 ] || { printf '\n\033[31m%d suite(s) failed\033[0m\n' "$failed"; exit 1; }
printf '\n\033[32mall suites passed\033[0m\n'
