#!/usr/bin/env bash
#
# Preveri vse tri toole prek HTTP.
#
# Uporaba:
#   BASE_URL=https://tvoja-domena.si/voice-ai bash tests/test-tools.sh
#
# Privzeto testira lokalni strežnik. Za lepši izpis namesti jq (ni obvezno).

set -u

BASE_URL="${BASE_URL:-http://localhost/voice-ai}"
PASSED=0
FAILED=0

if command -v jq >/dev/null 2>&1; then
  PRETTY="jq ."
else
  PRETTY="cat"
fi

# test <opis> <endpoint> <json> <pričakovan success: true|false>
test_call() {
  local label="$1" endpoint="$2" payload="$3" expect="$4"

  local response
  response=$(curl -s -X POST "$BASE_URL/tools/$endpoint" \
    -H "Content-Type: application/json" \
    -d "$payload")

  local got
  case "$response" in
    *'"success":true'*)  got="true" ;;
    *'"success":false'*) got="false" ;;
    *)                   got="neveljaven odgovor" ;;
  esac

  if [ "$got" = "$expect" ]; then
    printf '  OK    %s\n' "$label"
    PASSED=$((PASSED + 1))
  else
    printf '  NAPAKA %s\n' "$label"
    printf '        pričakovano success=%s, dobljeno=%s\n' "$expect" "$got"
    printf '        %s\n' "$response"
    FAILED=$((FAILED + 1))
  fi
}

echo "Testiram $BASE_URL"
echo

echo "product-lookup"
test_call "iskanje bukovih drv"        product-lookup.php '{"query":"bukova drva","action":"get_price"}'   true
test_call "sklanjana oblika (peletov)" product-lookup.php '{"query":"peletov"}'                            true
test_call "zožanje na kategorijo"      product-lookup.php '{"query":"paleta","category":"peleti"}'          true
test_call "neobstoječ izdelek"         product-lookup.php '{"query":"premog"}'                             false
test_call "manjka query"               product-lookup.php '{"action":"search"}'                            false
test_call "neveljavna akcija"          product-lookup.php '{"query":"drva","action":"delete_all"}'          false
echo

echo "order-lookup"
test_call "pravilna številka in telefon" order-lookup.php '{"order_id":"10005","verify":"041 234 567"}'     true
test_call "isti telefon v obliki E.164"  order-lookup.php '{"order_id":"10005","verify":"+38641234567"}'    true
test_call "preverjanje z e-pošto"        order-lookup.php '{"order_id":"10006","verify":"marija.kos@siol.net"}' true
test_call "TUJ telefon (mora zavrniti)"  order-lookup.php '{"order_id":"10005","verify":"031 876 543"}'     false
test_call "brez preverjanja"             order-lookup.php '{"order_id":"10005"}'                            false
test_call "neobstoječe naročilo"         order-lookup.php '{"order_id":"99999","verify":"041 234 567"}'     false
echo

echo "business-info"
test_call "delovni čas"      business-info.php '{"info_type":"hours"}'            true
test_call "območja dostave"  business-info.php '{"info_type":"delivery_regions"}' true
test_call "načini plačila"   business-info.php '{"info_type":"payments"}'         true
test_call "neveljaven tip"   business-info.php '{"info_type":"skrivnosti"}'       false
echo

echo "submit-inquiry"
test_call "polno povprasevanje"      submit-inquiry.php '{"name":"Testni Test","phone":"041 000 111","product":"bukova drva","quantity":"3 kubike"}' true
test_call "samo ime in telefon"      submit-inquiry.php '{"name":"Testni Test","phone":"+38641000111"}'                                          true
test_call "manjka telefon"           submit-inquiry.php '{"name":"Testni Test"}'                                                                 false
test_call "manjka ime"               submit-inquiry.php '{"phone":"041 000 111"}'                                                                false
test_call "prekratka stevilka"       submit-inquiry.php '{"name":"Testni Test","phone":"041"}'                                                   false
test_call "neveljavna e-posta"       submit-inquiry.php '{"name":"Testni Test","phone":"041 000 111","email":"ni-email"}'                        false
echo

echo "varnost"
GET_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/tools/product-lookup.php")
if [ "$GET_STATUS" = "405" ]; then
  echo "  OK    GET je zavrnjen (405)"
  PASSED=$((PASSED + 1))
else
  echo "  NAPAKA GET bi moral vrniti 405, vrnil je $GET_STATUS"
  FAILED=$((FAILED + 1))
fi

LOG_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/logs/")
if [ "$LOG_STATUS" = "403" ] || [ "$LOG_STATUS" = "404" ]; then
  echo "  OK    mapa logs/ ni dosegljiva prek brskalnika ($LOG_STATUS)"
  PASSED=$((PASSED + 1))
else
  echo "  NAPAKA mapa logs/ je dosegljiva (HTTP $LOG_STATUS) — preveri .htaccess"
  FAILED=$((FAILED + 1))
fi

echo
echo "Uspešno: $PASSED   Neuspešno: $FAILED"

echo
echo "Primer polnega odgovora (product-lookup):"
curl -s -X POST "$BASE_URL/tools/product-lookup.php" \
  -H "Content-Type: application/json" \
  -d '{"query":"bukova drva","action":"get_price"}' | $PRETTY

[ "$FAILED" -eq 0 ]
