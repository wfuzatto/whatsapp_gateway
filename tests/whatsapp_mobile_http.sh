#!/usr/bin/env bash
set -Eeuo pipefail
export APP_ENV=test APP_REQUIRE_HTTPS=0
export MASTER_KEY_BASE64="$(php -r "echo base64_encode(str_repeat('K',32));")"
tmp="$(mktemp -d)"
php -r '
require "src/whatsapp_bootstrap.php";
$p=db();
$profiles=[
    ["cliente1@portal-test.invalid","333333331","555000001","5511999911111","Privado da Empresa Sol A"],
    ["cliente2@portal-test.invalid","333333332","555000002","5511999922222","Privado da Empresa Lua B"]
];
foreach($profiles as $row) {
    [$email,$waba,$phoneId,$from,$body]=$row;
    $s=$p->prepare("SELECT tenant_id FROM whatsapp_users WHERE email=?");
    $s->execute([$email]);$tenant=$s->fetchColumn();
    if(!$tenant)throw new RuntimeException("Tenant de teste ausente: ".$email);
    $p->prepare("UPDATE whatsapp_subscriptions SET status='\''active'\'' WHERE tenant_id=?")->execute([$tenant]);
    $p->prepare("INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)")->execute([$tenant,$waba]);
    $p->prepare("INSERT INTO whatsapp_accounts(tenant_id,name,waba_id,phone_number_id,token_box)
         VALUES (?,?,?,?,?)")->execute([$tenant,"Mobile CI ".$email,$waba,$phoneId,seal("private-meta-ci-token")]);
    $account=(int)$p->lastInsertId();
    $p->prepare("INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id,display_name,last_inbound_at)
       VALUES (?,?,?,?,UTC_TIMESTAMP())")->execute([$tenant,$account,$from,$email]);
    $p->prepare("INSERT INTO whatsapp_messages(tenant_id,account_id,wa_id,direction,whatsapp_id,
        message_type,status,payload) VALUES (?,?,?,'\''inbound'\'',?,'\''text'\'','\''received'\'',?)")
      ->execute([$tenant,$account,$from,"wamid.test.".$account,
        jsonString(["type"=>"text","text"=>["body"=>$body]])]);
}
'
php -S 127.0.0.1:18884 -t public public/whatsapp_index.php > "$tmp/http.log" 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true; rm -rf "$tmp"' EXIT
base="http://127.0.0.1:18884/mobile-api/v1"
for i in $(seq 1 40); do
 if curl -fsS "$base/health" >/dev/null 2>&1; then break; fi
 sleep 0.2
done
function login() {
 local email="$1" target="$2" code
 code="$(curl -sS -o "$target" -w '%{http_code}' -H 'Content-Type: application/json' \
   -X POST "$base/auth/login" \
   -d '{"email":"'"$email"'","password":"SenhaForteEmCI789"}')"
 [[ "$code" == 200 ]] || { echo "Login falhou: $code"; cat "$target"; exit 1; }
 python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["access_token"])' "$target"
}
token1="$(login cliente1@portal-test.invalid "$tmp/login1.json")"
token2="$(login cliente2@portal-test.invalid "$tmp/login2.json")"
[[ "$token1" == za_* && "$token2" == za_* && "$token1" != "$token2" ]]
echo "OK: dois logins móveis distintos"
curl -fsS -H "Authorization: Bearer $token1" "$base/conversations" -o "$tmp/a.json"
curl -fsS -H "Authorization: Bearer $token2" "$base/conversations" -o "$tmp/b.json"
grep -q 'Privado da Empresa Sol A' "$tmp/a.json"
! grep -q 'Privado da Empresa Lua B' "$tmp/a.json"
grep -q 'Privado da Empresa Lua B' "$tmp/b.json"
! grep -q 'Privado da Empresa Sol A' "$tmp/b.json"
echo "OK: conversas isoladas pelo tenant"
idA="$(python3 -c 'import json; print(json.load(open("'"$tmp/a.json"'"))["data"][0]["id"])')"
code="$(curl -sS -o "$tmp/denied" -w '%{http_code}' -H "Authorization: Bearer $token2" \
 "$base/conversations/$idA/messages")"
[[ "$code" == 404 ]] || { echo "Vazamento entre tenants: HTTP $code"; exit 1; }
echo "OK: bloqueio de ID de conversa estrangeiro"
idNew="$(curl -fsS -H "Authorization: Bearer $token1" \
 -H 'Content-Type: application/json' -d '{"phone":"5511999933333"}' \
 "$base/conversations" | python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])')"
code="$(curl -sS -o "$tmp/window" -w '%{http_code}' \
  -H "Authorization: Bearer $token1" -H 'Content-Type: application/json' \
  -d '{"text":"Mensagem fora da janela"}' \
  "$base/conversations/$idNew/messages")"
[[ "$code" == 409 ]] || { echo "Janela 24 h nao bloqueou envio: $code"; cat "$tmp/window"; exit 1; }
echo "OK: janela de 24 horas validada no backend"
curl -fsS -H "Authorization: Bearer $token1" -H 'Content-Type: application/json' \
 -X POST -d '{}' "$base/auth/logout" >/dev/null
code="$(curl -sS -o /dev/null -w '%{http_code}' \
 -H "Authorization: Bearer $token1" "$base/conversations")"
[[ "$code" == 401 ]] || { echo "Logout nao revogou sessao: $code"; exit 1; }
echo "OK: revogacao de sessao"
