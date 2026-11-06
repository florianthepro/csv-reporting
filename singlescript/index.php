<?php
declare(strict_types=1);
///////////////////////
$csvreporting_edit_enabled=false; //?_page=edit
///////////////////////
$csvreporting_jsonfile='data.json';
$csvreporting_jsondir=__DIR__.DIRECTORY_SEPARATOR.$csvreporting_jsonfile;
$csvreporting_defaults=[
'dwlextpage'=>'https://hey.com/csv.csv',
'dwlfilters'=>['row'=>['line']],
'title'=>'Csv Reporting',
'heading'=>'Csv Reporting System',
'editortitle'=>'CSV Rules Editor',
'editorheading'=>'CSV Rules Editor',
'icon'=>'https://raw.githubusercontent.com/florianthepro/csv-reporting/main/index.svg',
'editoricon'=>'https://raw.githubusercontent.com/florianthepro/csv-reporting/main/edit.svg',
'theme'=>'auto'
];
$csvreporting_settings=$csvreporting_defaults;
if(is_readable($csvreporting_jsondir)){
$csvreporting_boot_raw=@file_get_contents($csvreporting_jsondir);
$csvreporting_boot=@json_decode((string)$csvreporting_boot_raw,true);
if(is_array($csvreporting_boot)&&isset($csvreporting_boot['app_settings'])&&is_array($csvreporting_boot['app_settings'])){
foreach($csvreporting_defaults as $csvreporting_key=>$csvreporting_default){
if(array_key_exists($csvreporting_key,$csvreporting_boot['app_settings']))$csvreporting_settings[$csvreporting_key]=$csvreporting_boot['app_settings'][$csvreporting_key];
}
}
}
$csvreporting_dwlextpage=is_string($csvreporting_settings['dwlextpage'])?trim($csvreporting_settings['dwlextpage']):$csvreporting_defaults['dwlextpage'];
$csvreporting_dwlfilters=is_array($csvreporting_settings['dwlfilters'])?$csvreporting_settings['dwlfilters']:$csvreporting_defaults['dwlfilters'];
$csvreporting_title=is_string($csvreporting_settings['title'])?$csvreporting_settings['title']:$csvreporting_defaults['title'];
$csvreporting_heading=is_string($csvreporting_settings['heading'])?$csvreporting_settings['heading']:$csvreporting_defaults['heading'];
$csvreporting_editortitle=is_string($csvreporting_settings['editortitle'])?$csvreporting_settings['editortitle']:$csvreporting_defaults['editortitle'];
$csvreporting_editorheading=is_string($csvreporting_settings['editorheading'])?$csvreporting_settings['editorheading']:$csvreporting_editortitle;
$csvreporting_icon=is_string($csvreporting_settings['icon'])?$csvreporting_settings['icon']:$csvreporting_defaults['icon'];
$csvreporting_editoricon=is_string($csvreporting_settings['editoricon'])?$csvreporting_settings['editoricon']:$csvreporting_defaults['editoricon'];
$csvreporting_theme=in_array($csvreporting_settings['theme'],['auto','light','dark'],true)?$csvreporting_settings['theme']:'auto';
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
if(!is_string($csvreporting_page)||!in_array($csvreporting_page,['index','edit','map','raw','doku'],true))$csvreporting_page='index';
if($csvreporting_page==='edit'&&!$csvreporting_edit_enabled){
header('Content-Type: text/html; charset=UTF-8');
$csvreporting_lock_theme=in_array($csvreporting_theme,['light','dark'],true)?$csvreporting_theme:'auto';
$csvreporting_export_name=pathinfo(__FILE__,PATHINFO_FILENAME).'-config.json';
?>
<!doctype html><html lang="de"<?php echo $csvreporting_lock_theme==='auto'?'':' data-theme="'.htmlspecialchars($csvreporting_lock_theme,ENT_QUOTES,'UTF-8').'"';?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Konfiguration exportieren</title><style>:root{--bg:#fff;--fg:#151515;--muted:#656b73;--panel:#f5f6f8;--border:#d7d9de;--accent:#1769e0}:root[data-theme="dark"]{--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--panel:#17191d;--border:#30343a;--accent:#72a7ff}@media(prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="dark"]){--bg:#0f1113;--fg:#e8e8ea;--muted:#a6adb6;--panel:#17191d;--border:#30343a;--accent:#72a7ff}}body{font-family:Arial,Helvetica,sans-serif;margin:24px;max-width:900px;background:var(--bg);color:var(--fg)}button{padding:7px 12px;background:var(--panel);color:var(--fg);border:1px solid var(--border);border-radius:3px;cursor:pointer}a{color:var(--accent)}</style></head><body><h1>Editor gesperrt</h1><p>Die Konfiguration kann weiterhin als JSON exportiert werden.</p><button type="button" onclick="exportConfig()">Export</button><p><a href="./">Zur Startseite</a></p><script>function exportConfig(){fetch(<?php echo json_encode($csvreporting_jsonfile);?>,{cache:'no-store'}).then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.text()}).then(t=>{JSON.parse(t);let b=new Blob([t],{type:'application/json'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=<?php echo json_encode($csvreporting_export_name);?>;a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000)}).catch(e=>alert('Export fehlgeschlagen: '+e.message))}</script></body></html><?php
exit;
}
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
  if($col===''||$pattern==='')return false;

  $idx=col_index($header,$col);
  if($idx===null)return false;
  $val=isset($row[$idx])?$row[$idx]:'';

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
    if(!rule_matches($rule,$header,$row,$timestampCols))continue;
    $generated=(string)($rule['description']??'Rule '.($idx+1));
    $alternative=trim((string)($rule['alternative_title']??''));
    $data=[];$seen=[];
    foreach(($rule['conditions']??[]) as $condition){
      $column=trim((string)($condition['column']??''));
      $key=strtolower($column);
      if($column===''||isset($seen[$key]))continue;
      $seen[$key]=true;
      $value=cell_by_colname($header,$row,$column);
      $minutes=timestamp_to_minutes($value);
      $data[]=['column'=>$column,'value'=>$minutes!==null?intval($minutes).' min':(trim((string)$value)===''?'n/a':(string)$value)];
    }
    $matched[]=[
      'idx'=>$idx,
      'desc'=>$generated,
      'title'=>$alternative!==''?$alternative:$generated,
      'notes'=>(string)($rule['overlay_notes']??''),
      'data'=>$data
    ];
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
<meta name="color-scheme" content="light dark">
<link rel="icon" type="image/svg+xml" href="<?=$csvreporting_icon?>">
<title><?=$csvreporting_title?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
table{border-collapse:collapse;width:100%;background:var(--bg)}
th,td{border:1px solid var(--border);padding:6px 8px;text-align:left;background:var(--bg);color:var(--fg)}
th{background:var(--panelhead);color:var(--fg)}
.error{color:var(--danger);font-weight:bold;margin-bottom:8px}
.fk-menu-btn{position:fixed;top:10px;right:10px;z-index:999999;background:var(--ctrl-bg);color:var(--ctrl-fg);border:none;padding:10px 14px;font-size:20px;cursor:pointer;border-radius:4px}
.fk-menu-overlay{position:fixed;inset:0;display:none;z-index:999998}
.fk-menu-overlay.is-visible{display:block}
.fk-menu-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55)}
.fk-menu-panel{position:absolute;top:0;right:0;width:260px;height:100%;background:var(--panel);padding:20px;box-sizing:border-box;transform:translateX(100%);transition:transform .25s ease-out}
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
.rules-overlay-item:last-child{border-bottom:none}.rules-overlay-item{cursor:pointer}.rules-overlay-item:hover{color:var(--accent)}.rule-detail-overlay{position:fixed;inset:0;display:none;z-index:10001;background:rgba(0,0,0,.72);padding:24px;box-sizing:border-box}.rule-detail-overlay.visible{display:flex;align-items:center;justify-content:center}.rule-detail-panel{width:min(1040px,100%);max-height:100%;overflow:auto;background:var(--panel);color:var(--fg);border:1px solid var(--border);border-radius:6px;padding:22px;box-shadow:0 12px 40px var(--shadow)}.rule-detail-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding-bottom:14px;border-bottom:1px solid var(--border)}.rule-detail-title{font-size:20px;font-weight:bold;line-height:1.4;overflow-wrap:anywhere}.rule-detail-close{border:0;background:transparent;color:var(--fg);font-size:28px;line-height:1;padding:0;cursor:pointer}.rule-detail-section{margin-top:18px}.rule-detail-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:bold;margin-bottom:7px}.rule-detail-box{padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:4px;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}.rule-detail-data{display:grid;grid-template-columns:minmax(140px,auto) 1fr;gap:7px 16px}.rule-detail-name{font-weight:bold;color:var(--muted)}@media(max-width:600px){.rule-detail-overlay{padding:8px}.rule-detail-panel{padding:16px}.rule-detail-data{grid-template-columns:1fr}}
.filter-toggle{background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:10px;margin-bottom:12px;cursor:pointer;user-select:none;display:flex;justify-content:space-between;align-items:center;color:var(--fg)}
.filter-toggle:hover{background:var(--panel2)}
.filter-toggle-icon{font-weight:bold;color:var(--accent)}
.filter-row{background:var(--panel);padding:12px;margin-bottom:12px;border-radius:3px;border:1px solid var(--border);display:none}
.filter-row.visible{display:block}
.filter-item{margin-bottom:10px}
.filter-item:last-child{margin-bottom:0}
.filter-row select{padding:4px;border:1px solid var(--border);border-radius:3px;font-size:13px;width:100%;box-sizing:border-box;background:var(--bg);color:var(--fg)}
.filter-label{font-size:12px;font-weight:bold;margin-bottom:4px;color:var(--muted)}
.index-topline{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;min-height:44px;margin-bottom:10px}.index-topline h2{grid-column:1;margin:0;text-align:left}.top-inline-nav{grid-column:2;display:flex;align-items:center;justify-content:center;gap:18px}.top-inline-nav a,.top-inline-nav button{padding:0;border:0;background:transparent;color:var(--fg);font:inherit;font-size:14px;text-decoration:none;cursor:pointer}.top-inline-nav a:hover,.top-inline-nav button:hover,.top-inline-nav .active{color:var(--accent);text-decoration:underline;text-underline-offset:4px}</style>
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
<button type="button" class="fk-menu-btn" id="fkMenuOpen" aria-label="Menü öffnen">☰</button>
<div class="fk-menu-overlay" id="fkMenuOverlay" aria-hidden="true">
<div class="fk-menu-backdrop" data-menu-close></div>
<aside class="fk-menu-panel" aria-label="Seitennavigation">
<button type="button" class="fk-menu-close" data-menu-close aria-label="Menü schließen">×</button>
<nav class="fk-menu-nav">
<a class="fk-menu-link" href="?_page=index">Index</a>
<a class="fk-menu-link" href="?_page=map">Map</a>
<a class="fk-menu-link" href="?_page=raw">Raw</a>
<a class="fk-menu-link" href="?_page=doku">Doku</a>
<a class="fk-menu-link" href="?_page=edit">Edit</a>
</nav>
</aside>
</div>
<div class="index-topline"><h2><?=$csvreporting_heading?></h2></div>

<div class="rules-overlay" id="rulesOverlay">
<div class="rules-overlay-title">Zutreffende Regeln:</div>
<div id="rulesContent"></div>
</div>
<div class="rule-detail-overlay" id="ruleDetailOverlay" aria-hidden="true"><div class="rule-detail-panel" role="dialog" aria-modal="true" aria-labelledby="ruleDetailTitle"><div class="rule-detail-header"><div class="rule-detail-title" id="ruleDetailTitle"></div><button type="button" class="rule-detail-close" onclick="closeRuleDetail()" aria-label="Schließen">×</button></div><section class="rule-detail-section"><div class="rule-detail-label">Regel</div><div class="rule-detail-box" id="ruleDetailRule"></div></section><section class="rule-detail-section" id="ruleDetailNotesSection"><div class="rule-detail-label">Notiz</div><div class="rule-detail-box" id="ruleDetailNotes"></div></section><section class="rule-detail-section"><div class="rule-detail-label">Relevante CSV-Daten</div><div class="rule-detail-box rule-detail-data" id="ruleDetailData"></div></section></div></div>


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
(function(){var open=document.getElementById('fkMenuOpen'),overlay=document.getElementById('fkMenuOverlay');if(!open||!overlay)return;function setMenu(show){overlay.classList.toggle('is-visible',show);overlay.setAttribute('aria-hidden',show?'false':'true');}open.addEventListener('click',function(){setMenu(true);});overlay.querySelectorAll('[data-menu-close]').forEach(function(el){el.addEventListener('click',function(){setMenu(false);});});document.addEventListener('keydown',function(e){if(e.key==='Escape')setMenu(false);});})();
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
function showRuleDetail(rule){
var overlay=document.getElementById('ruleDetailOverlay');
document.getElementById('ruleDetailTitle').textContent=rule.title||rule.desc||'';
document.getElementById('ruleDetailRule').textContent=rule.desc||'';
var notes=rule.notes||'';
document.getElementById('ruleDetailNotes').textContent=notes;
document.getElementById('ruleDetailNotesSection').style.display=notes.trim()===''?'none':'';
var data=document.getElementById('ruleDetailData');data.innerHTML='';
(rule.data||[]).forEach(function(entry){var name=document.createElement('div');name.className='rule-detail-name';name.textContent=entry.column;var value=document.createElement('div');value.textContent=entry.value;data.appendChild(name);data.appendChild(value);});
overlay.classList.add('visible');overlay.setAttribute('aria-hidden','false');document.documentElement.style.overflow='hidden';
}
function closeRuleDetail(){var overlay=document.getElementById('ruleDetailOverlay');overlay.classList.remove('visible');overlay.setAttribute('aria-hidden','true');document.documentElement.style.overflow='';}
function showRulesOverlay(event,rules){
var overlay=document.getElementById('rulesOverlay');var content=document.getElementById('rulesContent');content.innerHTML='';
rules.forEach(function(rule){var item=document.createElement('div');item.className='rules-overlay-item';var text=rule.title||rule.desc||'';item.textContent=text.length>60?text.substring(0,57)+'...':text;item.tabIndex=0;item.setAttribute('role','button');item.onclick=function(e){e.stopPropagation();overlay.classList.remove('visible');showRuleDetail(rule);};item.onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();item.click();}};content.appendChild(item);});
var x=Math.max(8,Math.min(event.clientX+10,window.innerWidth-420));var y=Math.max(8,Math.min(event.clientY+10,window.innerHeight-320));overlay.style.left=x+'px';overlay.style.top=y+'px';overlay.classList.add('visible');
setTimeout(function(){document.addEventListener('click',function hideOverlay(e){if(!overlay.contains(e.target)&&e.target!==event.target){overlay.classList.remove('visible');document.removeEventListener('click',hideOverlay);}});},0);
}
document.getElementById('ruleDetailOverlay').addEventListener('click',function(e){if(e.target===this)closeRuleDetail();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&document.getElementById('ruleDetailOverlay').classList.contains('visible'))closeRuleDetail();});
</script>
</body>
</html>

CSVREPORTING_INDEX_ROUTE;
eval($csvreporting_route);
exit;
}
if($csvreporting_page==='edit'){
$csvreporting_route=<<<'CSVREPORTING_EDIT_ROUTE'
$default=['header_line'=>'','show_columns'=>[],'rules'=>[],'column_renames'=>[],'enable_links'=>false,'column_links'=>[],'app_settings'=>$csvreporting_defaults];
$load_error='';$save_msg='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save'){
$incoming=$_POST['json']??'';$decoded=json_decode($incoming,true);
if($decoded===null&&trim($incoming)!==''){$save_msg='Fehler: Ungültiges JSON.';$parsed=$default;}
else{if(!is_array($decoded))$decoded=$default;
if(!isset($decoded['rules'])||!is_array($decoded['rules']))$decoded['rules']=[];
if(!isset($decoded['show_columns'])||!is_array($decoded['show_columns']))$decoded['show_columns']=[];
if(!isset($decoded['column_renames'])||!is_array($decoded['column_renames']))$decoded['column_renames']=[];
if(!isset($decoded['column_links'])||!is_array($decoded['column_links']))$decoded['column_links']=[];
if(!isset($decoded['app_settings'])||!is_array($decoded['app_settings']))$decoded['app_settings']=$csvreporting_defaults;
$ok=@file_put_contents($csvreporting_jsondir,json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
$save_msg=$ok===false?'Fehler beim Speichern.':'Gespeichert.';$parsed=$decoded;}}
if(!isset($parsed)){
$raw=@file_get_contents($csvreporting_jsondir);
if($raw===false){$parsed=$default;$load_error=is_file($csvreporting_jsondir)?'data.json ist vorhanden, kann vom Webserver aber nicht gelesen werden: '.$csvreporting_jsondir:'data.json wurde nicht neben index.php gefunden: '.$csvreporting_jsondir;}else{$parsed=json_decode((string)$raw,true);if(!is_array($parsed)){$parsed=$default;$load_error='data.json ist ungültig: '.json_last_error_msg().' – Standardwerte wurden nur im Editor geladen und nicht geschrieben.';}}}
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
$app_settings=is_array($parsed['app_settings']??null)?array_replace($csvreporting_defaults,$parsed['app_settings']):$csvreporting_defaults;$parsed['app_settings']=$app_settings;
$data_json=json_encode($parsed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$columns_json=json_encode(array_values($cols),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$csvreporting_target_dir=dirname($csvreporting_jsondir);
$csvreporting_json_writable=is_file($csvreporting_jsondir)?is_writable($csvreporting_jsondir):is_writable($csvreporting_target_dir);
$csvreporting_lock_command="sudo sed -i 's/\$csvreporting_edit_enabled=true;/\$csvreporting_edit_enabled=false;/' index.php";
$csvreporting_permission_command="sudo bash -c ".chr(39).'WEBROOT=/var/www/html; USER=www-data; sudo chown -R $USER:$USER "$WEBROOT" && sudo chmod -R u+rwX,g+rwX,o-rwx "$WEBROOT"'.chr(39);
?>
<!doctype html>
<?php $__th=(isset($csvreporting_theme)&&in_array($csvreporting_theme,['light','dark'],true))?$csvreporting_theme:'auto'; ?><html lang="de"<?= $__th==='light'?' data-theme="light"':($__th==='dark'?' data-theme="dark"':'') ?>>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<title><?=$csvreporting_editortitle?></title>
<link rel="icon" href="<?=$csvreporting_editoricon?>"><style>
body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}
h1,h2{margin:0 0 12px 0}
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
.info{font-size:12px;margin-top:4px;color:var(--muted)}.admin-hint{margin:12px 0;padding:12px;border:1px solid var(--border);border-radius:4px;background:var(--panel)}.admin-hint.warning{border-color:var(--danger);background:var(--danger-bg);color:var(--danger-fg)}.admin-hint code{display:block;margin-top:7px;padding:8px;background:var(--bg);color:var(--fg);border:1px solid var(--border);white-space:pre-wrap;overflow-wrap:anywhere}.admin-hint button{margin-top:7px}
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
button:disabled{opacity:.62;cursor:not-allowed;color:var(--muted);background:var(--panel2)}
a{color:var(--link)}
*{box-sizing:border-box}body{margin:0;padding:0}.ed-side{position:fixed;inset:0 auto 0 0;width:220px;background:var(--panel);border-right:1px solid var(--border);padding:10px;z-index:1000}.ed-side.closed{width:58px}.ed-side.closed .ed-text{display:none}.ed-toggle{width:36px}.ed-nav{margin-top:14px}.ed-nav button,.ed-nav a{display:flex;width:100%;padding:9px;border:0;background:transparent;color:var(--fg);text-decoration:none;text-align:left;border-radius:4px}.ed-nav button.active{background:var(--accent);color:var(--accent-fg)}.ed-main{margin-left:220px;padding:18px}.ed-side.closed~.ed-main{margin-left:58px}.ed-head{display:flex;justify-content:space-between;align-items:center}.ed-head-actions{display:flex;gap:14px;align-items:center}.ed-head-actions>div{display:flex;gap:7px;align-items:center}.section-title-row{display:flex;align-items:center;justify-content:space-between;gap:12px}.settings-title-actions{display:flex;align-items:center;gap:6px}.editor-home{font-size:13px;color:var(--muted)!important;text-decoration:none!important;white-space:nowrap}.editor-home:hover{color:var(--accent)!important;text-decoration:underline!important}.config-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px}.filter-rule{display:grid;grid-template-columns:minmax(170px,1fr) minmax(170px,1fr) auto;gap:10px;align-items:start;margin-top:10px;padding:10px;border:1px solid var(--border);border-radius:4px;background:var(--bg)}.filter-rule-field{display:grid;gap:6px}.filter-rule-field>span{font-size:11px;font-weight:bold;color:var(--muted)}.filter-rule input,.filter-rule select{width:100%;min-height:32px}.filter-rule-remove{align-self:end;min-width:34px;min-height:32px;font-size:18px;line-height:1}.filter-rule-field input[style*="display: none"]{display:none!important}.column-config,.column-config-head{display:grid;grid-template-columns:minmax(160px,1fr) minmax(160px,1fr) minmax(220px,2fr);gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border)}@media(max-width:750px){.config-grid,.filter-rule,.column-config,.column-config-head{grid-template-columns:1fr}.column-config-head{display:none}}.ed-pane{display:none}.ed-pane.active{display:block}.ed-card{margin-top:18px;padding:18px;background:var(--panel);border:1px solid var(--border);border-radius:4px}.ed-save{margin-top:14px}.ed-side{display:none!important}.ed-main,.ed-side.closed~.ed-main{margin-left:0}.fk-menu-btn{display:block!important}.fk-menu-overlay{display:none}.fk-menu-overlay.is-visible{display:block}table th:nth-child(1){width:55px}table th:nth-child(2){width:230px}td:nth-child(2) button{margin-right:4px}@media(max-width:700px){.ed-side{width:58px}.ed-side .ed-text{display:none}.ed-main{margin-left:58px;padding:10px}}.ed-head{position:relative;justify-content:center!important;min-height:44px;padding-left:0!important;gap:14px}.ed-head .fk-menu-btn{display:block!important;position:static!important;top:auto!important;left:auto!important;padding:8px 12px!important;font-size:14px!important}.ed-head h1{margin:0;text-align:center}.ed-head-actions{position:absolute;right:0}.rules-table-layout{table-layout:auto}.rules-table-layout th,.rules-table-layout td{text-align:left}.rules-table-layout th:first-child,.rules-table-layout td:first-child{width:52px;white-space:nowrap}.rules-table-layout th:nth-child(2),.rules-table-layout td:nth-child(2){width:1%;white-space:nowrap}.rules-table-layout th:nth-child(3),.rules-table-layout td:nth-child(3){width:auto}.ed-head{display:grid!important;grid-template-columns:1fr auto 1fr!important;align-items:center!important;min-height:44px;padding-left:0!important;gap:12px}.ed-head h1{grid-column:1;margin:0!important;text-align:left!important}.ed-head .top-inline-nav{grid-column:2}.ed-head-actions{grid-column:3;position:static!important;justify-self:end}.top-menu-toggle{padding:7px 12px;border:1px solid var(--border);border-radius:3px;background:var(--panel);color:var(--fg);font-size:14px;cursor:pointer}.top-menu-toggle:hover{color:var(--accent);border-color:var(--accent)}.rule-title{font-weight:600;cursor:pointer}.rule-title:hover{text-decoration:underline;text-underline-offset:3px}.edit-rule-detail{width:min(860px,calc(100vw - 32px))}.edit-rule-detail-grid{display:grid;grid-template-columns:150px 1fr;gap:8px 14px}.edit-rule-detail-label{font-weight:bold;color:var(--muted)}.edit-rule-detail-value{white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:600px){.edit-rule-detail-grid{grid-template-columns:1fr}}.rule-title.normal{color:var(--success)}.rule-title.inactive{color:var(--warn)}.rule-title.custom{color:var(--accent)}.info-pill{padding:2px 9px!important;border:1px solid var(--accent)!important;border-radius:999px!important;background:transparent!important;color:var(--accent)!important;font-weight:bold}.info-pill:hover{background:var(--accent)!important;color:var(--accent-fg)!important}#rule-modal .modal{width:min(1180px,calc(100vw - 32px));max-width:none}.rule-modal-actions{margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}.rule-modal-actions-right{display:flex;gap:6px}.help-legend{display:grid;gap:8px;margin:0 0 14px}.help-legend-row{display:flex;align-items:center;gap:9px}.help-color{width:12px;height:12px;border-radius:50%;flex:0 0 12px}.help-color.green{background:var(--success)}.help-color.blue{background:var(--accent)}.help-color.yellow{background:var(--warn)}.help-rule-logic{padding:10px 12px;border:1px solid var(--border);border-radius:4px;background:var(--bg);line-height:1.5}.ed-side{display:none!important}.ed-main,.ed-side.closed~.ed-main{margin-left:0!important}.edit-inline-nav{display:flex;align-items:center;justify-content:center;gap:20px;white-space:nowrap}.edit-inline-nav a,.edit-inline-nav a:visited,.edit-inline-nav a:hover,.edit-inline-nav a:active,.edit-inline-nav button,.edit-inline-nav button:hover,.edit-inline-nav button:active{display:inline!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:var(--fg)!important;text-decoration:none!important;box-shadow:none!important;font:inherit!important;font-weight:400!important;cursor:pointer}.edit-inline-nav a.active,.edit-inline-nav button.active{font-weight:700!important}.rules-table-wrap{width:100%;overflow-x:auto}.rules-table-layout{width:100%;min-width:760px;max-width:100%;table-layout:fixed}.rules-table-layout col.rule-col-number{width:56px}.rules-table-layout col.rule-col-actions{width:260px}.rules-table-layout col.rule-col-title{width:auto}.rules-table-layout th,.rules-table-layout td{text-align:left;vertical-align:top}.rules-table-layout th:nth-child(1),.rules-table-layout td:nth-child(1),.rules-table-layout th:nth-child(2),.rules-table-layout td:nth-child(2){white-space:nowrap}.rules-table-layout th:nth-child(3),.rules-table-layout td:nth-child(3){white-space:normal;overflow-wrap:anywhere}#rule-modal .modal{width:min(1180px,calc(100vw - 32px));max-width:none}@media(max-width:900px){.ed-head{grid-template-columns:1fr!important}.ed-head h1,.ed-head .edit-inline-nav,.ed-head-actions{grid-column:1!important;justify-self:start!important}.edit-inline-nav{flex-wrap:wrap;justify-content:flex-start}.rules-table-layout{min-width:700px}}</style>
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
<body><main class="ed-main"><div class="ed-head"><h1><?=$csvreporting_editorheading?></h1><nav class="top-inline-nav edit-inline-nav" aria-label="Editor-Bereiche"><button type="button" data-editor-section="rules">Rules</button><button type="button" data-editor-section="settings">Settings</button></nav><div class="ed-head-actions"><div id="settingsTopActions"><button type="button" onclick="openHelp('settings')">Hilfe</button><button type="submit" form="rules-form" class="primary"<?php if(!$csvreporting_json_writable)echo' disabled title="Konfigurationsdatei nicht schreibbar"';?>>Speichern</button></div><div id="ruleTopActions" style="display:none"><button type="button" onclick="openHelp('rules')">Hilfe</button><button type="submit" form="rules-form" class="primary"<?php if(!$csvreporting_json_writable)echo' disabled title="Konfigurationsdatei nicht schreibbar"';?>>Speichern</button></div><a href="./" class="editor-home">Zur Startseite</a></div></div>
<?php if(!$csvreporting_json_writable):?><div class="admin-hint warning"><strong>Speichern derzeit nicht möglich</strong><div>Die Konfigurationsdatei oder ihr Verzeichnis ist für den Webserver nicht schreibbar.</div><code id="permission-command"><?php echo h($csvreporting_permission_command);?></code><button type="button" onclick="copyAdminCommand('permission-command')">Befehl kopieren</button></div><?php endif;?>



<?php if($save_msg){?><div class="info"><?php echo h($save_msg);?></div><?php } ?>
<?php if($load_error){?><div class="error"><?php echo h($load_error);?></div><?php } ?>
<form method="post" id="rules-form" onkeydown="if(event.key==='Enter'&&event.target.tagName==='INPUT')event.preventDefault()">
<input type="hidden" name="action" value="save"><input type="hidden" name="json" id="rules-json"><input type="hidden" name="editor_section" id="editor-section" value="settings">

<div class="ed-pane active" data-editor-section-content="settings"><div class="ed-card">
<div class="section-title-row"><h2>Allgemein</h2><div class="settings-title-actions"><button type="button" onclick="document.getElementById('config-import-file').click()">Import</button><button type="button" onclick="exportConfig()">Export</button><input type="file" id="config-import-file" accept="application/json,.json" hidden onchange="importConfig(this)"></div></div>
<div class="filter-item"><div class="filter-label">CSV-Webadresse</div><input type="text" id="app-dwlextpage" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Ausgeschlossene CSV-Werte</div><div id="app-filter-rules"></div><button type="button" class="small" onclick="addFilterRule()">+ Ausschluss</button></div>
<div class="filter-item"><div class="filter-label">Seitentitel</div><input type="text" id="app-title" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Überschrift</div><input type="text" id="app-heading" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Editor-Titel</div><input type="text" id="app-editortitle" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Editor-Überschrift</div><input type="text" id="app-editorheading" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Index-Symbol URL</div><input type="text" id="app-icon" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Editor-Symbol URL</div><input type="text" id="app-editoricon" style="width:100%"></div>
<div class="filter-item"><div class="filter-label">Darstellung</div><select id="app-theme"><option value="auto">Automatisch</option><option value="light">Hell</option><option value="dark">Dunkel</option></select></div>

</div><div class="ed-card">
<h2>Tabellenspalten</h2>
<label style="display:block;margin:10px 0"><input type="checkbox" id="enable-links"<?php if($enable_links)echo' checked';?>> Links aktiv</label>
<div class="column-config-head"><strong>Spalte</strong><strong>Anzeigename</strong><strong>Link</strong></div><div><?php foreach($cols as $c):$hidden=!in_array($c,$show_columns,true);?><div class="column-config"><label><input type="checkbox" class="show-col-cb" value="<?php echo h($c);?>"<?php if($hidden)echo' checked';?>> <?php echo h($c);?></label><input type="text" class="column-rename-input" data-column="<?php echo h($c);?>" value="<?php echo h($column_renames[$c]??'');?>" placeholder="Anzeigename"><input type="text" class="column-link-input" data-column="<?php echo h($c);?>" value="<?php echo h($column_links[$c]??'');?>" placeholder="https://example.tld/ticket/*"></div><?php endforeach;?></div>
</div></div></div><div class="ed-pane" data-editor-section-content="rules"><div class="ed-card">
<div class="section-title-row"><h2>Regeln</h2><button type="button" class="primary" onclick="openRule(-1)">+ Regel</button></div>
<div class="rules-table-wrap"><table class="rules-table-layout"><colgroup><col class="rule-col-number"><col class="rule-col-actions"><col class="rule-col-title"></colgroup><thead><tr><th>#</th><th>Aktionen</th><th>Regel</th></tr></thead><tbody id="rules-body"></tbody></table></div>
</div></div>

</form></main>
<div class="modal-backdrop" id="rule-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Regel bearbeiten</div><button type="button" onclick="closeRule()" class="modal-close">×</button></div>
<label style="display:block"><span>Titel <small id="r-title-mode">Automatisch</small></span><input type="text" id="r-title" style="width:100%" placeholder="Leer lassen für automatischen Titel" oninput="updateTitleMode()"></label><label style="display:block;margin-top:8px">Notiz im Index-Overlay<textarea id="r-notes" rows="3"></textarea></label><label style="display:block;margin-top:8px">Interne Info<textarea id="r-info" rows="3"></textarea></label><label style="display:block;margin-top:8px">Bedingungen</label><div id="r-conds"></div><button type="button" class="small" onclick="addCond()" style="margin-top:6px">+ Bedingung</button><input type="hidden" id="r-desc">
<div class="rule-modal-actions"><button type="button" onclick="openHelp('edit')">Hilfe</button><div class="rule-modal-actions-right"><button type="button" onclick="closeRule()">Abbrechen</button><button type="button" class="primary" onclick="applyRule()">Übernehmen</button></div></div>
</div></div>
<div class="modal-backdrop" id="edit-detail-modal"><div class="modal edit-rule-detail"><div class="modal-header"><div class="modal-title" id="edit-detail-title">Regel</div><button type="button" onclick="closeEditRuleDetail()" class="modal-close">×</button></div><div class="edit-rule-detail-grid"><div class="edit-rule-detail-label">Status</div><div id="edit-detail-status"></div><div class="edit-rule-detail-label">Regel</div><div class="edit-rule-detail-value" id="edit-detail-rule"></div><div class="edit-rule-detail-label" id="edit-detail-overlay-label">Notiz im Index-Overlay</div><div class="edit-rule-detail-value" id="edit-detail-overlay"></div><div class="edit-rule-detail-label" id="edit-detail-info-label">Interne Info</div><div class="edit-rule-detail-value" id="edit-detail-info"></div></div></div></div>
<div class="modal-backdrop" id="info-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Info</div><button type="button" onclick="closeInfo()" class="modal-close">×</button></div>
<textarea id="info-text" style="width:100%;min-height:120px" placeholder="Optionaler Infotext zur Regel..."></textarea>
<div style="margin-top:10px;display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeInfo()">Schließen</button><button type="button" class="primary" onclick="saveInfo()">Speichern</button></div>
</div></div>
<div class="modal-backdrop" id="delete-modal"><div class="modal" style="width:min(440px,calc(100vw - 30px))"><div class="modal-header"><div class="modal-title">Regel löschen</div><button type="button" onclick="closeDeleteRule()" class="modal-close">×</button></div><div style="margin:8px 0 16px;line-height:1.5">Soll diese Regel wirklich gelöscht werden?</div><div style="display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeDeleteRule()">Abbrechen</button><button type="button" class="primary" onclick="confirmDeleteRule()">Löschen</button></div></div></div>
<div class="modal-backdrop" id="help-modal"><div class="modal"><div class="modal-header"><div class="modal-title" id="help-modal-title">Hilfe</div><button type="button" onclick="closeHelp()" class="modal-close">×</button></div>
<div class="help-context" data-help-context="edit"><div class="help-legend"><div class="help-legend-row"><span class="help-color green"></span><span>Grün: aktive Regel mit automatisch erzeugtem Titel</span></div><div class="help-legend-row"><span class="help-color blue"></span><span>Blau: aktive Regel mit individuellem Titel</span></div><div class="help-legend-row"><span class="help-color yellow"></span><span>Gelb: inaktive Regel</span></div></div><ul style="margin:14px 0 0;padding-left:18px;font-size:13px;line-height:1.5"><li><code>*</code> als Wildcard</li><li><code>n/a</code> für leer oder N/A</li><li><code>&gt;500</code>, <code>&gt;=500</code>, <code>&lt;500</code>, <code>&lt;=500</code></li><li><code>100..500</code> für Bereiche</li><li><code>500</code> für einen exakten Wert</li></ul></div>
<div class="help-context" data-help-context="rules"><div class="help-legend"><div class="help-legend-row"><span class="help-color green"></span><span>Grün: aktive Regel mit automatisch erzeugtem Titel</span></div><div class="help-legend-row"><span class="help-color blue"></span><span>Blau: aktive Regel mit individuellem Titel</span></div><div class="help-legend-row"><span class="help-color yellow"></span><span>Gelb: inaktive Regel</span></div></div><div class="help-rule-logic"><strong>Editor nach Abschluss wieder sperren</strong><code class="lock-command" style="display:block;margin-top:7px;white-space:pre-wrap"><?php echo h($csvreporting_lock_command);?></code><button type="button" onclick="copyLockCommand(this)" style="margin-top:7px">Befehl kopieren</button></div></div>
<div class="help-context" data-help-context="settings"><div class="help-rule-logic"><strong>Editor nach Abschluss wieder sperren</strong><code class="lock-command" style="display:block;margin-top:7px;white-space:pre-wrap"><?php echo h($csvreporting_lock_command);?></code><button type="button" onclick="copyLockCommand(this)" style="margin-top:7px">Befehl kopieren</button></div></div>
</div></div>
<script>
function copyAdminCommand(id){var el=document.getElementById(id);if(el&&navigator.clipboard)navigator.clipboard.writeText(el.textContent);}
var DATA=<?php echo $data_json;?>;var COLS=<?php echo $columns_json;?>;
if(!DATA||typeof DATA!=="object")DATA={};if(!Array.isArray(COLS))COLS=[];if(!Array.isArray(DATA.rules))DATA.rules=[];if(!Array.isArray(DATA.show_columns))DATA.show_columns=[];
if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};DATA.enable_links=!!DATA.enable_links;
var APP_DEFAULTS={dwlextpage:"https://hey.com/csv.csv",dwlfilters:{row:["line"]},title:"Csv Reporting",heading:"Csv Reporting System",editortitle:"CSV Rules Editor",editorheading:"CSV Rules Editor",icon:"https://raw.githubusercontent.com/florianthepro/csv-reporting/main/index.svg",editoricon:"https://raw.githubusercontent.com/florianthepro/csv-reporting/main/edit.svg",theme:"auto"};
if(!DATA.app_settings||typeof DATA.app_settings!=="object"||Array.isArray(DATA.app_settings))DATA.app_settings={};DATA.app_settings=Object.assign({},APP_DEFAULTS,DATA.app_settings);
var CSV_VALUES=<?php echo json_encode($csvMemoryRows??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);?>;var CSV_VALUE_MAP={};if(Array.isArray(CSV_VALUES)&&CSV_VALUES.length){let h=CSV_VALUES[0]||[];h.forEach((col,i)=>{CSV_VALUE_MAP[String(col)]=[...new Set(CSV_VALUES.slice(1).map(r=>String((r||[])[i]??"").trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,"de",{numeric:true}))})}
function filterSelect(options,value,onchange){let s=document.createElement("select");options.forEach(([v,t])=>{let o=document.createElement("option");o.value=v;o.textContent=t;s.appendChild(o)});if(options.some(x=>x[0]===value))s.value=value;else s.value="__custom__";if(onchange)s.addEventListener("change",onchange);return s}
function addFilterRule(column="",value=""){
let row=document.createElement("div"),cols=Object.keys(CSV_VALUE_MAP),initialColumn=String(column||""),initialValue=String(value||"");
row.className="filter-rule";
let cs=filterSelect([["","-- Spalte wählen --"],...cols.map(x=>[x,x]),["__custom__","Eigene Spalte"]],cols.includes(initialColumn)?initialColumn:(initialColumn?"__custom__":""));
let vs=filterSelect([["","-- Wert wählen --"],["__custom__","Eigener Wert"]],"");
let ci=document.createElement("input"),vi=document.createElement("input"),cf=document.createElement("label"),vf=document.createElement("label"),cl=document.createElement("span"),vl=document.createElement("span"),rm=document.createElement("button");
cf.className=vf.className="filter-rule-field";cl.textContent="Spalte";vl.textContent="Ausgeschlossener Wert";
ci.type=vi.type="text";ci.placeholder="Eigene Spalte eingeben";vi.placeholder="Eigenen Wert eingeben";
ci.value=cols.includes(initialColumn)?"":initialColumn;vi.value="";
rm.type="button";rm.className="small filter-rule-remove";rm.textContent="×";rm.title="Ausschluss entfernen";rm.setAttribute("aria-label","Ausschluss entfernen");rm.onclick=()=>row.remove();
cf.append(cl,cs,ci);vf.append(vl,vs,vi);row.append(cf,vf,rm);document.getElementById("app-filter-rules").appendChild(row);
function setValueOptions(preferred=""){
let selectedColumn=cs.value==="__custom__"?ci.value.trim():cs.value,vals=CSV_VALUE_MAP[selectedColumn]||[],wanted=String(preferred||"");
vs.innerHTML="";[["","-- Wert wählen --"],...vals.map(x=>[x,x]),["__custom__","Eigener Wert"]].forEach(([v,t])=>{let o=document.createElement("option");o.value=v;o.textContent=t;vs.appendChild(o)});
if(wanted&&vals.includes(wanted)){vs.value=wanted;vi.value=""}else if(wanted){vs.value="__custom__";vi.value=wanted}else{vs.value="";vi.value=""}
vi.style.display=vs.value==="__custom__"?"":"none";
}
function refreshColumn(){ci.style.display=cs.value==="__custom__"?"":"none";setValueOptions("")}
cs.addEventListener("change",refreshColumn);
ci.addEventListener("input",()=>{if(cs.value==="__custom__")setValueOptions(vi.value)});
vs.addEventListener("change",()=>{if(vs.value==="__custom__"){vi.style.display="";vi.focus()}else{vi.style.display="none";vi.value=""}});
ci.style.display=cs.value==="__custom__"?"":"none";setValueOptions(initialValue);
}
function readFilterRules(){let out={};document.querySelectorAll("#app-filter-rules .filter-rule").forEach(r=>{let selects=r.querySelectorAll("select"),inputs=r.querySelectorAll("input"),cs=selects[0],vs=selects[1],ci=inputs[0],vi=inputs[1];let c=cs.value==="__custom__"?ci.value.trim():cs.value,v=vs.value==="__custom__"?vi.value.trim():vs.value;if(c&&v)(out[c]||(out[c]=[])).push(v)});return out}
function loadAppSettings(){let a=DATA.app_settings;["dwlextpage","title","heading","editortitle","editorheading","icon","editoricon"].forEach(k=>{let el=document.getElementById("app-"+k);if(el)el.value=String(a[k]??APP_DEFAULTS[k])});document.getElementById("app-theme").value=["auto","light","dark"].includes(a.theme)?a.theme:"auto";let f=a.dwlfilters&&typeof a.dwlfilters==="object"?a.dwlfilters:{};Object.entries(f).forEach(([c,vals])=>(Array.isArray(vals)?vals:[vals]).forEach(v=>addFilterRule(c,String(v))));if(!Object.keys(f).length)addFilterRule()}

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
function ruleCols(r){let a=[],seen=new Set();(Array.isArray(r&&r.conditions)?r.conditions:[]).forEach(c=>{let v=String(c.column||"").trim();if(v&&!seen.has(v)){seen.add(v);a.push(v)}});a.sort((x,y)=>{let xi=COLS.indexOf(x),yi=COLS.indexOf(y);if(xi<0)xi=999999;if(yi<0)yi=999999;return xi-yi||x.localeCompare(y)});return a}
function effectiveTitle(r){return String((r&&r.alternative_title)||ruleDisplayTitle(r)||"")}
function ruleKey(r){return ruleCols(r).map(c=>String(COLS.indexOf(c)<0?999999:COLS.indexOf(c)).padStart(6,"0")+":"+c.toLowerCase()).join("|")}
function sortRules(){DATA.rules=DATA.rules.map((r,i)=>({r,i})).sort((a,b)=>{let ak=ruleKey(a.r),bk=ruleKey(b.r),ai=!ak,bi=!bk;if(ai!==bi)return ai?-1:1;return ak.localeCompare(bk,undefined,{numeric:true})||effectiveTitle(a.r).localeCompare(effectiveTitle(b.r),undefined,{numeric:true,sensitivity:"base"})||a.i-b.i}).map(x=>x.r)}
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
function updateTitleMode(){var i=document.getElementById("r-title"),m=document.getElementById("r-title-mode");if(i&&m)m.textContent=i.value.trim()?"Individuell":"Automatisch";}
function updateDescFromDom(){
var rows=document.querySelectorAll("#r-conds .condition-row");var list=[];
rows.forEach(r=>{var c=r.querySelector("select").value.trim();var p=r.querySelector('input[type=text]').value.trim();var n=r.querySelector('input[type=checkbox]').checked;if(c!==""&&p!=="")list.push({column:c,pattern:p,negate:n});});
document.getElementById("r-desc").value=buildAutoDescFromConditions(list)==="!..."?"":buildAutoDescFromConditions(list);
}
function sync(){
let a={};["dwlextpage","title","heading","editortitle","editorheading","icon","editoricon"].forEach(k=>a[k]=document.getElementById("app-"+k).value.trim()||APP_DEFAULTS[k]);a.dwlfilters=readFilterRules();a.theme=document.getElementById("app-theme").value;DATA.app_settings=a;
var v=[];document.querySelectorAll(".show-col-cb").forEach(x=>{if(!x.checked)v.push(x.value);});
DATA.show_columns=v;DATA.enable_links=document.getElementById("enable-links").checked;
if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};
DATA.column_renames={};document.querySelectorAll(".column-rename-input").forEach(x=>{let v=x.value.trim();if(v)DATA.column_renames[x.dataset.column]=v;});
DATA.column_links={};document.querySelectorAll(".column-link-input").forEach(x=>{let v=x.value.trim();if(v)DATA.column_links[x.dataset.column]=v;});
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
sortRules();
document.getElementById("rules-json").value=JSON.stringify(DATA);
}
function exportConfig(){sync();let text=document.getElementById("rules-json").value||JSON.stringify(DATA),blob=new Blob([JSON.stringify(JSON.parse(text),null,2)],{type:"application/json"}),a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="csv-reporting-config.json";a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);}
function importConfig(input){let file=input.files&&input.files[0];if(!file)return;let reader=new FileReader();reader.onload=function(){try{let value=JSON.parse(String(reader.result||""));if(!value||typeof value!=="object"||Array.isArray(value))throw new Error("JSON muss ein Objekt sein.");DATA=value;if(!Array.isArray(DATA.rules))DATA.rules=[];if(!Array.isArray(DATA.show_columns))DATA.show_columns=[];if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};DATA.enable_links=!!DATA.enable_links;if(!DATA.app_settings||typeof DATA.app_settings!=="object"||Array.isArray(DATA.app_settings))DATA.app_settings={};DATA.app_settings=Object.assign({},APP_DEFAULTS,DATA.app_settings);document.getElementById("app-filter-rules").innerHTML="";loadAppSettings();document.querySelectorAll(".show-col-cb").forEach(x=>x.checked=!DATA.show_columns.includes(x.value));document.getElementById("enable-links").checked=DATA.enable_links;document.querySelectorAll(".column-rename-input").forEach(x=>x.value=String(DATA.column_renames[x.dataset.column]||""));document.querySelectorAll(".column-link-input").forEach(x=>x.value=String(DATA.column_links[x.dataset.column]||""));render();alert("JSON importiert. Zum dauerhaften Speichern auf Speichern klicken.");}catch(e){alert("Import fehlgeschlagen: "+e.message)}input.value=""};reader.readAsText(file);}
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
var DELETE_RULE_INDEX=-1;
function deleteRule(i){DELETE_RULE_INDEX=i;document.getElementById("delete-modal").style.display="flex";}
function closeDeleteRule(){document.getElementById("delete-modal").style.display="none";DELETE_RULE_INDEX=-1;}
function confirmDeleteRule(){if(DELETE_RULE_INDEX<0)return closeDeleteRule();DATA.rules.splice(DELETE_RULE_INDEX,1);closeDeleteRule();render();}
function duplicateRule(i){var r=DATA.rules[i];if(!r||typeof r!=="object")return;var copy=JSON.parse(JSON.stringify(r));DATA.rules.splice(i+1,0,copy);render();}
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
let tb=document.getElementById("rules-body");tb.innerHTML="";let active=[],inactive=[];DATA.rules.forEach((r,i)=>{if(!r||typeof r!=="object")return;((Array.isArray(r.conditions)&&r.conditions.length)?active:inactive).push({r,i})});
active.sort((a,b)=>ruleKey(a.r).localeCompare(ruleKey(b.r),undefined,{numeric:true})||effectiveTitle(a.r).localeCompare(effectiveTitle(b.r),undefined,{numeric:true,sensitivity:"base"})||a.i-b.i);inactive.sort((a,b)=>effectiveTitle(a.r).localeCompare(effectiveTitle(b.r),undefined,{numeric:true,sensitivity:"base"})||a.i-b.i);
function row(x,key){let r=x.r,i=x.i,en=Array.isArray(r.conditions)&&r.conditions.length>0;if(en)r.description=buildAutoDescFromConditions(r.conditions);let tr=document.createElement("tr");if(key)tr.setAttribute("data-group-item",key);tr.innerHTML="<td>"+(i+1)+"</td>";let ac=document.createElement("td");let e=document.createElement("button");e.type="button";e.className="small";e.textContent="Edit";e.onclick=()=>openRule(i);let t=document.createElement("button");t.type="button";t.className="small";t.textContent=en?"Deaktivieren":"Aktivieren";t.onclick=()=>en?deactivateRule(i):activateRule(i);let d=document.createElement("button");d.type="button";d.className="small";d.textContent="X";d.onclick=()=>deleteRule(i);let cp=document.createElement("button");cp.type="button";cp.className="small";cp.textContent="Duplizieren";cp.onclick=()=>duplicateRule(i);ac.append(e,t,cp,d);if(r.info&&String(r.info).trim()!==""){let info=document.createElement("button");info.type="button";info.className="small info-pill";info.textContent="Info";info.onclick=()=>openInfo(i);ac.appendChild(info)}tr.appendChild(ac);let title=document.createElement("td");title.className="rule-title "+(!en?"inactive":(r.alternative_title&&String(r.alternative_title).trim()!==""?"custom":"normal"));title.textContent=effectiveTitle(r);title.onclick=()=>openEditRuleDetail(i);tr.appendChild(title);tb.appendChild(tr)}
inactive.forEach(x=>row(x,""));active.forEach(x=>row(x,""));}


let edit=-1;
let editWasInactive=false;
function openEditRuleDetail(i){let r=DATA.rules[i];if(!r||typeof r!=="object")return;let active=Array.isArray(r.conditions)&&r.conditions.length>0,conds=active?r.conditions:(Array.isArray(r.stash_conditions)?r.stash_conditions:[]);document.getElementById("edit-detail-title").textContent=effectiveTitle(r)||"Regel";document.getElementById("edit-detail-status").textContent=active?"Aktiv":"Inaktiv";document.getElementById("edit-detail-rule").textContent=conds.length?buildAutoDescFromConditions(conds):dec5(stripInactivePrefix(r.description||""));let notes=String(r.overlay_notes||""),info=String(r.info||"");document.getElementById("edit-detail-overlay").textContent=notes;document.getElementById("edit-detail-info").textContent=info;document.getElementById("edit-detail-overlay-label").style.display=document.getElementById("edit-detail-overlay").style.display=notes?"":"none";document.getElementById("edit-detail-info-label").style.display=document.getElementById("edit-detail-info").style.display=info?"":"none";document.getElementById("edit-detail-modal").style.display="flex";}
function closeEditRuleDetail(){document.getElementById("edit-detail-modal").style.display="none";}
function openRule(i){
edit=i;
let modal=document.getElementById("rule-modal");
let r=(i>=0&&DATA.rules[i]&&typeof DATA.rules[i]==="object")?DATA.rules[i]:{conditions:[]};
editWasInactive=i>=0&&(!Array.isArray(r.conditions)||r.conditions.length===0);
let c=document.getElementById("r-conds");c.innerHTML="";
var conds=editWasInactive&&Array.isArray(r.stash_conditions)?r.stash_conditions:(Array.isArray(r.conditions)?r.conditions:[]);
document.getElementById("r-title").value=typeof r.alternative_title==="string"?r.alternative_title:"";
document.getElementById("r-notes").value=typeof r.overlay_notes==="string"?r.overlay_notes:"";
document.getElementById("r-info").value=typeof r.info==="string"?r.info:"";
if(conds.length>0)conds.forEach(x=>addCond(x));else addCond();
updateDescFromDom();updateTitleMode();
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
let previous=edit>=0&&DATA.rules[edit]&&typeof DATA.rules[edit]==="object"?DATA.rules[edit]:{};
let autoDescription=buildAutoDescFromConditions(list);
let rule=Object.assign({},previous);
if(editWasInactive){
rule.conditions=[];
rule.stash_conditions=list;
rule.description="!..."+(autoDescription?(" "+enc5(autoDescription)):"");
}else{
rule.conditions=list;
rule.description=list.length?autoDescription:"!...";
}
let title=document.getElementById("r-title").value.trim(),notes=document.getElementById("r-notes").value.trim(),info=document.getElementById("r-info").value.trim();
if(title)rule.alternative_title=title;else delete rule.alternative_title;if(notes)rule.overlay_notes=notes;else delete rule.overlay_notes;if(info)rule.info=info;else delete rule.info;
if(edit>=0)DATA.rules[edit]=rule;else DATA.rules.push(rule);
closeRule();render();
}
function openHelp(context){context=context||"rules";document.querySelectorAll(".help-context").forEach(x=>x.style.display=x.dataset.helpContext===context?"":"none");document.getElementById("help-modal-title").textContent=context==="edit"?"Regel-Muster Hilfe":(context==="settings"?"Einstellungen Hilfe":"Regeln Hilfe");document.getElementById("help-modal").style.display="flex";}
function closeHelp(){document.getElementById("help-modal").style.display="none";}
function copyLockCommand(button){var el=button.parentElement.querySelector(".lock-command");if(el&&navigator.clipboard)navigator.clipboard.writeText(el.textContent);}
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
(function(){let ruleActions=document.getElementById("ruleTopActions"),settingsActions=document.getElementById("settingsTopActions"),sectionInput=document.getElementById("editor-section");function showSection(name){name=name==="rules"?"rules":"settings";document.querySelectorAll("[data-editor-section-content]").forEach(x=>x.classList.toggle("active",x.dataset.editorSectionContent===name));document.querySelectorAll("[data-editor-section]").forEach(x=>x.classList.toggle("active",x.dataset.editorSection===name));if(sectionInput)sectionInput.value=name;ruleActions.style.display=name==="rules"?"inline-flex":"none";settingsActions.style.display=name==="settings"?"inline-flex":"none";}document.querySelectorAll("[data-editor-section]").forEach(button=>button.addEventListener("click",function(){showSection(button.dataset.editorSection);}));showSection(<?php echo json_encode((string)($_POST['editor_section']??'settings'));?>);})();
loadAppSettings();
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
if($col===''||$pattern==='')return false;
$idx=col_index($header,$col);
if($idx===null)return false;
$val=isset($row[$idx])?$row[$idx]:'';
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
<meta name="color-scheme" content="light dark">
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
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8px"><h2 style="margin:0"><?=$csvreporting_heading?></h2><a href="./" style="font-size:13px;color:var(--muted);text-decoration:none;white-space:nowrap">Zur Startseite</a></div>
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
<meta name="color-scheme" content="light dark">
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
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8px"><h2 style="margin:0"><?=$csvreporting_heading?></h2><a href="./" style="font-size:13px;color:var(--muted);text-decoration:none;white-space:nowrap">Zur Startseite</a></div>
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
if($csvreporting_page==='doku'){
$csvreporting_route=<<<'CSVREPORTING_DOKU_ROUTE'
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Regeldokumentation</title>
<style>
:root{--bg:#fff;--fg:#151515;--muted:#656b73;--border:#d7d9de;--panel:#f5f6f8;--panel2:#eceef1;--accent:#1769e0;--green:#177245;--greenbg:#e8f5ed;--yellow:#806100;--yellowbg:#fff3c4;--red:#b3261e}@media(prefers-color-scheme:dark){:root{--bg:#101214;--fg:#eceef1;--muted:#a7adb5;--border:#30343a;--panel:#181b1f;--panel2:#22262c;--accent:#72a7ff;--green:#65d79a;--greenbg:#153626;--yellow:#e9ca58;--yellowbg:#3a3110;--red:#ff7870}}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;margin:18px;background:var(--bg);color:var(--fg)}.head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}.head h2{margin:0}.home{font-size:13px;color:var(--muted);text-decoration:none;white-space:nowrap}.home:hover{color:var(--accent);text-decoration:underline}.filter-toggle{background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:10px;margin-bottom:12px;cursor:pointer;display:flex;justify-content:space-between}.filter-toggle:hover{background:var(--panel2)}.filter{display:none;background:var(--panel);border:1px solid var(--border);border-radius:3px;padding:12px;margin-bottom:12px}.filter.visible{display:block}.filter-item{margin-bottom:10px}.filter-item:last-child{margin-bottom:0}.filter-label{font-size:12px;font-weight:bold;color:var(--muted);margin-bottom:4px}.filter select{width:100%;padding:5px 7px;border:1px solid var(--border);border-radius:3px;background:var(--bg);color:var(--fg);font:inherit}.rules{display:grid;gap:8px}.rule{border:1px solid var(--border);background:var(--bg)}.rule summary{list-style:none;display:grid;grid-template-columns:36px 1fr auto 18px;gap:10px;align-items:center;padding:10px 12px;background:var(--panel);cursor:pointer}.rule summary::-webkit-details-marker{display:none}.rule summary:after{content:'+';font-weight:bold;color:var(--accent)}.rule[open] summary:after{content:'−'}.number{font-size:12px;color:var(--muted)}.title{font-weight:bold;overflow-wrap:anywhere}.status{font-size:11px;font-weight:bold;border-radius:999px;padding:2px 8px}.active{color:var(--green);background:var(--greenbg)}.inactive{color:var(--yellow);background:var(--yellowbg)}.body{padding:8px 12px 12px}.condition{display:grid;grid-template-columns:minmax(140px,230px) 30px 1fr;gap:8px;padding:7px 0;border-bottom:1px solid var(--border)}.condition:last-child{border-bottom:0}.column{font-weight:bold}.operator{text-align:center;color:var(--accent);font-weight:bold}.pattern{font-family:Consolas,"Courier New",monospace;overflow-wrap:anywhere}.info{border-top:1px solid var(--border);padding-top:10px;margin-top:8px}.info-row{display:grid;grid-template-columns:150px 1fr;gap:10px;margin:6px 0}.info-label{font-size:12px;font-weight:bold;color:var(--muted)}.info-value{white-space:pre-wrap;overflow-wrap:anywhere}.empty,.error{padding:12px;border:1px solid var(--border);background:var(--panel)}.error{color:var(--red)}@media(max-width:650px){.condition,.info-row{grid-template-columns:1fr}.operator{text-align:left}.rule summary{grid-template-columns:30px 1fr 18px}.status{grid-column:2}.rule summary:after{grid-column:3;grid-row:1/3}}
</style></head><body>
<div class="head"><h2>Regeldokumentation</h2><a class="home" href="./">Zur Startseite</a></div>
<div class="filter-toggle" id="filterToggle"><span>Filter</span><span id="filterIcon">▼</span></div>
<div class="filter" id="filterPanel"></div><div class="rules" id="rules"></div>
<script>
const CONFIG={jsonFile:__CSVREPORTING_JSON_FILE__};let RULES=[];const $=s=>document.querySelector(s);const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function decode(v){return String(v??'').replace(/^!...\s*/,'').split('$$$$$≠$$$$$').join('≠').split('$$$$$=$$$$$').join('=').split('$$$$$&$$$$$').join('&').split('...&...').join(' & ').replace(/\.\.\./g,' ').replace(/\s+/g,' ').trim()}
function fieldLabel(k){return({alternative_title:'Individueller Titel',info:'Interne Notiz',overlay_notes:'Notiz im Index-Overlay',notes:'Notiz'}[k]||String(k).replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()))}
function isUserField(k,v){return !['description','title','name','conditions','stash_conditions'].includes(k)&&(typeof v==='string'||typeof v==='number'||typeof v==='boolean')}
function norm(r,i,cols){r=r&&typeof r==='object'?r:{};const raw=String(r.description??r.title??r.name??`Regel ${i+1}`),activeConditions=Array.isArray(r.conditions)?r.conditions:[],inactive=activeConditions.length===0,stash=Array.isArray(r.stash_conditions)?r.stash_conditions:[],conditions=inactive?stash:activeConditions,user={};Object.entries(r).forEach(([k,v])=>{if(isUserField(k,v)&&String(v).trim()!=='')user[k]=String(v)});const title=String(r.alternative_title||r.title||decode(raw)||`Regel ${i+1}`),used=[...new Set(conditions.map(c=>String(c?.column??c?.field??'').trim()).filter(Boolean))],order=used.map(c=>{let x=cols.indexOf(c);return x<0?999999:x}).sort((a,b)=>a-b).join('|');return{source:i+1,inactive,title,conditions,user,used,order}}
function render(r){const cond=r.conditions.length?r.conditions.map(c=>`<div class="condition"><div class="column">${esc(c.column??c.field??'Spalte')}</div><div class="operator">${c.negate?'≠':'='}</div><div class="pattern">${esc(c.pattern??c.value??'')}</div></div>`).join(''):'<div class="condition"><div class="pattern">Keine Bedingungen angegeben</div></div>';const entries=Object.entries(r.user),info=entries.length?`<div class="info">${entries.map(([k,v])=>`<div class="info-row"><div class="info-label">${esc(fieldLabel(k))}</div><div class="info-value">${esc(v)}</div></div>`).join('')}</div>`:'';return `<details class="rule"><summary><span class="number">#${r.source}</span><span class="title">${esc(r.title)}</span><span class="status ${r.inactive?'inactive':'active'}">${r.inactive?'Inaktiv':'Aktiv'}</span></summary><div class="body">${cond}${info}</div></details>`}
function addSelect(key,title,options){const item=document.createElement('div');item.className='filter-item';const lab=document.createElement('div');lab.className='filter-label';lab.textContent=title;const select=document.createElement('select');select.dataset.filter=key;select.innerHTML='<option value="">-- Alle --</option>'+options.map(([value,text])=>`<option value="${esc(value)}">${esc(text)}</option>`).join('');select.addEventListener('change',apply);item.append(lab,select);$('#filterPanel').appendChild(item)}
function valueOptions(key){const values=[...new Set(RULES.map(r=>String(r.user[key]??'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'de',{numeric:true,sensitivity:'base'}));return [['__na__','n/a'],...values.map(v=>[v,v])]}
function buildFilters(){const panel=$('#filterPanel');panel.innerHTML='';addSelect('status','Status',[['active','Aktiv'],['inactive','Inaktiv']]);const conditionColumns=[...new Set(RULES.flatMap(r=>r.conditions.map(c=>String(c?.column??c?.field??'').trim())).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'de',{numeric:true,sensitivity:'base'}));const conditionPatterns=[...new Set(RULES.flatMap(r=>r.conditions.map(c=>String(c?.pattern??c?.value??'').trim())).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'de',{numeric:true,sensitivity:'base'}));addSelect('condition_column','Bedingung: Spalte',conditionColumns.map(v=>[v,v]));addSelect('condition_operator','Bedingung: Operator',[['=','='],['≠','≠']]);addSelect('condition_pattern','Bedingung: Muster',conditionPatterns.map(v=>[v,v]));const standard=['alternative_title','info','overlay_notes'];standard.forEach(k=>addSelect(k,fieldLabel(k),valueOptions(k)));const extra=[...new Set(RULES.flatMap(r=>Object.keys(r.user)).filter(k=>!standard.includes(k)))];extra.forEach(k=>addSelect(k,fieldLabel(k),valueOptions(k)))}
function matches(r,key,value){if(key==='status')return value===(r.inactive?'inactive':'active');if(key==='condition_column')return r.conditions.some(c=>String(c?.column??c?.field??'').trim()===value);if(key==='condition_operator')return r.conditions.some(c=>(c?.negate?'≠':'=')===value);if(key==='condition_pattern')return r.conditions.some(c=>String(c?.pattern??c?.value??'').trim()===value);const actual=String(r.user[key]??'').trim();return value==='__na__'?actual==='':actual===value}
function apply(){const selected=[...document.querySelectorAll('[data-filter]')].filter(x=>x.value!=='');let visible=0;document.querySelectorAll('.rule').forEach((el,i)=>{const show=selected.every(f=>matches(RULES[i],f.dataset.filter,f.value));el.hidden=!show;if(show)visible++});let empty=$('#empty');if(!visible&&!empty){$('#rules').insertAdjacentHTML('beforeend','<div class="empty" id="empty">Keine Regeln entsprechen den Filtern.</div>');empty=$('#empty')}if(empty)empty.hidden=visible>0}
async function load(){try{const response=await fetch(CONFIG.jsonFile,{cache:'no-store'});if(!response.ok)throw new Error(`HTTP ${response.status}`);const json=await response.json(),source=Array.isArray(json)?json:Array.isArray(json.rules)?json.rules:[],columns=Array.isArray(json.show_columns)?json.show_columns:[];RULES=source.map((r,i)=>norm(r,i,columns));const inactive=RULES.filter(r=>r.inactive).sort((a,b)=>a.title.localeCompare(b.title,undefined,{numeric:true,sensitivity:'base'})||a.source-b.source),active=RULES.filter(r=>!r.inactive).sort((a,b)=>a.order.localeCompare(b.order,undefined,{numeric:true})||a.title.localeCompare(b.title,undefined,{numeric:true,sensitivity:'base'})||a.source-b.source);RULES=[...inactive,...active];buildFilters();$('#rules').innerHTML=RULES.map(render).join('');apply()}catch(error){$('#rules').innerHTML=`<div class="error">${esc(CONFIG.jsonFile)} konnte nicht geladen werden: ${esc(error.message)}</div>`}}
$('#filterToggle').addEventListener('click',()=>{const panel=$('#filterPanel');panel.classList.toggle('visible');$('#filterIcon').textContent=panel.classList.contains('visible')?'▲':'▼'});load();
</script></body></html>
CSVREPORTING_DOKU_ROUTE;
$csvreporting_route=str_replace('__CSVREPORTING_JSON_FILE__',json_encode($csvreporting_jsonfile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$csvreporting_route);
eval($csvreporting_route);exit;
}
http_response_code(404);
exit;
