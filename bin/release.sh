#!/usr/bin/env bash
#
# Tag a Hydra release.
#
#   bin/release.sh 0.3.1                 # dry run: report what would happen
#   bin/release.sh 0.3.1 --push          # tag and push
#   bin/release.sh 0.4.0 --minor --push  # also rewrite the ^0.3 constraints first
#
# Two repositories are tagged: the hydra monorepo and the app skeleton. The
# sixteen hydrakit/* package repositories are not touched here — the split
# workflow regenerates them from the monorepo tag, and pushing to them by
# hand would be overwritten.
#
# Sibling constraints are ranges (^0.3), so a patch or minor inside the
# current 0.x needs no file edits, only tags. Crossing to a new 0.x does need
# them, because ^0.3 will not accept 0.4.0; that is what --minor rewrites.
set -euo pipefail

DIR="${HYDRA_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BRANCH="main"
REPOS=(hydra app)

VERSION=""
DO_PUSH=0
DO_MINOR=0
ASSUME_YES=0

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

usage() {
    # The comment block under the shebang, so editing the header cannot
    # desynchronise --help from it.
    awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
    exit "${1:-0}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --push)  DO_PUSH=1 ;;
        --minor) DO_MINOR=1 ;;
        --yes|-y) ASSUME_YES=1 ;;
        -h|--help) usage 0 ;;
        -*) die "unknown flag: $1 (try --help)" ;;
        *)  [ -z "$VERSION" ] || die "version given twice: $VERSION and $1"
            VERSION="$1" ;;
    esac
    shift
done

[ -n "$VERSION" ] || usage 1

VERSION="${VERSION#v}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must look like 0.3.1, got '$VERSION'"
TAG="v$VERSION"
NEW_SERIES="${VERSION%.*}"

# The series currently in use, read from the monorepo rather than assumed.
OLD_SERIES=$(sed -n 's/.*"dev-main": "\([0-9]*\.[0-9]*\)\.x-dev".*/\1/p' "$DIR/hydra/composer.json" | head -1)
[ -n "$OLD_SERIES" ] || die "could not read the current series from hydra/composer.json"

if [ "$DO_MINOR" -eq 1 ] && [ "$NEW_SERIES" = "$OLD_SERIES" ]; then
    die "--minor given but $TAG is still in the $OLD_SERIES series; drop --minor"
fi
if [ "$DO_MINOR" -eq 0 ] && [ "$NEW_SERIES" != "$OLD_SERIES" ]; then
    die "$TAG leaves the $OLD_SERIES series: ^$OLD_SERIES constraints will not accept it. Re-run with --minor"
fi

# ---------------------------------------------------------------- preflight --
problems=()
echo "Checking ${#REPOS[@]} repositories for $TAG ..."

for repo in "${REPOS[@]}"; do
    path="$DIR/$repo"

    [ -d "$path/.git" ] || { problems+=("$repo: not a git checkout at $path"); continue; }

    branch=$(git -C "$path" branch --show-current)
    [ "$branch" = "$BRANCH" ] || problems+=("$repo: on '$branch', expected '$BRANCH'")

    [ -z "$(git -C "$path" status --porcelain)" ] || problems+=("$repo: working tree is dirty")

    if git -C "$path" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
        problems+=("$repo: tag $TAG already exists locally")
    fi

    if ! git -C "$path" fetch --quiet --tags origin 2>/dev/null; then
        problems+=("$repo: cannot reach origin")
        continue
    fi

    if git -C "$path" rev-parse -q --verify "refs/remotes/origin/$BRANCH" >/dev/null; then
        counts=$(git -C "$path" rev-list --left-right --count "origin/$BRANCH...$BRANCH")
        behind=${counts%%	*}
        ahead=${counts##*	}
        [ "$behind" -eq 0 ] || problems+=("$repo: $behind commit(s) behind origin/$BRANCH — pull first")
        [ "$ahead" -gt 0 ] && echo "  $repo: $ahead unpushed commit(s), will be pushed with the tag"
    else
        problems+=("$repo: no origin/$BRANCH")
    fi
done

# The suite has to be green before a tag goes out: the split repositories are
# generated from it and cannot be fixed in place.
if [ -x "$DIR/hydra/vendor/bin/phpunit" ]; then
    echo "Running the suite ..."
    (cd "$DIR/hydra" && ./vendor/bin/phpunit --order-by=random >/dev/null 2>&1) \
        || problems+=("hydra: the test suite fails — fix it before tagging")
else
    problems+=("hydra: no vendor/bin/phpunit — run composer install in hydra/")
fi

if [ ${#problems[@]} -gt 0 ]; then
    printf '\nRefusing to release:\n' >&2
    printf '  - %s\n' "${problems[@]}" >&2
    exit 1
fi

echo "All clean."

# --------------------------------------------------------------------- plan --
echo
echo "Plan for $TAG:"
[ "$DO_MINOR" -eq 1 ] && \
    echo "  1. rewrite ^$OLD_SERIES -> ^$NEW_SERIES across hydra/packages/*, hydra/ and app/, and commit"
echo "  $([ "$DO_MINOR" -eq 1 ] && echo 2 || echo 1). tag hydra and app $TAG and push $BRANCH + tag"
echo "  $([ "$DO_MINOR" -eq 1 ] && echo 3 || echo 2). the split workflow regenerates the 16 package repos at $TAG"

if [ "$DO_PUSH" -eq 0 ]; then
    echo
    echo "Dry run — nothing written. Re-run with --push to do it."
    exit 0
fi

if [ "$ASSUME_YES" -eq 0 ]; then
    echo
    read -r -p "Push $TAG to hydra and app? [y/N] " reply
    case "$reply" in [yY]|[yY][eE][sS]) ;; *) echo "Aborted."; exit 1 ;; esac
fi

# -------------------------------------------------------------------- do it --
if [ "$DO_MINOR" -eq 1 ]; then
    echo
    echo "Rewriting constraints $OLD_SERIES -> $NEW_SERIES ..."

    # app/composer.dev.json pins hydrakit/* at "*" so the path repos always
    # win, and must keep doing so — it is deliberately not rewritten.
    find "$DIR/hydra/packages" -maxdepth 2 -name composer.json -print0 \
        | xargs -0 sed -i "s|\(\"hydrakit/[a-z-]*\": \"\)\^${OLD_SERIES}\"|\1^${NEW_SERIES}\"|g"

    sed -i "s|\"dev-main\": \"${OLD_SERIES}\.x-dev\"|\"dev-main\": \"${NEW_SERIES}.x-dev\"|" \
        "$DIR/hydra/composer.json"
    find "$DIR/hydra/packages" -maxdepth 2 -name composer.json -print0 \
        | xargs -0 sed -i "s|\"dev-main\": \"${OLD_SERIES}\.x-dev\"|\"dev-main\": \"${NEW_SERIES}.x-dev\"|"

    sed -i "s|\(\"hydrakit/[a-z-]*\": \"\)\^${OLD_SERIES}\"|\1^${NEW_SERIES}\"|g" \
        "$DIR/app/composer.json"

    for repo in "${REPOS[@]}"; do
        path="$DIR/$repo"
        if [ -n "$(git -C "$path" status --porcelain)" ]; then
            git -C "$path" add -A
            git -C "$path" commit -qm "chore: require the ^${NEW_SERIES} series"
            echo "  $repo: committed"
        else
            echo "  $repo: nothing to change"
        fi
    done
fi

echo
echo "Tagging and pushing ..."
for repo in "${REPOS[@]}"; do
    path="$DIR/$repo"
    git -C "$path" tag -a "$TAG" -m "Release $TAG"
    git -C "$path" push --quiet origin "$BRANCH"
    git -C "$path" push --quiet origin "$TAG"
    echo "  $repo $TAG pushed"
done

cat <<EOF

Done — hydra and app at $TAG.

The split workflow now regenerates the 16 package repositories and pushes
$TAG to each. Watch it at:
  https://github.com/hydra-foundation/hydra/actions

The skeleton ships no composer.lock: .gitattributes marks it export-ignore,
so create-project resolves $TAG fresh from Packagist rather than installing
whatever the lock happened to pin when the tag was cut.

The tracked lock is for the app checkout and its CI. Refresh it whenever:
  cd $DIR/app && composer update "hydrakit/*" --no-install
--no-install because app/vendor holds the symlinks to hydra/packages/*, and a
plain update would replace them with copies from Packagist.
EOF
