#!/usr/bin/env bash
#
# End-to-end verification of the Micropub + IndieAuth endpoints.
#
# Usage:
#   BASE_URL=http://localhost:8080 TOKEN=<micropub token> bin/verify-micropub.sh
#
# Optional (enables the IndieAuth PKCE flow + scope-enforcement tests):
#   ADMIN_USER=<admin username> ADMIN_PASS=<admin password>
#   (skipped automatically if TOTP is enabled — approve a client manually instead)
#
# TOKEN is the legacy shared token from Settings → Micropub.

set -u

BASE_URL="${BASE_URL:-http://localhost:8080}"
TOKEN="${TOKEN:-}"
ADMIN_USER="${ADMIN_USER:-}"
ADMIN_PASS="${ADMIN_PASS:-}"

PASS=0
FAIL=0
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -z "$TOKEN" ]; then
    echo "TOKEN is required (Settings → Micropub → Generate token)." >&2
    exit 1
fi

# check <label> <expected-status> <actual-status> [detail]
check() {
    local label="$1" expected="$2" actual="$3" detail="${4:-}"
    if [ "$expected" = "$actual" ]; then
        PASS=$((PASS+1)); printf 'ok   %-58s %s\n' "$label" "$actual"
    else
        FAIL=$((FAIL+1)); printf 'FAIL %-58s expected %s got %s  %s\n' "$label" "$expected" "$actual" "$detail"
    fi
}

status() { curl -s -o "$TMP/body" -w '%{http_code}' "$@"; }
body()   { cat "$TMP/body"; }
loc()    { curl -s -o "$TMP/body" -D "$TMP/headers" "$@" >/dev/null; grep -i '^location:' "$TMP/headers" | tr -d '\r' | awk '{print $2}' | tail -1; }

MP="$BASE_URL/micropub.php"
AUTHZ="Authorization: Bearer $TOKEN"

echo "== Error handling"
check "no token → 401"                 401 "$(status "$MP?q=config")"
check "bad token → 401"                401 "$(status -H 'Authorization: Bearer nope' "$MP?q=config")"
check "token in header AND body → 400" 400 "$(status -H "$AUTHZ" --data "access_token=x&h=entry&content=hi" "$MP")"
check "unknown q → 400"                400 "$(status -H "$AUTHZ" "$MP?q=nope")"
# nginx limit_except answers non-GET/POST itself with 403 (PHP would say 405).
check "PUT → 403 (nginx limit_except)" 403 "$(status -X PUT -H "$AUTHZ" "$MP")"

echo "== Queries"
check "q=config → 200"       200 "$(status -H "$AUTHZ" "$MP?q=config")"
grep -q '"media-endpoint"' "$TMP/body" && check "config has media-endpoint" y y || check "config has media-endpoint" y n "$(body)"
grep -q '"syndicate-to"'   "$TMP/body" && check "config has syndicate-to"   y y || check "config has syndicate-to"   y n
check "q=syndicate-to → 200" 200 "$(status -H "$AUTHZ" "$MP?q=syndicate-to")"
check "q=category → 200"     200 "$(status -H "$AUTHZ" "$MP?q=category")"

echo "== Create (JSON)"
SLUG="mp-verify-$(date +%s)"
URL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{
  \"type\": [\"h-entry\"],
  \"properties\": {
    \"name\": [\"Micropub verification post\"],
    \"content\": [\"Created by bin/verify-micropub.sh\"],
    \"mp-slug\": [\"$SLUG\"],
    \"category\": [\"verify\"],
    \"mp-syndicate-to\": []
  }
}" "$MP")
CODE=$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"x\"],\"mp-slug\":[\"$SLUG\"]}}" "$MP")
check "create → Location returned" y "$([ -n "$URL" ] && echo y || echo n)" "$URL"
check "duplicate slug auto-suffixes (201)" 201 "$CODE"
DUP_URL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"cleanup me\"],\"mp-slug\":[\"$SLUG-cleanup\"]}}" "$MP")
check "created page is live" 200 "$(status "$URL")"

echo "== Create (form-encoded)"
FORM_URL=$(loc -H "$AUTHZ" --data-urlencode "h=entry" --data-urlencode "content=Form-encoded note from verify script" --data-urlencode "mp-slug=$SLUG-form" "$MP")
check "form create → Location" y "$([ -n "$FORM_URL" ] && echo y || echo n)"

echo "== q=source"
check "q=source → 200" 200 "$(status -H "$AUTHZ" "$MP?q=source&url=$URL")"
grep -q '"h-entry"' "$TMP/body" && check "source has h-entry type" y y || check "source has h-entry type" y n
check "q=source unknown url → 404" 404 "$(status -H "$AUTHZ" "$MP?q=source&url=$BASE_URL/2020/01/01/nope/")"

echo "== Update"
# NOTE: JSON payloads are pre-assigned to variables — bash 3.2 (macOS default)
# misparses \" escapes inside nested "$(…)" and brace-expands the payload.
DATA="{\"action\":\"update\",\"url\":\"$URL\",\"replace\":{\"content\":[\"Updated content.\"]}}"
check "update content → 204" 204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
NEWURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"action\":\"update\",\"url\":\"$URL\",\"replace\":{\"mp-slug\":[\"$SLUG-renamed\"]}}" "$MP")
check "update slug → 201 + new Location" y "$([ -n "$NEWURL" ] && echo y || echo n)" "$NEWURL"
[ -n "$NEWURL" ] && URL="$NEWURL"
check "form-encoded update → 400" 400 "$(status -H "$AUTHZ" --data "action=update&url=$URL" "$MP")"

echo "== Update published"
DATA="{\"action\":\"update\",\"url\":\"$URL\",\"replace\":{\"published\":[\"not a date\"]}}"
check "invalid published → 400" 400 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
DATA="{\"action\":\"update\",\"url\":\"$URL\",\"add\":{\"published\":[\"2024-01-01T00:00:00Z\"]}}"
check "add published → 400" 400 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
DATA="{\"action\":\"update\",\"url\":\"$URL\",\"delete\":[\"published\"]}"
check "delete published → 400" 400 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
OLD_URL="$URL"
BDURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"action\":\"update\",\"url\":\"$URL\",\"replace\":{\"published\":[\"2024-02-03T10:00:00Z\"]}}" "$MP")
check "backdate published → 201 + new Location" y "$([ -n "$BDURL" ] && echo y || echo n)" "$BDURL"
echo "$BDURL" | grep -q '/2024/02/03/' && check "new URL reflects date" y y || check "new URL reflects date" y n "$BDURL"
[ -n "$BDURL" ] && URL="$BDURL"
check "old URL removed"    404 "$(status "$OLD_URL")"
check "backdated page live" 200 "$(status "$URL")"
# Future date on a published post unpublishes it (scheduler republishes later).
DATA="{\"action\":\"update\",\"url\":\"$DUP_URL\",\"replace\":{\"published\":[\"2030-01-01T00:00:00Z\"]}}"
check "future published → 204 (now scheduled)" 204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
check "future-dated page removed" 404 "$(status "$DUP_URL")"

echo "== Context posts (reply/like/repost/bookmark)"
REPLY_TARGET="https://example.com/some-post"
RURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{
  \"type\": [\"h-entry\"],
  \"properties\": {
    \"content\": [\"Replying from verify script\"],
    \"in-reply-to\": [\"$REPLY_TARGET\"],
    \"mp-syndicate-to\": []
  }
}" "$MP")
check "titleless reply create → Location" y "$([ -n "$RURL" ] && echo y || echo n)" "$RURL"
echo "$RURL" | grep -q '/replying-from-verify-script/$' && check "reply slugs from its body" y y || check "reply slugs from its body" y n "$RURL"
status "$RURL" > /dev/null
grep -q 'u-in-reply-to' "$TMP/body" && check "page renders u-in-reply-to" y y || check "page renders u-in-reply-to" y n
grep -q "$REPLY_TARGET" "$TMP/body" && check "page links reply target" y y || check "page links reply target" y n
status -H "$AUTHZ" "$MP?q=source&url=$RURL" > /dev/null
grep -q '"in-reply-to"' "$TMP/body" && check "q=source returns in-reply-to" y y || check "q=source returns in-reply-to" y n
DATA="{\"action\":\"update\",\"url\":\"$RURL\",\"add\":{\"like-of\":[\"https://example.com/liked\"]}}"
check "add like-of → 204" 204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
status -H "$AUTHZ" "$MP?q=source&url=$RURL" > /dev/null
grep -q '"like-of"' "$TMP/body" && check "q=source returns like-of" y y || check "q=source returns like-of" y n
DATA="{\"action\":\"update\",\"url\":\"$RURL\",\"delete\":[\"in-reply-to\"]}"
check "delete in-reply-to → 204" 204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
status -H "$AUTHZ" "$MP?q=source&url=$RURL" > /dev/null
grep -q '"in-reply-to"' "$TMP/body" && check "in-reply-to removed from source" n y || check "in-reply-to removed from source" n n
# Bare bookmark: no content, no photo — context alone is enough.
BURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"bookmark-of\":[\"https://example.com/bookmarked\"],\"mp-syndicate-to\":[]}}" "$MP")
check "bare bookmark create → Location" y "$([ -n "$BURL" ] && echo y || echo n)"
# No content to slug from, so this one falls back to the autoincrement id.
echo "$BURL" | grep -qE '/[0-9]+/$' && check "bodyless bookmark falls back to id slug" y y || check "bodyless bookmark falls back to id slug" y n "$BURL"
status "$BURL" > /dev/null
grep -q 'u-bookmark-of' "$TMP/body" && check "page renders u-bookmark-of" y y || check "page renders u-bookmark-of" y n
BAD_DATA="{\"type\":[\"h-entry\"],\"properties\":{\"in-reply-to\":[\"not-a-url\"]}}"
check "invalid context URL → 400" 400 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$BAD_DATA" "$MP")"

echo "== Titleless notes become asides"
# No name property and no context: still an aside, slugged from its opening words.
NOTE_BODY="Kettle boiled over again this morning"
NURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"$NOTE_BODY\"],\"mp-syndicate-to\":[]}}" "$MP")
check "contextless note create → Location" y "$([ -n "$NURL" ] && echo y || echo n)" "$NURL"
echo "$NURL" | grep -q '/kettle-boiled-over-again-this/$' && check "note slugs from first 5 words" y y || check "note slugs from first 5 words" y n "$NURL"
status "$NURL" > /dev/null
grep -q '<h1' "$TMP/body" && check "note renders without an h1" n y || check "note renders without an h1" n n
grep -q 'post--note' "$TMP/body" && check "note renders as an aside" y y || check "note renders as an aside" y n

# Same opening words again: the second must not collide.
NURL2=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"$NOTE_BODY too\"],\"mp-syndicate-to\":[]}}" "$MP")
check "duplicate opening words → Location" y "$([ -n "$NURL2" ] && echo y || echo n)" "$NURL2"
echo "$NURL2" | grep -q '/kettle-boiled-over-again-this-2/$' && check "collision gets -2 suffix" y y || check "collision gets -2 suffix" y n "$NURL2"

# mp-slug still wins on a titleless post.
MSURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"Body words are ignored here\"],\"mp-slug\":[\"$SLUG-chosen\"],\"mp-syndicate-to\":[]}}" "$MP")
check "titleless + mp-slug → Location" y "$([ -n "$MSURL" ] && echo y || echo n)" "$MSURL"
echo "$MSURL" | grep -q "/$SLUG-chosen/\$" && check "mp-slug wins over derived slug" y y || check "mp-slug wins over derived slug" y n "$MSURL"

echo "== Photo property"
PHOTO_URL_1="https://example.com/a.jpg"
PURL=$(loc -H "$AUTHZ" -H 'Content-Type: application/json' -d "{
  \"type\": [\"h-entry\"],
  \"properties\": {
    \"content\": [\"Photo post\"],
    \"mp-slug\": [\"$SLUG-photo\"],
    \"photo\": [{\"value\": \"$PHOTO_URL_1\", \"alt\": \"An example photo\"}],
    \"mp-syndicate-to\": []
  }
}" "$MP")
check "photo create → Location" y "$([ -n "$PURL" ] && echo y || echo n)"
status "$PURL" > /dev/null
grep -q 'u-photo' "$TMP/body" && check "page renders u-photo" y y || check "page renders u-photo" y n
grep -q 'alt="An example photo"' "$TMP/body" && check "page renders alt text" y y || check "page renders alt text" y n
status -H "$AUTHZ" "$MP?q=source&url=$PURL" > /dev/null
grep -q '"photo"' "$TMP/body" && check "q=source returns photo" y y || check "q=source returns photo" y n
DATA="{\"action\":\"update\",\"url\":\"$PURL\",\"delete\":{\"photo\":[\"$PHOTO_URL_1\"]}}"
check "delete photo values → 204" 204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DATA" "$MP")"
status "$PURL" > /dev/null
grep -q 'u-photo' "$TMP/body" && check "photo removed from page" n y || check "photo removed from page" n n

echo "== Media endpoint"
printf '\x89PNG\r\n\x1a\n' > "$TMP/fake.png"  # not a valid image — expect 422 from MIME check
check "media.php GET → 405" 405 "$(status "$BASE_URL/media.php")"
check "media.php no file → 400" 400 "$(status -H "$AUTHZ" -X POST -F 'x=1' "$BASE_URL/media.php")"
check "media.php invalid file → 422" 422 "$(status -H "$AUTHZ" -F "file=@$TMP/fake.png;type=image/png" "$BASE_URL/media.php")"
if [ -n "${MEDIA_FILE:-}" ] && [ -f "$MEDIA_FILE" ]; then
    MCODE=$(status -H "$AUTHZ" -F "file=@$MEDIA_FILE" "$BASE_URL/media.php")
    check "media.php real upload → 201" 201 "$MCODE"
    grep -q '"url"' "$TMP/body" && check "media.php returns JSON url" y y || check "media.php returns JSON url" y n
else
    echo "     (set MEDIA_FILE=/path/to/image.jpg to test a real upload)"
fi

echo "== Delete / undelete"
DEL_DATA="{\"action\":\"delete\",\"url\":\"$URL\"}"
UNDEL_DATA="{\"action\":\"undelete\",\"url\":\"$URL\"}"
check "delete → 204"            204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DEL_DATA" "$MP")"
check "deleted page gone → 404" 404 "$(status "$URL")"
check "q=source deleted → 404"  404 "$(status -H "$AUTHZ" "$MP?q=source&url=$URL")"
check "delete again → 404"      404 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$DEL_DATA" "$MP")"
check "undelete → 204"          204 "$(status -H "$AUTHZ" -H 'Content-Type: application/json' -d "$UNDEL_DATA" "$MP")"
check "restored page live"      200 "$(status "$URL")"
check "form-encoded delete → 204" 204 "$(status -H "$AUTHZ" --data-urlencode "action=delete" --data-urlencode "url=$URL" "$MP")"

echo "== IndieAuth metadata + endpoints"
check "indieauth-metadata.php → 200" 200 "$(status "$BASE_URL/indieauth-metadata.php")"
grep -q '"authorization_endpoint"' "$TMP/body" && check "metadata lists endpoints" y y || check "metadata lists endpoints" y n
check "token.php GET w/ legacy token → 200" 200 "$(status -H "$AUTHZ" "$BASE_URL/token.php")"
grep -q '"me"' "$TMP/body" && check "token verification returns me" y y || check "token verification returns me" y n
check "token.php revoke (unknown) → 200" 200 "$(status -X POST --data 'token=unknown' "$BASE_URL/token.php?action=revoke")"
check "indieauth.php bad client_id → 400" 400 "$(status "$BASE_URL/indieauth.php?client_id=nonsense&redirect_uri=x&state=s")"

if [ -n "$ADMIN_USER" ] && [ -n "$ADMIN_PASS" ]; then
    echo "== IndieAuth PKCE flow (scripted consent)"
    JAR="$TMP/cookies"
    VERIFIER=$(head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=')
    CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
    STATE="st$(date +%s)"
    CLIENT_ID="$BASE_URL/"
    REDIRECT="$BASE_URL/verify-callback"

    # Log in (fails safely if TOTP is enabled).
    curl -s -c "$JAR" "$BASE_URL/admin/" > "$TMP/login.html"
    CSRF=$(grep -o 'name="csrf_token" value="[^"]*"' "$TMP/login.html" | head -1 | sed 's/.*value="//;s/"//')
    curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf_token=$CSRF" \
        --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=$ADMIN_PASS" "$BASE_URL/admin/"

    AUTH_QS="response_type=code&client_id=$CLIENT_ID&redirect_uri=$REDIRECT&state=$STATE&code_challenge=$CHALLENGE&code_challenge_method=S256&scope=create+update"
    curl -s -b "$JAR" -c "$JAR" "$BASE_URL/indieauth.php?$AUTH_QS" > "$TMP/consent.html"
    if grep -q 'name="csrf_token"' "$TMP/consent.html"; then
        check "consent screen renders" y y
        CSRF2=$(grep -o 'name="csrf_token"[^>]*value="[^"]*"' "$TMP/consent.html" | head -1 | sed 's/.*value="//;s/"//')
        CODE_URL=$(curl -s -b "$JAR" -o /dev/null -D - \
            --data-urlencode "csrf_token=$CSRF2" \
            --data-urlencode "client_id=$CLIENT_ID" \
            --data-urlencode "redirect_uri=$REDIRECT" \
            --data-urlencode "state=$STATE" \
            --data-urlencode "code_challenge=$CHALLENGE" \
            --data-urlencode "code_challenge_method=S256" \
            --data-urlencode "scopes[]=create" \
            "$BASE_URL/indieauth.php" | grep -i '^location:' | tr -d '\r' | awk '{print $2}')
        AUTH_CODE=$(printf '%s' "$CODE_URL" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
        echo "$CODE_URL" | grep -q "state=$STATE" && check "state round-trips" y y || check "state round-trips" y n
        echo "$CODE_URL" | grep -q "iss=" && check "iss parameter present" y y || check "iss parameter present" y n

        TCODE=$(status -X POST "$BASE_URL/token.php" \
            --data-urlencode "grant_type=authorization_code" \
            --data-urlencode "code=$AUTH_CODE" \
            --data-urlencode "client_id=$CLIENT_ID" \
            --data-urlencode "redirect_uri=$REDIRECT" \
            --data-urlencode "code_verifier=$VERIFIER")
        check "token exchange → 200" 200 "$TCODE"
        SCOPED=$(sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p' "$TMP/body")
        check "access_token issued" y "$([ -n "$SCOPED" ] && echo y || echo n)"

        # Replay the code — must fail.
        check "code replay → 400" 400 "$(status -X POST "$BASE_URL/token.php" \
            --data-urlencode "grant_type=authorization_code" \
            --data-urlencode "code=$AUTH_CODE" \
            --data-urlencode "client_id=$CLIENT_ID" \
            --data-urlencode "redirect_uri=$REDIRECT" \
            --data-urlencode "code_verifier=$VERIFIER")"

        if [ -n "$SCOPED" ]; then
            SURL=$(loc -H "Authorization: Bearer $SCOPED" -H 'Content-Type: application/json' \
                -d "{\"type\":[\"h-entry\"],\"properties\":{\"content\":[\"scoped token post\"],\"mp-slug\":[\"$SLUG-scoped\"],\"mp-syndicate-to\":[]}}" "$MP")
            check "create with create-scope token" y "$([ -n "$SURL" ] && echo y || echo n)"
            SDEL_DATA="{\"action\":\"delete\",\"url\":\"$SURL\"}"
            check "delete with create-scope token → 403" 403 "$(status -H "Authorization: Bearer $SCOPED" \
                -H 'Content-Type: application/json' -d "$SDEL_DATA" "$MP")"
            check "revoke scoped token → 200" 200 "$(status -X POST --data-urlencode "token=$SCOPED" "$BASE_URL/token.php?action=revoke")"
            check "revoked token → 401" 401 "$(status -H "Authorization: Bearer $SCOPED" "$MP?q=config")"
        fi
    else
        check "consent screen renders (is TOTP enabled? skip with unset ADMIN_USER)" y n
    fi
else
    echo "== IndieAuth PKCE flow skipped (set ADMIN_USER + ADMIN_PASS to enable)"
fi

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
