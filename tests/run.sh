#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Always isolate both the volume and the host port from the interactive demo.
export REDIS_BIND_PORT=0
project="bloom-test-$(date +%s)-$$"
compose=(docker compose -f compose.yaml -p "$project" --profile tools)

cleanup() {
    status=$?
    trap - EXIT
    if (( status != 0 )); then
        "${compose[@]}" logs --no-color redis >&2 || true
    fi
    "${compose[@]}" down --volumes --remove-orphans || true
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

expect() {
    local expected=$1 actual
    shift
    actual=$("$@")
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL: expected <%s>, got <%s>\n' "$expected" "$actual" >&2
        return 1
    fi
    printf 'PASS: CLI %s\n' "$expected"
}

"${compose[@]}" config --quiet
"${compose[@]}" pull redis
"${compose[@]}" build --pull php
"${compose[@]}" up -d --wait --wait-timeout 60 redis
"${compose[@]}" exec -T redis redis-server --version
"${compose[@]}" run --rm -T --no-deps php --version
"${compose[@]}" run --rm -T --no-deps --entrypoint sh php -ec \
    'for file in ./*.php tests/*.php; do php -l "$file"; done'
expect 'Usage: php demo.php init < ids.txt | add ID | check ID' \
    "${compose[@]}" run --rm -T --no-deps php demo.php --help
if "${compose[@]}" run --rm -T --no-deps php demo.php check; then
    printf 'FAIL: CLI accepted a missing ID\n' >&2
    exit 1
fi
printf 'PASS: CLI rejects a missing ID\n'
"${compose[@]}" run --rm -T --no-deps -e BLOOM_TEST=1 php tests/integration.php prepare

expect MAYBE "${compose[@]}" run --rm -T --no-deps php demo.php check 1001
expect ABSENT "${compose[@]}" run --rm -T --no-deps php demo.php check definitely-not-in-db
expect ADDED "${compose[@]}" run --rm -T --no-deps php demo.php add 1003
expect 'READY, input rows: 3' "${compose[@]}" run --rm -T --no-deps php demo.php init < ids.txt
expect ADDED "${compose[@]}" run --rm -T --no-deps php demo.php add 1003

# Save the exact bitmap after the CLI round trip, then exercise real AOF reload.
"${compose[@]}" run --rm -T --no-deps -e BLOOM_TEST=1 php tests/integration.php checkpoint
"${compose[@]}" restart redis
"${compose[@]}" up -d --wait --wait-timeout 60 redis
expect UNKNOWN "${compose[@]}" run --rm -T --no-deps php demo.php check 1001
"${compose[@]}" run --rm -T --no-deps -e BLOOM_TEST=1 php tests/integration.php recover
printf '\nPASS: container integration suite completed\n'
