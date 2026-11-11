#!/usr/bin/env python3
"""Auditoria somente leitura da raiz FTP; nunca imprime credenciais ou dados privados."""
import ftplib
import hashlib
import io
import os
import posixpath
import sys

HOST="ftp.zapapi1.hospedagemdesites.ws"
PATHS=["/","/public_html","/public_html/public","/public_html/src",
       "/public_html/bin","/public_html/database"]
def safe(name):
    n=posixpath.basename(str(name).rstrip("/"))
    if any(k in n.lower() for k in (".env","password","secret","key","credential","token","backup","config")):
        return "[arquivo reservado]"
    return n[:90]
def main():
    user=os.getenv("FTP_USERNAME","")
    pw=os.getenv("FTP_PASSWORD","")
    if user!="zapapi1" or not pw:
        raise RuntimeError("Credenciais FTP ausentes ou usuario inesperado")
    ftp=ftplib.FTP(timeout=35)
    try:
        ftp.connect(HOST,21)
        ftp.login(user,pw)
        ftp.set_pasv(True)
        print("Servidor FTP conectado; nenhuma gravacao sera feita",flush=True)
        for path in PATHS:
            try:
                ftp.cwd(path)
                try:
                    entries=list(ftp.mlsd())
                    items=[safe(x) + ("/" if info.get("type")=="dir" else "")
                           for x,info in entries if x not in (".","..")]
                except ftplib.all_errors:
                    items=[safe(x) for x in ftp.nlst()]
                print("DIRETORIO",path,"N=",len(items),
                      "ENTRADAS=",", ".join(sorted(items)[:100]),flush=True)
            except ftplib.all_errors:
                print("DIRETORIO",path,"INDISPONIVEL",flush=True)
        for path in ["/public_html/index.php","/public_html/.htaccess",
                     "/public_html/public/whatsapp_index.php",
                     "/public_html/src/whatsapp_MetaPanel.php"]:
            try:
                data=io.BytesIO()
                ftp.retrbinary("RETR "+path,data.write)
                content=data.getvalue()
                markers=[]
                for token in [b"WhatsAppMetaPanel", b"whatsapp_index.php",
                              b"WhatsAppPortal::route",b"WordPress",b"wp-blog-header.php"]:
                    if token in content: markers.append(token.decode("ascii"))
                print("ARQUIVO",path,"BYTES",len(content),"SHA256",
                      hashlib.sha256(content).hexdigest(),
                      "MARCADORES",",".join(markers),flush=True)
            except ftplib.all_errors:
                print("ARQUIVO",path,"INDISPONIVEL",flush=True)
    finally:
        try: ftp.quit()
        except Exception: ftp.close()
if __name__=="__main__":
    try: main()
    except Exception as exc:
        print("::error::Auditoria FTP falhou: "+str(exc),file=sys.stderr)
        sys.exit(1)
