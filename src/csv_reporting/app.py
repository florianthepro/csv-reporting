from __future__ import annotations
import hmac,json,os,secrets
from importlib.resources import files
from pathlib import Path
from urllib.parse import parse_qs
from ftpkit import FtpkitError,parse_uuid
from .config import ConfigStore
from .data import CsvData,render
from .migration import migrate_import

class Application:
    def __init__(self,data_root:Path,token:str,instance_id:str,editor_enabled:bool=True):
        if len(token)<20: raise RuntimeError("CSV_REPORTING_AUTH_TOKEN must contain at least 20 characters")
        self.root=data_root; self.root.mkdir(parents=True,exist_ok=True); self.token=token; self.instance_id=parse_uuid(instance_id); self.editor_enabled=editor_enabled
        self.store=ConfigStore(self.root/"config.json"); self.csv=CsvData(self.root/"report.csv"); self.frontend=files("csv_reporting").joinpath("frontend"); self.csrf={}
    def __call__(self,e,start):
        method=e.get("REQUEST_METHOD","GET"); path=e.get("PATH_INFO","/")
        try:
            if path in {"/","/index","/map","/raw","/edit"}: return self.asset(start,"index.html","text/html; charset=utf-8")
            if path in {"/app.js","/style.css"}: return self.asset(start,path[1:],"text/javascript" if path.endswith("js") else "text/css")
            if path=="/health": return self.reply(start,200,{"status":"ok","app":"csv-reporting","instance_id":str(self.instance_id),"version":"1.0.0"})
            if path.startswith("/management/v1"): return self.management(e,start,path,method)
            if not self.authorized(e): return self.reply(start,401,{"error":"unauthorized"})
            if method not in {"GET","HEAD"} and e.get("HTTP_X_CSRF_TOKEN") not in self.csrf: return self.reply(start,403,{"error":"csrf_failed"})
            if path=="/api/session":
                t=secrets.token_urlsafe(32); self.csrf[t]=True; return self.reply(start,200,{"csrf_token":t,"editor_enabled":self.editor_enabled})
            if path=="/api/config" and method=="GET": return self.reply(start,200,self.store.load())
            if path=="/api/config" and method=="PUT":
                value=self.body(e); saved,changed=self.store.save(value,value.get("revision")); return self.reply(start,200,{"config":saved,"changed":changed})
            if path=="/api/import" and method=="POST":
                cfg,stats=migrate_import(self.body(e)); current=self.store.load(); cfg["revision"]=current["revision"]; saved,_=self.store.save(cfg,current["revision"]); return self.reply(start,200,{"config":saved,"statistics":stats})
            if path=="/api/export": return self.reply(start,200,self.store.load())
            if path=="/api/report":
                q={k:v[0] for k,v in parse_qs(e.get("QUERY_STRING","")).items()}; return self.reply(start,200,render(self.csv.load(),self.store.load(),q))
            return self.reply(start,404,{"error":"not_found"})
        except (FtpkitError,ValueError,TypeError,json.JSONDecodeError) as x:
            d=x.detail.to_dict() if isinstance(x,FtpkitError) else {"message":str(x)}; return self.reply(start,400,{"error":d})
    def management(self,e,start,path,method):
        if not self.authorized(e): return self.reply(start,401,{"error":"unauthorized"})
        base={"app_id":"csv-reporting","instance_id":str(self.instance_id),"version":"1.0.0","protocol_version":1,"capabilities":["status","start","stop","restart","health","logs","backup","restore","upgrade","configure","remove","import","export"]}
        if path.endswith("/health"): return self.reply(start,200,{**base,"status":"ok"})
        if path.endswith("/status"): return self.reply(start,200,{**base,"editor_enabled":self.editor_enabled,"configured":(self.root/"config.json").exists(),"csv_available":(self.root/"report.csv").exists()})
        if path.endswith("/schema"): return self.reply(start,200,{"backup_paths":["data/config.json","data/report.csv"],"persistent_directories":["data"],"config_format":"ftpkit.csv-reporting.config","config_version":1})
        if path.endswith("/validate-restore") and method=="POST":
            body=self.body(e); return self.reply(start,200,{"valid":body.get("app_id")=="csv-reporting" and body.get("instance_id")==str(self.instance_id)})
        return self.reply(start,404,{"error":"not_found"})
    def authorized(self,e):
        h=e.get("HTTP_AUTHORIZATION",""); return h.startswith("Bearer ") and hmac.compare_digest(h[7:],self.token)
    def body(self,e):
        n=int(e.get("CONTENT_LENGTH") or 0)
        if n>2*1024*1024: raise ValueError("body too large")
        v=json.loads(e["wsgi.input"].read(n));
        if not isinstance(v,dict): raise ValueError("JSON object required")
        return v
    def asset(self,start,name,typ):
        b=self.frontend.joinpath(name).read_bytes(); start("200 OK",[("Content-Type",typ),("Content-Length",str(len(b))),("Content-Security-Policy","default-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'")]); return [b]
    def reply(self,start,code,value):
        b=json.dumps(value,ensure_ascii=False,separators=(",",":")).encode(); start(f"{code} { {200:'OK',400:'Bad Request',401:'Unauthorized',403:'Forbidden',404:'Not Found'}.get(code,'OK')}",[("Content-Type","application/json"),("Content-Length",str(len(b))),("Cache-Control","no-store")]); return [b]
