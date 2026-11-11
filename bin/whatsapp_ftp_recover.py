#!/usr/bin/env python3
"""Recuperar somente o gateway correto. Quarentena do site misturado sem apagar dados."""
import ftplib
import hashlib
import io
import os
import pathlib
import posixpath
import re
import sys

HOST="ftp.zapapi1.hospedagemdesites.ws"
USER="zapapi1"
LIVE="/public_html"
ARCHIVE_ROOT="/whatsapp_gateway_backups"
ROOT=pathlib.Path(__file__).resolve().parents[1]

def fail(message):
    raise RuntimeError(message)

def sha256(value):
    return hashlib.sha256(value).hexdigest()

def entries(ftp,folder):
    try:
        items=list(ftp.mlsd(folder))
        return sorted(n for n,meta in items if n not in (".","..") and
                      meta.get("type") not in ("cdir","pdir"))
    except ftplib.error_perm:
        ftp.cwd(folder)
        names=ftp.nlst()
        return sorted(posixpath.basename(n.rstrip("/")) for n in names
                      if posixpath.basename(n.rstrip("/")) not in (".",".."))

def get_bytes(ftp,path):
    blob=io.BytesIO()
    ftp.retrbinary("RETR "+path,blob.write)
    return blob.getvalue()

def mkdir_once(ftp,folder,created):
    try:
        ftp.cwd(folder)
    except ftplib.error_perm:
        ftp.mkd(folder)
        created.append(folder)

def selected_files():
    names=[pathlib.Path(".htaccess"),pathlib.Path("public/.htaccess")]
    names += sorted(pathlib.Path("public").glob("whatsapp_*.php"))
    names += sorted(pathlib.Path("src").glob("whatsapp_*.php"))
    names += [pathlib.Path("bin/whatsapp_manage.php"),
              pathlib.Path("bin/whatsapp_worker.php"),
              pathlib.Path("database/whatsapp_schema.sql"),
              pathlib.Path("database/whatsapp_migration_portal.sql")]
    data={}
    for file in names:
        source=ROOT/file
        if not source.is_file() or source.is_symlink():
            fail("Arquivo essencial ausente ou symlink: "+file.as_posix())
        data[file.as_posix()]=source.read_bytes()
    must=[".htaccess","public/.htaccess","public/whatsapp_index.php",
          "public/whatsapp_portal.php","public/whatsapp_meta_panel.php",
          "src/whatsapp_bootstrap.php","src/whatsapp_MetaPanel.php",
          "src/whatsapp_Installer.php","database/whatsapp_schema.sql"]
    if len(data)<18 or not all(k in data for k in must):
        fail("Repositorio nao contem a estrutura esperada do WhatsApp Gateway")
    if b"WhatsAppMetaPanel::route" not in data["public/whatsapp_index.php"]:
        fail("Roteador do gateway nao esta na versao correta")
    if b"formatMetaError" not in data["src/whatsapp_MetaPanel.php"]:
        fail("Correcao de diagnostico Meta nao encontrada")
    return data

def main():
    user=os.environ.get("FTP_USERNAME","")
    password=os.environ.get("FTP_PASSWORD","")
    if user!=USER or not password:
        fail("Credenciais FTP incorretas ou indisponiveis")
    expected=os.environ.get("FTP_PATH","/public_html/").rstrip("/")
    if expected!=LIVE:
        fail("Apenas /public_html pode ser recuperado")
    if os.environ.get("FTP_PROTOCOL","ftp")!="ftp":
        fail("Protocolo inesperado para esta hospedagem")
    upload=selected_files()
    run=re.sub("[^0-9]","",os.environ.get("GITHUB_RUN_ID","")) or "manual"
    sha=re.sub("[^a-f0-9]","",os.environ.get("GITHUB_SHA",""))[:12]
    archive=ARCHIVE_ROOT+"/whatsapp_recuperacao_"+run+"_"+sha
    ftp=ftplib.FTP(timeout=60)
    moved=[]
    uploaded=[]
    dirs=[]
    started=False
    finished=False
    try:
        ftp.connect(HOST,21)
        ftp.login(user,password)
        ftp.set_pasv(True)
        ftp.cwd(LIVE)
        old=entries(ftp,LIVE)
        print("Inventario anterior: ",len(old),"itens no nivel principal",flush=True)
        expected_wrong={"app","assets","index.php","worker.php"}
        if not expected_wrong.issubset(set(old)):
            fail("A estrutura misturada nao corresponde a auditoria. "
                 "Nenhum arquivo foi alterado. Repetir auditoria.")
        if "public" in old or "src" in old:
            fail("Pastas do gateway ja presentes; abortado para evitar mistura")
        if ".env" in old or ".env.production" in old:
            fail("Configuracao privada encontrada na pasta publica; tratamento manual necessario")
        # Verificar a configuracao privada sem sequer ler os segredos.
        root=entries(ftp,"/")
        if "whatsapp_install_done.txt" not in root:
            fail("Marcador de instalacao nao encontrado; nao substituir estrutura antes de verificar")
        print("Instalacao anterior detectada; banco e .env nao serao alterados",flush=True)

        mkdir_once(ftp,ARCHIVE_ROOT,dirs)
        ftp.mkd(archive)  # unico por run; nunca sobrescreve backup anterior.
        print("Quarentena protegida criada:",archive,flush=True)
        # Mover os arquivos do projeto errado, inclusive pastas OLD, app, assets.
        # RNFR/RNTO preserva bytes/diretorios sem copiá-los nem apagá-los.
        started=True
        for name in old:
            ftp.rename(LIVE+"/"+name,archive+"/"+name)
            moved.append(name)
            print("Original preservado:",name,flush=True)
        if entries(ftp,LIVE):
            fail("Existem itens antigos restantes em public_html")

        # Publicar somente runtime do whatsapp_gateway (nao workflow, .env ou docs).
        # Apache bloqueia acesso publico a src/, database/ e bin/.
        for relative,content in upload.items():
            directory=posixpath.dirname(LIVE+"/"+relative)
            parts=directory[len(LIVE):].strip("/").split("/")
            current=LIVE
            for part in parts:
                if not part:
                    continue
                current=current+"/"+part
                if current not in dirs:
                    mkdir_once(ftp,current,dirs)
            dest=LIVE+"/"+relative
            ftp.storbinary("STOR "+dest,io.BytesIO(content))
            uploaded.append(dest)
            if sha256(get_bytes(ftp,dest))!=sha256(content):
                fail("SHA256 divergente apos envio: "+relative)
            print("WhatsApp Gateway confirmado:",relative,flush=True)
        finished=True
        print("RECUPERACAO CONCLUIDA: ",len(upload),
              "arquivos do WhatsApp Gateway em /public_html",flush=True)
        print("Projeto anterior integralmente preservado em:",archive,flush=True)
        print("Nao foram alterados MySQL, .env ou chaves Meta",flush=True)
        summary=os.environ.get("GITHUB_STEP_SUMMARY")
        if summary:
            with open(summary,"a",encoding="utf-8") as out:
                out.write("### WhatsApp Gateway restaurado em /public_html\n\n")
                out.write("- Origem: wfuzatto/whatsapp_gateway, branch main\n")
                out.write("- Publicados e comparados SHA256: **"+str(len(upload))+" arquivos**\n")
                out.write("- Arquivos do projeto anterior preservados: **"+str(len(moved))+" itens**\n")
                out.write("- Quarentena: "+archive+"\n")
                out.write("- Banco de dados, .env e segredos Meta: **inalterados**\n")
    except Exception as problem:
        if started and not finished:
            print("Falha durante recuperacao. Iniciando rollback.",flush=True)
            errors=[]
            for path in reversed(uploaded):
                try: ftp.delete(path)
                except ftplib.all_errors as e: errors.append("delete "+path+": "+str(e))
            for folder in reversed(dirs):
                if folder.startswith(LIVE+"/"):
                    try: ftp.rmd(folder)
                    except ftplib.all_errors as e: errors.append("rmd "+folder+": "+str(e))
            for name in reversed(moved):
                try: ftp.rename(archive+"/"+name,LIVE+"/"+name)
                except ftplib.all_errors as e: errors.append("restore "+name+": "+str(e))
            if errors:
                print("::error::Rollback incompleto; verificar FTP antes de novo deploy",flush=True)
                for entry in errors[:12]: print("::error::"+entry,flush=True)
            else:
                print("Rollback completo: aplicacao anterior devolvida ao local original",flush=True)
        raise
    finally:
        try: ftp.quit()
        except Exception: ftp.close()

if __name__=="__main__":
    try: main()
    except Exception as exc:
        print("::error::Recuperacao FTP falhou: "+str(exc),file=sys.stderr)
        sys.exit(1)
