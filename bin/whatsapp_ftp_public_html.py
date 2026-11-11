#!/usr/bin/env python3
"""Publica SOMENTE os arquivos de execução do gateway em /public_html/.
FTP simples, conforme solicitado. Faz backup de qualquer arquivo substituido.
"""
import ftplib
import hashlib
import io
import json
import os
import pathlib
import posixpath
import re
import secrets
import sys
import time
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
REMOTE_ROOT = "/public_html"
EXPECTED_HOST = "ftp.zapapi1.hospedagemdesites.ws"
EXPECTED_USER = "zapapi1"


def fail(message: str) -> None:
    raise RuntimeError(message)


def sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def remote_listing(ftp, folder):
    current = ftp.pwd()
    try:
        ftp.cwd(folder)
        try:
            return {posixpath.basename(p.rstrip("/")) for p in ftp.nlst()}
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise
            return set()
    finally:
        ftp.cwd(current)


def ensure_remote_dir(ftp, folder):
    current = ftp.pwd()
    ftp.cwd("/")
    for part in folder.strip("/").split("/"):
        if not part:
            continue
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)
    ftp.cwd(current)


def retrieve_if_exists(ftp, path, known_listing):
    parent, name = posixpath.split(path)
    try:
        existing = remote_listing(ftp, parent or REMOTE_ROOT)
    except ftplib.error_perm as error:
        # Nao ha arquivo anterior se o subdiretorio ainda nao existe.
        if str(error).startswith("550"):
            return None
        raise
    if name not in existing:
        return None
    buffer = io.BytesIO()
    ftp.retrbinary("RETR " + path, buffer.write)
    return buffer.getvalue()


def main():
    host = os.getenv("FTP_SERVER", "")
    user = os.getenv("FTP_USERNAME", "")
    password = os.getenv("FTP_PASSWORD", "")
    port = os.getenv("FTP_PORT") or "21"
    protocol = (os.getenv("FTP_PROTOCOL") or "ftp").lower()
    configured_root = os.getenv("FTP_PATH") or REMOTE_ROOT
    if host != EXPECTED_HOST or user != EXPECTED_USER:
        fail("FTP_SERVER/FTP_USERNAME nao correspondem ao destino autorizado")
    if not password:
        fail("Secret FTP_PASSWORD ausente. Confira GitHub Actions Secrets")
    if port != "21" or protocol != "ftp":
        fail("Este workflow usa FTP comum na porta 21, conforme solicitado")
    if configured_root.rstrip("/") != REMOTE_ROOT:
        fail("FTP_PATH deve ser /public_html/; caminho de deploy diferente recusado")

    files = [pathlib.Path(".htaccess")]
    files += sorted(pathlib.Path("public").glob("whatsapp_*.php"))
    files += [pathlib.Path("public/.htaccess")]
    files += sorted(pathlib.Path("public/whatsapp_assets").glob("whatsapp_*.svg"))
    files += sorted(pathlib.Path("src").glob("whatsapp_*.php"))
    files += [pathlib.Path("bin/whatsapp_worker.php"),
              pathlib.Path("bin/whatsapp_manage.php"),
              pathlib.Path("database/whatsapp_schema.sql"),
              pathlib.Path("database/whatsapp_migration_portal.sql")]
    if len(files) < 10 or not pathlib.Path("public/whatsapp_index.php").is_file():
        fail("Arquivos essenciais ausentes: deploy interrompido")
    for file in files:
        if not file.is_file() or file.is_symlink():
            fail("Arquivo ausente ou link simbolico: " + str(file))
    # Nao enviar README, docs, testes, .github, .env nem credenciais.
    upload = {f.as_posix(): (ROOT / f).read_bytes() for f in files}

    run = re.sub("[^A-Za-z0-9_-]", "", os.getenv("GITHUB_RUN_ID", "manual"))
    sha = re.sub("[^a-f0-9]", "", os.getenv("GITHUB_SHA", ""))[:12]
    ftp = ftplib.FTP(timeout=45)
    try:
        ftp.connect(host, int(port))
        ftp.login(user, password)
        ftp.set_pasv(True)
        ftp.cwd(REMOTE_ROOT)

        names = remote_listing(ftp, REMOTE_ROOT)
        # NUNCA substituir sites nao reconhecidos. A raiz pode conter um
        # index.php criado pelo provedor ou pelo administrador. Preserva-lo
        # somente se esta instalacao ja tiver assinatura estrutural do gateway.
        conflicting = sorted(names.intersection({"wp-config.php", "wp-content"}))
        if conflicting:
            fail("Publicacao bloqueada: outro site detectado: " + ", ".join(conflicting))
        homepage = sorted(names.intersection({"index.php", "index.html"}))
        if homepage:
            router = retrieve_if_exists(ftp, REMOTE_ROOT + "/public/whatsapp_index.php", names)
            public_rules = retrieve_if_exists(ftp, REMOTE_ROOT + "/public/.htaccess", names)
            root_rules = retrieve_if_exists(ftp, REMOTE_ROOT + "/.htaccess", names)
            recognized = (
                router is not None and b"WhatsAppPortal::route" in router and
                b"WhatsAppInstaller::route" in router and
                public_rules is not None and b"whatsapp_index.php" in public_rules and
                root_rules is not None and b"RewriteRule ^(.*)$ public/$1" in root_rules
            )
            if not recognized:
                fail("index.php/index.html detectado, mas nao foi possivel confirmar "
                     "uma instalacao existente deste gateway. Nenhum arquivo alterado.")
            print("Gateway existente reconhecido. Arquivos index.php/index.html "
                  "serao preservados sem qualquer alteracao.")
        # Preparar uma chave aleatoria de primeiro uso FORA de public_html.
        # O operador a le pelo FileZilla na raiz FTP, nunca por URL.
        done = retrieve_if_exists(ftp, "/whatsapp_install_done.txt", names)
        if done is None:
            old_token = retrieve_if_exists(ftp, "/whatsapp_install_key.txt", names)
            if old_token is None:
                secret = secrets.token_urlsafe(40).encode("ascii")
                ftp.storbinary("STOR /whatsapp_install_key.txt", io.BytesIO(secret))
                verify = io.BytesIO()
                ftp.retrbinary("RETR /whatsapp_install_key.txt", verify.write)
                if sha256(verify.getvalue()) != sha256(secret):
                    fail("Chave de instalacao nao foi verificada")
                print("Chave do instalador criada em /whatsapp_install_key.txt (valor oculto).")
            else:
                print("Chave do instalador ja existe em /whatsapp_install_key.txt (valor oculto).")
        else:
            print("Instalacao ja bloqueada; chave de instalacao nao sera gerada.")
        print("Destino de publicacao confirmado: /public_html/")
        print("Arquivos do projeto selecionados para deploy:", len(upload))

        previous = {}
        for path in upload:
            remote_path = posixpath.join(REMOTE_ROOT, path)
            old = retrieve_if_exists(ftp, remote_path, names)
            if old is not None:
                previous[path] = old
        print("Arquivos existentes que serao substituidos:", len(previous))

        if previous:
            backup_folder = "/whatsapp_gateway_backups"
            ensure_remote_dir(ftp, backup_folder)
            archive = io.BytesIO()
            with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as zipf:
                for path, old in previous.items():
                    zipf.writestr(path, old)
                zipf.writestr("whatsapp_backup_manifest.json", json.dumps({
                    "run": run, "sha": sha, "destination": REMOTE_ROOT,
                    "files": {p: sha256(v) for p, v in previous.items()}
                }, indent=2))
            archive.seek(0)
            backup_name = f"{backup_folder}/whatsapp_predeploy_{run}_{sha}.zip"
            ftp.storbinary("STOR " + backup_name, archive)
            print("Backup criado fora da pasta publica:", backup_name)

        # .htaccess vem primeiro para bloquear acesso web a src, bin e database.
        for path, content in upload.items():
            remote_path = posixpath.join(REMOTE_ROOT, path)
            ensure_remote_dir(ftp, posixpath.dirname(remote_path))
            ftp.storbinary("STOR " + remote_path, io.BytesIO(content))
            received = io.BytesIO()
            ftp.retrbinary("RETR " + remote_path, received.write)
            if sha256(received.getvalue()) != sha256(content):
                fail("Falha na verificacao SHA-256 apos envio: " + path)
            print("Verificado:", path)

        print("FTP deploy concluido em /public_html/:", len(upload), "arquivos.")
        summary = os.getenv("GITHUB_STEP_SUMMARY", "")
        if summary:
            with open(summary, "a", encoding="utf-8") as out:
                out.write("### Publicacao FTP em /public_html/\n\n")
                out.write("- Protocolo: FTP porta 21 (sem criptografia)\n")
                out.write(f"- Arquivos publicados e verificados: **{len(upload)}**\n")
                out.write(f"- Arquivos preexistentes com backup: **{len(previous)}**\n")
                out.write("- Outros arquivos remotos: preservados\n")
                out.write("- Banco de dados e .env: nao alterados\n")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("::error::" + str(error), file=sys.stderr)
        sys.exit(1)
