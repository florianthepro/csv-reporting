import argparse,os
from pathlib import Path
from wsgiref.simple_server import make_server
from .app import Application

def main():
 p=argparse.ArgumentParser(); s=p.add_subparsers(dest="cmd",required=True); serve=s.add_parser("serve"); serve.add_argument("--host",default="127.0.0.1"); serve.add_argument("--port",type=int,default=8080); a=p.parse_args()
 app=Application(Path(os.getenv("CSV_REPORTING_DATA_ROOT","/sites/csv-reporting/data")),os.getenv("CSV_REPORTING_AUTH_TOKEN",""),os.getenv("FTPKIT_INSTANCE_ID",""),os.getenv("CSV_REPORTING_EDITOR_ENABLED","true").lower() not in {"0","false","no"})
 with make_server(a.host,a.port,app) as server: print(f"CSV Reporting: http://{a.host}:{a.port}"); server.serve_forever()
if __name__=="__main__": main()
