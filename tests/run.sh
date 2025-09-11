#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

PHP="php"

export EFORMS_LOG_MODE=jsonl

pass=0
fail=0

mkdir -p tmp/uploads/eforms-private
touch tmp/uploads/eforms-private/eforms.log

function run_test() {
  local name="$1"; shift
  echo "[TEST] $name"
  rm -rf tmp && mkdir -p tmp/uploads/eforms-private
  touch tmp/uploads/eforms-private/eforms.log
  : > tmp/headers.txt
  set +e
  $PHP "integration/$name.php" > tmp/stdout.txt 2> tmp/stderr.txt
  local code=$?
  set -e
  echo "  -> exit code: $code"
}

function run_test_keep() {
  local name="$1"; shift
  echo "[TEST] $name (keep tmp)"
  mkdir -p tmp/uploads/eforms-private
  touch tmp/uploads/eforms-private/eforms.log
  : > tmp/headers.txt
  set +e
  $PHP "integration/$name.php" > tmp/stdout.txt 2> tmp/stderr.txt
  local code=$?
  set -e
  echo "  -> exit code: $code"
}

function assert_grep() {
  local file="$1"; shift
  local pattern="$1"; shift
  if grep -qE "$pattern" "$file"; then
    return 0
  else
    echo "  ASSERT FAIL: '$pattern' not found in $file"
    return 1
  fi
}

function assert_equal_file() {
  local file="$1"; shift
  local expected="$1"; shift
  local got="$(cat "$file" 2>/dev/null || true)"
  if [[ "$got" == "$expected" ]]; then
    return 0
  else
    echo "  ASSERT FAIL: expected $expected, got $got (from $file)"
    return 1
  fi
}

function record_result() {
  local name="$1"; shift
  local ok="$1"; shift
  if [[ "$ok" -eq 0 ]]; then
    echo "[PASS] $name"
    pass=$((pass+1))
  else
    echo "[FAIL] $name"
    fail=$((fail+1))
  fi
}

# Schema validation for templates
run_test template_schema_check
ok=0
assert_grep tmp/stdout.txt '^OK$' || ok=1
record_result "template schema parity" $ok

# Spec vs schema parity
run_test test_schema_parity
ok=0
assert_grep tmp/stdout.txt '^OK$' || ok=1
record_result "spec schema parity" $ok

# Prime endpoint cookie attributes
run_test test_prime_cookie
ok=0
assert_grep tmp/headers.txt 'Set-Cookie: eforms_t_contact_us=' || ok=1
assert_grep tmp/headers.txt 'Max-Age=' || ok=1
record_result "prime sets max-age" $ok

# 1) Submit route: 405
run_test test_submit_405
ok=0
assert_equal_file tmp/status_code.txt "405" || ok=1
record_result "submit 405 on non-POST" $ok

# 1) Submit route: 415
run_test test_submit_415
ok=0
assert_equal_file tmp/status_code.txt "415" || ok=1
assert_grep tmp/stdout.txt "Unsupported Media Type" || ok=1
record_result "submit 415 on bad content-type" $ok

# 1b) Submit route: multipart boundary valid
run_test test_submit_boundary_valid
ok=0
assert_equal_file tmp/status_code.txt "" || ok=1
record_result "submit multipart valid boundary" $ok

# 1c) Submit route: multipart boundary missing
run_test test_submit_boundary_missing
ok=0
assert_equal_file tmp/status_code.txt "415" || ok=1
assert_grep tmp/stdout.txt "Unsupported Media Type" || ok=1
record_result "submit multipart missing boundary" $ok

# 1d) Submit route: multipart boundary empty
run_test test_submit_boundary_empty
ok=0
assert_equal_file tmp/status_code.txt "415" || ok=1
assert_grep tmp/stdout.txt "Unsupported Media Type" || ok=1
record_result "submit multipart empty boundary" $ok

# 2) Origin soft default: allow cross origin
run_test test_origin_soft
ok=0
assert_grep tmp/redirect.txt '"status":303' || ok=1
assert_grep tmp/redirect.txt '\\?eforms_success=contact_us' || ok=1
assert_grep tmp/mail.json 'alice@example.com' || ok=1
assert_grep tmp/headers.txt 'Cache-Control: private, no-store, max-age=0' || ok=1
record_result "origin policy soft: not blocked" $ok

# 2) Origin hard: block cross origin
run_test test_origin_hard
ok=0
assert_grep tmp/stdout.txt 'Security check failed\.' || ok=1
! assert_grep tmp/mail.json 'alice@example.com' || ok=1
record_result "origin policy hard: blocked" $ok

# 2b) Cookie missing policies
run_test test_cookie_policy_hard
ok=0
assert_grep tmp/stdout.txt 'This form was already submitted or has expired – please reload the page\.' || ok=1
! assert_grep tmp/mail.json 'zed@example.com' || ok=1
record_result "cookie policy hard: missing cookie hard fail" $ok

run_test test_cookie_policy_challenge
ok=0
assert_grep tmp/stdout.txt 'Security check failed\.' || ok=1
! assert_grep tmp/mail.json 'zed@example.com' || ok=1
record_result "cookie policy challenge: unconfigured blocks" $ok

# 2c) Challenge verification
run_test test_challenge_success
ok=0
assert_grep tmp/redirect.txt '"status":303' || ok=1
assert_grep tmp/mail.json 'zed@example.com' || ok=1
assert_equal_file tmp/uploads/eforms-private/eforms.log '' || ok=1
record_result "challenge success clears soft signal" $ok

run_test test_challenge_fail
ok=0
assert_grep tmp/stdout.txt 'Security challenge failed\.' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_CHALLENGE_FAILED' || ok=1
! assert_grep tmp/mail.json 'zed@example.com' || ok=1
record_result "challenge failure logged" $ok

# 3) Honeypot stealth success
run_test test_honeypot
ok=0
assert_grep tmp/redirect.txt '"status":303' || ok=1
assert_grep tmp/redirect.txt '\\?eforms_success=contact_us' || ok=1
! assert_grep tmp/mail.json 'bot-foo|alice@example.com|zed@example.com' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_HONEYPOT' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"stealth":true' || ok=1
record_result "honeypot: stealth success, no email" $ok

# 3b) Honeypot hard fail
run_test test_honeypot_hard
ok=0
assert_grep tmp/stdout.txt 'Form submission failed\.' || ok=1
! assert_grep tmp/redirect.txt '"status":303' || ok=1
! assert_grep tmp/mail.json 'bot-foo|alice@example.com|zed@example.com' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_HONEYPOT' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"stealth":false' || ok=1
record_result "honeypot: hard fail" $ok

# 3c) Honeypot throttle increments
EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 run_test test_honeypot_throttle_first
EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 run_test_keep test_honeypot_throttle_second
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_HONEYPOT' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"throttle_state":"ok"' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"throttle_state":"over"' || ok=1
record_result "honeypot: throttle increments logged" $ok

# 3d) Timing checks
run_test test_timing_min_fill
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_MIN_FILL' || ok=1
assert_grep tmp/mail.json 'alice@example.com' || ok=1
record_result "timing: early submission soft fail" $ok

run_test test_timing_expired
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_FORM_AGE' || ok=1
assert_grep tmp/mail.json 'zed@example.com' || ok=1
record_result "timing: expired form soft fail" $ok

# 3e) JS disabled checks
run_test test_js_soft
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_JS_DISABLED' || ok=1
assert_grep tmp/mail.json 'alice@example.com' || ok=1
record_result "js disabled: soft signal" $ok

run_test test_js_hard
ok=0
assert_grep tmp/stdout.txt 'Security check failed\.' || ok=1
! assert_grep tmp/mail.json 'alice@example.com' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_ERR_JS_DISABLED' || ok=1
record_result "js disabled: hard fail" $ok

# 4) Validation missing required
run_test test_validation_required
ok=0
assert_grep tmp/stdout.txt 'This field is required\.' || ok=1
record_result "validation: missing required fields" $ok

# 4) Validation formats
run_test test_validation_formats
ok=0
assert_grep tmp/stdout.txt 'Invalid email\.' || ok=1
assert_grep tmp/stdout.txt 'Invalid ZIP\.' || ok=1
assert_grep tmp/stdout.txt 'Invalid phone\.' || ok=1
record_result "validation: email/zip/tel formats" $ok

# 5) Ledger reserve duplicate token
run_test test_ledger_dup_first
run_test_keep test_ledger_dup_second
ok=0
assert_grep tmp/stdout.txt 'Already submitted or expired\.' || ok=1
record_result "ledger reserve: duplicate token" $ok

# 5b) Ledger disabled skips file
EFORMS_TOKEN_LEDGER_ENABLE=0 run_test test_ledger_disable
ok=0
assert_grep tmp/stdout.txt '^missing$' || ok=1
record_result "ledger: disabled skips file" $ok

# 6) Success inline: renderer shows message
run_test test_success_inline
ok=0
assert_grep tmp/out_success_inline.html 'Thanks! We got your message\.' || ok=1
assert_grep tmp/headers.txt 'Cache-Control: private, no-store, max-age=0' || ok=1
record_result "success inline: shows message" $ok

# 7) Minimal email: subject/to/body
run_test test_minimal_email
ok=0
assert_grep tmp/mail.json 'office@flooringartists.com' || ok=1
assert_grep tmp/mail.json 'Contact Form - Zed' || ok=1
assert_grep tmp/mail.json 'name: Zed' || ok=1
assert_grep tmp/mail.json 'email: zed@example.com' || ok=1
assert_grep tmp/mail.json 'message: Ping' || ok=1
record_result "minimal email: to/subject/body" $ok

# 7b) Email attachments
run_test test_email_attachment
ok=0
assert_grep tmp/mail.json 'doc.pdf' || ok=1
record_result "email attachments: file included" $ok

# 7c) Email policy autocorrect
EFORMS_EMAIL_POLICY=autocorrect run_test test_email_policy_autocorrect
ok=0
assert_grep tmp/mail.json 'a@example.com' || ok=1
record_result "email policy autocorrect" $ok

# 7d) Email disable send
EFORMS_EMAIL_DISABLE_SEND=1 run_test test_email_disable_send
ok=0
assert_equal_file tmp/mail.json '[]' || ok=1
record_result "email disable send" $ok

# 7e) Email staging redirect
EFORMS_EMAIL_STAGING_REDIRECT_TO=stage@example.com run_test test_email_staging_redirect
ok=0
assert_grep tmp/mail.json 'stage@example.com' || ok=1
assert_grep tmp/mail.json '\[STAGING\]' || ok=1
assert_grep tmp/mail.json 'X-EForms-Env: staging' || ok=1
assert_grep tmp/mail.json 'X-EForms-Original-To: a@example.com' || ok=1
record_result "email staging redirect" $ok

# 8) Logging minimal: SMTP failure
run_test test_logging
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log '"severity":"error"' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"code":"EFORMS_EMAIL_FAIL"' || ok=1
record_result "logging: SMTP failure" $ok

# 8b) Logging minimal file output
EFORMS_LOG_MODE=minimal run_test test_logging_minimal
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'severity=error' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log 'code=EFORMS_EMAIL_FAIL' || ok=1
record_result "logging: minimal file output" $ok

# 8c) Logging User-Agent sanitization
EFORMS_LOG_HEADERS=1 run_test test_logging_user_agent
ok=0
assert_grep tmp/ua.txt '^A{256}$' || ok=1
record_result "logging: user-agent sanitized" $ok

# 9) Upload valid
run_test test_upload_valid
ok=0
assert_equal_file tmp/uploaded.txt '' || ok=1
record_result "upload: valid file handled" $ok

# 9b) Upload too large
EFORMS_UPLOAD_MAX_FILE_BYTES=100 run_test test_upload_reject
ok=0
assert_grep tmp/stdout.txt 'This file exceeds the size limit\.' || ok=1
! assert_grep tmp/uploaded.txt 'eforms-private' || ok=1
record_result "upload: file too large rejected" $ok

# 9c) Upload retention GC
EFORMS_UPLOAD_RETENTION_SECONDS=1 run_test test_upload_gc
ok=0
assert_equal_file tmp/gc.txt 'empty' || ok=1
record_result "upload: retention GC" $ok

# 10) Throttle soft and hard
EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 run_test test_throttle_soft_first
EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 run_test_keep test_throttle_soft_second
ok=0
assert_grep tmp/uploads/eforms-private/eforms.log 'EFORMS_THROTTLE' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"state":"over"' || ok=1
record_result "throttle: soft over-limit" $ok

EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 EFORMS_THROTTLE_HARD_MULTIPLIER=1.5 run_test test_throttle_hard_first
EFORMS_THROTTLE_ENABLE=1 EFORMS_THROTTLE_MAX_PER_MINUTE=1 EFORMS_THROTTLE_HARD_MULTIPLIER=1.5 run_test_keep test_throttle_hard_second
ok=0
assert_grep tmp/stdout.txt 'Security check failed\.' || ok=1
! assert_grep tmp/mail.json 'alice@example.com.*alice@example.com' || ok=1
assert_grep tmp/uploads/eforms-private/eforms.log '"state":"hard"' || ok=1
record_result "throttle: hard over-limit" $ok

# 11) Cache-Control headers for render()
run_test test_render_cacheable
ok=0
! assert_grep tmp/headers.txt 'Cache-Control: private, no-store, max-age=0' || ok=1
record_result "render cacheable: no cache-control" $ok

run_test test_render_nocache
ok=0
assert_grep tmp/headers.txt 'Cache-Control: private, no-store, max-age=0' || ok=1
record_result "render non-cacheable: cache-control set" $ok

echo
echo "Summary: $pass passed, $fail failed"
exit $(( fail > 0 ))
