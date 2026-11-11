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

# The limiter is MySQL-backed, so prior runs would otherwise carry over.
(cd "$ROOT" && php -r '
require "vendor/autoload.php";
Mshahara\Payroll\Support\Env::load(__DIR__ . "/.env");
$c = require __DIR__ . "/config/app.php";
Mshahara\Payroll\Support\Db::connect($c["db"])->exec("DELETE FROM rate_limits");
') >/dev/null 2>&1

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
seed_out=$(cd "$ROOT" && php bin/seed.php "Smoke Co $(date +%s)" "$email")
invite_url=$(echo "$seed_out" | grep -o 'http[^ ]*invite/[A-Za-z0-9_-]*' | head -1)

if [ -n "$invite_url" ]; then ok "seed prints an invite URL"; else bad "seed prints an invite URL"; exit 1; fi

curl -s -c "$JAR" -b "$JAR" -o "$INVITE_HTML" "$invite_url"
itoken=$(grep -o 'name="_token" value="[^"]*"' "$INVITE_HTML" | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$itoken" ]; then ok "invite page carries a CSRF token"; else bad "invite page carries a CSRF token"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$invite_url" \
  --data-urlencode "_token=$itoken" \
  --data-urlencode "first_name=Smoke" \
  --data-urlencode "last_name=Owner" \
  --data-urlencode "password=smoke password 1" \
  --data-urlencode "password_confirmation=smoke password 1")
expect "invite acceptance redirects" "$status" "302"

session_after=$(grep mshahara_payroll_session "$JAR" | awk '{print $7}' | tail -1)
if [ "$session_before" != "$session_after" ]; then ok "session id rotates on login"; else bad "session id rotates on login"; fi

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app")
expect "GET /app is 200" "$status" "200"
if grep -q "Smoke Co" "$APP_HTML"; then ok "workspace shows the company"; else bad "workspace shows the company"; fi
if grep -q "Owner" "$APP_HTML"; then ok "workspace shows the database role name"; else bad "workspace shows the database role name"; fi

# ------------------------------------------------------------ access management

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/app/access"
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app/access")
expect "GET /app/access is 200" "$status" "200"
if grep -q "Invite someone" "$APP_HTML"; then ok "access page has the invite form"; else bad "access page has the invite form"; fi

atoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
role_id=$(grep -o '<option value="[0-9]*">Employee</option>' "$APP_HTML" | head -1 | sed 's/[^0-9]//g')
access_email="access+$(date +%s)@example.test"

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/app/access/invite" \
  --data-urlencode "_token=$atoken" \
  --data-urlencode "email=$access_email" \
  --data-urlencode "role_id=$role_id")
expect "access invitation redirects" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/app/access"
if grep -q "$access_email" "$APP_HTML"; then ok "pending invitation is listed"; else bad "pending invitation is listed"; fi

role_url=$(grep -o 'href="/app/access/roles/[0-9]*"' "$APP_HTML" | head -1 | sed 's/href="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE$role_url")
expect "GET /app/access/roles/{id} is 200" "$status" "200"

# ------------------------------------------------------------------- settings

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app/settings")
expect "GET /app/settings is 200" "$status" "200"
if grep -q "Leave policy" "$APP_HTML" || grep -q "Company name" "$APP_HTML"; then
  ok "settings opens on the organisation tab"
else
  bad "settings opens on the organisation tab"
fi

for tab in leave shifts devices; do
  status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app/settings?tab=$tab")
  expect "GET /app/settings?tab=$tab is 200" "$status" "200"
done

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/app/settings?tab=holidays"
if grep -q "New Year" "$APP_HTML"; then ok "new companies get statutory holidays"; else bad "new companies get statutory holidays"; fi

stoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE/app/settings/holidays" \
  --data-urlencode "_token=$stoken" \
  --data-urlencode "holiday_date=2027-03-20" \
  --data-urlencode "name=Idd ul-Fitr")
expect "holiday creation redirects" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/app/settings?tab=leave&year=2027"
if grep -q "Idd ul-Fitr" "$APP_HTML"; then ok "the holiday is listed"; else bad "the holiday is listed"; fi

# ------------------------------------------------------------------ employees

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app/employees")
expect "GET /app/employees is 200" "$status" "200"
if grep -q "Add employee" "$APP_HTML"; then ok "register offers to add people"; else bad "register offers to add people"; fi

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app/employees/new")
expect "GET /app/employees/new is 200" "$status" "200"
if grep -q "Reports to" "$APP_HTML" && grep -q "Portal login" "$APP_HTML"; then
  ok "employee form sets the approver and login"
else
  bad "employee form sets the approver and login"
fi
etoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
emp_no="SMK-$(date +%s)"

location=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" -X POST "$BASE/app/employees" \
  --data-urlencode "_token=$etoken" \
  --data-urlencode "employee_no=$emp_no" \
  --data-urlencode "first_name=Njeri" \
  --data-urlencode "last_name=Wambui" \
  --data-urlencode "job_title=Clerk" \
  --data-urlencode "national_id=12345678" \
  --data-urlencode "kra_pin=A012345678Z" \
  --data-urlencode "monthly_salary=45000.00" \
  --data-urlencode "start_date=2024-03-01")
emp_path=${location#"$BASE"}
if [ -n "$emp_path" ]; then ok "employee creation redirects"; else bad "employee creation redirects"; fi

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE$emp_path")
expect "employee record opens" "$status" "200"
if grep -q "Wambui" "$APP_HTML" && grep -q "45,000.00" "$APP_HTML"; then
  ok "record shows the person and salary"
else
  bad "record shows the person and salary"
fi

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/app/employees"
if grep -q "$emp_no" "$APP_HTML"; then ok "employee appears in the register"; else bad "employee appears in the register"; fi

etoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$emp_path" \
  --data-urlencode "_token=$etoken" \
  --data-urlencode "first_name=Njeri" \
  --data-urlencode "last_name=Wambui" \
  --data-urlencode "job_title=Senior clerk" \
  --data-urlencode "start_date=2024-03-01")
expect "employee update redirects" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE$emp_path"
if grep -q "Senior clerk" "$APP_HTML"; then ok "employee update is saved"; else bad "employee update is saved"; fi

# ------------------------------------------------------------------- payroll

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE/app/payroll")
expect "GET /app/payroll is 200" "$status" "200"
if grep -q "Prepare a month" "$APP_HTML"; then ok "payroll offers to prepare a run"; else bad "payroll offers to prepare a run"; fi

ptoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
location=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" -X POST "$BASE/app/payroll" \
  --data-urlencode "_token=$ptoken" \
  --data-urlencode "period=2026-03" \
  --data-urlencode "label=Smoke payroll")
pay_path=${location#"$BASE"}
if [ -n "$pay_path" ]; then ok "payroll run is prepared"; else bad "payroll run is prepared"; fi

status=$(curl -s -b "$JAR" -o "$APP_HTML" -w '%{http_code}' "$BASE$pay_path")
expect "payroll run opens" "$status" "200"
if grep -q "Draft" "$APP_HTML" && grep -q "Wambui" "$APP_HTML"; then
  ok "run lists the employee and stays draft"
else
  bad "run lists the employee and stays draft"
fi

ptoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$pay_path/approve" \
  --data-urlencode "_token=$ptoken")
expect "self-approval answers with a redirect" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE$pay_path"
if grep -q "Draft" "$APP_HTML"; then ok "self-approval leaves the run in draft"; else bad "self-approval leaves the run in draft"; fi

slip_path=$(grep -o 'href="/app/payroll/[0-9]*/lines/[0-9]*/payslip"' "$APP_HTML" | head -1 | sed 's/href="//;s/"$//')
if [ -n "$slip_path" ]; then ok "the run links to a payslip"; else bad "the run links to a payslip"; fi

status=$(curl -s -b "$JAR" -o /tmp/payslip.html -w '%{http_code}' "$BASE$slip_path")
expect "payslip opens" "$status" "200"
if grep -q "Statutory detail" /tmp/payslip.html && grep -q "PAYE" /tmp/payslip.html && grep -q "Net pay" /tmp/payslip.html; then
  ok "payslip shows the statutory breakdown"
else
  bad "payslip shows the statutory breakdown"
fi
if grep -q "print.js" /tmp/payslip.html; then ok "payslip loads the print control"; else bad "payslip loads the print control"; fi

etoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$BASE$emp_path/archive" \
  --data-urlencode "_token=$etoken" --data-urlencode "exit_date=2025-06-30")
expect "employee archive redirects" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE$emp_path"
if grep -q "Archived" "$APP_HTML"; then ok "archive is shown on the record"; else bad "archive is shown on the record"; fi

# ----------------------------------------------------------------------- me

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/me")
expect "GET /me is 200" "$status" "200"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/me"
ptoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/me/profile" \
  --data-urlencode "_token=$ptoken" \
  --data-urlencode "first_name=Smoke" \
  --data-urlencode "last_name=Ownerly")
expect "profile update redirects" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/me"
if grep -q "Ownerly" "$APP_HTML"; then ok "profile change is shown"; else bad "profile change is shown"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/me/password" \
  --data-urlencode "_token=$ptoken" \
  --data-urlencode "current_password=brand new password 2" \
  --data-urlencode "password=brand new password 3" \
  --data-urlencode "password_confirmation=brand new password 3")
expect "password change redirects" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/me")
expect "password change keeps this session" "$status" "200"

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
temp_password=$(grep -E '^  [A-HJ-NP-Z2-9]{6}$' "$mail" | head -1 | tr -d ' ')
if [ -n "$temp_password" ]; then ok "reset email carries a six-character password"; else bad "reset email carries a six-character password"; fi

curl -s -c "$JAR" -b "$JAR" -o "$LOGIN_HTML" "$BASE/login"
ltoken=$(grep -o 'name="_token" value="[^"]*"' "$LOGIN_HTML" | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data-urlencode "_token=$ltoken" --data-urlencode "email=$email" --data-urlencode "password=$temp_password")
expect "temporary password signs in" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "workspace is blocked until the password changes" "$status" "302"

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/me"
mtoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/me/password" \
  --data-urlencode "_token=$mtoken" \
  --data-urlencode "current_password=$temp_password" \
  --data-urlencode "password=brand new password 2" \
  --data-urlencode "password_confirmation=brand new password 2")
expect "choosing a new password redirects" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "workspace opens after choosing a new password" "$status" "200"

# ------------------------------------------------------- sign out everywhere

curl -s -b "$JAR" -o "$APP_HTML" "$BASE/me"
mtoken=$(grep -o 'name="_token" value="[^"]*"' "$APP_HTML" | head -1 | sed 's/.*value="//;s/"$//')
if [ -n "$mtoken" ]; then ok "account page carries a CSRF token"; else bad "account page carries a CSRF token"; fi

status=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$BASE/me/sign-out-everywhere" \
  --data-urlencode "_token=$mtoken")
expect "sign out everywhere redirects" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app")
expect "sign out everywhere ends the session" "$status" "302"

status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/app/employees")
expect "the register requires a session" "$status" "302"

echo
if [ "$fail" -ne 0 ]; then
  echo "HTTP smoke failed."
  exit 1
fi

echo "HTTP smoke passed."
