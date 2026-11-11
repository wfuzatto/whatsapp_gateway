#!/usr/bin/env python3
"""Atualiza apenas dois arquivos do modulo Meta em instalacao reconhecida."""
import ftplib
import hashlib
import io
import json
import os
import pathlib
import sys
import zipfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
DEST="/public_html"
FILES={
    "src/whatsapp_MetaPanel.php":b"final class WhatsAppMetaPanel",
    "public/whatsapp_meta_panel.php":b"WHATSAPP_META_PANEL_VIEW",
}

def digest(content):
    return hashlib.sha256(content).hexdigest()

def download(ftp,path):
    buffer=io.BytesIO()
    ftp.retrbinary("RETR "+path,buffer.write)
    return buffer.getvalue()

def main():
    user=os.getenv("FTP_USERNAME","")
    password=os.getenv("FTP_PASSWORD","")
    if user!="zapapi1" or not password:
        raise RuntimeError("Credenciais FTP nao encontradas nos Secrets")
    new={}
    for name,marker in FILES.items():
        path=ROOT/name
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Arquivo local invalido: "+name)
        new[name]=path.read_bytes()
        if marker not in new[name]:
            raise RuntimeError("Assinatura invalida do modulo: "+name)
    ftp=ftplib.FTP(timeout=45)
    try:
        ftp.connect("ftp.zapapi1.hospedagemdesites.ws",21)
        ftp.login(user,password)
        ftp.set_pasv(True)
        previous={}
        for name,marker in FILES.items():
            previous[name]=download(ftp,DEST+"/"+name)
            if marker not in previous[name]:
                raise RuntimeError("Gateway nao confirmado no arquivo remoto: "+name)
        backup="/whatsapp_gateway_backups"
        try:
            ftp.cwd(backup)
        except ftplib.error_perm:
            ftp.mkd(backup)
        run="".join(x for x in os.getenv("GITHUB_RUN_ID","manual") if x.isdigit())
        sha="".join(x for x in os.getenv("GITHUB_SHA","") if x in "abcdef0123456789")[:12]
        name="whatsapp_meta_"+run+"_"+sha+".zip"
        archive=io.BytesIO()
        with zipfile.ZipFile(archive,"w",zipfile.ZIP_DEFLATED) as z:
            for path,contents in previous.items():
                z.writestr(path,contents)
            z.writestr("whatsapp_manifest.json",json.dumps({
                "sha256":{k:digest(v) for k,v in previous.items()},
                "destination":DEST
            }))
        archive.seek(0)
        ftp.storbinary("STOR "+backup+"/"+name,archive)
        if not download(ftp,backup+"/"+name):
            raise RuntimeError("Nao foi possivel verificar o backup")
        print("Backup dos dois arquivos salvo fora de public_html")
        for path,contents in new.items():
            dest=DEST+"/"+path
            ftp.storbinary("STOR "+dest,io.BytesIO(contents))
            if digest(download(ftp,dest))!=digest(contents):
                raise RuntimeError("Upload nao foi validado: "+path)
            print("Arquivo validado:",path)
        print("Patch Meta concluido sem alterar index.php, .htaccess nem o banco.")
    finally:
        try: ftp.quit()
        except Exception: ftp.close()

if __name__=="__main__":
    try: main()
    except Exception as exc:
        print("::error::"+str(exc),file=sys.stderr)
        sys.exit(1)
