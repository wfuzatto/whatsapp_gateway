#!/usr/bin/env bash
# Testa HTTP, sessao, CSRF e criacao de dois tenants no MySQL descartavel do CI.
set -Eeuo pipefail
export APP_REQUIRE_HTTPS=0
host="http://127.0.0.1:18881"
tmp="$(mktemp -d)"
php -S 127.0.0.1:18881 -t public public/whatsapp_index.php > "$tmp/server.log" 2>&1 &
server_pid=$!
cleanup() { kill "$server_pid" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
for i in $(seq 1 35); do
 if curl -fsS "$host/health" > /dev/null 2>&1; then break; fi
 sleep 0.3
done
curl -fsS "$host/" -o "$tmp/landing"
grep -q 'Seu WhatsApp conectado' "$tmp/landing"
grep -q 'R$ 79,00' "$tmp/landing"
grep -q 'R$ 199,00' "$tmp/landing"
grep -q 'R$ 399,00' "$tmp/landing"
echo "OK: landing publica e preco dos planos"
csrf_of() {
 grep -o 'name="csrf" value="[^"]*"' "$1" | head -1 | cut -d'"' -f4
}
register_tenant() {
 local jar="$1" prefix="$2" plan="$3" company="$4"
 curl -fsS -c "$jar" -b "$jar" "$host/cadastro?plano=$plan" -o "$tmp/form-$prefix"
 local csrf
 csrf="$(csrf_of "$tmp/form-$prefix")"
 [[ "$csrf" =~ ^[a-f0-9]{64}$ ]]
 local code
 code="$(curl -sS -o "$tmp/resp-$prefix" -D "$tmp/headers-$prefix" -w '%{http_code}' \
     -b "$jar" -c "$jar" -X POST "$host/cadastro" \
     --data-urlencode "csrf=$csrf" \
     --data-urlencode "name=Administrador $prefix" \
     --data-urlencode "company=$company" \
     --data-urlencode "email=$prefix@portal-test.invalid" \
     --data-urlencode 'password=SenhaForteEmCI789' \
     --data-urlencode 'confirm_password=SenhaForteEmCI789' \
     --data-urlencode "plan=$plan" \
     --data-urlencode 'accept_terms=yes')"
 [[ "$code" == "303" ]] || { echo "Cadastro $prefix falhou HTTP $code"; head -c 1500 "$tmp/resp-$prefix"; exit 1; }
 grep -qi '/painel' "$tmp/headers-$prefix"
 curl -fsS -b "$jar" "$host/painel" -o "$tmp/dashboard-$prefix"
 grep -q "$company" "$tmp/dashboard-$prefix"
 echo "OK: usuario $prefix cadastrado e autenticado"
}
register_tenant "$tmp/a.jar" "cliente1" "essencial" "Empresa Sol A"
register_tenant "$tmp/b.jar" "cliente2" "profissional" "Empresa Lua B"
if grep -q 'Empresa Lua B' "$tmp/dashboard-cliente1"; then echo "FAIL: vazamento B para A"; exit 1; fi
if grep -q 'Empresa Sol A' "$tmp/dashboard-cliente2"; then echo "FAIL: vazamento A para B"; exit 1; fi
echo "OK: painéis isolados por tenant"
token_a="$(grep -oE 'wg_[a-f0-9]{64}' "$tmp/dashboard-cliente1" | head -1)"
token_b="$(grep -oE 'wg_[a-f0-9]{64}' "$tmp/dashboard-cliente2" | head -1)"
[[ "$token_a" != "$token_b" ]]
curl -fsS -b "$tmp/a.jar" "$host/painel" -o "$tmp/reload-a"
if grep -qF "$token_a" "$tmp/reload-a"; then echo "FAIL: token repetido"; exit 1; fi
TOKEN_A="$token_a" TOKEN_B="$token_b" php -r '
require "src/whatsapp_ApiSecurity.php";
foreach(["A"=>"cliente1","B"=>"cliente2"] as $letter=>$prefix){
    $s=db()->prepare("SELECT k.id,k.account_id,k.active,d.pending_box FROM whatsapp_api_tokens k
        JOIN whatsapp_users u ON u.tenant_id=k.tenant_id
        JOIN whatsapp_auto_api_token_delivery d ON d.token_id=k.id
        WHERE u.email=? AND k.token_hash=?");
    $s->execute([$prefix."@portal-test.invalid",hash("sha256",getenv("TOKEN_".$letter))]);
    $row=$s->fetch();
    if(!$row || $row["account_id"]!==null || !(int)$row["active"] || $row["pending_box"]!==null)exit(1);
    $_SERVER["HTTP_AUTHORIZATION"]="Bearer ".getenv("TOKEN_".$letter);
    try{WhatsAppApiSecurity::authenticate();exit(1);}catch(HttpError $e){if($e->httpStatus!==401)throw $e;}
}
echo "OK: tokens unicos, hash por tenant, entrega unica e assinatura pendente bloqueada\n";
'
php -r '
require "src/whatsapp_bootstrap.php";
$counts=db()->query("SELECT (SELECT COUNT(*) FROM whatsapp_users) AS u,
(SELECT COUNT(*) FROM whatsapp_subscriptions) AS s,
(SELECT COUNT(*) FROM whatsapp_tenants) AS t")->fetch();
if((int)$counts["u"]!==2 || (int)$counts["s"]!==2 || (int)$counts["t"]!==4) {
  fwrite(STDERR,json_encode($counts)."\n"); exit(1);
}
$status=db()->query("SELECT COUNT(*) FROM whatsapp_subscriptions WHERE status=\"pending_activation\"")->fetchColumn();
if((int)$status!==2)exit(1);
echo "OK: dois tenants com assinaturas pendentes, sem pagamento\n";
'
csrf="$(csrf_of "$tmp/dashboard-cliente1")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]]
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/a.jar" -c "$tmp/a.jar" \
 -X POST "$host/sair" --data-urlencode "csrf=$csrf")"
[[ "$code" == "303" ]]
curl -fsS -b "$tmp/a.jar" "$host/entrar" -o "$tmp/login"
csrf="$(csrf_of "$tmp/login")"
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/a.jar" -c "$tmp/a.jar" \
 -X POST "$host/entrar" --data-urlencode "csrf=$csrf" \
 --data-urlencode 'email=cliente1@portal-test.invalid' \
 --data-urlencode 'password=SenhaForteEmCI789')"
[[ "$code" == "303" ]]
curl -fsS -b "$tmp/a.jar" "$host/painel" -o "$tmp/painel-login"
grep -q 'Empresa Sol A' "$tmp/painel-login"
echo "OK: logout e login"

# Cliente legado sem token: backfill repetivel, sem imprimir credenciais.
php -r '
require "src/whatsapp_bootstrap.php";
db()->prepare("INSERT INTO whatsapp_tenants(slug,name) VALUES (?,?)")->execute(["legacy-token-test","Cliente anterior"]);
$id=(int)db()->lastInsertId();
db()->prepare("INSERT INTO whatsapp_users(tenant_id,name,email,password_hash) VALUES (?,?,?,?)")->execute([$id,"Cliente anterior","legacy-token-test@portal-test.invalid",password_hash("SenhaForteEmCI789",PASSWORD_DEFAULT)]);
'
php bin/whatsapp_manage.php token:backfill --dry-run | grep -q '"eligible":1'
php bin/whatsapp_manage.php token:backfill | grep -q '"created":1'
php bin/whatsapp_manage.php token:backfill | grep -q '"created":0'
php -r '
require "src/whatsapp_ApiSecurity.php";
$id=(int)tenantBySlug("legacy-token-test")["id"];
$plain=WhatsAppApiSecurity::takeDefaultToken($id);
if(!$plain || WhatsAppApiSecurity::takeDefaultToken($id)!==null)exit(1);
$_SERVER["HTTP_AUTHORIZATION"]="Bearer ".$plain;
$c=WhatsAppApiSecurity::authenticate();
if((int)$c["tenant_id"]!==$id || !$c["scoped"] || $c["account_id"]!==null)exit(1);
WhatsAppApiSecurity::revoke($id,(int)$c["credential_id"],"token");
if(WhatsAppApiSecurity::provisionDefaultToken($id))exit(1);
try{WhatsAppApiSecurity::authenticate();exit(1);}catch(HttpError $e){if($e->httpStatus!==401)throw $e;}
echo "OK: backfill idempotente, autenticacao e revogacao\n";
'
