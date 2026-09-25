#!/usr/bin/env bash
#
# Tag a Hydra release.
#
#   bin/release.sh 0.3.1                 # dry run: report what would happen
#   bin/release.sh 0.3.1 --push          # tag and push
#   bin/release.sh 0.4.0 --minor --push  # also rewrite the ^0.3 constraints first
#   bin/release.sh 0.3.2 --security --push  # mark it a security fix in version.json
#
# Two repositories are tagged: the hydra monorepo and the app skeleton. The
# hydrakit/* package repositories are not touched here — the split
# workflow regenerates them from the monorepo tag, and pushing to them by
# hand would be overwritten.
#
# Sibling constraints are ranges (^0.3), so a patch or minor inside the
# current 0.x needs no file edits, only tags. Crossing to a new 0.x does need
# them, because ^0.3 will not accept 0.4.0; that is what --minor rewrites.
#
# Two things here exist because v0.3.4 got them wrong and had to be pulled off
# Packagist an hour later:
#
#   - It shipped six renames as a patch. ^0.3 promises a consumer that nothing
#     inside the series will be taken away, so `composer update` handed them a
#     framework their code no longer compiled against. bin/api-surface.php now
#     reports what a tag removes, and a removal without --minor is refused.
#
#   - It tagged both repositories at once, so app's lock still named the
#     previous release when the tag was cut. hydra is tagged first now, and
#     app's lock is refreshed and verified against the packages that release
#     actually published before app is tagged behind it.
set -euo pipefail

DIR="${HYDRA_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BRANCH="main"
REPOS=(hydra app)

# Every package repository the split regenerates. Declared here rather than
# beside the wait loop because the plan counts it before printing, and a count
# that has to be kept in step by hand is one that drifts the next time a
# package is added.
PACKAGES=(admin auth authorization cache console core csrf database event http
          kernel log mail nyholm php-di queue scheduler session symfony-console throttle
          validation view)

VERSION=""
DO_PUSH=0
DO_MINOR=0
DO_SECURITY=0
ASSUME_YES=0

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

# Run a check and keep what it said. Reporting only that the suite failed
# leaves nothing to act on: the run being judged happens in a temp directory
# this script deletes on the way out, so discarding its output is what makes
# a failed release irreproducible.
run_checked() {
    local what="$1" dir="$2"
    shift 2
    local log
    log=$(mktemp)

    if (cd "$dir" && "$@") >"$log" 2>&1; then
        rm -f "$log"
        return 0
    fi

    printf '\n--- %s ---\n' "$what" >&2
    tail -n 40 "$log" >&2
    printf -- '--- end of %s ---\n\n' "$what" >&2
    rm -f "$log"
    return 1
}

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
        --security) DO_SECURITY=1 ;;
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
        # Not `[ ... ] && echo`: with everything already pushed that list
        # returns 1, and under `set -e` the preflight would exit right here.
        if [ "$ahead" -gt 0 ]; then
            echo "  $repo: $ahead unpushed commit(s), will be pushed with the tag"
        fi
    else
        problems+=("$repo: no origin/$BRANCH")
    fi
done

# REDIS_REQUIRED turns "no Redis, skipped" into a failure, and without it a
# green run here says nothing about the store the rate limiter rests on: the
# twenty tests that cover it skip, and the gate reports the silence as a pass.
#
# Setting the flag blind is what must not happen. It needs an ext-redis and a
# server on the same side of the fence, and this project keeps them in two
# different places: the extension is baked into app/docker/php/Dockerfile, and
# the server is a compose service publishing no port to the host. So the route
# is looked for rather than assumed, the host first and the app stack second,
# and a machine that has neither is told so in one line naming both.
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"

# The framework checkout as the php container sees it: compose mounts the
# parent of both repositories, so hydra/ is a sibling of app/ in there too.
CONTAINER_DIR="/var/www/html/$(basename "$DIR/hydra")"

suite_dir="$DIR/hydra"
suite_where="this machine, without Redis"
suite_cmd=(./vendor/bin/phpunit --order-by=random)

if ! php -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
    host_why="no ext-redis"
elif ! php -r 'try { exit((new Redis)->connect($argv[1], (int) $argv[2], 1.0) ? 0 : 1); } catch (Throwable) { exit(1); }' \
        -- "$REDIS_HOST" "$REDIS_PORT" 2>/dev/null; then
    host_why="nothing on redis://$REDIS_HOST:$REDIS_PORT"
else
    host_why=""
fi

if [ -z "$host_why" ]; then
    suite_where="this machine"
    suite_cmd=(env REDIS_REQUIRED=1 ./vendor/bin/phpunit --order-by=random)
# One probe rather than a liveness check and then a capability check: what
# matters is whether a Redis can be reached from where the suite would run,
# and a stack that is down fails this the same way a stack without Redis does.
elif (cd "$DIR/app" && docker compose exec -T php php -r \
        'try { $r = new Redis; exit(extension_loaded("redis") && $r->connect("redis", 6379, 1.0) ? 0 : 1); } catch (Throwable) { exit(1); }') \
        >/dev/null 2>&1; then
    # PHP 8.4 in the image against 8.5 on the host, so a release verified this
    # way was verified on a different minor than it was written on. Both are
    # inside the >=8.2 the packages claim, and CI covers the matrix; worth
    # knowing when a release passes here and fails there.
    suite_dir="$DIR/app"
    suite_where="app-php-1"
    suite_cmd=(docker compose exec -T -w "$CONTAINER_DIR"
               -e REDIS_REQUIRED=1 -e REDIS_HOST=redis
               php vendor/bin/phpunit --order-by=random)
else
    problems+=("hydra: nowhere to run the Redis tests — this machine: $host_why; app stack: not reachable. Start it with app/bin/dev up -d, or release from CI")
fi

# The suite has to be green before a tag goes out: the split repositories are
# generated from it and cannot be fixed in place.
if [ -x "$DIR/hydra/vendor/bin/phpunit" ]; then
    echo "Running the suite ($suite_where) ..."
    run_checked "hydra: phpunit" "$suite_dir" "${suite_cmd[@]}" \
        || problems+=("hydra: the test suite fails — fix it before tagging")
else
    problems+=("hydra: no vendor/bin/phpunit — run composer install in hydra/")
fi

# A symbol that disappears is a break, and a break has to leave the series it
# was promised in. Properties aside, this is the whole compatibility contract.
REMOVED=""
PREV_TAG=$(git -C "$DIR/hydra" tag --list 'v*' --sort=-v:refname | head -1)

if [ -n "$PREV_TAG" ]; then
    echo "Comparing the public surface with $PREV_TAG ..."
    work=$(mktemp -d)
    # Two listings and a comparison that knows which signature changes a
    # consumer has to act on; see bin/api-diff.php for which ones those are.
    if git -C "$DIR/hydra" worktree add --quiet --detach "$work" "$PREV_TAG" 2>/dev/null; then
        php "$DIR/hydra/bin/api-surface.php" "$work/packages" > "$work.old"
        php "$DIR/hydra/bin/api-surface.php" "$DIR/hydra/packages" > "$work.new"
        REMOVED=$(php "$DIR/hydra/bin/api-diff.php" "$work.old" "$work.new")
        rm -f "$work.old" "$work.new"
        git -C "$DIR/hydra" worktree remove --force "$work"
    else
        problems+=("hydra: cannot check $PREV_TAG out to compare the surface")
    fi
fi

if [ -n "$REMOVED" ] && [ "$DO_MINOR" -eq 1 ]; then
    echo "  $(printf '%s\n' "$REMOVED" | wc -l) symbol(s) removed — the release notes owe consumers an upgrade path:"
    printf '%s\n' "$REMOVED" | sed 's/^/    - /'
fi

if [ -n "$REMOVED" ] && [ "$DO_MINOR" -eq 0 ]; then
    count=$(printf '%s\n' "$REMOVED" | wc -l)
    problems+=("hydra: $count public symbol(s) removed since $PREV_TAG, so $TAG breaks ^$OLD_SERIES — release it as ${NEW_SERIES%.*}.$(( ${NEW_SERIES#*.} + 1 )).0 --minor, or put the symbols back")
    printf '\nRemoved since %s:\n' "$PREV_TAG" >&2
    printf '%s\n' "$REMOVED" | sed 's/^/  - /' >&2
fi

# The documentation site is hand-written static HTML with no build step, and
# every stylesheet and script is referenced as ?v=<first 8 of the file's md5>.
# That key is kept by hand, so it drifts in one direction only and in silence:
# an edited asset published under its old key is served from cache, so the page
# looks right to whoever just wrote it and stale to everybody who had visited
# before. search.js drifted across v0.5.5 and shipped a search index no
# returning reader ever saw.
#
# A warning rather than a refusal. The wiki is neither of the repositories
# being tagged, it is live whatever this script decides, and a release that is
# otherwise correct should not be held for it.
WIKI="${HYDRA_WIKI_DIR:-$HOME/.mount/hydra}/public"

if [ -d "$WIKI" ] && command -v md5sum >/dev/null 2>&1; then
    stale=()

    while IFS= read -r ref; do
        asset="${ref%%\?v=*}"
        key="${ref##*\?v=}"

        if [ ! -f "$WIKI/$asset" ]; then
            stale+=("$asset is referenced but not there")
            continue
        fi

        actual=$(md5sum "$WIKI/$asset" | cut -c1-8)
        [ "$key" = "$actual" ] || stale+=("$asset: pages say ?v=$key, the file is $actual")
    done < <(grep -rhoE '[A-Za-z0-9_/.-]+\.(css|js)\?v=[0-9a-f]+' --include='*.html' "$WIKI" | sort -u)

    if [ ${#stale[@]} -gt 0 ]; then
        printf '\nWiki cache keys are stale — returning readers are served the old file:\n' >&2
        printf '  - %s\n' "${stale[@]}" >&2
        printf 'Fix under %s; the release is not blocked by this.\n' "$WIKI" >&2
    fi
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
n=$([ "$DO_MINOR" -eq 1 ] && echo 1 || echo 0)
echo "  $((n + 1)). tag hydra $TAG and push $BRANCH + tag"
echo "  $((n + 2)). the split workflow regenerates the ${#PACKAGES[@]} package repos at $TAG"
echo "  $((n + 3)). wait for Packagist to index all ${#PACKAGES[@]} at $TAG, then write version.json$([ "$DO_SECURITY" -eq 1 ] && echo " marking it a security fix")"
echo "  $((n + 4)). refresh app's lock onto $TAG and verify the skeleton against it"
echo "  $((n + 5)). commit the lock, tag app $TAG and push"

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
echo "Tagging hydra ..."
git -C "$DIR/hydra" tag -a "$TAG" -m "Release $TAG"
git -C "$DIR/hydra" push --quiet origin "$BRANCH"
git -C "$DIR/hydra" push --quiet origin "$TAG"
echo "  hydra $TAG pushed — the split workflow is regenerating the package repos"

# app cannot be locked onto a release that is not resolvable yet, which is the
# whole reason the two tags are no longer cut together.
echo
echo "Waiting for Packagist to index $TAG ..."
deadline=$(( SECONDS + 600 ))

for pkg in "${PACKAGES[@]}"; do
    until curl -fsS "https://repo.packagist.org/p2/hydrakit/$pkg.json" 2>/dev/null \
        | grep -q "\"version\":\"$TAG\""
    do
        [ "$SECONDS" -lt "$deadline" ] \
            || die "hydrakit/$pkg never showed $TAG on Packagist. hydra is tagged and split; once it lands, finish with: cd $DIR/app && composer update \"hydrakit/*\" --no-install && git commit -am 'chore: lock the skeleton to $TAG' && git tag -a $TAG -m \"Release $TAG\" && git push origin $BRANCH $TAG"
        sleep 10
    done
    echo "  hydrakit/$pkg $TAG"
done

# What the admin's update check reads. Not before Packagist has the tag, or
# every install is told to upgrade to a release composer cannot find yet.
if [ -d "$WIKI" ]; then
    php "$DIR/hydra/bin/version-feed.php" "$WIKI/version.json" "$VERSION" \
        $([ "$DO_SECURITY" -eq 1 ] && echo --security) \
        && echo "  version.json names $TAG" \
        || echo "warning: version.json was not updated; installs will not hear of $TAG" >&2
else
    echo "warning: no wiki at $WIKI; version.json was not updated" >&2
fi

# --no-install because app/vendor holds the symlinks to hydra/packages/*, and a
# plain update would replace them with copies from Packagist.
echo
echo "Locking the skeleton onto $TAG ..."
(cd "$DIR/app" && composer update "hydrakit/*" --no-install --no-interaction --quiet)
(cd "$DIR/app" && composer validate --strict --quiet) \
    || die "app: composer.json and the refreshed lock disagree"
behind=$(php "$DIR/hydra/bin/lock-behind.php" "$DIR/app/composer.lock" "$TAG")
[ -z "$behind" ] \
    || die "app: the refreshed lock holds $(printf '%s' "$behind" | tr '\n' ',' | sed 's/,/, /g') rather than $TAG. hydra is tagged; app is not. \`composer why-not <package> $VERSION\` in app says why — usually an extension this PHP lacks"

# The lock is only a claim until something installs from it. This is the check
# app's own CI runs, brought forward to where it can still stop the tag.
echo "Verifying the skeleton against the published packages ..."
verify=$(mktemp -d)
# Cleared only on success. When a check below fails this directory is the
# only copy of the tree it judged, and removing it regardless is the other
# half of why the last failure could not be looked at.
trap '[ "${VERIFY_OK:-0}" = 1 ] && rm -rf "$verify"' EXIT
git -C "$DIR/app" archive --format=tar "$BRANCH" | tar -x -C "$verify"
cp "$DIR/app/composer.lock" "$verify/composer.lock"

run_checked "app: composer install against $TAG" "$verify" \
    composer install --no-interaction --no-progress \
    || die "app: the skeleton does not install from $TAG. hydra is tagged; app is not. Fix the skeleton, then re-run for the next patch. The tree it judged is kept at $verify"
run_checked "app: phpunit against $TAG" "$verify" ./vendor/bin/phpunit \
    || die "app: the suite fails against $TAG. hydra is tagged; app is not. The tree it judged is kept at $verify"
run_checked "app: phpstan against $TAG" "$verify" ./vendor/bin/phpstan analyse --no-progress \
    || die "app: phpstan fails against $TAG. hydra is tagged; app is not. The tree it judged is kept at $verify"
VERIFY_OK=1
echo "  installs, tests and analyses clean"

echo
echo "Tagging app ..."
git -C "$DIR/app" commit -qam "chore: lock the skeleton to $TAG"
git -C "$DIR/app" tag -a "$TAG" -m "Release $TAG"
git -C "$DIR/app" push --quiet origin "$BRANCH"
git -C "$DIR/app" push --quiet origin "$TAG"
echo "  app $TAG pushed"

cat <<EOF

Done — hydra and app at $TAG, and app's lock names $TAG rather than the
release before it.

The skeleton still ships no composer.lock: .gitattributes marks it
export-ignore, so create-project resolves the constraints fresh. The tracked
lock is what this checkout and app's CI install from, and it is now current.
EOF
