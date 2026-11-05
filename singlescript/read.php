<?php //read         ?_page=map/raw
declare(strict_types=1);
///////////////////////
$csvreporting_dwlextpage = 'https://hey.com/csv.csv'; #http<und/oder"s">s://domain.tld/csv.csv<oder"index.php">
$csvreporting_dwlfilters=['row'=>['line'],];
$csvreporting_title='Csv Reporting';
$csvreporting_heading='Csv Reporting System';
$csvreporting_jsonfile='csv-reporting.json';
$csvreporting_jsondir=__DIR__.DIRECTORY_SEPARATOR.$csvreporting_jsonfile;
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
if(!is_string($csvreporting_page)||!in_array($csvreporting_page,['index','map','raw'],true))$csvreporting_page='index';
unset($_GET['_page'],$_REQUEST['_page']);
if(in_array($csvreporting_page,['index','map','raw'],true)){[$csvreporting_live_ok,$csvreporting_live_message,$csvreporting_csv_memory]=csvreporting_remote_fetch($csvreporting_dwlextpage,$csvreporting_dwlfilters);}
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
.index-topline{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;min-height:44px;margin-bottom:10px}.index-topline h2{grid-column:1;margin:0;text-align:left}.top-inline-nav{grid-column:2;display:flex;align-items:center;justify-content:center;gap:18px}.top-inline-nav a,.top-inline-nav button{padding:0;border:0;background:transparent;color:var(--fg);font:inherit;font-size:14px;text-decoration:none;cursor:pointer}.top-inline-nav a:hover,.top-inline-nav button:hover,.top-inline-nav .active{color:var(--accent);text-decoration:underline;text-underline-offset:4px}.index-topline{display:block;min-height:44px;margin-bottom:10px}.index-topline h2{margin:0;text-align:left}.index-topline .top-inline-nav{display:none!important}</style>
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
<div class="index-topline"><h2><?=$csvreporting_heading?></h2></div>

<div class="rules-overlay" id="rulesOverlay">
<div class="rules-overlay-title">Zutreffende Regeln:</div>
<div id="rulesContent"></div>
</div>


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
