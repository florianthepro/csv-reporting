from __future__ import annotations

import re
from typing import Any

from ftpkit import ValidationError
from .config import FORMAT, VERSION, default_config, validate_config

ALIASES={"columns":"show_columns","columnNames":"column_renames","rename_columns":"column_renames","links":"column_links","downloadFilters":"download_filters","link_enabled":"enable_links"}


def decode_legacy(text:str)->str:
    return str(text).replace("&amp;","&").replace("&equal;","=").replace("&ne;","≠").replace("&space;"," ").replace("\\$","$")


def reconstruct(description:str)->list[dict[str,str]]:
    text=decode_legacy(description).strip()
    text=re.sub(r"^!+","",text).strip()
    found=[]
    pattern=re.compile(r"([^;&|]+?)\s*(≠|!=|=|contains|not contains)\s*([^;&|]+)")
    for column,operator,value in pattern.findall(text):
        found.append({"column":column.strip(),"operator":"!=" if operator=="≠" else operator,"value":value.strip()})
    return found


def migrate_import(payload:Any)->tuple[dict[str,Any],dict[str,Any]]:
    if not isinstance(payload,dict): raise ValidationError("import must be a JSON object")
    source=payload.get("config",payload)
    if not isinstance(source,dict): raise ValidationError("config must be an object")
    source=dict(source)
    for old,new in ALIASES.items():
        if old in source and new not in source: source[new]=source.pop(old)
    source.setdefault("format",FORMAT); source.setdefault("version",VERSION)
    for key in ("show_columns","rules","download_filters"):
        if source.get(key)=={}: source[key]=[]
    for key in ("column_renames","column_links"):
        if source.get(key)==[]: source[key]={}
    rules=[]; active=disabled=invalid=0; errors=[]
    for i,raw in enumerate(source.get("rules",[])):
        try:
            if isinstance(raw,str): raw={"description":raw,"conditions":[]}
            if not isinstance(raw,dict): raise ValueError("rule is not an object")
            description=decode_legacy(raw.get("description",raw.get("name","")))
            is_disabled=bool(raw.get("disabled",False) or raw.get("active") is False or description.lstrip().startswith("!"))
            conditions=raw.get("conditions",raw.get("condition",[]))
            if isinstance(conditions,dict): conditions=[conditions]
            conditions=[{"column":decode_legacy(c.get("column",c.get("field",""))),"operator":decode_legacy(c.get("operator",c.get("op","="))),"value":decode_legacy(c.get("value",""))} for c in conditions]
            stash=[]
            if is_disabled and not conditions: stash=reconstruct(description)
            if not is_disabled and not conditions: raise ValueError("active rule has no conditions")
            if is_disabled: disabled+=1
            else: active+=1
            rules.append({"id":str(raw.get("id",f"rule-{i+1}")),"active":not is_disabled,"description":description.lstrip("! "),"conditions":[] if is_disabled else conditions,"stash_conditions":stash if is_disabled else [],"style":dict(raw.get("style",{}))})
        except Exception as exc:
            invalid+=1; errors.append({"index":i,"message":str(exc)})
    if errors: raise ValidationError("invalid rules in import",context={"errors":errors,"active":active,"disabled":disabled,"invalid":invalid})
    source["rules"]=rules
    config=default_config(); config.update(source); config=validate_config(config)
    return config,{"active_rules":active,"disabled_rules":disabled,"invalid_rules":invalid,"stored_rules":len(rules)}
