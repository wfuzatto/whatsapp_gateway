#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
exec python3 bin/whatsapp_ftp_public_html.py
