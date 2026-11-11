#!/usr/bin/env python3
"""Teste remoto sem credenciais: endpoints publicos e bloqueio de dados privados."""
import json
import ssl
import urllib.error
import urllib.request
base="https://zapapi.inf.br/mobile-api/v1"
def check(path, expected):
    r=urllib.request.Request(base+path,headers={"Accept":"application/json",
      "User-Agent":"ZapApi-Mobile-CI/1.0"})
    try:
        with urllib.request.urlopen(r,timeout=25,context=ssl.create_default_context()) as resp:
            status=resp.status
            raw=resp.read(8000)
            typ=resp.headers.get("Content-Type","")
    except urllib.error.HTTPError as e:
        status=e.code
        raw=e.read(8000)
        typ=e.headers.get("Content-Type","")
    print(path,"=>",status,typ,flush=True)
    assert status==expected,(path,status,expected)
    assert "json" in typ,typ
    content=json.loads(raw)
    assert isinstance(content,dict)
    return content
health=check("/health",200)
assert health.get("service")=="zapapi-mobile",health
check("/auth/login",200)
unauth=check("/conversations",401)
assert "error" in unauth
print("API MOVEL V1 DISPONIVEL: health/login JSON e dados protegidos",flush=True)
