#!/usr/bin/env bash
#
# Parse every PHP file in the tree with the interpreter running this script.
#
#   bin/syntax.sh                  # packages, bin and tests
#   bin/syntax.sh packages/admin   # one directory
#   PHP=php8.2 bin/syntax.sh       # a particular interpreter
#
# PHPUnit only compiles the files a test reaches, and PHPStan's phpVersion
# range governs type rules rather than the parser, so nothing else in the gate
# reads a file it does not run. `new X()->method()` is 8.4 syntax and it
# shipped in AdminController past all of it once. This is worth running on
# every supported version and worth the most on the oldest one.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

usage() {
    # The comment block under the shebang, so editing the header cannot
    # desynchronise --help from it.
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
    exit "${1:-0}"
}

PHP="${PHP:-php}"
ROOTS=()
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage 0 ;;
        *) ROOTS+=("$1") ;;
    esac
    shift
done
[ ${#ROOTS[@]} -gt 0 ] || ROOTS=(packages bin tests)

# php -l reports on stdout and says nothing worth reading when a file is fine,
# so each one goes through a wrapper that prints only on the way out.
lint() {
    local output
    output=$("$PHP" -l "$1" 2>&1) && return 0
    printf '%s\n' "$output"
    return 1
}
export -f lint
export PHP

mapfile -d '' FILES < <(find "${ROOTS[@]}" -type f -name '*.php' -print0)
[ ${#FILES[@]} -gt 0 ] || { printf '\033[33mno PHP files under %s\033[0m\n' "${ROOTS[*]}"; exit 1; }

VERSION=$("$PHP" -r 'echo PHP_VERSION;')

if ! printf '%s\0' "${FILES[@]}" \
    | xargs -0 -P "$(nproc 2>/dev/null || echo 4)" -n 1 bash -c 'lint "$0"'; then
    printf '\n\033[31mparse errors on PHP %s\033[0m\n' "$VERSION"
    exit 1
fi

printf '\033[32m%d files parse on PHP %s\033[0m\n' "${#FILES[@]}" "$VERSION"
