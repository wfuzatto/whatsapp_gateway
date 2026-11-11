#!/usr/bin/env python3
"""HTTP checks do WhatsApp Gateway implantado. Somente requisicoes GET."""
import json
import ssl
import sys
import urllib.error
import urllib.request

BASE="https://zapapi.inf.br"
TLS=ssl.create_default_context()
def probe(path):
    url=BASE+path
    request=urllib.request.Request(url,headers={"User-Agent":"WhatsApp-Gateway-Deployment-Check/1.0"})
    opener=urllib.request.build_opener(urllib.request.HTTPSHandler(context=TLS))
    try:
        response=opener.open(request,timeout=18)
        return response.status,response.headers, response.read(200000).decode("utf-8","replace")
    except urllib.error.HTTPError as exc:
        return exc.code,exc.headers,exc.read(10000).decode("utf-8","replace")

def main():
    status,headers,body=probe("/health")
    print("GET /health",status,"content-type",headers.get("Content-Type","?"),flush=True)
    if status!=200: raise RuntimeError("/health nao respondeu HTTP 200")
    try: data=json.loads(body)
    except json.JSONDecodeError as exc: raise RuntimeError("/health nao retornou JSON") from exc
    if data.get("gateway")!="cloud-api" or data.get("status")!="ok":
        raise RuntimeError("/health retornou outra aplicacao ou status inesperado")
    status,headers,body=probe("/")
    print("GET /",status,"content-type",headers.get("Content-Type","?"),flush=True)
    if status!=200 or "Gateway WhatsApp" not in body:
        raise RuntimeError("A pagina inicial nao parece ser o WhatsApp Gateway")
    status,headers,body=probe("/entrar")
    print("GET /entrar",status,flush=True)
    if status!=200 or 'action="/entrar"' not in body:
        raise RuntimeError("Login dos tenants indisponivel")
    print("VALIDACAO HTTPS APROVADA: API, landing e login do gateway",flush=True)

if __name__=="__main__":
    try:main()
    except Exception as exc:
        print("::error::Teste HTTP remoto falhou: "+str(exc),file=sys.stderr)
        sys.exit(1)
