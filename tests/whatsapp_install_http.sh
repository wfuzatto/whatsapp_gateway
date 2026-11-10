#!/usr/bin/env bash
# Teste HTTP do assistente em banco e diretorio descartaveis do CI.
set -Eeuo pipefail
export APP_ENV=test APP_REQUIRE_HTTPS=0
tmp="$(mktemp -d)"
export WHATSAPP_INSTALL_TEST_DIR="$tmp/secrets"
mkdir -p "$WHATSAPP_INSTALL_TEST_DIR"
chmod 700 "$WHATSAPP_INSTALL_TEST_DIR"
php -r 'echo bin2hex(random_bytes(32));' > "$WHATSAPP_INSTALL_TEST_DIR/whatsapp_install_key.txt"
chmod 600 "$WHATSAPP_INSTALL_TEST_DIR/whatsapp_install_key.txt"
key="$(cat "$WHATSAPP_INSTALL_TEST_DIR/whatsapp_install_key.txt")"
host=http://127.0.0.1:18882
php -S 127.0.0.1:18882 -t public public/whatsapp_index.php > "$tmp/http.log" 2>&1 &
server_pid=$!
cleanup(){ kill "$server_pid" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
for i in $(seq 1 35); do
 if curl -fsS "$host/health" >/dev/null 2>&1; then break; fi
 sleep 0.25
done
curl -fsS -b "$tmp/cookie" -c "$tmp/cookie" "$host/install" -o "$tmp/install.html"
grep -q 'Configure o WhatsApp Gateway' "$tmp/install.html"
csrf="$(grep -o 'name="csrf" value="[^"]*"' "$tmp/install.html" | head -1 | cut -d'"' -f4)"
[[ "$csrf" =~ ^[0-9a-f]{64}$ ]]
code="$(curl -sS -o "$tmp/bad.html" -w '%{http_code}' -b "$tmp/cookie" -c "$tmp/cookie" \
 -X POST "$host/install" --data-urlencode "csrf=$csrf" \
 --data-urlencode 'install_key=chave-errada' \
 --data-urlencode "db_password=$DB_PASSWORD" \
 --data-urlencode 'admin_name=Administrador CI' \
 --data-urlencode 'admin_email=admin-ci@example.invalid' \
 --data-urlencode 'admin_password=AdminSuperSegura789' \
 --data-urlencode 'admin_confirm=AdminSuperSegura789')"
[[ "$code" == "403" ]] || { echo "Chave incorreta nao foi rejeitada: $code"; exit 1; }
echo "OK: instalador exige chave confidencial"
code="$(curl -sS -o "$tmp/out.html" -D "$tmp/headers" -w '%{http_code}' \
 -b "$tmp/cookie" -c "$tmp/cookie" -X POST "$host/install" \
 --data-urlencode "csrf=$csrf" \
 --data-urlencode "install_key=$key" \
 --data-urlencode "db_password=$DB_PASSWORD" \
 --data-urlencode 'admin_name=Administrador CI' \
 --data-urlencode 'admin_email=admin-ci@example.invalid' \
 --data-urlencode 'admin_password=AdminSuperSegura789' \
 --data-urlencode 'admin_confirm=AdminSuperSegura789')"
if [[ "$code" != "303" ]]; then
 echo "Falha ao instalar HTTP $code"
 grep -o 'class="notice error"[^<]*' "$tmp/out.html" || true
 cat "$tmp/http.log" | tail -35
 exit 1
fi
grep -qi 'location: /admin' "$tmp/headers"
[[ -s "$WHATSAPP_INSTALL_TEST_DIR/.env" ]]
[[ -s "$WHATSAPP_INSTALL_TEST_DIR/whatsapp_install_done.txt" ]]
[[ ! -f "$WHATSAPP_INSTALL_TEST_DIR/whatsapp_install_key.txt" ]]
php -r 'require "src/whatsapp_bootstrap.php"; $s=db()->query("SELECT COUNT(*) FROM whatsapp_admins")->fetchColumn(); if((int)$s!==1)exit(1);'
echo "OK: DB, admin, .env, bloqueio criados e chave removida"
curl -fsS -b "$tmp/cookie" -c "$tmp/cookie" "$host/admin" -o "$tmp/admin.html"
grep -q 'Superadministração' "$tmp/admin.html"
grep -q 'Assinaturas' "$tmp/admin.html"
curl -fsS "$host/install" -o "$tmp/done.html"
grep -q 'O sistema já foi instalado' "$tmp/done.html"
echo "OK: painel admin acessivel e reinstalacao bloqueada"
csrf="$(grep -o 'name="csrf" value="[^"]*"' "$tmp/admin.html" | head -1 | cut -d'"' -f4)"
slug="$(php -r 'require "src/whatsapp_bootstrap.php"; echo db()->query("SELECT t.slug FROM whatsapp_tenants t JOIN whatsapp_subscriptions s ON s.tenant_id=t.id ORDER BY t.id DESC LIMIT 1")->fetchColumn();')"
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookie" -c "$tmp/cookie" \
 -X POST "$host/admin/assinatura" --data-urlencode "csrf=$csrf" \
 --data-urlencode "tenant_slug=$slug" --data-urlencode 'action=activate')"
[[ "$code" == "303" ]]
result="$(php -r 'require "src/whatsapp_bootstrap.php"; $s=db()->query("SELECT COUNT(*) FROM whatsapp_subscriptions WHERE status=\"active\""); echo $s->fetchColumn();')"
[[ "$result" == "1" ]]
echo "OK: administrador ativa assinatura manualmente"
csrf="$(grep -o 'name="csrf" value="[^"]*"' "$tmp/admin.html" | head -1 | cut -d'"' -f4)"
curl -sS -o /dev/null -b "$tmp/cookie" -c "$tmp/cookie" -X POST "$host/admin/sair" --data-urlencode "csrf=$csrf"
curl -fsS -b "$tmp/cookie" -c "$tmp/cookie" "$host/admin/entrar" -o "$tmp/login.html"
csrf="$(grep -o 'name="csrf" value="[^"]*"' "$tmp/login.html" | head -1 | cut -d'"' -f4)"
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/cookie" -c "$tmp/cookie" \
 -X POST "$host/admin/entrar" --data-urlencode "csrf=$csrf" \
 --data-urlencode 'email=admin-ci@example.invalid' \
 --data-urlencode 'password=AdminSuperSegura789')"
[[ "$code" == "303" ]]
curl -fsS -b "$tmp/cookie" "$host/admin" -o "$tmp/again.html"
grep -q 'Superadministração' "$tmp/again.html"
echo "OK: login do superadmin com senha protegida"
