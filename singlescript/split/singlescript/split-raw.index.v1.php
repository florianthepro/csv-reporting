<?php
declare(strict_types=1);
$csvreporting_jsonfile=__DIR__.'/../data.json';
if(!is_readable($csvreporting_jsonfile)){
http_response_code(500);
exit('data.json konnte nicht gelesen werden.');
}
$csvreporting_boot_raw=file_get_contents($csvreporting_jsonfile);
$csvreporting_data=json_decode((string)$csvreporting_boot_raw,true);
if(!is_array($csvreporting_data)){
http_response_code(500);
exit('data.json enthält kein gültiges JSON.');
}
$csvreporting_settings=$csvreporting_data['app_settings']??null;
if(!is_array($csvreporting_settings)){
http_response_code(500);
exit('app_settings fehlen in data.json.');
}
$csvreporting_dwlextpage=trim((string)($csvreporting_settings['dwlextpage']??''));
$csvreporting_dwlfilters=$csvreporting_settings['dwlfilters']??[];
$csvreporting_title=(string)($csvreporting_settings['title']??'');
$csvreporting_heading=(string)($csvreporting_settings['heading']??'');
$csvreporting_icon=(string)($csvreporting_settings['icon']??'');
$csvreporting_theme=(string)($csvreporting_settings['theme']??'');
if($csvreporting_dwlextpage===''){
http_response_code(500);
exit('dwlextpage fehlt in data.json.');
}
if(!is_array($csvreporting_dwlfilters)){
http_response_code(500);
exit('dwlfilters ist in data.json ungültig.');
}
if(!in_array($csvreporting_theme,['auto','light','dark'],true)){
http_response_code(500);
exit('theme ist in data.json ungültig.');
}
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

[, $csvreporting_live_message, $csvreporting_csv_memory]=csvreporting_remote_fetch($csvreporting_dwlextpage,$csvreporting_dwlfilters);
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
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
function check_filter($filterValue,$cellValue,$isTimestamp){
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
$config=$csvreporting_data;
$showColumns=$config['show_columns']??[];
$enableLinks=!empty($config['enable_links']);
$columnLinks=is_array($config['column_links']??null)?$config['column_links']:[];
$idColumn=null;
foreach($columnLinks as $k=>$v){if(trim((string)$v)==='*'){$idColumn=$k;break;}}
$csvRows=is_string($csvreporting_csv_memory)?csvreporting_parse_csv($csvreporting_csv_memory):null;
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
if(!check_filter($activeFilters[$col],$val,$isTS)){$passFilters=false;break;}
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
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8px"><h2 style="margin:0"><?=$csvreporting_heading?></h2><a href="../" onclick="this.href=csvFilterPageUrl()" style="font-size:13px;color:var(--muted);text-decoration:none;white-space:nowrap">Zur Startseite</a></div>
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
function csvFilterPageUrl(){
var target=new URL('../',window.location.href);
target.search='';
new URL(window.location.href).searchParams.forEach(function(value,key){
if(key.indexOf('filter_')===0)target.searchParams.append(key,value);
});
return target.toString();
}
</script>
</body>
</html>
