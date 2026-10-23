from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from ftpkit import FileLock, ValidationError, atomic_write_json, read_json

FORMAT = "ftpkit.csv-reporting.config"
VERSION = 1


def default_config() -> dict[str, Any]:
    return {"format": FORMAT, "version": VERSION, "title": "CSV Reporting", "heading": "CSV Reporting", "show_columns": [], "column_renames": {},
            "rules": [], "enable_links": False, "column_links": {}, "download_filters": [], "filters": {}, "revision": 0}


def digest_config(value: dict[str, Any]) -> str:
    clean = {k: v for k, v in value.items() if k != "revision"}
    return hashlib.sha256(json.dumps(clean, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def validate_config(value: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(value, dict): raise ValidationError("configuration must be an object")
    result = default_config()
    result.update(value)
    if result["format"] != FORMAT or int(result["version"]) != VERSION: raise ValidationError("unsupported configuration format")
    for key in ("title", "heading"):
        if not isinstance(result[key], str) or len(result[key]) > 200: raise ValidationError(f"invalid {key}", field=key)
    if not isinstance(result["show_columns"], list) or not all(isinstance(x, str) for x in result["show_columns"]): raise ValidationError("show_columns must be a string list")
    for key in ("column_renames", "column_links", "filters"):
        if not isinstance(result[key], dict): raise ValidationError(f"{key} must be an object", field=key)
    if not isinstance(result["download_filters"], list): raise ValidationError("download_filters must be a list")
    if not isinstance(result["enable_links"], bool): raise ValidationError("enable_links must be boolean")
    if not isinstance(result["rules"], list): raise ValidationError("rules must be a list")
    normalized=[]
    for i,rule in enumerate(result["rules"]):
        if not isinstance(rule,dict): raise ValidationError("rule must be an object",field=f"rules.{i}")
        conditions=rule.get("conditions",[]); stash=rule.get("stash_conditions",[])
        if not isinstance(conditions,list) or not isinstance(stash,list): raise ValidationError("rule conditions must be lists",field=f"rules.{i}")
        active=bool(rule.get("active",True))
        if active and not conditions: raise ValidationError("active rule requires conditions",field=f"rules.{i}")
        for c in conditions+stash:
            if not isinstance(c,dict) or not all(k in c for k in ("column","operator","value")): raise ValidationError("invalid rule condition",field=f"rules.{i}")
        normalized.append({"id":str(rule.get("id",f"rule-{i+1}")),"active":active,"description":str(rule.get("description","")),"conditions":conditions,"stash_conditions":stash,"style":dict(rule.get("style",{}))})
    result["rules"]=normalized; result["revision"]=int(result.get("revision",0))
    return result


@dataclass
class ConfigStore:
    path: Path
    lock: FileLock = field(init=False)
    def __post_init__(self): self.lock=FileLock(self.path.with_suffix(".lock"))
    def load(self):
        with self.lock:
            if not self.path.exists():
                value=default_config(); atomic_write_json(self.path,value,mode=0o600); return value
            return validate_config(read_json(self.path))
    def save(self,value:dict[str,Any],expected_revision:int|None=None):
        with self.lock:
            current=default_config() if not self.path.exists() else validate_config(read_json(self.path))
            if expected_revision is not None and current["revision"]!=expected_revision: raise ValidationError("configuration revision conflict")
            normalized=validate_config(value)
            if digest_config(normalized)==digest_config(current): return current,False
            normalized["revision"]=current["revision"]+1
            atomic_write_json(self.path,normalized,mode=0o600,backup=self.path.exists()); return normalized,True
