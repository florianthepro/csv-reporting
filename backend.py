#!/usr/bin/env python3
import argparse,csv,fnmatch,io,json,os,subprocess,sys,urllib.parse,urllib.request
from datetime import datetime,timezone
from pathlib import Path
def pip(x): subprocess.run([sys.executable,'-m','pip','install',x],check=True)
if len(sys.argv)>1 and sys.argv[1]=='install':
 p=argparse.ArgumentParser();p.add_argument('command');p.add_argument('--django-version',default='5.2.*');p.add_argument('--gunicorn-version',default='23.*');a=p.parse_args();pip(f'Django=={a.django_version}');pip(f'gunicorn=={a.gunicorn_version}');pip('psycopg[binary]>=3.2,<4');raise SystemExit
from django.conf import settings
STATE=Path(os.getenv('STATE_DIRECTORY','/var/lib/csv-reporting/state'));CONFIG=STATE/'csv-reporting.json';CACHE=STATE/'latest.csv';URL=os.getenv('CSV_REMOTE_URL','');EDIT=os.getenv('CSV_EDITOR_ENABLED','false')=='true';LIMIT=int(os.getenv('CSV_MAX_DOWNLOAD_BYTES','10485760'))
settings.configure(DEBUG=False,SECRET_KEY=os.environ['DJANGO_SECRET_KEY'],ALLOWED_HOSTS=[x for x in os.getenv('DJANGO_ALLOWED_HOSTS','localhost').split(',') if x],ROOT_URLCONF=__name__,INSTALLED_APPS=['django.contrib.contenttypes'],MIDDLEWARE=['django.middleware.security.SecurityMiddleware','django.middleware.common.CommonMiddleware','django.middleware.csrf.CsrfViewMiddleware','django.middleware.clickjacking.XFrameOptionsMiddleware'],DATABASES={'default':{'ENGINE':'django.db.backends.postgresql','NAME':os.environ['DATABASE_NAME'],'USER':os.environ['DATABASE_USER'],'PASSWORD':os.environ['DATABASE_PASSWORD'],'HOST':'127.0.0.1','PORT':'5432'}},CSRF_COOKIE_SECURE=os.getenv('DJANGO_HTTPS')=='true',SECURE_PROXY_SSL_HEADER=('HTTP_X_FORWARDED_PROTO','https'),USE_TZ=True)
import django;django.setup()
from django.core.management import call_command
from django.core.wsgi import get_wsgi_application
from django.http import JsonResponse,HttpResponse
from django.urls import path
from django.views.decorators.csrf import ensure_csrf_cookie
from django.views.decorators.http import require_GET,require_http_methods
DEFAULT={'format':'csv-reporting','version':2,'title':'CSV Reporting','heading':'CSV Reporting System','show_columns':[],'column_renames':{},'rules':[],'enable_links':False,'column_links':{},'download_filters':{}}
def delimiter(t):
 f=t.splitlines()[0] if t.splitlines() else t;c={',':f.count(','),';':f.count(';'),'\t':f.count('\t')};d=max(c,key=c.get);return d if c[d] else ','
def parse(t):
 t=t.lstrip('\ufeff');d=delimiter(t);r=[x for x in csv.reader(io.StringIO(t),delimiter=d) if x and any(v.strip() for v in x)]
 if not r or len(r[0])<2:raise ValueError('CSV benötigt mindestens zwei Spalten.')
 if any(len(x)!=len(r[0]) for x in r[:10]):raise ValueError('CSV-Spaltenanzahl ist inkonsistent.')
 h=[x.strip() for x in r[0]];return h,[dict(zip(h,x)) for x in r[1:]],d
def fetch():
 if urllib.parse.urlparse(URL).scheme not in ('http','https'):raise ValueError('CSV-URL ungültig.')
 errors=[]
 for method,data in [('POST',urllib.parse.urlencode({'downloadBtn':'1'}).encode()),('GET',None)]:
  try:
   q=urllib.request.Request(URL,data=data,method=method,headers={'Accept':'text/csv,text/plain,*/*;q=.1','User-Agent':'CSV-Reporting/3'})
   with urllib.request.urlopen(q,timeout=60) as z:raw=z.read(LIMIT+1);enc=z.headers.get_content_charset() or 'utf-8'
   if len(raw)>LIMIT:raise ValueError('CSV zu groß.')
   t=raw.decode(enc);parse(t);return t
  except Exception as e:errors.append(f'{method}: {e}')
 raise ValueError('Remote-Abruf fehlgeschlagen. '+' | '.join(errors))
def normalize(v):
 if isinstance(v,dict) and isinstance(v.get('config'),dict):v=v['config']
 if not isinstance(v,dict):raise ValueError('JSON muss ein Objekt sein.')
 aliases={'csvreporting_title':'title','csvreporting_heading':'heading','csvreporting_dwlfilters':'download_filters'}
 v=dict(v)
 for old,new in aliases.items():
  if old in v and new not in v:v[new]=v[old]
 out=DEFAULT|v
 for k in ('show_columns','rules'):
  if not isinstance(out.get(k),list):raise ValueError(f'{k} muss eine Liste sein.')
 for k in ('column_renames','column_links','download_filters'):
  if isinstance(out.get(k),list) and not out[k]:out[k]={}
  if not isinstance(out.get(k),dict):raise ValueError(f'{k} muss ein Objekt oder eine leere Liste sein.')
 out['enable_links']=bool(out.get('enable_links'));out['title']=str(out.get('title') or DEFAULT['title']);out['heading']=str(out.get('heading') or DEFAULT['heading']);out['format']='csv-reporting';out['version']=2
 for k in aliases:out.pop(k,None)
 out['download_filters']={str(k):[str(x) for x in (vals if isinstance(vals,list) else [vals])] for k,vals in out['download_filters'].items()}
 return out
def config():
 try:return normalize(json.loads(CONFIG.read_text()))
 except Exception:return DEFAULT.copy()
def save(v):
 v=normalize(v);STATE.mkdir(parents=True,exist_ok=True);p=CONFIG.with_suffix('.tmp');p.write_text(json.dumps(v,ensure_ascii=False,indent=2));p.replace(CONFIG);return v
def minutes(v):
 try:d=datetime.strptime(v.strip(),'%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc);return (d-datetime.now(timezone.utc)).total_seconds()/60
 except:return None
def pattern(v,p):
 m=minutes(v);p=str(p).strip()
 if m is not None:
  for op in ('>=','<=','>','<'):
   if p.startswith(op):
    try:n=float(p[len(op):]);return {'>=':m>=n,'<=':m<=n,'>':m>n,'<':m<n}[op]
    except:return False
  if '..' in p:
   try:a,b=map(float,p.split('..',1));return a<=m<=b
   except:return False
 if p.lower() in ('n/a','na'):return not v.strip() or v.strip().lower() in ('n/a','na')
 return fnmatch.fnmatch(v.lower(),p.lower())
def matched(row,r):
 c=r.get('conditions',[]);return bool(c) and all((not pattern(str(row.get(x.get('column',''),'')),x.get('pattern',''))) if x.get('negate') else pattern(str(row.get(x.get('column',''),'')),x.get('pattern','')) for x in c)
def dataset(force=False):
 STATE.mkdir(parents=True,exist_ok=True)
 if force or not CACHE.exists():CACHE.write_text(fetch())
 h,r,d=parse(CACHE.read_text());f=config().get('download_filters',{})
 r=[x for x in r if not any(str(x.get(k,'')).strip().lower() in {str(y).strip().lower() for y in vals} for k,vals in f.items())];return h,r,d
@require_GET
def health(r):return JsonResponse({'status':'ok'})
@require_GET
def data(r):
 try:
  h,rows,d=dataset(r.GET.get('refresh')=='1');c=config();visible=[x for x in (c.get('show_columns') or h) if x in h];mode=r.GET.get('view','index');out=[]
  for row in rows:
   rules=[x for x in c.get('rules',[]) if matched(row,x)]
   if mode in ('index','map') and c.get('rules') and not rules:continue
   out.append({'values':{x:row.get(x,'') for x in visible},'rules':rules})
  return JsonResponse({'all_header':h,'header':visible,'rows':out,'delimiter':d,'view':mode})
 except Exception as e:return JsonResponse({'error':str(e)},status=502)
@ensure_csrf_cookie
@require_http_methods(['GET','PUT'])
def cfg(r):
 if r.method=='GET':return JsonResponse({'config':config(),'editable':EDIT})
 if not EDIT:return JsonResponse({'error':'Editor deaktiviert.'},status=403)
 try:return JsonResponse({'saved':True,'config':save(json.loads(r.body))})
 except Exception as e:return JsonResponse({'error':str(e)},status=400)
@require_GET
def raw(r):
 try:
  h,rows,d=dataset(r.GET.get('refresh')=='1');o=io.StringIO();w=csv.DictWriter(o,fieldnames=h,delimiter=d);w.writeheader();w.writerows(rows);return HttpResponse(o.getvalue(),content_type='text/csv')
 except Exception as e:return JsonResponse({'error':str(e)},status=502)
urlpatterns=[path('api/health',health),path('api/data',data),path('api/config',cfg),path('api/raw',raw)]
application=get_wsgi_application()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('command',choices=['check','migrate','refresh']);a=p.parse_args();call_command(a.command,'--deploy') if a.command=='check' else call_command('migrate',interactive=False) if a.command=='migrate' else (dataset(True),print('CSV aktualisiert.'))
