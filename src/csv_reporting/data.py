from __future__ import annotations
import csv, json
from pathlib import Path
from typing import Any
from ftpkit import ValidationError

class CsvData:
    def __init__(self,path:Path): self.path=path; self._key=None; self._rows=[]; self._columns=[]
    def load(self):
        if not self.path.exists(): return {"columns":[],"rows":[]}
        stat=self.path.stat(); key=(stat.st_mtime_ns,stat.st_size)
        if key!=self._key:
            with self.path.open("r",encoding="utf-8-sig",newline="") as f:
                sample=f.read(4096); f.seek(0)
                try: dialect=csv.Sniffer().sniff(sample,delimiters=",;\t|")
                except csv.Error: dialect=csv.excel
                reader=csv.DictReader(f,dialect=dialect)
                if not reader.fieldnames: raise ValidationError("CSV has no header")
                self._columns=[str(x) for x in reader.fieldnames]
                self._rows=[{k:(v or "") for k,v in row.items() if k is not None} for row in reader]
            self._key=key
        return {"columns":self._columns,"rows":self._rows}

def matches(row:dict[str,str],condition:dict[str,str])->bool:
    value=str(row.get(condition["column"],"")); target=str(condition["value"]); op=condition["operator"]
    return {"=":value==target,"!=":value!=target,"contains":target.lower() in value.lower(),"not contains":target.lower() not in value.lower(),"starts":value.startswith(target),"ends":value.endswith(target)}.get(op,False)

def render(data:dict[str,Any],config:dict[str,Any],query:dict[str,str]):
    columns=config["show_columns"] or data["columns"]
    rows=[]
    for row in data["rows"]:
        if any(q.lower() not in str(row.get(c,"")).lower() for c,q in query.items() if c in data["columns"] and q): continue
        styles={}
        for rule in config["rules"]:
            if rule["active"] and all(matches(row,c) for c in rule["conditions"]): styles.update(rule["style"])
        rows.append({"values":{c:row.get(c,"") for c in columns},"style":styles})
    return {"columns":columns,"labels":{c:config["column_renames"].get(c,c) for c in columns},"rows":rows,"links":config["column_links"] if config["enable_links"] else {}}
