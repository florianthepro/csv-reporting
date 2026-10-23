import io,json,tempfile,unittest
from pathlib import Path
from uuid import uuid4
from ftpkit import ValidationError
from csv_reporting.app import Application
from csv_reporting.config import ConfigStore,default_config
from csv_reporting.data import CsvData,render
from csv_reporting.migration import migrate_import

TOKEN="synthetic-development-token-value"
class CsvTests(unittest.TestCase):
 def test_csv_rules_filters(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d)/"report.csv"; p.write_text("Name,State\nAlpha,open\nBeta,closed\n")
   cfg=default_config(); cfg["rules"]=[{"id":"r","active":True,"description":"open","conditions":[{"column":"State","operator":"=","value":"open"}],"stash_conditions":[],"style":{"fontWeight":"bold"}}]
   out=render(CsvData(p).load(),cfg,{"Name":"Alpha"}); self.assertEqual(len(out["rows"]),1); self.assertEqual(out["rows"][0]["style"]["fontWeight"],"bold")
 def test_autosave_compare_revision(self):
  with tempfile.TemporaryDirectory() as d:
   s=ConfigStore(Path(d)/"config.json"); c=s.load(); c["title"]="Changed"; saved,changed=s.save(c,0); self.assertTrue(changed); self.assertEqual(saved["revision"],1)
   saved,changed=s.save(saved,1); self.assertFalse(changed)
   with self.assertRaises(ValidationError): s.save(saved,0)
 def test_legacy_disabled_rule(self):
  cfg,stats=migrate_import({"title":"CSV Reporting","rules":[{"description":"!State &equal; open","conditions":[]}],"column_renames":[]})
  self.assertFalse(cfg["rules"][0]["active"]); self.assertEqual(cfg["rules"][0]["stash_conditions"][0]["operator"],"="); self.assertEqual(stats["disabled_rules"],1)
 def test_wrapped_alias_and_empty_arrays(self):
  cfg,stats=migrate_import({"config":{"columns":["A"],"columnNames":{"A":"Column A"},"rules":{},"downloadFilters":{}}})
  self.assertEqual(cfg["show_columns"],["A"]); self.assertEqual(cfg["download_filters"],[]); self.assertEqual(stats["stored_rules"],0)
 def test_invalid_rule_reported(self):
  with self.assertRaises(ValidationError): migrate_import({"rules":[{"description":"broken","conditions":[]}]})
class ApiTests(unittest.TestCase):
 def setUp(self):
  self.t=tempfile.TemporaryDirectory(); self.uuid=str(uuid4()); self.app=Application(Path(self.t.name),TOKEN,self.uuid); Path(self.t.name,"report.csv").write_text("A,B\n1,2\n")
 def tearDown(self): self.t.cleanup()
 def call(self,m,p,b=None,csrf="",auth=True):
  raw=b"" if b is None else json.dumps(b).encode(); e={"REQUEST_METHOD":m,"PATH_INFO":p,"QUERY_STRING":"","wsgi.input":io.BytesIO(raw),"CONTENT_LENGTH":str(len(raw)),"HTTP_AUTHORIZATION":f"Bearer {TOKEN}" if auth else "","HTTP_X_CSRF_TOKEN":csrf}; c={}
  def start(s,h): c["s"]=s
  response=json.loads(b"".join(self.app(e,start)))
  return int(c["s"].split()[0]),response
 def test_health_auth_csrf_import(self):
  self.assertEqual(self.call("GET","/health",auth=False)[0],200); self.assertEqual(self.call("GET","/api/config",auth=False)[0],401)
  _,session=self.call("GET","/api/session"); csrf=session["csrf_token"]; self.assertEqual(self.call("PUT","/api/config",default_config())[0],403)
  code,result=self.call("POST","/api/import",{"config":{"rules":[]}},csrf); self.assertEqual(code,200); self.assertEqual(result["statistics"]["invalid_rules"],0)
 def test_management_identity(self):
  code,v=self.call("GET","/management/v1/status"); self.assertEqual(code,200); self.assertEqual(v["instance_id"],self.uuid)
if __name__=="__main__": unittest.main()
