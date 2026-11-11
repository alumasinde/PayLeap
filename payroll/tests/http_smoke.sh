#!/usr/bin/env bash
# End-to-end auth smoke test. Expects the app on 127.0.0.1:8090 and MySQL seeded.

set -uo pipefail

BASE="http://127.0.0.1:8090"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
JAR="$(mktemp)"
INVITE_HTML="$(mktemp)"
LOGIN_HTML="$(mktemp)"
APP_HTML="$(mktemp)"

trap 'rm -f "$JAR" "$INVITE_HTML" "$LOGIN_HTML" "$APP_HTML"' EXIT

fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1 ${2:-}"; fail=1; }
expect() {
  if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi
}

status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login")
expect "GET /login is 200" "$status" "200"

status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/login" -d 'email=x@y.z&password=nope')
expect "POST without CSRF is 419" "$status" "419"

curl -s -c "$JAR" -o "$LOGIN_HTML" "$BASE/login"
token=$(grep -o 'name="_token" value="[^"]*"' "$LOGIN_HTML" | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$token" ]; then ok "login form carries a CSRF token"; else bad "login form carries a CSRF token"; fi

session_before=$(grep mshahara_payroll_session "$JAR" | awk '{print $7}' | tail -1)

email="smoke+$(date +%s)@example.test"
seed_out=$(cd "$ROOT" && php bin/seed.php "Smoke Co" "$email")
invite_url=$(echo "$seed_out" | grep -o 'http[^ ]*invite/[A-Za-z0-9_-]*' | head -1)

if [ -n "$invite_url" ]; then ok "seed prints an invite URL"; else bad "seed prints an invite URL"; exit 1; fi

curl -s -c "$JAR" -b "$JAR" -o "$INVITE_HTML" "$invite_url"
itoken=$(grep -o 'name="_token" value="[^"]*"' "$INVITE_HTML" | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$itoken" ]; then ok "invite page carries a CSRF token"; else bad "invite page carries a CSRF token"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$invite_url" \
  --data-urlencode "_token=$itoken" \
  --data-urlencode "name=Smoke Owner" \
  --data-urlencode "password=smoke password 1" \
  --data-urlencode "password_confirmation=smoke password 1")
expect "invite acceptance redirects" "$status" "302"

session_after=$(grep mshahara_payroll_session "$JAR" | awk '{print $7}' | tail -1)
if [ "$session_before" != "$session_after" ]; then ok "session id rotates on login"; else bad "session id rotates on login"; fi

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app")
expect "GET /app is 200" "$status" "200"
if grep -q "Smoke Co" "$APP_HTML"; then ok "workspace shows the company"; else bad "workspace shows the company"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/me")
expect "GET /me is 200" "$status" "200"

logout_token=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/logout" --data-urlencode "_token=$logout_token")
expect "logout redirects" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "workspace requires a session after logout" "$status" "302"

# ------------------------------------------------------------------- reset flow

curl -s -c "$JAR" -b "$JAR" -o /tmp/forgot.html "$BASE/forgot"
ftoken=$(grep -o 'name="_token" value="[^"]*"' /tmp/forgot.html | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/forgot" \
  --data-urlencode "_token=$ftoken" --data-urlencode "email=$email")
expect "forgot password answers with a redirect" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/forgot" \
  --data-urlencode "_token=$ftoken" --data-urlencode "email=nobody@example.test")
expect "unknown email gets the same answer" "$status" "302"

mail=$(ls -t "$ROOT"/storage/mail/*.eml 2>/dev/null | head -1)
reset_url=$(grep -o 'http[^ ]*/reset/[A-Za-z0-9_-]*' "$mail" | head -1)
if [ -n "$reset_url" ]; then ok "reset email contains a link"; else bad "reset email contains a link"; fi

curl -s -c "$JAR" -b "$JAR" -o /tmp/reset.html "$reset_url"
rtoken=$(grep -o 'name="_token" value="[^"]*"' /tmp/reset.html | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$reset_url" \
  --data-urlencode "_token=$rtoken" \
  --data-urlencode "password=brand new password 2" \
  --data-urlencode "password_confirmation=brand new password 2")
expect "reset completes with a redirect" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$reset_url" \
  --data-urlencode "_token=$rtoken" \
  --data-urlencode "password=another password 3" \
  --data-urlencode "password_confirmation=another password 3")
expect "used reset link is rejected" "$status" "410"

curl -s -c "$JAR" -b "$JAR" -o "$LOGIN_HTML" "$BASE/login"
ltoken=$(grep -o 'name="_token" value="[^"]*"' "$LOGIN_HTML" | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data-urlencode "_token=$ltoken" --data-urlencode "email=$email" --data-urlencode "password=brand new password 2")
expect "login with the new password works" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "workspace opens after the new login" "$status" "200"

# ------------------------------------------------------- sign out everywhere

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/me"
mtoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$mtoken" ]; then ok "account page carries a CSRF token"; else bad "account page carries a CSRF token"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/me/sign-out-everywhere" \
  --data-urlencode "_token=$mtoken")
expect "sign out everywhere redirects" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "sign out everywhere ends the session" "$status" "302"

echo
if [ "$fail" -ne 0 ]; then
  echo "HTTP smoke failed."
  exit 1
fi

echo "HTTP smoke passed."
