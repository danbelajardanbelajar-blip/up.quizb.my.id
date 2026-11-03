#!/usr/bin/env bash
# Run as the owning cPanel user after git pull. Preserves unrelated cron entries.
set -euo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-$(command -v php || true)}"
worker="$project_dir/bin/notification-worker.php"
log_file="${QUIZB_PUSH_LOG:-$HOME/quizb-notifications.log}"
if [[ -z "$php_bin" || ! -x "$php_bin" ]]; then
  echo 'PHP CLI not found. Set PHP_BIN to the absolute executable path.' >&2; exit 1
fi
# The known Rumahweb paths have no whitespace; reject ambiguous crontab syntax.
for cron_path in "$php_bin" "$worker" "$log_file"; do
  if [[ ! "$cron_path" =~ ^/[A-Za-z0-9_./-]+$ ]]; then
    echo 'Use absolute paths containing only letters, digits, slash, dot, underscore, and hyphen.' >&2; exit 1
  fi
done
"$php_bin" -r 'if (PHP_VERSION_ID < 80000) { fwrite(STDERR, "PHP 8+ CLI is required.\n"); exit(1); }'
"$php_bin" "$worker" --migrate --check --probe-firebase
cron_line="* * * * * $php_bin -q $worker --limit=100 --seconds=40 >> $log_file 2>&1"
if ! command -v crontab >/dev/null; then
  printf 'Configure this command every minute in cPanel Cron Jobs:\n%s\n' "$cron_line" >&2; exit 1
fi
cron_tmp="$(mktemp)"
trap 'rm -f -- "$cron_tmp" "$cron_tmp.err" "$cron_tmp.new"' EXIT
if ! crontab -l >"$cron_tmp" 2>"$cron_tmp.err"; then
  if grep -qi 'no crontab' "$cron_tmp.err"; then : >"$cron_tmp";
  else cat "$cron_tmp.err" >&2; exit 1; fi
fi
awk -v worker="$worker" '/^[[:space:]]*#/ || index($0, worker) == 0' "$cron_tmp" >"$cron_tmp.new"
printf '%s\n' "$cron_line" >>"$cron_tmp.new"
crontab "$cron_tmp.new"
echo 'Notification cron installed (one worker per minute).'
printf 'Log: %s\n' "$log_file"
