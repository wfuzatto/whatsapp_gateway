#!/usr/bin/env bash
# Envia os arquivos versionados a uma pasta NOVA no FTP do usuario.
# Nao faz deploy no site. Usa FTP comum (sem TLS), conforme solicitado.
# ATENCAO: FTP em texto claro expoe credenciais e arquivos na rede.
set -Eeuo pipefail
umask 077

for key in FTP_SERVER FTP_USERNAME FTP_PASSWORD; do
  if [[ -z "${!key:-}" ]]; then
    echo "::error::Secret obrigatorio ausente: ${key}. Configure em Settings > Secrets and variables > Actions."
    exit 1
  fi
done

protocol="${FTP_PROTOCOL:-ftp}"
protocol="${protocol,,}"
if [[ "$protocol" != "ftp" ]]; then
  echo "::error::FTP_PROTOCOL deve ser ftp para este teste (porta 21, sem TLS)."
  exit 1
fi
if [[ "$FTP_SERVER" != "ftp.zapapi1.hospedagemdesites.ws" || "$FTP_USERNAME" != "zapapi1" ]]; then
  echo "::error::Servidor/usuario diferente do destino previsto para este teste. Interrompido sem enviar arquivos."
  exit 1
fi
port="${FTP_PORT:-21}"
if [[ "$port" != "21" ]]; then
  echo "::error::Este teste foi configurado exclusivamente para FTP comum na porta 21."
  exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
  echo "::error::curl indisponivel no runner"
  exit 1
fi

# O FTP_PATH nao e usado no teste: o diretorio fisico /home/zapapi1
# pode nao corresponder ao caminho visto pela sessao FTP.
# Cria pasta unicamente para este run sob a raiz visivel do FTP.
destination="whatsapp_gateway_teste/${GITHUB_RUN_ID:-manual}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_SHA:0:12}"
base="ftp://${FTP_SERVER}:${port}/${destination}"
netrc="$(mktemp)"
marker="$(mktemp)"
received="$(mktemp)"
trap 'rm -f "$netrc" "$marker" "$received"' EXIT
chmod 600 "$netrc"
# Escape para o formato .netrc sem mostrar senha no log.
password_escaped="${FTP_PASSWORD//\\/\\\\}"
password_escaped="${password_escaped//\"/\\\"}"
printf 'machine %s login %s password "%s"\n' "$FTP_SERVER" "$FTP_USERNAME" "$password_escaped" > "$netrc"

ftp_upload() {
  local source="$1" remote="$2"
  curl --silent --show-error --fail --retry 1 --retry-delay 1 \
    --proto '=ftp' --ftp-pasv --ftp-create-dirs \
    --netrc-file "$netrc" --connect-timeout 20 --max-time 90 \
    --upload-file "$source" "${base}/${remote}"
}
ftp_download() {
  local remote="$1" target="$2"
  curl --silent --show-error --fail --retry 1 --retry-delay 1 \
    --proto '=ftp' --ftp-pasv \
    --netrc-file "$netrc" --connect-timeout 20 --max-time 90 \
    --output "$target" "${base}/${remote}"
}

printf 'Teste FTP WhatsApp Gateway\ncommit=%s\nrun=%s\n' \
  "${GITHUB_SHA:-local}" "${GITHUB_RUN_ID:-manual}" > "$marker"
echo "Iniciando teste FTP comum (sem criptografia). Destino: ${destination}"
ftp_upload "$marker" whatsapp_teste_conexao.txt
ftp_download whatsapp_teste_conexao.txt "$received"
if ! cmp -s "$marker" "$received"; then
  echo "::error::Arquivo de teste enviado, mas verificacao de download falhou."
  exit 1
fi
echo "Conexao, autenticacao, gravacao e leitura FTP OK."

count=0
while IFS= read -r -d '' file; do
  [[ -f "$file" && ! -L "$file" ]] || { echo "::error::Arquivo invalido: $file"; exit 1; }
  name="${file##*/}"
  case "$name" in
    .htaccess|.gitignore|whatsapp_*) ;;
    *) echo "::error::Arquivo fora do padrao whatsapp_: $file"; exit 1 ;;
  esac
  ftp_upload "$file" "$file"
  count=$((count + 1))
done < <(git ls-files -z)

echo "Envio FTP concluido: ${count} arquivos do Git, isolados em ${destination}."
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo '### Teste FTP do WhatsApp Gateway'
    echo
    echo '- Conexao FTP sem criptografia: **OK**'
    echo '- Upload/download do marcador: **OK**'
    echo "- Arquivos enviados: **${count}**"
    echo "- Pasta de teste relativa a raiz FTP: \`${destination}\`"
    echo '- Site de producao: **inalterado**'
    echo '- FTP_PATH: **nao utilizado neste teste**'
  } >> "$GITHUB_STEP_SUMMARY"
fi
