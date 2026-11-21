#!/usr/bin/env bash
#
# Preveri orodja prek HTTP.
#
# Uporaba:
#   BASE_URL=https://kreativnisplet.si/asistent TOOL_SECRET=... bash tests/test-tools.sh
#
# Da skrivnost ne ostane v zgodovini ukazov:
#   read -rs TOOL_SECRET && export TOOL_SECRET
#   BASE_URL=https://kreativnisplet.si/asistent bash tests/test-tools.sh
#
# TOOL_SECRET je ista vrednost kot v config.php na strežniku; brez nje orodja
# vrnejo 401.
#
# Testi tečejo proti živi bazi. Noben ne zapiše povpraševanja: vsak klic
# submit-inquiry je brez potrditve ali pa ima eno polje neveljavno. Test s
# "confirmed":true in samimi veljavnimi polji bi zapisal pravo vrstico v
# ai_inquiries in poslal obvestilo podjetju — takega ne dodajaj.
#
# Privzeto testira lokalni strežnik. Za lepši izpis namesti jq (ni obvezno).

set -u

BASE_URL="${BASE_URL:-http://localhost/voice-ai}"
TOOL_SECRET="${TOOL_SECRET:-}"
PASSED=0
FAILED=0

if [ -z "$TOOL_SECRET" ]; then
  echo "Manjka TOOL_SECRET (ista vrednost kot v config.php). Brez nje orodja vrnejo 401." >&2
  exit 2
fi

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
    -H "X-Tool-Secret: $TOOL_SECRET" \
    -d "$payload")

  # Zavrnjena skrivnost je vedno napaka testa, tudi kjer test pričakuje
  # success=false — sicer bi napačna skrivnost te teste "prestala".
  local got
  case "$response" in
    *'"error_code":"unauthorized"'*) got="zavrnjena skrivnost" ;;
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
test_call "iskanje spletne strani"     product-lookup.php '{"query":"spletna stran","action":"get_price"}' true
test_call "sklanjana oblika (trgovine)" product-lookup.php '{"query":"trgovine"}'                          true
test_call "zožanje na kategorijo"      product-lookup.php '{"query":"oglasi","category":"trzenje"}'         true
test_call "storitev brez objavljene cene" product-lookup.php '{"query":"logotip","action":"get_price"}'     true
test_call "neobstoječa storitev"       product-lookup.php '{"query":"traktor"}'                            false
test_call "manjka query"               product-lookup.php '{"action":"search"}'                            false
test_call "neveljavna akcija"          product-lookup.php '{"query":"drva","action":"delete_all"}'          false
echo

echo "order-lookup"
test_call "pravilna številka in telefon" order-lookup.php '{"order_id":"10001","verify":"041 234 567"}'     true
test_call "isti telefon v obliki E.164"  order-lookup.php '{"order_id":"10001","verify":"+38641234567"}'    true
test_call "preverjanje z e-pošto"        order-lookup.php '{"order_id":"10002","verify":"test2@example.com"}' true
test_call "TUJ telefon (mora zavrniti)"  order-lookup.php '{"order_id":"10001","verify":"031 876 543"}'     false
test_call "brez preverjanja"             order-lookup.php '{"order_id":"10001"}'                            false
test_call "neobstoječe naročilo"         order-lookup.php '{"order_id":"99999","verify":"041 234 567"}'     false
echo

echo "business-info"
test_call "delovni čas"      business-info.php '{"info_type":"hours"}'            true
test_call "roki in potek dela" business-info.php '{"info_type":"delivery"}'      true
test_call "načini plačila"   business-info.php '{"info_type":"payments"}'         true
test_call "neveljaven tip"   business-info.php '{"info_type":"skrivnosti"}'       false
echo

# Od 6895e7f (26. 9. 2026) InquiryTool brez "confirmed" ne zapiše nič. Prej sta
# tu stala testa s pričakovanim success=true brez potrditve in sta od takrat
# padala. Pravila za polja se preverijo s "confirmed":true in natanko enim
# neveljavnim poljem: preverba pade pred zapisom v bazo.
echo "submit-inquiry (nič ne zapiše)"
test_call "brez potrditve se ne odda"  submit-inquiry.php '{"name":"Testni Test","phone":"041 000 111","email":"test@example.com","product":"napredna spletna stran"}' false
test_call "manjka ime"                 submit-inquiry.php '{"phone":"041 000 111","email":"test@example.com","confirmed":true}'                      false
test_call "manjka telefon"             submit-inquiry.php '{"name":"Testni Test","email":"test@example.com","confirmed":true}'                       false
test_call "manjka e-posta"             submit-inquiry.php '{"name":"Testni Test","phone":"041 000 111","confirmed":true}'                            false
test_call "prekratka stevilka"         submit-inquiry.php '{"name":"Testni Test","phone":"041","email":"test@example.com","confirmed":true}'         false
test_call "neveljavna e-posta"         submit-inquiry.php '{"name":"Testni Test","phone":"041 000 111","email":"ni-email","confirmed":true}'         false
test_call "nadomestek namesto imena"   submit-inquiry.php '{"name":"ime priimek","phone":"041 000 111","email":"test@example.com","confirmed":true}' false
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

NOSECRET_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/tools/product-lookup.php" \
  -H "Content-Type: application/json" -d '{"query":"spletna stran"}')
if [ "$NOSECRET_STATUS" = "401" ]; then
  echo "  OK    brez skrivnosti zavrnjeno (401)"
  PASSED=$((PASSED + 1))
else
  echo "  NAPAKA brez skrivnosti bi moral vrniti 401, vrnil je $NOSECRET_STATUS"
  FAILED=$((FAILED + 1))
fi

WRONG_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/tools/business-info.php" \
  -H "Content-Type: application/json" -H "X-Tool-Secret: napacna-skrivnost" -d '{"info_type":"hours"}')
if [ "$WRONG_STATUS" = "401" ]; then
  echo "  OK    napačna skrivnost zavrnjena (401)"
  PASSED=$((PASSED + 1))
else
  echo "  NAPAKA napačna skrivnost bi morala vrniti 401, vrnila je $WRONG_STATUS"
  FAILED=$((FAILED + 1))
fi

echo
echo "Uspešno: $PASSED   Neuspešno: $FAILED"

echo
echo "Primer polnega odgovora (product-lookup):"
curl -s -X POST "$BASE_URL/tools/product-lookup.php" \
  -H "Content-Type: application/json" \
  -H "X-Tool-Secret: $TOOL_SECRET" \
  -d '{"query":"spletna stran","action":"get_price"}' | $PRETTY

[ "$FAILED" -eq 0 ]
