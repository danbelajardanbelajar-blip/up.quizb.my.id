#!/usr/bin/env bash
# Exercises installer with mocked PHP/crontab; never modifies the machine's crontab.
set -euo pipefail
project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -f -- "$tmp/bin/php-fixture" "$tmp/bin/crontab" "$tmp/state" "$tmp/before"; rmdir "$tmp/bin" "$tmp" 2>/dev/null || true' EXIT
mkdir "$tmp/bin"
cat >"$tmp/bin/php-fixture" <<'PHP'
#!/usr/bin/env bash
exit 0
PHP
cat >"$tmp/bin/crontab" <<'CRON'
#!/usr/bin/env bash
if [[ "${MOCK_DENY:-}" == yes ]]; then echo 'Permission denied' >&2; exit 1; fi
if [[ "$1" == '-l' ]]; then cat "$MOCK_CRON"; else cp -- "$1" "$MOCK_CRON"; fi
CRON
chmod +x "$tmp/bin/php-fixture" "$tmp/bin/crontab"
export PATH="$tmp/bin:$PATH" PHP_BIN="$tmp/bin/php-fixture" MOCK_CRON="$tmp/state" QUIZB_PUSH_LOG="$tmp/job.log"
printf '15 2 * * * /usr/bin/true\n# Keep this unrelated comment\n' >"$MOCK_CRON"
bash "$project/bin/setup-notifications.sh"
bash "$project/bin/setup-notifications.sh"
[[ "$(grep -c 'notification-worker.php' "$MOCK_CRON")" == 1 ]]
grep -q '^15 2 \* \* \* /usr/bin/true$' "$MOCK_CRON"
grep -q '^# Keep this unrelated comment$' "$MOCK_CRON"
echo 'PASS: cron installation is idempotent and preserves unrelated jobs/comments'
cp "$MOCK_CRON" "$tmp/before"
export MOCK_DENY=yes
if bash "$project/bin/setup-notifications.sh"; then echo 'Installer must fail on denied crontab access' >&2; exit 1; fi
cmp "$tmp/before" "$MOCK_CRON"
echo 'PASS: denied cron access does not overwrite existing crontab'
unset MOCK_DENY
export QUIZB_PUSH_LOG="$tmp/invalid;command"
if bash "$project/bin/setup-notifications.sh"; then echo 'Installer must reject unsafe command paths' >&2; exit 1; fi
echo 'PASS: unsafe cron command paths are rejected'
