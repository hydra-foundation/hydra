#!/usr/bin/env bash
#
# Show git status for the Hydra development checkouts.
#
#   bin/status.sh            # hydra and app
#   bin/status.sh --fetch    # git fetch first, so ahead/behind is real
set -uo pipefail

DIR="${HYDRA_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

usage() {
    # The comment block under the shebang, so editing the header cannot
    # desynchronise --help from it.
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
    exit "${1:-0}"
}

FETCH=0
case "${1:-}" in
    --fetch) FETCH=1 ;;
    -h|--help) usage 0 ;;
    "") ;;
    *) printf 'error: unknown flag: %s (try --help)\n' "$1" >&2; exit 1 ;;
esac

attention=0
for repo in hydra app; do
    path="$DIR/$repo"
    if [ ! -d "$path/.git" ]; then
        printf '  \033[31m%-6s\033[0m  not a checkout at %s\n' "$repo" "$path"
        attention=$((attention + 1))
        continue
    fi

    [ "$FETCH" -eq 1 ] && git -C "$path" fetch --quiet --prune --tags 2>/dev/null

    branch=$(git -C "$path" symbolic-ref --quiet --short HEAD 2>/dev/null) \
        || branch="detached@$(git -C "$path" rev-parse --short HEAD)"
    dirty=$(git -C "$path" status --porcelain --untracked-files=all | wc -l)

    state=()
    [ "$dirty" -gt 0 ] && state+=("$(printf '\033[31m%d changed\033[0m' "$dirty")")
    if counts=$(git -C "$path" rev-list --left-right --count '@{upstream}...HEAD' 2>/dev/null); then
        behind="${counts%%[[:space:]]*}"; ahead="${counts##*[[:space:]]}"
        [ "$ahead"  -gt 0 ] && state+=("$(printf '\033[36m↑%d\033[0m' "$ahead")")
        [ "$behind" -gt 0 ] && state+=("$(printf '\033[35m↓%d\033[0m' "$behind")")
    else
        state+=("$(printf '\033[33mno upstream\033[0m')")
    fi

    if [ ${#state[@]} -eq 0 ]; then
        summary=$(printf '\033[32mclean\033[0m')
    else
        summary="${state[0]}"
        for (( i = 1; i < ${#state[@]}; i++ )); do summary+=", ${state[i]}"; done
        attention=$((attention + 1))
    fi
    printf '  \033[1m%-6s\033[0m  %-20s %s\n' "$repo" "$branch" "$summary"
done

[ "$attention" -eq 0 ] || exit 1
