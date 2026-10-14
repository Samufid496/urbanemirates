#!/usr/bin/env bash
# Urban Emirates — end-to-end smoke test against a local WordPress Playground site
# (see .claude/launch.json). Uses the DEV-ONLY helpers in dev/mu-plugins.
#   bash dev/smoke-test.sh http://127.0.0.1:9400
set -u
B="${1:-http://127.0.0.1:9400}"
T="$(mktemp -d 2>/dev/null || echo ./.smoke)"; mkdir -p "$T"
command -v cygpath >/dev/null && T="$(cygpath -m "$T")"  # Windows curl needs C:/ paths
PASS=0; FAIL=0
ok()   { echo "  ok   $1"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL $1"; FAIL=$((FAIL+1)); }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
php_errors() { grep -ciE '<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>' "$1"; }
field()  { python -c "import sys,re;s=open(sys.argv[1],encoding='utf-8',errors='ignore').read();m=re.search(sys.argv[2],s,re.S);print(m.group(1) if m else '')" "$1" "$2"; }

echo "Waiting for $B ..."
for i in $(seq 1 90); do b=$(curl -s "$B/" | head -c 300); case "$b" in *"not ready"*|"") sleep 4;; *) break;; esac; done

echo "1. Code"
curl -s "$B/?kj_dev_lint=1" > "$T/lint.txt"; check "PHP syntax: $(cat "$T/lint.txt")" "grep -q ', 0 with errors' '$T/lint.txt'"

echo "2. Public pages"
curl -s "$B/?kj_dev_seed=1" > /dev/null
for u in / /jobs/ "/jobs/?q=nurse&country=uae" "/jobs/?category=hospitality&posted=7&salary=1&benefit=visa" /job/staff-nurse/ /job-alerts/ /job-scam-safety/ /sign-in/ /cv-builder/ /my-account/ /privacy-policy/ /terms/; do
	code=$(curl -s -o "$T/p.html" -w "%{http_code}" "$B$u")
	check "$code no PHP errors $u" "[ '$code' = 200 ] && [ \$(php_errors '$T/p.html') = 0 ]"
done
curl -s "$B/" > "$T/home.html"
check "home shows job cards" "grep -q 'kj-card' '$T/home.html'"
check "home links Privacy and Terms" "grep -q '/privacy-policy/' '$T/home.html' && grep -q '/terms/' '$T/home.html'"
curl -s "$B/job/staff-nurse/" > "$T/job.html"
check "job page has JobPosting data" "grep -q '\"@type\":\"JobPosting\"' '$T/job.html'"
JID=$(field "$T/job.html" 'kj_go=(\d+)')
check "apply link redirects to original site" "curl -s -o /dev/null -w '%{redirect_url}' '$B/?kj_go=$JID' | grep -q 'linkedin.com'"

echo "3. Sign in with email code"
J="$T/seeker.txt"; rm -f "$J"
curl -s -c "$J" -b "$J" "$B/sign-in/" > "$T/si.html"
N=$(field "$T/si.html" 'value="send_code">.*?name="_wpnonce" value="([^"]+)"')
R=$(curl -s -c "$J" -b "$J" -o /dev/null -w "%{redirect_url}" --data "kj_auth=send_code&_wpnonce=$N&kj_email=smoke.tester%40example.com&kj_terms=1&kj_job_emails=1&kj_website=&redirect_to=" "$B/sign-in/")
check "code requested" "echo '$R' | grep -q 't='"
CODE=$(curl -s "$B/?kj_dev_mail=1" | grep -oE 'letter-spacing:8px;margin:8px 0 18px">[0-9]{6}' | tail -1 | grep -oE '[0-9]{6}$')
curl -s -c "$J" -b "$J" "$R" > "$T/code.html"
VN=$(field "$T/code.html" 'value="verify_code">.*?name="_wpnonce" value="([^"]+)"'); TK=$(echo "$R" | grep -oE 't=[A-Za-z0-9]+' | cut -d= -f2)
W=$(curl -s -c "$J" -b "$J" -o /dev/null -w "%{redirect_url}" --data "kj_auth=verify_code&_wpnonce=$VN&t=$TK&kj_code=000000" "$B/sign-in/")
check "wrong code rejected" "echo '$W' | grep -q 'kj_err=code'"
G=$(curl -s -c "$J" -b "$J" -o /dev/null -w "%{redirect_url}" --data "kj_auth=verify_code&_wpnonce=$VN&t=$TK&kj_code=$CODE" "$B/sign-in/")
check "right code signs in and goes to My account" "echo '$G' | grep -q '/my-account/'"
check "job seeker kept out of wp-admin" "curl -s -c '$J' -b '$J' -o /dev/null -w '%{redirect_url}' '$B/wp-admin/' | grep -q '/my-account/'"

echo "4. CV builder"
curl -s -c "$J" -b "$J" "$B/cv-builder/" > "$T/cvb.html"
RN=$(field "$T/cvb.html" '"nonce":"([^"]+)"')
check "builder script loaded" "[ -n '$RN' ]"
CVJ=$(curl -s -c "$J" -b "$J" -X POST -H "X-WP-Nonce: $RN" "$B/wp-json/kj/v1/cvs")
CVID=$(echo "$CVJ" | python -c "import sys,json;print(json.load(sys.stdin)['id'])" 2>/dev/null)
check "CV created" "[ -n '$CVID' ]"
cat > "$T/cv.json" <<'JSON'
{"template":"gulf","cv":{"personal":{"name":"Smoke Tester","phone":"+971 50 000 0000","location_type":"gulf","city":"Dubai","country":"United Arab Emirates","visa_status":"residence","notice":"1m","nationality":"Indian"},"target":{"title":"Staff Nurse","category":"healthcare","countries":["uae"]},"summary":"Nurse with 5 years in ICU.","experience":[{"title":"Staff Nurse","company":"City Hospital","city":"Dubai","start":"2020-01","current":true,"duties":"ICU care\nMedication rounds"}],"education":[{"degree":"B.Sc Nursing","school":"Father Muller College","year":"2018"}],"skills":"ICU, BLS, ACLS","languages":"English, Konkani","licences":[{"name":"DHA licence","detail":"valid"}]}}
JSON
check "CV saved" "curl -s -c '$J' -b '$J' -X PUT -H 'X-WP-Nonce: $RN' -H 'Content-Type: application/json' --data @'$T/cv.json' '$B/wp-json/kj/v1/cvs/$CVID' | grep -q 'saved'"
PV=$(curl -s -c "$J" -b "$J" -H "X-WP-Nonce: $RN" "$B/wp-json/kj/v1/cvs/$CVID/preview?template=modern")
check "preview renders" "echo '$PV' | grep -q 'Smoke Tester'"
for a in summary check; do
	check "AI $a answers" "curl -s -c '$J' -b '$J' -X POST -H 'X-WP-Nonce: $RN' -H 'Content-Type: application/json' --data '{\"action\":\"$a\"}' '$B/wp-json/kj/v1/cvs/$CVID/ai' | grep -q 'left'"
done
DL=$(curl -s -c "$J" -b "$J" -H "X-WP-Nonce: $RN" "$B/wp-json/kj/v1/cvs/$CVID")
for f in pdf docx txt; do
	U=$(echo "$DL" | python -c "import sys,json;print(json.load(sys.stdin)['downloads']['$f'])")
	curl -s -c "$J" -b "$J" -o "$T/cv.$f" "$U"
done
check "PDF download" "head -c 5 '$T/cv.pdf' | grep -q '%PDF-'"
check "Word download" "head -c 2 '$T/cv.docx' | grep -q 'PK'"
check "text download" "grep -q 'SMOKE TESTER' '$T/cv.txt'"
check "another user cannot read this CV" "curl -s -o /dev/null -w '%{http_code}' '$B/wp-json/kj/v1/cvs/$CVID' | grep -qE '401|403'"

echo "5. Account"
curl -s -c "$J" -b "$J" "$B/my-account/" > "$T/acc.html"
check "My account lists the CV" "grep -q 'Staff Nurse CV\|My CV\|New CV' '$T/acc.html'"
EX=$(field "$T/acc.html" 'href="([^"]*kj_export=1[^"]*)"' | sed 's/&#038;/\&/g;s/&amp;/\&/g')
check "data export includes CV" "curl -s -c '$J' -b '$J' '$EX' | grep -q 'Smoke Tester'"

echo "6. Admin: paste a job, Claude check, review"
A="$T/admin.txt"; rm -f "$A"
curl -s -c "$A" -b "$A" "$B/wp-login.php" > /dev/null
curl -s -c "$A" -b "$A" -o /dev/null --data "log=admin&pwd=password&wp-submit=Log+In&testcookie=1" "$B/wp-login.php"
curl -s -c "$A" -b "$A" "$B/wp-admin/edit.php?post_type=kj_job&page=kj-add-jobs" > "$T/add.html"
QN=$(field "$T/add.html" 'value="kj_queue_jobs">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"')
AN=$(field "$T/add.html" 'var KJAdmin = \{.*?"nonce":"([^"]+)"')
printf 'https://www.bayt.com/en/uae/jobs/smoke-999/\nPharmacist – Abu Dhabi\nCompany: Smoke Pharmacy\nAED 9,000 - 11,000 visa accommodation\n---\nhttps://x.example.com/smoke\nHelper – Kuwait\nPay registration fee first\n' > "$T/paste.txt"
Q=$(curl -s -c "$A" -b "$A" -o /dev/null -w "%{redirect_url}" --data-urlencode "kj_paste@$T/paste.txt" --data-urlencode "action=kj_queue_jobs" --data-urlencode "_wpnonce=$QN" "$B/wp-admin/admin-post.php")
check "2 jobs queued" "echo '$Q' | grep -q 'queued=2'"
R1=$(curl -s -c "$A" -b "$A" --data "action=kj_process_next&nonce=$AN" "$B/wp-admin/admin-ajax.php")
R2=$(curl -s -c "$A" -b "$A" --data "action=kj_process_next&nonce=$AN" "$B/wp-admin/admin-ajax.php")
check "genuine job rated green" "echo '$R1$R2' | grep -q '\"risk\":\"green\"'"
check "fee-asking job rated red" "echo '$R1$R2' | grep -q '\"risk\":\"red\"'"
for p in kj-review kj-whatsapp kj-subscribers kj-settings; do
	curl -s -c "$A" -b "$A" "$B/wp-admin/edit.php?post_type=kj_job&page=$p" -o "$T/a.html"
	check "admin page $p has no PHP errors" "[ \$(php_errors '$T/a.html') = 0 ] && grep -q 'wrap kj-wrap' '$T/a.html'"
done

echo "7. Daily email matched to the CV"
curl -s "$B/?kj_dev_newjob=ICU%20Staff%20Nurse&country=uae&cat=healthcare" > /dev/null
curl -s "$B/?kj_dev_newjob=Hotel%20Chef&country=uae&cat=hospitality" > /dev/null
sleep 1
curl -s "$B/?kj_dev_cron=kj_daily_digest" > /dev/null; curl -s "$B/?kj_dev_cron=kj_every_five" > /dev/null; curl -s "$B/?kj_dev_cron=kj_every_five" > /dev/null
curl -s "$B/?kj_dev_mail=1" > "$T/mail.txt"
check "CV-matched email includes the nurse job" "grep -q 'icu-staff-nurse' '$T/mail.txt'"
check "CV-matched email skips the chef job" "! grep -q 'hotel-chef' '$T/mail.txt'"

echo "8. Delete account"
curl -s -c "$J" -b "$J" "$B/my-account/" > "$T/acc.html"
DN=$(field "$T/acc.html" 'value="delete_account">.*?name="_wpnonce" value="([^"]+)"')
D=$(curl -s -c "$J" -b "$J" -o /dev/null -w "%{redirect_url}" --data "kj_auth=delete_account&_wpnonce=$DN&kj_confirm=DELETE" "$B/my-account/")
check "account deleted" "echo '$D' | grep -q 'kj_msg=deleted'"

echo; echo "Passed: $PASS   Failed: $FAIL"
[ "$FAIL" = 0 ]
