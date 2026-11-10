#!/usr/bin/env bash
set -Eeuo pipefail
export APP_REQUIRE_HTTPS=0
tmp="$(mktemp -d)"
php -S 127.0.0.1:18883 -t public public/whatsapp_index.php >"$tmp/server.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
host="http://127.0.0.1:18883"
for i in $(seq 1 30); do
 if curl -fsS "$host/health" >/dev/null 2>&1; then break; fi
 sleep .3
done
# Cliente nao autenticado nunca pode abrir painel de integracoes.
code="$(curl -sS -o /dev/null -w '%{http_code}' "$host/painel/meta")"
[[ "$code" == 303 ]] || { echo "Meta unauthenticated route returned $code"; exit 1; }
curl -fsS -b "$tmp/a.jar" -c "$tmp/a.jar" "$host/entrar" -o "$tmp/login.html"
csrf="$(grep -o 'name="csrf" value="[^"]*"' "$tmp/login.html" | head -1 | cut -d'"' -f4)"
[[ "$csrf" =~ ^[0-9a-f]{64}$ ]]
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/a.jar" -c "$tmp/a.jar" \
    -X POST "$host/entrar" --data-urlencode "csrf=$csrf" \
    --data-urlencode 'email=cliente1@portal-test.invalid' \
    --data-urlencode 'password=SenhaForteEmCI789')"
[[ "$code" == 303 ]] || { echo "Tenant login returned $code"; exit 1; }
curl -fsS -b "$tmp/a.jar" "$host/painel/meta" -o "$tmp/meta.html"
grep -q "Integração com a Meta" "$tmp/meta.html"
grep -q "Validar e salvar integração" "$tmp/meta.html"
grep -q "Criar e enviar template para aprovação" "$tmp/meta.html"
grep -q "Conecte primeiro seu WABA" "$tmp/meta.html"
grep -q "Empresa Sol A" "$tmp/meta.html"
if grep -q "Empresa Lua B" "$tmp/meta.html"; then
    echo "FAIL: tenant B leaked into tenant A meta settings"; exit 1
fi
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$tmp/a.jar" -c "$tmp/a.jar" \
    -X POST "$host/painel/meta" \
    --data-urlencode 'action=key_create' --data-urlencode 'account_id=1' \
    --data-urlencode 'key_name=ERP principal' \
    --data-urlencode 'csrf=WRONG')"
[[ "$code" == 419 ]] || { echo "CSRF protection returned $code"; exit 1; }
echo "OK: protected tenant Meta page, CSRF and isolation"
