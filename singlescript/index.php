<?php //read and write
declare(strict_types=1);
///////////////////////
$csvreporting_dwlextpage = 'https://hey.com/csv.csv'; #http
$csvreporting_dwlfilters=['row'=>['line'],];
$csvreporting_title='Csv Reporting';
$csvreporting_heading='Csv Reporting System';
$csvreporting_jsonfile='csv-reporting.json';
$csvreporting_jsondir=__DIR__.DIRECTORY_SEPARATOR.$csvreporting_jsonfile;
$csvreporting_editortitle='CSV Rules Editor';
$csvreporting_editorheading=$csvreporting_editortitle;
$csvreporting_icon='https://raw.githubusercontent.com/florianthepro/csv-reporting/main/index.svg';
$csvreporting_editoricon='https://raw.githubusercontent.com/florianthepro/csv-reporting/main/edit.svg';
///////////////////////
$csvreporting_theme='auto'; #'light' (fix hell) | 'dark' (fix dunkel) | 'auto' (dynamisch, folgt System)

function csvreporting_detect_delimiter(string $csv):string{
$firstLine=strtok($csv,"\r\n");
if($firstLine===false)$firstLine=$csv;
$counts=[","=>substr_count($firstLine,","),";"=>substr_count($firstLine,";"),"\t"=>substr_count($firstLine,"\t")];
arsort($counts);
$delimiter=(string)array_key_first($counts);
return ((int)reset($counts)>0)?$delimiter:',';
}
function csvreporting_parse_csv(string $csv):array{
$csv=preg_replace('/^\xEF\xBB\xBF/','',$csv)??$csv;
$delimiter=csvreporting_detect_delimiter($csv);
$handle=fopen('php://memory','r+');
if($handle===false)return [];
fwrite($handle,$csv);
rewind($handle);
$rows=[];
while(($row=fgetcsv($handle,0,$delimiter))!==false){
if($row===[null]||$row===[])continue;
$rows[]=$row;
}
fclose($handle);
return $rows;
}
function csvreporting_is_csv_response(string $body,string $contentType):bool{
$sample=ltrim(substr($body,0,4096));
if($sample==='')return false;
if(preg_match('/^<!doctype\s+html|^<html\b|^<\?xml/i',$sample))return false;
$rows=csvreporting_parse_csv($body);
if(count($rows)<1)return false;
$columns=count($rows[0]);
if($columns<2)return stripos($contentType,'csv')!==false;
$checked=0;
foreach(array_slice($rows,0,10) as $row){
if(count($row)!==$columns)return false;
$checked++;
}
return $checked>0;
}
function csvreporting_rows_to_csv(array $rows,string $delimiter):string{
$handle=fopen('php://memory','r+');
if($handle===false)return '';
foreach($rows as $row)fputcsv($handle,is_array($row)?$row:[],$delimiter,'"','\\');
rewind($handle);
$out=stream_get_contents($handle);
fclose($handle);
return is_string($out)?$out:'';
}
function csvreporting_remote_fetch(string $url,array $filters):array{
if(trim($url)==='')return [false,'Remote nicht erreichbar: keine Remote-URL konfiguriert.',null];
if(!function_exists('curl_init'))return [false,'Remote nicht erreichbar: cURL ist nicht verfügbar.',null];
$attempts=[
['method'=>'POST','fields'=>['downloadBtn'=>'1']],
['method'=>'GET','fields'=>null]
];
$errors=[];
foreach($attempts as $attempt){
$ch=curl_init();
if($ch===false){$errors[]=$attempt['method'].': cURL-Initialisierung fehlgeschlagen';continue;}
curl_setopt($ch,CURLOPT_URL,$url);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true);
curl_setopt($ch,CURLOPT_MAXREDIRS,5);
curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,20);
curl_setopt($ch,CURLOPT_TIMEOUT,60);
curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (CSV-Downloader/2.4)');
curl_setopt($ch,CURLOPT_HTTPHEADER,['Accept: text/csv, application/csv, application/octet-stream, text/plain;q=0.9, */*;q=0.1']);
if($attempt['method']==='POST'){
curl_setopt($ch,CURLOPT_POST,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,$attempt['fields']);
}else{
curl_setopt($ch,CURLOPT_HTTPGET,true);
}
$body=curl_exec($ch);
$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
$error=curl_error($ch);
curl_close($ch);
if(!is_string($body)||$body===''){$errors[]=$attempt['method'].': '.($error!==''?$error:'leere Antwort');continue;}
if($code<200||$code>=300){$errors[]=$attempt['method'].': HTTP '.$code;continue;}
if(!csvreporting_is_csv_response($body,$contentType)){$errors[]=$attempt['method'].': Antwort ist keine CSV';continue;}
$delimiter=csvreporting_detect_delimiter($body);
$rows=csvreporting_parse_csv($body);
$header=$rows[0];
$index=[];
foreach($header as $i=>$name)$index[strtolower(trim((string)$name))]=(int)$i;
$filterRules=[];
foreach($filters as $column=>$values){
$key=strtolower(trim((string)$column));
if(!array_key_exists($key,$index))continue;
$blocked=[];
foreach((array)$values as $value)$blocked[strtolower(trim((string)$value))]=true;
$filterRules[]=[$index[$key],$blocked];
}
$filtered=[$header];
foreach(array_slice($rows,1) as $row){
$drop=false;
foreach($filterRules as [$columnIndex,$blocked]){
$value=strtolower(trim((string)($row[$columnIndex]??'')));
if($value!==''&&isset($blocked[$value])){$drop=true;break;}
}
if(!$drop)$filtered[]=$row;
}
$csv=csvreporting_rows_to_csv($filtered,$delimiter);
if($csv===''){$errors[]=$attempt['method'].': CSV-Verarbeitung fehlgeschlagen';continue;}
return [true,'',$csv];
}
return [false,'Remote nicht erreichbar: PHP/SQL-CSV-Abruf per POST und direkter Abruf per GET fehlgeschlagen. '.implode(' | ',$errors),null];
}

$csvreporting_page=$_GET['_page']??'index';
if(!is_string($csvreporting_page)||!in_array($csvreporting_page,['index','edit','map','raw'],true))$csvreporting_page='index';
unset($_GET['_page'],$_REQUEST['_page']);
if(in_array($csvreporting_page,['index','edit','map','raw'],true)){[$csvreporting_live_ok,$csvreporting_live_message,$csvreporting_csv_memory]=csvreporting_remote_fetch($csvreporting_dwlextpage,$csvreporting_dwlfilters);}
if($csvreporting_page==='index'){
$csvreporting_route=<<<'CSVREPORTING_INDEX_ROUTE'
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function load_rules($path){
if(!is_readable($path))return['show_columns'=>[],'rules'=>[],'enable_links'=>false,'column_links'=>[]];
$json=@file_get_contents($path);
$data=@json_decode($json,true);
if(!is_array($data))$data=[];
if(!isset($data['show_columns'])||!is_array($data['show_columns']))$data['show_columns']=[];
if(!isset($data['rules'])||!is_array($data['rules']))$data['rules']=[];
if(!isset($data['enable_links']))$data['enable_links']=false;
if(!isset($data['column_links'])||!is_array($data['column_links']))$data['column_links']=[];
return $data;}
function parse_csv_string($csvContent){return csvreporting_parse_csv((string)$csvContent);}
function load_csv($path){global $csvreporting_csv_memory;if(!is_string($csvreporting_csv_memory??null))return null;return parse_csv_string($csvreporting_csv_memory);}
function col_index($header,$colName){
foreach($header as $i=>$h){
if(strcasecmp(trim($h),trim($colName))===0)return $i;}
return null;}
function timestamp_to_minutes($timestamp){
$pattern='/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/';
if(!preg_match($pattern,trim($timestamp)))return null;
$ts=strtotime(trim($timestamp));
if($ts===false)return null;
$now=time();
$diff=($ts-$now)/60;
return round($diff,2);}
function is_timestamp_column($header,$dataRows,$colName){
$idx=col_index($header,$colName);
if($idx===null)return false;
$sampleCount=0;
foreach($dataRows as $row){
if(isset($row[$idx])&&$row[$idx]!==''){
if(timestamp_to_minutes($row[$idx])!==null){
$sampleCount++;
if($sampleCount>=3)return true;
}
}
}
return false;
}
function get_cell_value_for_filter($cellValue,$isTimestamp){
if($isTimestamp){
$minutes=timestamp_to_minutes($cellValue);
if($minutes!==null)return intval($minutes);
return null;
}
$v=trim((string)$cellValue);
if($v==='')return 'n/a';
if(strcasecmp($v,'n/a')===0||strcasecmp($v,'na')===0)return 'n/a';
return $v;
}
function get_all_filter_values($header,$dataRows,$colName,$isTimestamp){
  $idx=col_index($header,$colName);
  if($idx===null)return [];
  $vals=[];
  foreach($dataRows as $row){
    if(isset($row[$idx])){
      $val=get_cell_value_for_filter($row[$idx],$isTimestamp);
      if($val===null)continue;

      if($isTimestamp){
        $display=$val.' min';
      }else{
        $display=$val;
      }

      if(!in_array($display,$vals,true)){
        $vals[]=$display;
      }
    }
  }
  if($isTimestamp){
    usort($vals,function($a,$b){
      $aNum=intval(explode(' ',$a)[0]);
      $bNum=intval(explode(' ',$b)[0]);
      return $aNum<=>$bNum;
    });
  }else{
    sort($vals,SORT_NATURAL|SORT_FLAG_CASE);
  }
  return $vals;
}
function parse_minutes_pattern($pattern){
  $p=trim((string)$pattern);
  $pattern_clean=preg_replace('/\s+/','',strtolower($p));
  if(preg_match('/^>=(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'gte','value'=>floatval($m[1])];
  }
  if(preg_match('/^>(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'gt','value'=>floatval($m[1])];
  }
  if(preg_match('/^<=(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'lte','value'=>floatval($m[1])];
  }
  if(preg_match('/^<(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'lt','value'=>floatval($m[1])];
  }
  if(preg_match('/^(-?\d+(?:\.\d+)?)\.\.(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'range','min'=>floatval($m[1]),'max'=>floatval($m[2])];
  }
  if(preg_match('/^(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m)){
    return ['type'=>'exact','value'=>floatval($m[1])];
  }
  return ['type'=>'invalid'];
}
function check_minutes_condition($cellValue,$pattern){
  $minutes=timestamp_to_minutes($cellValue);
  if($minutes===null)return false;
  $parsed=parse_minutes_pattern($pattern);
  switch($parsed['type']){
    case 'gt':
      return $minutes > $parsed['value'];
    case 'gte':
      return $minutes >= $parsed['value'];
    case 'lt':
      return $minutes < $parsed['value'];
    case 'lte':
      return $minutes <= $parsed['value'];
    case 'range':
      return $minutes >= $parsed['min'] && $minutes <= $parsed['max'];
    case 'exact':
      return $minutes == $parsed['value'];
    default:
      return false;
  }
}
function eval_text_op($value,$op,$cmp){
  $value=(string)$value;
  $cmp=(string)$cmp;
  switch($op){
    case 'wildcard':
      $pattern='/^'.str_replace(['\*','\?'],['.*','.'],preg_quote($cmp,'/')).'$/i';
      return @preg_match($pattern,$value)===1;
    case 'contains':
      return stripos($value,$cmp)!==false;
    case 'equals':
      return strcmp($value,$cmp)===0;
    case 'starts_with':
      return stripos($value,$cmp)===0;
    case 'ends_with':
      $len=strlen($cmp);
      if($len===0)return true;
      return strcasecmp(substr($value,-$len),$cmp)===0;
    case 'regex':
      $ok=@preg_match($cmp,$value);
      return $ok===1;
    case 'empty':
      return trim($value)==='';
    case 'empty_or_na':
      $v=trim($value);
      return $v===''||strcasecmp($v,'N/A')===0;
    default:
      return false;
  }
}
function parse_condition_pattern($pattern){
  $p=trim((string)$pattern);
  if($p==='*')return['op'=>'wildcard','value'=>'*'];
  if(strcasecmp($p,'n/a')===0||strcasecmp($p,'na')===0)return['op'=>'empty_or_na','value'=>''];
  return['op'=>'wildcard','value'=>$p];
}

function eval_condition($cond,$header,$row,$timestampCols){
  $col=isset($cond['column'])?(string)$cond['column']:'';
  $pattern=isset($cond['pattern'])?(string)$cond['pattern']:'';
  if($col===''||$pattern==='')return true;

  $idx=col_index($header,$col);
  $val=($idx!==null&&isset($row[$idx]))?$row[$idx]:'';

  $isTimestampCol=isset($timestampCols[$col]);

  if($isTimestampCol){
    $base=check_minutes_condition($val,$pattern);
  }else{
    $parsed=parse_condition_pattern($pattern);
    $base=eval_text_op($val,$parsed['op'],$parsed['value']);
  }

  if(!empty($cond['negate']))$base=!$base;
  return $base;
}

function rule_matches($rule,$header,$row,$timestampCols){
  $conds=isset($rule['conditions'])&&is_array($rule['conditions'])?$rule['conditions']:[];
  if(count($conds)===0)return false;
  foreach($conds as $c){
    if(!eval_condition($c,$header,$row,$timestampCols))return false;
  }
  return true;
}

function get_matching_rules($header,$row,$rules,$timestampCols){
  $matched=[];
  foreach($rules as $idx=>$rule){
    if(rule_matches($rule,$header,$row,$timestampCols)){
      $matched[]=['idx'=>$idx,'desc'=>$rule['description']??'Rule '.($idx+1)];
    }
  }
  return $matched;
}

function format_cell_value($val){
$minutes=timestamp_to_minutes($val);
if($minutes!==null)return intval($minutes).' min';
$v=trim((string)$val);
if($v===''||strcasecmp($v,'n/a')===0||strcasecmp($v,'na')===0)$v='n/a';
return h($v);
}

function check_filter($colName,$filterValue,$cellValue,$isTimestamp){
  if($filterValue==='')return true;

  $converted=get_cell_value_for_filter($cellValue,$isTimestamp);
  if($converted===null)return false;

  if($isTimestamp){
    $filterMinutes=intval($filterValue);
    return $converted===$filterMinutes;
  }else{
    return $converted===$filterValue;
  }
}

function cell_by_colname($header,$row,$colName){
  $idx=col_index($header,$colName);
  return($idx!==null&&isset($row[$idx]))?$row[$idx]:'';
}

function cell_is_trigger($header,$row,$colName,$rules,$timestampCols){
  foreach($rules as $rule){
    if(!rule_matches($rule,$header,$row,$timestampCols))continue;
    $conds=isset($rule['conditions'])&&is_array($rule['conditions'])?$rule['conditions']:[];
    foreach($conds as $c){
      if(!isset($c['column']))continue;
      if(strcasecmp(trim($c['column']),trim($colName))!==0)continue;
      if(eval_condition($c,$header,$row,$timestampCols))return true;
    }
  }
  return false;
}

function build_cell_link($enableLinks,$columnLinks,$idColumn,$header,$row,$colName){
  if(!$enableLinks)return null;
  if(!is_array($columnLinks))return null;
  if(!array_key_exists($colName,$columnLinks))return null;
  $pattern=trim((string)$columnLinks[$colName]);
  if($pattern===''||$pattern==='*')return null;
  $idVal='';
  if($idColumn!==null)$idVal=cell_by_colname($header,$row,$idColumn);
  else $idVal=cell_by_colname($header,$row,$colName);
  $idVal=trim((string)$idVal);
  if($idVal==='')return null;
  $enc=rawurlencode($idVal);
  if(strpos($pattern,'*')!==false)$href=str_replace('*',$enc,$pattern);
  else $href=$pattern;
  return $href;
}

$rulesData=load_rules($csvreporting_jsondir);
$showColumns=$rulesData['show_columns']??[];
$rules=$rulesData['rules']??[];
$enableLinks=!empty($rulesData['enable_links']);
$columnLinks=is_array($rulesData['column_links']??null)?$rulesData['column_links']:[];
$idColumn=null;
foreach($columnLinks as $k=>$v){
  if(trim((string)$v)==='*'){ $idColumn=$k; break; }
}
$csvRows=load_csv(null);
$csvError=null;

if($csvRows===null)$csvError=$csvreporting_live_message?:'Remote nicht erreichbar: Abruf per GET und POST fehlgeschlagen.';

$header=[];
$dataRows=[];
if(is_array($csvRows)&&count($csvRows)>0){
  $header=array_map('trim',$csvRows[0]);
  $dataRows=array_slice($csvRows,1);
}

if(!is_array($showColumns)||count($showColumns)===0)$showColumns=$header;

$timestampCols=[];
foreach($showColumns as $col){
  if(is_timestamp_column($header,$dataRows,$col)){
    $timestampCols[$col]=true;
  }
}

$ruleBaseRows=[];
if(is_array($dataRows)&&count($dataRows)>0&&is_array($rules)&&count($rules)>0){
foreach($dataRows as $row){
foreach($rules as $r){
if(rule_matches($r,$header,$row,$timestampCols)){$ruleBaseRows[]=$row;break;}
}
}
}

$activeFilters=[];
foreach($showColumns as $col){
$activeFilters[$col]=$_GET['filter_'.urlencode($col)]??'';
}
$activeFilterPairs=[];
foreach($activeFilters as $c=>$v){if($v!=='')$activeFilterPairs[]=$c.': '.$v;}
$activeFilterText=implode(', ',$activeFilterPairs);
$filtered=[];
if(count($ruleBaseRows)>0){
foreach($ruleBaseRows as $row){
$pass=true;
foreach($showColumns as $col){
$val=cell_by_colname($header,$row,$col);
$isTS=isset($timestampCols[$col]);
if(!check_filter($col,$activeFilters[$col]??'',$val,$isTS)){$pass=false;break;}
}
if($pass)$filtered[]=$row;
}
}

?>
<!doctype html>
<?php $__th=(isset($csvreporting_theme)&&in_array($csvreporting_theme,['light','dark'],true))?$csvreporting_theme:'auto'; ?><html lang="de"<?= $__th==='light'?' data-theme="light"':($__th==='dark'?' data-theme="dark"':'') ?>>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="only light">
<link rel="icon" type="image/svg+xml" href="<?=$csvreporting_icon?>">
<title><?=$csvreporting_title?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
table{border-collapse:collapse;width:100%;background:var(--bg)}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left;background:var(--bg);color:var(--fg)}
th{background:var(--panelhead);color:var(--fg)}
.error{color:var(--danger);font-weight:bold;margin-bottom:8px}
.fk-menu-btn{position:fixed;top:10px;left:10px;z-index:999999;background:var(--ctrl-bg);color:var(--ctrl-fg);border:none;padding:10px 14px;font-size:20px;cursor:pointer;border-radius:4px}
.fk-menu-overlay{position:fixed;inset:0;display:none;z-index:999998}
.fk-menu-overlay.is-visible{display:block}
.fk-menu-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55)}
.fk-menu-panel{position:absolute;top:0;left:0;width:260px;height:100%;background:var(--panel);padding:20px;box-sizing:border-box;transform:translateX(-100%);transition:transform .25s ease-out}
.fk-menu-overlay.is-visible .fk-menu-panel{transform:translateX(0)}
.fk-menu-close{background:none;border:none;font-size:28px;cursor:pointer;margin-left:auto;display:block;color:var(--fg)}
.fk-menu-nav{margin-top:20px;display:flex;flex-direction:column;gap:12px}
.fk-menu-link{text-decoration:none;font-size:18px;color:var(--fg)}
.fk-menu-link:hover{color:var(--accent)}
.info-icon{cursor:pointer;font-weight:bold;color:var(--accent);margin-left:4px;font-size:14px}
.rules-overlay{position:fixed;display:none;background:var(--panel);border:1px solid var(--accent);border-radius:4px;padding:12px;max-width:400px;box-shadow:0 2px 10px var(--shadow);z-index:10000;max-height:300px;overflow-y:auto;color:var(--fg)}
.rules-overlay.visible{display:block}
.rules-overlay-title{font-weight:bold;margin-bottom:8px;color:var(--accent);border-bottom:1px solid var(--accent);padding-bottom:6px;font-size:12px}
.rules-overlay-item{padding:6px 0;border-bottom:1px solid var(--border);font-size:12px;line-height:1.3;word-break:break-word}
.rules-overlay-item:last-child{border-bottom:none}
.filter-toggle{background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:10px;margin-bottom:12px;cursor:pointer;user-select:none;display:flex;justify-content:space-between;align-items:center;color:var(--fg)}
.filter-toggle:hover{background:var(--panel2)}
.filter-toggle-icon{font-weight:bold;color:var(--accent)}
.filter-row{background:var(--panel);padding:12px;margin-bottom:12px;border-radius:3px;border:1px solid var(--border);display:none}
.filter-row.visible{display:block}
.filter-item{margin-bottom:10px}
.filter-item:last-child{margin-bottom:0}
.filter-row select{padding:4px;border:1px solid var(--border);border-radius:3px;font-size:13px;width:100%;box-sizing:border-box;background:var(--bg);color:var(--fg)}
.filter-label{font-size:12px;font-weight:bold;margin-bottom:4px;color:var(--muted)}
</style>
<style id="theme-tokens">
:root{
--bg:#ffffff;--fg:#141414;--muted:#5a5f66;--border:#d5d7dd;
--panel:#f5f6f8;--panel2:#e9ebef;--panelhead:#eef0f3;
--accent:#0a63ff;--accent-fg:#ffffff;--link:#0a58ca;
--danger:#b3261e;--danger-bg:#fde7e7;--danger-fg:#7f1512;
--success:#1a7f37;--success-bg:#e6f4ea;--warn:#8a6d00;--warn-bg:#fff4cf;
--ctrl-bg:#141414;--ctrl-fg:#ffffff;--shadow:rgba(0,0,0,.18);
}
:root[data-theme="dark"]{
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]){
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}}
</style>
<style>
a{color:var(--link)}
:root[data-theme="dark"] td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]) td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}}
</style>
</head>
<body>
<button class="fk-menu-btn" data-fk-menu-btn>☰</button>
<div class="fk-menu-overlay" data-fk-menu-overlay aria-hidden="true">
<div class="fk-menu-backdrop" data-fk-menu-close></div>
<div class="fk-menu-panel">
<button class="fk-menu-close" data-fk-menu-close>×</button>
<nav class="fk-menu-nav">
<a href="?" target="_self" class="fk-menu-link">index</a>
<a href="?_page=map" target="_self" class="fk-menu-link">map</a>
<a href="?_page=raw" target="_self" class="fk-menu-link">raw</a>
<a href="?_page=edit" target="_self" class="fk-menu-link">edit</a>
</nav>
</div>
</div>
<div class="rules-overlay" id="rulesOverlay">
<div class="rules-overlay-title">Zutreffende Regeln:</div>
<div id="rulesContent"></div>
</div>
<script>
(function(){"use strict";var btn=document.querySelector("[data-fk-menu-btn]");var overlay=document.querySelector("[data-fk-menu-overlay]");var closers=document.querySelectorAll("[data-fk-menu-close]");var isOpen=false;if(!btn||!overlay)return;function openMenu(){if(isOpen)return;isOpen=true;overlay.classList.add("is-visible");overlay.setAttribute("aria-hidden","false");document.documentElement.style.overflow="hidden"}function closeMenu(){if(!isOpen)return;isOpen=false;overlay.classList.remove("is-visible");overlay.setAttribute("aria-hidden","true");document.documentElement.style.overflow=""}btn.addEventListener("click",function(e){e.stopPropagation();isOpen?closeMenu():openMenu()});closers.forEach(function(el){el.addEventListener("click",closeMenu)});overlay.addEventListener("click",function(e){var panel=overlay.querySelector(".fk-menu-panel");if(panel&&!panel.contains(e.target))closeMenu()});document.addEventListener("keydown",function(e){if(e.key==="Escape")closeMenu()})})();
</script>
<h2>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?=$csvreporting_heading?></h2>
<?php if($csvError):?><div class="error"><?php echo h($csvError);?></div><?php endif;?>
<h3>Ergebnisse (<?php echo count($filtered);?>)</h3>
<div class="filter-toggle" onclick="toggleFilters()">
<span>Filter <span class="filter-toggle-icon" id="filterToggleIcon">▼</span></span>
</div>
<div class="filter-row" id="filterPanel">
<?php foreach($showColumns as $col):
$isTS=isset($timestampCols[$col]);
$values=get_all_filter_values($header,$ruleBaseRows,$col,$isTS);
$af=$activeFilters[$col]??'';
if($af!==''&&!in_array($af,$values,true))$values[]=$af;
if(count($values)===0)continue;
?>
<div class="filter-item">
<div class="filter-label"><?php echo h($col);?></div>
<select onchange="document.location=addOrUpdateParam('filter_<?php echo urlencode($col);?>',this.value)">
<option value="">-- Alle --</option>
<?php foreach($values as $v):?>
<option value="<?php echo h($v);?>"<?php
$af=$activeFilters[$col]??'';
if($af!==''){
if(isset($timestampCols[$col])){if(intval($af)===intval($v))echo' selected';}
else{if($af===$v)echo' selected';}
}
?>>
<?php echo h($v);?></option>
<?php endforeach;?>
</select>
</div>
<?php endforeach;?>
</div>
<table>
<thead>
<tr>
<th style="width:30px"></th>
<?php foreach($showColumns as $col):?>
<th><?php echo h($col);?></th>
<?php endforeach;?>
</tr>
</thead>
<tbody>
<?php if(count($filtered)===0):?>
<tr><td colspan="<?php echo count($showColumns)+1;?>">Keine Zeilen entsprechen den Regeln.</td></tr>
<?php else:foreach($filtered as $row):$matchingRules=get_matching_rules($header,$row,$rules,$timestampCols);?>
<tr>
<td style="text-align:center;padding:4px"><span class="info-icon" onclick="showRulesOverlay(event,<?php echo htmlspecialchars(json_encode($matchingRules));?>)">ⓘ</span></td>
<?php foreach($showColumns as $col):$isTrigger=cell_is_trigger($header,$row,$col,$rules,$timestampCols);$cellValue=cell_by_colname($header,$row,$col);$href=build_cell_link($enableLinks,$columnLinks,$idColumn,$header,$row,$col);?>
<td<?php if($isTrigger)echo' style="background:#ffe5e5"';?>><?php
$txt=format_cell_value($cellValue);
if($href!==null){
echo'<a target="_blank" rel="noopener noreferrer" href="'.h($href).'" style="color:var(--fg);text-decoration:underline;text-underline-offset:2px">'. $txt .'</a>';
}else{
echo $txt;
}
?></td>
<?php endforeach;?>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
<script>
function toggleFilters(){
var panel=document.getElementById('filterPanel');
var icon=document.getElementById('filterToggleIcon');
panel.classList.toggle('visible');
icon.textContent=panel.classList.contains('visible')?'▲':'▼';
}
function addOrUpdateParam(key,value){
var url=new URL(window.location);
if(value===''){
url.searchParams.delete(key);
}else{
url.searchParams.set(key,value);
}
return url.toString();
}
function showRulesOverlay(event,rules){
var overlay=document.getElementById('rulesOverlay');
var content=document.getElementById('rulesContent');
content.innerHTML='';
rules.forEach(function(r){
var div=document.createElement('div');
div.className='rules-overlay-item';
var text=r.desc;
if(text.length>60){
text=text.substring(0,57)+'...';
}
div.textContent=text;
content.appendChild(div);
});
var maxX=window.innerWidth-420;
var x=Math.min(event.clientX+10,maxX);
var maxY=window.innerHeight-320;
var y=Math.min(event.clientY+10,maxY);
overlay.style.left=x+'px';
overlay.style.top=y+'px';
overlay.classList.add('visible');
setTimeout(function(){
document.addEventListener('click',function hideOverlay(e){
if(!overlay.contains(e.target)&&e.target!==event.target){
overlay.classList.remove('visible');
document.removeEventListener('click',hideOverlay);
}
});
},0);
}
</script>
</body>
</html>

CSVREPORTING_INDEX_ROUTE;
eval($csvreporting_route);
exit;
}
if($csvreporting_page==='edit'){
$csvreporting_route=<<<'CSVREPORTING_EDIT_ROUTE'
$default=['header_line'=>'','show_columns'=>[],'rules'=>[],'column_renames'=>[],'enable_links'=>false,'column_links'=>[]];
$load_error='';$save_msg='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save'){
$incoming=$_POST['json']??'';$decoded=json_decode($incoming,true);
if($decoded===null&&trim($incoming)!==''){$save_msg='Fehler: Ungültiges JSON.';$parsed=$default;}
else{if(!is_array($decoded))$decoded=$default;
if(!isset($decoded['rules'])||!is_array($decoded['rules']))$decoded['rules']=[];
if(!isset($decoded['show_columns'])||!is_array($decoded['show_columns']))$decoded['show_columns']=[];
if(!isset($decoded['column_renames'])||!is_array($decoded['column_renames']))$decoded['column_renames']=[];
if(!isset($decoded['column_links'])||!is_array($decoded['column_links']))$decoded['column_links']=[];
$ok=@file_put_contents($csvreporting_jsondir,json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$save_msg=$ok===false?'Fehler beim Speichern.':'Gespeichert.';$parsed=$decoded;}}
if(!isset($parsed)){
$raw=is_readable($csvreporting_jsondir)?@file_get_contents($csvreporting_jsondir):json_encode($default,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$parsed=@json_decode((string)$raw,true);
if(!is_array($parsed)){$parsed=$default;$load_error='rules.json beschädigt – Standard geladen.';}}
$header_line=(string)($parsed['header_line']??'');
$show_columns=is_array($parsed['show_columns']??null)?$parsed['show_columns']:[];
$column_renames=is_array($parsed['column_renames']??null)?$parsed['column_renames']:[];
$enable_links=!empty($parsed['enable_links']);
$column_links=is_array($parsed['column_links']??null)?$parsed['column_links']:[];
$csvCols=[];
if(is_string($csvreporting_csv_memory??null)){$csvMemoryRows=csvreporting_parse_csv($csvreporting_csv_memory);$first=$csvMemoryRows[0]??null;if(is_array($first))$csvCols=array_map('trim',$first);}
if($header_line===''&&$csvCols)$header_line=implode(',',$csvCols);
if(!$show_columns)$show_columns=$csvCols;
$cols=$csvCols?:array_map('trim',explode(',',$header_line));if(!is_array($cols))$cols=[];
$parsed['header_line']=$header_line;$parsed['show_columns']=$show_columns;$parsed['rules']=is_array($parsed['rules']??null)?$parsed['rules']:[];
$parsed['column_renames']=$column_renames;$parsed['enable_links']=$enable_links;$parsed['column_links']=$column_links;
$data_json=json_encode($parsed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$columns_json=json_encode(array_values($cols),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<?php $__th=(isset($csvreporting_theme)&&in_array($csvreporting_theme,['light','dark'],true))?$csvreporting_theme:'auto'; ?><html lang="de"<?= $__th==='light'?' data-theme="light"':($__th==='dark'?' data-theme="dark"':'') ?>>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="only light">
<title><?=$csvreporting_editortitle?></title>
<link rel="icon" href="<?=$csvreporting_editoricon?>"><style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
h1,h2{margin:0 0 8px 0}
section{margin-top:18px}
textarea,input[type=text],select{padding:4px;border:1px solid var(--border);border-radius:3px;font-size:14px;background:var(--bg);color:var(--fg)}
textarea{width:100%;min-height:40px}
button{padding:4px 10px;border:1px solid var(--border);border-radius:3px;background:var(--panel);color:var(--fg);cursor:pointer}
button.primary{background:var(--accent);color:var(--accent-fg);border-color:var(--accent)}
button.small{font-size:12px;padding:2px 6px}
table{border-collapse:collapse;width:100%;margin-top:6px}
th,td{border:1px solid var(--border);padding:4px;font-size:13px;vertical-align:top;color:var(--fg)}
th{background:var(--panelhead)}
.error{color:var(--danger);margin-top:6px;font-weight:bold}
.info{font-size:12px;margin-top:4px;color:var(--muted)}
.condition-row{display:flex;gap:6px;margin-top:4px;align-items:center}
.condition-row select{width:220px}
.condition-row input[type=text]{flex:1}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:999}
.modal{background:var(--panel);color:var(--fg);padding:12px;border-radius:4px;min-width:340px;max-width:820px;max-height:90vh;overflow:auto}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.modal-title{font-size:16px;font-weight:bold}
.modal-close{background:none;color:var(--fg);border:1px solid var(--border);border-radius:3px;font-size:18px;line-height:16px;padding:2px 8px;cursor:pointer}
.fk-menu-btn{position:fixed;top:10px;left:10px;background:var(--ctrl-bg);color:var(--ctrl-fg);border:none;padding:10px;font-size:20px;border-radius:4px;cursor:pointer;z-index:1000}
.fk-menu-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;z-index:999}
.fk-menu-overlay.is-visible{display:block}
.fk-menu-panel{position:absolute;top:0;left:0;width:260px;height:100%;background:var(--panel);padding:20px;box-sizing:border-box;transform:translateX(-100%);transition:.25s}
.fk-menu-overlay.is-visible .fk-menu-panel{transform:translateX(0)}
.fk-menu-nav{display:flex;flex-direction:column;gap:12px;margin-top:20px}
.fk-menu-link{text-decoration:none;font-size:18px;color:var(--fg)}
.fk-menu-link:hover{color:var(--accent)}
.group-row{background:var(--panel);cursor:pointer;user-select:none}
.group-row:hover{background:var(--panel2)}
.group-title{display:flex;justify-content:space-between;align-items:center}
.group-icon{font-weight:bold;color:var(--accent)}
.badge{display:inline-block;font-size:11px;border:1px solid var(--border);border-radius:10px;padding:1px 8px;margin-left:8px;background:var(--panel);color:var(--fg)}
.badge.on{border-color:var(--success);background:var(--success-bg);color:var(--success)}
.badge.off{border-color:var(--danger);background:var(--danger-bg);color:var(--danger-fg)}
.badge.info{border-color:var(--accent);background:var(--panel2);color:var(--accent);cursor:pointer}
.view-toggle{display:inline-flex;align-items:center;gap:6px;margin-left:10px;font-size:13px}
button:disabled{opacity:.45;cursor:not-allowed}
a{color:var(--link)}
</style>
<style id="theme-tokens">
:root{
--bg:#ffffff;--fg:#141414;--muted:#5a5f66;--border:#d5d7dd;
--panel:#f5f6f8;--panel2:#e9ebef;--panelhead:#eef0f3;
--accent:#0a63ff;--accent-fg:#ffffff;--link:#0a58ca;
--danger:#b3261e;--danger-bg:#fde7e7;--danger-fg:#7f1512;
--success:#1a7f37;--success-bg:#e6f4ea;--warn:#8a6d00;--warn-bg:#fff4cf;
--ctrl-bg:#141414;--ctrl-fg:#ffffff;--shadow:rgba(0,0,0,.18);
}
:root[data-theme="dark"]{
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]){
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}}
</style>
</head>
<body>
<button class="fk-menu-btn" data-fk-menu-btn>☰</button>
<div class="fk-menu-overlay" data-fk-menu-overlay aria-hidden="true">
<div class="fk-menu-backdrop" data-fk-menu-close></div>
<div class="fk-menu-panel">
<button class="fk-menu-close" data-fk-menu-close>×</button>
<nav class="fk-menu-nav">
<a href="?" target="_self" class="fk-menu-link">index</a>
<a href="?_page=map" target="_self" class="fk-menu-link">map</a>
<a href="?_page=raw" target="_self" class="fk-menu-link">raw</a>
<a href="?_page=edit" target="_self" class="fk-menu-link">edit</a>
</nav>
</div>
</div>
<script>
(function(){"use strict";var btn=document.querySelector("[data-fk-menu-btn]");var overlay=document.querySelector("[data-fk-menu-overlay]");var closers=document.querySelectorAll("[data-fk-menu-close]");var isOpen=false;if(!btn||!overlay)return;function openMenu(){if(isOpen)return;isOpen=true;overlay.classList.add("is-visible");overlay.setAttribute("aria-hidden","false");document.documentElement.style.overflow="hidden"}function closeMenu(){if(!isOpen)return;isOpen=false;overlay.classList.remove("is-visible");overlay.setAttribute("aria-hidden","true");document.documentElement.style.overflow=""}btn.addEventListener("click",function(e){e.stopPropagation();isOpen?closeMenu():openMenu()});closers.forEach(function(el){el.addEventListener("click",closeMenu)});overlay.addEventListener("click",function(e){var panel=overlay.querySelector(".fk-menu-panel");if(panel&&!panel.contains(e.target))closeMenu()});document.addEventListener("keydown",function(e){if(e.key==="Escape")closeMenu()})})();
</script>
<h1 style="margin-top:72px"><?=$csvreporting_editorheading?></h1>
<?php if($save_msg){?><div class="info"><?php echo h($save_msg);?></div><?php } ?>
<?php if($load_error){?><div class="error"><?php echo h($load_error);?></div><?php } ?>
<form method="post" id="rules-form" onkeydown="if(event.key==='Enter'&&event.target.tagName==='INPUT')event.preventDefault()">
<input type="hidden" name="action" value="save"><input type="hidden" name="json" id="rules-json">

<section>
<h2>Spalten ausblenden:</h2>
<div style="margin-top:8px"></div><!-- <- --><div style="display:flex;flex-wrap:wrap;gap:8px;"><?php foreach($cols as $c){$hidden=!in_array($c,$show_columns,true);?><label><input type="checkbox" class="show-col-cb" value="<?php echo h($c);?>"<?php if($hidden)echo' checked';?>><?php echo h($column_renames[$c]??$c);?></label><?php } ?></div>
</section>
<section>
<h2>Spalten umbenennen:</h2>
<div class="filter-item"><div class="filter-label"></div><!-- <- --><select id="rename-col-select" onchange="updateRenameInput()"><option value="">-- Spalte wählen --</option><?php foreach($cols as $c): ?><option value="<?php echo h($c);?>"><?php echo h($c);?></option><?php endforeach; ?></select></div><div class="filter-item" style="margin-top:8px"><div class="filter-label">Neuer Anzeigename</div><input type="text" id="rename-col-value"></div>
</section>

<section>
<h2>Generische Links</h2>
<label>
<input type="checkbox" id="enable-links"<?php if($enable_links)echo' checked';?>> aktiv</label><div class="filter-item" style="margin-top:8px"><div class="filter-label">Spalte</div><select id="link-col-select" onchange="updateLinkInput()"><option value="">-- Spalte wählen --</option><?php foreach($cols as $c): ?><option value="<?php echo h($c);?>"><?php echo h($column_renames[$c]??$c);?></option><?php endforeach; ?></select></div><div class="filter-item" style="margin-top:8px"><div class="filter-label">Link mit/ohne `*` oder *</div><input type="text" id="link-col-value" placeholder=""></div></section>
<section>
<h2>Regeln</h2>
<button type="button" class="primary" onclick="openRule(-1)">Regel hinzufügen</button>
<label class="view-toggle"><input type="checkbox" id="view-flat">nur Aktiv/Deaktiv</label>
<table><thead><tr><th style="width:60px">#</th><th>Beschreibung</th><th style="width:280px">Aktionen</th></tr></thead><tbody id="rules-body"></tbody></table>
</section>
<div style="margin-top:14px"><button type="submit" class="primary">Speichern</button><button type="button" onclick="openHelp()" style="margin-left:8px">Hilfe</button></div>
</form>
<div class="modal-backdrop" id="rule-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Regel bearbeiten</div><button type="button" onclick="closeRule()" class="modal-close">×</button></div>
<label>Beschreibung (automatisch)</label><input type="text" id="r-desc" readonly>
<label style="display:block;margin-top:8px">Bedingungen (UND)</label><div id="r-conds"></div><button type="button" class="small" onclick="addCond()" style="margin-top:6px">+ Bedingung</button>
<div style="margin-top:10px;display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeRule()">Abbrechen</button><button type="button" class="primary" onclick="applyRule()">Übernehmen</button></div>
</div></div>
<div class="modal-backdrop" id="info-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Info</div><button type="button" onclick="closeInfo()" class="modal-close">×</button></div>
<textarea id="info-text" style="width:100%;min-height:120px" placeholder="Optionaler Infotext zur Regel..."></textarea>
<div style="margin-top:10px;display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeInfo()">Schließen</button><button type="button" class="primary" onclick="saveInfo()">Speichern</button></div>
</div></div>
<div class="modal-backdrop" id="help-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Regel-Muster Hilfe</div><button type="button" onclick="closeHelp()" class="modal-close">×</button></div>
<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.4"><li>* → Wildcard</li><li>n/a → leer oder N/A</li><li>&gt;500, &gt;=500, &lt;500, &lt;=500</li><li>100..500, -500..-100, -500..+100</li><li>500 → genau 500</li></ul>
</div></div>
<script>
var DATA=<?php echo $data_json;?>;var COLS=<?php echo $columns_json;?>;
if(!DATA||typeof DATA!=="object")DATA={};if(!Array.isArray(COLS))COLS=[];if(!Array.isArray(DATA.rules))DATA.rules=[];if(!Array.isArray(DATA.show_columns))DATA.show_columns=[];
if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};DATA.enable_links=!!DATA.enable_links;
var ESC5="$$$$$";
function enc5(s){
s=String(s==null?"":s);
s=s.split(ESC5).join(ESC5+ESC5);
s=s.split("&").join(ESC5+"&"+ESC5);
s=s.split("=").join(ESC5+"="+ESC5);
s=s.split("≠").join(ESC5+"≠"+ESC5);
return s;
}
function dec5(s){
s=String(s==null?"":s);
s=s.split(ESC5+"≠"+ESC5).join("≠");
s=s.split(ESC5+"="+ESC5).join("=");
s=s.split(ESC5+"&"+ESC5).join("&");
s=s.split(ESC5+ESC5).join(ESC5);
return s;
}
function stripInactivePrefix(desc){
desc=String(desc||"").trim();
if(desc.startsWith("!..."))desc=desc.slice(4).trim();
desc=desc.split("...&...").join(" & ").split("...").join(" ").replace(/\s+/g," ").trim();
return desc;
}
function ruleDisplayTitle(r){
if(!r||typeof r!=="object")return "";
var conds=Array.isArray(r.conditions)?r.conditions:[];
if(conds.length>0)return buildAutoDescFromConditions(conds);
return dec5(stripInactivePrefix(r.description||""));
}
var GROUP_OPEN={};try{GROUP_OPEN=JSON.parse(localStorage.getItem("rules_group_open")||"{}")||{};}catch(e){GROUP_OPEN={};}

function persistGroupOpen(){try{localStorage.setItem("rules_group_open",JSON.stringify(GROUP_OPEN));}catch(e){}}
function esc(s){return String(s).replace(/[<>&]/g,m=>({"<":"&lt;",">":"&gt;","&":"&amp;"}[m]));}
function normalizeLegacyDesc(desc){
desc=String(desc||"").trim();
let prefix="";
if(desc.startsWith("!...")){prefix="!...";desc=desc.slice(4).trim();}
desc=desc.split("...&...").join(" & ");
desc=desc.split("...").join(" ");
desc=desc.replace(/\s+/g," ").trim();
return prefix?("!..." + (desc?(" "+desc):"")):desc;
}
function buildAutoDescFromConditions(conds){
if(!Array.isArray(conds)||conds.length===0)return "";
return conds.map(c=>String(c.column||"")+" "+(c.negate?"≠":"=")+" "+String(c.pattern||"")).join(" & ").replace(/\s+/g," ").trim();
}
function parseTitleToConditions(desc){
let t=dec5(stripInactivePrefix(desc));
if(!t)return [];
let parts=t.split(/\s*&\s*/);
let out=[];
for(let p of parts){
p=String(p||"").trim();
if(!p)continue;
let m=p.match(/^(.*?)\s*(≠|=)\s*(.+)$/);
if(!m)continue;
let col=String(m[1]||"").trim();
let op=String(m[2]||"").trim();
let pat=String(m[3]||"").trim();
if(!col||pat==="")continue;
out.push({column:col,pattern:pat,negate:op==="≠"});
}
return out;
}
function updateDescFromDom(){
var rows=document.querySelectorAll("#r-conds .condition-row");var list=[];
rows.forEach(r=>{var c=r.querySelector("select").value.trim();var p=r.querySelector('input[type=text]').value.trim();var n=r.querySelector('input[type=checkbox]').checked;if(c!==""&&p!=="")list.push({column:c,pattern:p,negate:n});});
document.getElementById("r-desc").value=buildAutoDescFromConditions(list)==="!..."?"":buildAutoDescFromConditions(list);
}
function updateRenameInput(){let col=document.getElementById("rename-col-select").value;document.getElementById("rename-col-value").value=(DATA.column_renames&&DATA.column_renames[col])||"";}
document.getElementById("rename-col-value").addEventListener("input",function(){let col=document.getElementById("rename-col-select").value;if(!col)return;let v=this.value.trim();if(v==="")delete DATA.column_renames[col];else DATA.column_renames[col]=v;render();});
function updateLinkInput(){let col=document.getElementById("link-col-select").value;let inp=document.getElementById("link-col-value");if(!col){inp.value="";return;}inp.value=(DATA.column_links&&DATA.column_links[col])||"";}
document.getElementById("link-col-value").addEventListener("input",function(){let col=document.getElementById("link-col-select").value;if(!col)return;if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};let v=this.value;if(v==="")delete DATA.column_links[col];else DATA.column_links[col]=v;});
function sync(){
var v=[];document.querySelectorAll(".show-col-cb").forEach(x=>{if(!x.checked)v.push(x.value);});
DATA.show_columns=v;DATA.enable_links=document.getElementById("enable-links").checked;
if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};
let rc=document.getElementById("rename-col-select").value;let rv=document.getElementById("rename-col-value").value.trim();if(rc){if(rv==="")delete DATA.column_renames[rc];else DATA.column_renames[rc]=rv;}
let lc=document.getElementById("link-col-select").value;let lv=document.getElementById("link-col-value").value;if(lc){if(lv==="")delete DATA.column_links[lc];else DATA.column_links[lc]=lv;}
DATA.rules.forEach(r=>{
if(!r||typeof r!=="object")return;
if(typeof r.info!=="string")r.info=String(r.info||"");
var conds=Array.isArray(r.conditions)?r.conditions:[];
if(conds.length>0){
r.description=buildAutoDescFromConditions(conds);
if(Array.isArray(r.stash_conditions))delete r.stash_conditions;
}else{
let title=dec5(stripInactivePrefix(r.description||""));
r.description="!..."+(title?(" "+enc5(title)):"");
}
});
document.getElementById("rules-json").value=JSON.stringify(DATA);
}
document.getElementById("rules-form").onsubmit=sync;
var VIEW_FLAT=false;
try{VIEW_FLAT=(localStorage.getItem("rules_view_flat")||"")==="1";}catch(e){VIEW_FLAT=false;}
var vb=document.getElementById("view-flat");
if(vb){vb.checked=VIEW_FLAT;vb.addEventListener("change",function(){VIEW_FLAT=!!this.checked;try{localStorage.setItem("rules_view_flat",VIEW_FLAT?"1":"0");}catch(e){}render();});}
function toggleGroup(key){GROUP_OPEN[key]=!GROUP_OPEN[key];persistGroupOpen();applyGroupVisibility();}
function applyGroupVisibility(){
document.querySelectorAll("tr[data-group]").forEach(tr=>{let key=tr.getAttribute("data-group");let open=GROUP_OPEN[key]!==false;let icon=tr.querySelector(".group-icon");if(icon)icon.textContent=open?"▲":"▼";});
document.querySelectorAll("tr[data-group-item]").forEach(tr=>{let key=tr.getAttribute("data-group-item");let open=GROUP_OPEN[key]!==false;tr.style.display=open?"":"none";});
}
function deleteRule(i){if(!confirm("Regel wirklich löschen?"))return;DATA.rules.splice(i,1);render();}
function deactivateRule(i){
var r=DATA.rules[i];if(!r||typeof r!=="object")return;
var conds=Array.isArray(r.conditions)?r.conditions:[];
r.stash_conditions=conds;
r.conditions=[];
var title=buildAutoDescFromConditions(conds);
r.description="!..."+(title?(" "+enc5(title)):"");
render();
}
function activateRule(i){
var r=DATA.rules[i];if(!r||typeof r!=="object")return;
var conds=Array.isArray(r.conditions)?r.conditions:[];
if(conds.length>0)return;
if(Array.isArray(r.stash_conditions)&&r.stash_conditions.length>0){
r.conditions=r.stash_conditions;
delete r.stash_conditions;
r.description=buildAutoDescFromConditions(r.conditions);
render();
return;
}
var parsed=parseTitleToConditions(r.description||"");
if(parsed.length===0){alert("Aktivieren nicht möglich: Titel leer/ungültig (Format: Spalte = Muster & Spalte ≠ Muster).");return;}
r.conditions=parsed;
r.description=buildAutoDescFromConditions(r.conditions);
render();
}
function render(){
let tb=document.getElementById("rules-body");tb.innerHTML="";
let groups={};let inactive=[];let active=[];
DATA.rules.forEach((r,i)=>{
if(!r||typeof r!=="object")return;
let conds=Array.isArray(r.conditions)?r.conditions:[];
if(conds.length===0){inactive.push({i,r});}
else active.push({i,r});
if(!VIEW_FLAT){
let used=[...new Set(conds.map(c=>c.column))];
used.forEach(col=>{if(!groups[col])groups[col]=[];groups[col].push({i,r});});
}
});
function addGroup(key,title,items){
let tr=document.createElement("tr");tr.className="group-row";tr.setAttribute("data-group",key);
let icon=(GROUP_OPEN[key]===false)?"▼":"▲";
tr.innerHTML="<td colspan='3'><div class='group-title'><span>"+esc(title)+"</span><span class='group-icon'>"+icon+"</span></div></td>";
tr.onclick=()=>toggleGroup(key);
tb.appendChild(tr);
items.forEach(x=>{
let r=x.r;let i=x.i;
let conds=Array.isArray(r.conditions)?r.conditions:[];
let enabled=conds.length>0;
if(enabled)r.description=buildAutoDescFromConditions(conds);
else{
let title=dec5(stripInactivePrefix(r.description||""));
r.description="!..."+(title?(" "+enc5(title)):"");
}
let tr2=document.createElement("tr");
tr2.setAttribute("data-group-item",key);
tr2.setAttribute("data-rule-idx",String(i));
tr2.setAttribute("draggable","true");
let hasInfo=!!(r.info&&String(r.info).trim()!=="");
let infoBadge=hasInfo?" <span class='badge info' data-info-idx='"+i+"'>info</span>":"";
tr2.innerHTML="<td>"+(i+1)+"</td><td>"+esc(ruleDisplayTitle(r))+infoBadge+"</td>";
let td=document.createElement("td");td.style.whiteSpace="nowrap";
let b=document.createElement("button");b.type="button";b.textContent="Edit";b.className="small";b.disabled=!enabled;b.onclick=e=>{e.stopPropagation();openRule(i);};
let tog=document.createElement("button");tog.type="button";tog.textContent=enabled?"Deaktivieren":"Aktivieren";tog.className="small";tog.style.marginLeft="6px";tog.onclick=e=>{e.stopPropagation();enabled?deactivateRule(i):activateRule(i);};
let info=document.createElement("button");info.type="button";info.textContent="Info";info.className="small";info.style.marginLeft="6px";info.onclick=e=>{e.stopPropagation();openInfo(i);};
let del=document.createElement("button");del.type="button";del.textContent="X";del.className="small";del.style.marginLeft="6px";del.onclick=e=>{e.stopPropagation();deleteRule(i);};
td.appendChild(b);td.appendChild(tog);td.appendChild(info);td.appendChild(del);
tr2.appendChild(td);
tb.appendChild(tr2);
});
}
if(VIEW_FLAT){
addGroup("__active","Aktiv",active);
addGroup("__inactive","Deaktiv",inactive);
applyGroupVisibility();
attachInfoBadges();
return;
}
if(inactive.length>0)addGroup("__inactive","Inaktiv / Spezial (!...)",inactive);
Object.keys(groups).sort((a,b)=>a.localeCompare(b,undefined,{numeric:true,sensitivity:"base"})).forEach(col=>{
let title=(DATA.column_renames&&DATA.column_renames[col])?DATA.column_renames[col]+" ("+col+")":col;
addGroup(col,title,groups[col]);
});
applyGroupVisibility();
attachGroupDnD();
attachInfoBadges();
}
var DND_STATE=null;
function attachGroupDnD(){
Array.from(document.querySelectorAll("tr[data-group-item]")).forEach(tr=>{
tr.ondragstart=e=>{
let key=tr.getAttribute("data-group-item");
let idx=parseInt(tr.getAttribute("data-rule-idx")||"-1",10);
if(!key||idx<0)return;
DND_STATE={key:key,fromIdx:idx,el:tr};
tr.style.opacity="0.5";
try{e.dataTransfer.setData("text/plain","x");}catch(_){}
e.dataTransfer.effectAllowed="move";
e.stopPropagation();
};
tr.ondragend=()=>{
tr.style.opacity="";
DND_STATE=null;
};
tr.ondragover=e=>{
if(!DND_STATE)return;
let key=tr.getAttribute("data-group-item");
if(key!==DND_STATE.key)return;
e.preventDefault();
e.dataTransfer.dropEffect="move";
let r=tr.getBoundingClientRect();
let before=e.clientY<(r.top+r.height/2);
let parent=tr.parentNode;
if(before)parent.insertBefore(DND_STATE.el,tr);
else parent.insertBefore(DND_STATE.el,tr.nextSibling);
};
tr.ondrop=e=>{
if(!DND_STATE)return;
let key=tr.getAttribute("data-group-item");
if(key!==DND_STATE.key)return;
e.preventDefault();
applyGroupOrderFromDom(DND_STATE.key);
DND_STATE=null;
render();
};
});
}
function applyGroupOrderFromDom(groupKey){
let rows=Array.from(document.querySelectorAll('tr[data-group-item="'+CSS.escape(groupKey)+'"]'));
let domOrder=rows.map(r=>parseInt(r.getAttribute("data-rule-idx")||"-1",10)).filter(n=>Number.isFinite(n)&&n>=0);
let n=DATA.rules.length;
if(domOrder.length===0)return;
let subset=new Set(domOrder);
let orig=DATA.rules.slice();
let p=0;
let out=[];
for(let i=0;i<n;i++){
if(subset.has(i)){out.push(orig[domOrder[p++]]);}
else out.push(orig[i]);
}
DATA.rules=out;
}
let edit=-1;
function openRule(i){
edit=i;
if(i>=0){
let rr=DATA.rules[i];
let cc=(rr&&typeof rr==="object"&&Array.isArray(rr.conditions))?rr.conditions:[];
if(cc.length===0){alert("Diese Regel ist deaktiviert und nicht editierbar. Bitte zuerst aktivieren.");return;}
}
let modal=document.getElementById("rule-modal");
let r=(i>=0&&DATA.rules[i]&&typeof DATA.rules[i]==="object")?DATA.rules[i]:{conditions:[]};
let c=document.getElementById("r-conds");c.innerHTML="";
var conds=Array.isArray(r.conditions)?r.conditions:[];
if(conds.length>0)conds.forEach(x=>addCond(x));else addCond();
updateDescFromDom();
modal.style.display="flex";
}
function closeRule(){document.getElementById("rule-modal").style.display="none";}
function addCond(x){
let d=document.createElement("div");d.className="condition-row";
let s=document.createElement("select");let o=document.createElement("option");o.value="";o.textContent="Spalte";s.appendChild(o);
COLS.forEach(col=>{let op=document.createElement("option");op.value=col;op.textContent=(DATA.column_renames&&DATA.column_renames[col])||col;s.appendChild(op);});
let t=document.createElement("input");t.type="text";t.placeholder="Muster";
let n=document.createElement("input");n.type="checkbox";
let l=document.createElement("label");l.style.fontSize="12px";l.style.display="inline-flex";l.style.alignItems="center";l.style.gap="4px";l.appendChild(n);l.appendChild(document.createTextNode("neg."));
let rm=document.createElement("button");rm.type="button";rm.textContent="×";rm.className="small";rm.onclick=()=>{d.remove();updateDescFromDom();};
if(x){s.value=x.column||"";t.value=x.pattern||"";n.checked=!!x.negate;}
d.appendChild(s);d.appendChild(t);d.appendChild(l);d.appendChild(rm);
document.getElementById("r-conds").appendChild(d);
t.addEventListener("input",updateDescFromDom);s.addEventListener("change",updateDescFromDom);n.addEventListener("change",updateDescFromDom);
}
function applyRule(){
let list=[];
document.querySelectorAll("#r-conds .condition-row").forEach(r=>{
let c=r.querySelector("select").value.trim();
let p=r.querySelector("input[type=text]").value.trim();
let n=r.querySelector("input[type=checkbox]").checked;
if(c!==""&&p!=="")list.push({column:c,pattern:p,negate:n});
});
let rule={conditions:list,description:"!..."};
if(list.length>0)rule.description=buildAutoDescFromConditions(list);
if(edit>=0){var __prev=DATA.rules[edit];if(__prev&&typeof __prev==="object"&&typeof __prev.info==="string"&&__prev.info!=="")rule.info=__prev.info;DATA.rules[edit]=rule;}else DATA.rules.push(rule);
closeRule();render();
}
function openHelp(){document.getElementById("help-modal").style.display="flex";}
function closeHelp(){document.getElementById("help-modal").style.display="none";}
var INFO_EDIT=-1;
function openInfo(i){
INFO_EDIT=i;
let r=DATA.rules[i];
if(!r||typeof r!=="object")return;
if(typeof r.info!=="string")r.info=String(r.info||"");
document.getElementById("info-text").value=r.info||"";
document.getElementById("info-modal").style.display="flex";
}
function closeInfo(){
document.getElementById("info-modal").style.display="none";
INFO_EDIT=-1;
}
function saveInfo(){
if(INFO_EDIT<0)return closeInfo();
let r=DATA.rules[INFO_EDIT];
if(!r||typeof r!=="object")return closeInfo();
r.info=document.getElementById("info-text").value||"";
closeInfo();
render();
}
function attachInfoBadges(){
document.querySelectorAll(".badge.info").forEach(el=>{
el.onclick=function(e){
e.stopPropagation();
let idx=parseInt(el.getAttribute("data-info-idx")||"-1",10);
if(idx>=0)openInfo(idx);
};
});
}
render();
</script>
</body>
</html>

CSVREPORTING_EDIT_ROUTE;
eval($csvreporting_route);
exit;
}
if($csvreporting_page==='map'){
$csvreporting_route=<<<'CSVREPORTING_MAP_ROUTE'
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function load_rules($path){
if(!is_readable($path))return['show_columns'=>[],'rules'=>[]];
$json=@file_get_contents($path);
$data=@json_decode($json,true);
if(!is_array($data))$data=[];
if(!isset($data['show_columns'])||!is_array($data['show_columns']))$data['show_columns']=[];
if(!isset($data['rules'])||!is_array($data['rules']))$data['rules']=[];
return $data;
}
function parse_csv_string($csvContent){return csvreporting_parse_csv((string)$csvContent);}
function load_csv($path){global $csvreporting_csv_memory;if(!is_string($csvreporting_csv_memory??null))return null;return parse_csv_string($csvreporting_csv_memory);}
function col_index($header,$colName){
foreach($header as $i=>$h){
if(strcasecmp(trim($h),trim($colName))===0)return $i;
}
return null;
}
function timestamp_to_minutes($timestamp){
$pattern='/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/';
if(!preg_match($pattern,trim($timestamp)))return null;
$ts=strtotime(trim($timestamp));
if($ts===false)return null;
$now=time();
$diff=($ts-$now)/60;
return round($diff,2);
}
function is_timestamp_column($header,$dataRows,$colName){
$idx=col_index($header,$colName);
if($idx===null)return false;
$sampleCount=0;
foreach($dataRows as $row){
if(isset($row[$idx])&&$row[$idx]!==''){
if(timestamp_to_minutes($row[$idx])!==null){
$sampleCount++;
if($sampleCount>=3)return true;
}
}
}
return false;
}
function parse_minutes_pattern($pattern){
$p=trim((string)$pattern);
$pattern_clean=preg_replace('/\s+/','',strtolower($p));
if(preg_match('/^>=(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'gte','value'=>floatval($m[1])];
if(preg_match('/^>(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'gt','value'=>floatval($m[1])];
if(preg_match('/^<=(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'lte','value'=>floatval($m[1])];
if(preg_match('/^<(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'lt','value'=>floatval($m[1])];
if(preg_match('/^(-?\d+(?:\.\d+)?)\.\.(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'range','min'=>floatval($m[1]),'max'=>floatval($m[2])];
if(preg_match('/^(-?\d+(?:\.\d+)?)$/',$pattern_clean,$m))return['type'=>'exact','value'=>floatval($m[1])];
return['type'=>'invalid'];
}
function check_minutes_condition($cellValue,$pattern){
$minutes=timestamp_to_minutes($cellValue);
if($minutes===null)return false;
$parsed=parse_minutes_pattern($pattern);
switch($parsed['type']){
case 'gt':return $minutes>$parsed['value'];
case 'gte':return $minutes>=$parsed['value'];
case 'lt':return $minutes<$parsed['value'];
case 'lte':return $minutes<=$parsed['value'];
case 'range':return $minutes>=$parsed['min']&&$minutes<=$parsed['max'];
case 'exact':return $minutes==$parsed['value'];
default:return false;
}
}
function eval_text_op($value,$op,$cmp){
$value=(string)$value;
$cmp=(string)$cmp;
switch($op){
case 'wildcard':$pattern='/^'.str_replace(['\*','\?'],['.*','.'],preg_quote($cmp,'/')).'$/i';return @preg_match($pattern,$value)===1;
case 'contains':return stripos($value,$cmp)!==false;
case 'equals':return strcmp($value,$cmp)===0;
case 'starts_with':return stripos($value,$cmp)===0;
case 'ends_with':$len=strlen($cmp);if($len===0)return true;return strcasecmp(substr($value,-$len),$cmp)===0;
case 'regex':$ok=@preg_match($cmp,$value);return $ok===1;
case 'empty':return trim($value)==='';
case 'empty_or_na':$v=trim($value);return $v===''||strcasecmp($v,'N/A')===0;
default:return false;
}
}
function parse_condition_pattern($pattern){
$p=trim((string)$pattern);
if($p==='*')return['op'=>'wildcard','value'=>'*'];
if(strcasecmp($p,'n/a')===0||strcasecmp($p,'na')===0)return['op'=>'empty_or_na','value'=>''];
return['op'=>'wildcard','value'=>$p];
}
function eval_condition($cond,$header,$row,$timestampCols){
$col=isset($cond['column'])?(string)$cond['column']:'';
$pattern=isset($cond['pattern'])?(string)$cond['pattern']:'';
if($col===''||$pattern==='')return true;
$idx=col_index($header,$col);
$val=($idx!==null&&isset($row[$idx]))?$row[$idx]:'';
$isTimestampCol=isset($timestampCols[$col]);
if($isTimestampCol)$base=check_minutes_condition($val,$pattern);
else{$parsed=parse_condition_pattern($pattern);$base=eval_text_op($val,$parsed['op'],$parsed['value']);}
if(!empty($cond['negate']))$base=!$base;
return $base;
}
function rule_matches($rule,$header,$row,$timestampCols){
$conds=isset($rule['conditions'])&&is_array($rule['conditions'])?$rule['conditions']:[];
if(count($conds)===0)return false;
foreach($conds as $c){if(!eval_condition($c,$header,$row,$timestampCols))return false;}
return true;
}
function format_cell_value($val){
$minutes=timestamp_to_minutes($val);
if($minutes!==null)return intval($minutes).' min';
return h($val);
}
function cell_by_colname($header,$row,$colName){
$idx=col_index($header,$colName);
return($idx!==null&&isset($row[$idx]))?$row[$idx]:'';
}
function cell_is_trigger($header,$row,$colName,$rules,$timestampCols){
foreach($rules as $rule){
if(!rule_matches($rule,$header,$row,$timestampCols))continue;
$conds=isset($rule['conditions'])&&is_array($rule['conditions'])?$rule['conditions']:[];
foreach($conds as $c){
if(!isset($c['column']))continue;
if(strcasecmp(trim($c['column']),trim($colName))!==0)continue;
if(eval_condition($c,$header,$row,$timestampCols))return true;
}
}
return false;
}
$rulesData=load_rules($csvreporting_jsondir);
$showColumns=$rulesData['show_columns']??[];
$rules=$rulesData['rules']??[];
$csvRows=load_csv(null);
$csvError=null;
if($csvRows===null)$csvError=$csvreporting_live_message?:'Remote nicht erreichbar: Abruf per GET und POST fehlgeschlagen.';
$header=[];
$dataRows=[];
if(is_array($csvRows)&&count($csvRows)>0){$header=array_map('trim',$csvRows[0]);$dataRows=array_slice($csvRows,1);}
if(!is_array($showColumns)||count($showColumns)===0)$showColumns=$header;
$timestampCols=[];
foreach($showColumns as $col){if(is_timestamp_column($header,$dataRows,$col))$timestampCols[$col]=true;}
$filtered=[];
if(is_array($dataRows)&&count($dataRows)>0&&is_array($rules)&&count($rules)>0){
foreach($dataRows as $row){
foreach($rules as $r){
if(rule_matches($r,$header,$row,$timestampCols)){$filtered[]=$row;break;}
}
}
}
?>
<!doctype html>
<?php $__th=(isset($csvreporting_theme)&&in_array($csvreporting_theme,['light','dark'],true))?$csvreporting_theme:'auto'; ?><html lang="de"<?= $__th==='light'?' data-theme="light"':($__th==='dark'?' data-theme="dark"':'') ?>>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="only light">
<link rel="icon" type="image/svg+xml" href="<?=$csvreporting_icon?>">
<!--<meta http-equiv="refresh" content="1">-->
<title><?=$csvreporting_title?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
table{border-collapse:collapse;width:100%;background:var(--bg)}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left;background:var(--bg);color:var(--fg)}
th{background:var(--panelhead);color:var(--fg)}
.error{color:var(--danger);font-weight:bold;margin-bottom:8px}
a{color:var(--link)}
</style>
<style id="theme-tokens">
:root{
--bg:#ffffff;--fg:#141414;--muted:#5a5f66;--border:#d5d7dd;
--panel:#f5f6f8;--panel2:#e9ebef;--panelhead:#eef0f3;
--accent:#0a63ff;--accent-fg:#ffffff;--link:#0a58ca;
--danger:#b3261e;--danger-bg:#fde7e7;--danger-fg:#7f1512;
--success:#1a7f37;--success-bg:#e6f4ea;--warn:#8a6d00;--warn-bg:#fff4cf;
--ctrl-bg:#141414;--ctrl-fg:#ffffff;--shadow:rgba(0,0,0,.18);
}
:root[data-theme="dark"]{
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]){
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}}
</style>
<style>
:root[data-theme="dark"] td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]) td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}}
</style>
</head>
<body>
<h2><?=$csvreporting_heading?></h2>
<?php if($csvError):?><div class="error"><?php echo h($csvError);?></div><?php endif;?>
<h3>Ergebnisse (<?php echo count($filtered);?>)</h3>
<table>
<thead>
<tr>
<?php foreach($showColumns as $col):?>
<th><?php echo h($col);?></th>
<?php endforeach;?>
</tr>
</thead>
<tbody>
<?php if(count($filtered)===0):?>
<tr><td colspan="<?php echo count($showColumns);?>">Keine Zeilen entsprechen den Regeln.</td></tr>
<?php else:foreach($filtered as $row):?>
<tr>
<?php foreach($showColumns as $col):$isTrigger=cell_is_trigger($header,$row,$col,$rules,$timestampCols);$cellValue=cell_by_colname($header,$row,$col);?>
<td<?php if($isTrigger)echo' style="background:#ffe5e5"';?>><?php echo format_cell_value($cellValue);?></td>
<?php endforeach;?>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
</body>
</html>

CSVREPORTING_MAP_ROUTE;
eval($csvreporting_route);
exit;
}
if($csvreporting_page==='raw'){
$csvreporting_route=<<<'CSVREPORTING_RAW_ROUTE'
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function load_config($path){
$out=['show_columns'=>[],'enable_links'=>false,'column_links'=>[]];
if(!is_readable($path))return $out;
$json=@file_get_contents($path);
$data=@json_decode($json,true);
if(!is_array($data))return $out;
if(isset($data['show_columns'])&&is_array($data['show_columns']))$out['show_columns']=$data['show_columns'];
if(isset($data['enable_links']))$out['enable_links']=!empty($data['enable_links']);
if(isset($data['column_links'])&&is_array($data['column_links']))$out['column_links']=$data['column_links'];
return $out;
}
function parse_csv_string($csvContent){return csvreporting_parse_csv((string)$csvContent);}
function load_csv($path){global $csvreporting_csv_memory;if(!is_string($csvreporting_csv_memory??null))return null;return parse_csv_string($csvreporting_csv_memory);}
function col_index($header,$colName){
foreach($header as $i=>$h){
if(strcasecmp(trim((string)$h),trim((string)$colName))===0)return $i;
}
return null;
}
function timestamp_to_minutes($timestamp){
$pattern='/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/';
$t=trim((string)$timestamp);
if(!preg_match($pattern,$t))return null;
$ts=strtotime($t);
if($ts===false)return null;
$diff=($ts-time())/60;
return round($diff,2);
}
function is_timestamp_column($header,$dataRows,$colName){
$idx=col_index($header,$colName);
if($idx===null)return false;
$sampleCount=0;
foreach($dataRows as $row){
if(isset($row[$idx])&&$row[$idx]!==''){
if(timestamp_to_minutes($row[$idx])!==null){
$sampleCount++;
if($sampleCount>=3)return true;
}
}
}
return false;
}
function get_cell_value_for_filter($cellValue,$isTimestamp){
if($isTimestamp){
$minutes=timestamp_to_minutes($cellValue);
if($minutes!==null)return intval($minutes);
return null;
}
return trim((string)$cellValue);
}
function get_all_filter_values($header,$dataRows,$colName,$isTimestamp){
$idx=col_index($header,$colName);
if($idx===null)return [];
$vals=[];
foreach($dataRows as $row){
if(isset($row[$idx])){
$val=get_cell_value_for_filter($row[$idx],$isTimestamp);
if($val===null)continue;
$display=$isTimestamp?($val.' min'):$val;
if(!in_array($display,$vals,true))$vals[]=$display;
}
}
if($isTimestamp){
usort($vals,function($a,$b){
$aNum=intval(explode(' ',$a)[0]);
$bNum=intval(explode(' ',$b)[0]);
return $aNum<=>$bNum;
});
}else{
sort($vals,SORT_NATURAL|SORT_FLAG_CASE);
}
return $vals;
}
function format_cell_value($val){
$minutes=timestamp_to_minutes($val);
if($minutes!==null)return intval($minutes).' min';
return h($val);
}
function check_filter($colName,$filterValue,$cellValue,$isTimestamp){
if($filterValue==='')return true;
$converted=get_cell_value_for_filter($cellValue,$isTimestamp);
if($converted===null)return false;
if($isTimestamp){
$filterMinutes=intval((string)$filterValue);
return $converted===$filterMinutes;
}
return $converted===(string)$filterValue;
}
function cell_by_colname($header,$row,$colName){
$idx=col_index($header,$colName);
return($idx!==null&&isset($row[$idx]))?$row[$idx]:'';
}
function build_cell_link($enableLinks,$columnLinks,$idColumn,$header,$row,$colName){
if(!$enableLinks)return null;
if(!is_array($columnLinks))return null;
if(!array_key_exists($colName,$columnLinks))return null;
$pattern=trim((string)$columnLinks[$colName]);
if($pattern===''||$pattern==='*')return null;
$idVal='';
if($idColumn!==null)$idVal=cell_by_colname($header,$row,$idColumn);else $idVal=cell_by_colname($header,$row,$colName);
$idVal=trim((string)$idVal);
if($idVal==='')return null;
$enc=rawurlencode($idVal);
if(strpos($pattern,'*')!==false)$href=str_replace('*',$enc,$pattern);else $href=$pattern;
return $href;
}
$config=load_config($csvreporting_jsondir);
$showColumns=$config['show_columns']??[];
$enableLinks=!empty($config['enable_links']);
$columnLinks=is_array($config['column_links']??null)?$config['column_links']:[];
$idColumn=null;
foreach($columnLinks as $k=>$v){if(trim((string)$v)==='*'){$idColumn=$k;break;}}
$csvRows=load_csv(null);
$csvError=null;
if($csvRows===null)$csvError=$csvreporting_live_message?:'Remote nicht erreichbar: Abruf per GET und POST fehlgeschlagen.';
$header=[];
$dataRows=[];
if(is_array($csvRows)&&count($csvRows)>0){$header=array_map('trim',$csvRows[0]);$dataRows=array_slice($csvRows,1);}
if(!is_array($showColumns)||count($showColumns)===0)$showColumns=$header;
$timestampCols=[];
foreach($showColumns as $col){if(is_timestamp_column($header,$dataRows,$col))$timestampCols[$col]=true;}
$activeFilters=[];
foreach($showColumns as $col){$activeFilters[$col]=$_GET['filter_'.urlencode($col)]??'';}
$filtered=[];
if(is_array($dataRows)&&count($dataRows)>0){
foreach($dataRows as $row){
$passFilters=true;
foreach($showColumns as $col){
$val=cell_by_colname($header,$row,$col);
$isTS=isset($timestampCols[$col]);
if(!check_filter($col,$activeFilters[$col],$val,$isTS)){$passFilters=false;break;}
}
if($passFilters)$filtered[]=$row;
}
}
?>
<!doctype html>
<?php $__th=(isset($csvreporting_theme)&&in_array($csvreporting_theme,['light','dark'],true))?$csvreporting_theme:'auto'; ?><html lang="de"<?= $__th==='light'?' data-theme="light"':($__th==='dark'?' data-theme="dark"':'') ?>>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="only light">
<link rel="icon" type="image/svg+xml" href="<?=$csvreporting_icon?>">
<title><?=$csvreporting_title?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
table{border-collapse:collapse;width:100%;background:var(--bg)}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left;background:var(--bg);color:var(--fg)}
th{background:var(--panelhead);color:var(--fg)}
.error{color:var(--danger);font-weight:bold;margin-bottom:8px}
a{color:var(--link)}
.fk-menu-btn{position:fixed;top:10px;left:10px;z-index:999999;background:var(--ctrl-bg);color:var(--ctrl-fg);border:none;padding:10px 14px;font-size:20px;cursor:pointer;border-radius:4px}
.fk-menu-overlay{position:fixed;inset:0;display:none;z-index:999998}
.fk-menu-overlay.is-visible{display:block}
.fk-menu-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55)}
.fk-menu-panel{position:absolute;top:0;left:0;width:260px;height:100%;background:var(--panel);padding:20px;box-sizing:border-box;transform:translateX(-100%);transition:transform .25s ease-out}
.fk-menu-overlay.is-visible .fk-menu-panel{transform:translateX(0)}
.fk-menu-close{background:none;border:none;font-size:28px;cursor:pointer;margin-left:auto;display:block;color:var(--fg)}
.fk-menu-nav{margin-top:20px;display:flex;flex-direction:column;gap:12px}
.fk-menu-link{text-decoration:none;font-size:18px;color:var(--fg)}
.fk-menu-link:hover{color:var(--accent)}
.filter-toggle{background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:10px;margin-bottom:12px;cursor:pointer;user-select:none;display:flex;justify-content:space-between;align-items:center;color:var(--fg)}
.filter-toggle:hover{background:var(--panel2)}
.filter-toggle-icon{font-weight:bold;color:var(--accent)}
.filter-row{background:var(--panel);padding:12px;margin-bottom:12px;border-radius:3px;border:1px solid var(--border);display:none}
.filter-row.visible{display:block}
.filter-item{margin-bottom:10px}
.filter-item:last-child{margin-bottom:0}
.filter-row select{padding:4px;border:1px solid var(--border);border-radius:3px;font-size:13px;width:100%;box-sizing:border-box;background:var(--bg);color:var(--fg)}
.filter-label{font-size:12px;font-weight:bold;margin-bottom:4px;color:var(--muted)}
</style>
<style id="theme-tokens">
:root{
--bg:#ffffff;--fg:#141414;--muted:#5a5f66;--border:#d5d7dd;
--panel:#f5f6f8;--panel2:#e9ebef;--panelhead:#eef0f3;
--accent:#0a63ff;--accent-fg:#ffffff;--link:#0a58ca;
--danger:#b3261e;--danger-bg:#fde7e7;--danger-fg:#7f1512;
--success:#1a7f37;--success-bg:#e6f4ea;--warn:#8a6d00;--warn-bg:#fff4cf;
--ctrl-bg:#141414;--ctrl-fg:#ffffff;--shadow:rgba(0,0,0,.18);
}
:root[data-theme="dark"]{
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]){
--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--border:#2b3038;
--panel:#17191d;--panel2:#20242a;--panelhead:#20242a;
--accent:#5b9bff;--accent-fg:#0a0d12;--link:#7db0ff;
--danger:#ff6b6b;--danger-bg:#3f1d1d;--danger-fg:#ffd9d9;
--success:#5bd47e;--success-bg:#163a22;--warn:#e6c34a;--warn-bg:#3a3212;
--ctrl-bg:#20242a;--ctrl-fg:#e8e8ea;--shadow:rgba(0,0,0,.6);
}}
</style>
<style>
:root[data-theme="dark"] td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]) td[style*="ffe5e5"]{background:var(--danger-bg)!important;color:var(--danger-fg)!important}}
</style>
</head>
<body>
<h2><?=$csvreporting_heading?></h2>
<?php if($csvError):?><div class="error"><?php echo h($csvError);?></div><?php endif;?>
<h3>Ergebnisse (<?php echo count($filtered);?>)</h3>
<div class="filter-toggle" onclick="toggleFilters()"><span>Filter <span class="filter-toggle-icon" id="filterToggleIcon">▼</span></span></div>
<div class="filter-row" id="filterPanel">
<?php foreach($showColumns as $col):$values=get_all_filter_values($header,$dataRows,$col,isset($timestampCols[$col]));if(count($values)===0)continue;?>
<div class="filter-item">
<div class="filter-label"><?php echo h($col);?></div>
<select onchange="document.location=addOrUpdateParam('filter_<?php echo urlencode($col);?>',this.value)">
<option value="">-- Alle --</option>
<?php foreach($values as $v):?>
<option value="<?php echo h($v);?>"<?php if($activeFilters[$col]===$v)echo' selected';?>><?php echo h($v);?></option>
<?php endforeach;?>
</select>
</div>
<?php endforeach;?>
</div>
<table>
<thead>
<tr>
<?php foreach($showColumns as $col):?><th><?php echo h($col);?></th><?php endforeach;?>
</tr>
</thead>
<tbody>
<?php if(count($filtered)===0):?>
<tr><td colspan="<?php echo count($showColumns);?>">Keine Zeilen entsprechen den Filtern.</td></tr>
<?php else:foreach($filtered as $row):?>
<tr>
<?php foreach($showColumns as $col):$cellValue=cell_by_colname($header,$row,$col);$href=build_cell_link($enableLinks,$columnLinks,$idColumn,$header,$row,$col);$txt=format_cell_value($cellValue);?>
<td><?php if($href!==null){echo'<a target="_blank" rel="noopener noreferrer" href="'.h($href).'" style="color:var(--fg);text-decoration:underline;text-underline-offset:2px">'.$txt.'</a>';}else{echo$txt;}?></td>
<?php endforeach;?>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
<script>
function toggleFilters(){var panel=document.getElementById('filterPanel');var icon=document.getElementById('filterToggleIcon');panel.classList.toggle('visible');icon.textContent=panel.classList.contains('visible')?'▲':'▼';}
function addOrUpdateParam(key,value){var url=new URL(window.location);if(value===''){url.searchParams.delete(key);}else{url.searchParams.set(key,value);}return url.toString();}
</script>
</body>
</html>

CSVREPORTING_RAW_ROUTE;
eval($csvreporting_route);
exit;
}
http_response_code(404);
exit;
