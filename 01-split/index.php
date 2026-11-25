<?php
declare(strict_types=1);
$csvreporting_jsonfile=__DIR__.'/data/config.json';
if(!is_readable($csvreporting_jsonfile)){
http_response_code(500);
exit('config.json konnte nicht gelesen werden.');
}
$csvreporting_boot_raw=file_get_contents($csvreporting_jsonfile);
$csvreporting_data=json_decode((string)$csvreporting_boot_raw,true);
if(!is_array($csvreporting_data)){
http_response_code(500);
exit('config.json enthält kein gültiges JSON.');
}
$csvreporting_settings=$csvreporting_data['app_settings']??null;
if(!is_array($csvreporting_settings)){
http_response_code(500);
exit('app_settings fehlen in config.json.');
}
$csvreporting_dwlextpage=trim((string)($csvreporting_settings['dwlextpage']??''));
$csvreporting_dwlfilters=$csvreporting_settings['dwlfilters']??[];
$csvreporting_title=(string)($csvreporting_settings['title']??'');
$csvreporting_heading=(string)($csvreporting_settings['heading']??'');
$csvreporting_icon=(string)($csvreporting_settings['icon']??'');
$csvreporting_theme=(string)($csvreporting_settings['theme']??'');
if($csvreporting_dwlextpage===''){
http_response_code(500);
exit('dwlextpage fehlt in config.json.');
}
if(!is_array($csvreporting_dwlfilters)){
http_response_code(500);
exit('dwlfilters ist in config.json ungültig.');
}
if(!in_array($csvreporting_theme,['auto','light','dark'],true)){
http_response_code(500);
exit('theme ist in config.json ungültig.');
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

function check_filter($filterValue,$cellValue,$isTimestamp){
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

$rulesData=$csvreporting_data;
$showColumns=$rulesData['show_columns']??[];
$rules=$rulesData['rules']??[];
$enableLinks=!empty($rulesData['enable_links']);
$columnLinks=is_array($rulesData['column_links']??null)?$rulesData['column_links']:[];
$idColumn=null;
foreach($columnLinks as $k=>$v){
  if(trim((string)$v)==='*'){ $idColumn=$k; break; }
}
$csvRows=is_string($csvreporting_csv_memory)?csvreporting_parse_csv($csvreporting_csv_memory):null;
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
$filtered=[];
if(count($ruleBaseRows)>0){
foreach($ruleBaseRows as $row){
$pass=true;
foreach($showColumns as $col){
$val=cell_by_colname($header,$row,$col);
$isTS=isset($timestampCols[$col]);
if(!check_filter($activeFilters[$col]??'',$val,$isTS)){$pass=false;break;}
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
<link rel="stylesheet" href="style.css">

</head>
<body>
<button type="button" class="fk-menu-btn" id="fkMenuOpen" aria-label="Menü öffnen">☰</button>
<div class="fk-menu-overlay" id="fkMenuOverlay" aria-hidden="true">
<div class="fk-menu-backdrop" data-menu-close></div>
<aside class="fk-menu-panel" aria-label="Seitennavigation">
<button type="button" class="fk-menu-close" data-menu-close aria-label="Menü schließen">×</button>
<nav class="fk-menu-nav">
<a class="fk-menu-link" href="./" onclick="this.href=csvFilterPageUrl('index')">Index</a>
<a class="fk-menu-link" href="./map/" onclick="this.href=csvFilterPageUrl('map')">Map</a>
<a class="fk-menu-link" href="./raw/" onclick="this.href=csvFilterPageUrl('raw')">Raw</a>
<a class="fk-menu-link" href="./doku/">Doku</a>
<a class="fk-menu-link" href="./edit/">Edit</a>
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
function csvFilterPageUrl(page){
var paths={index:'./',map:'./map/',raw:'./raw/'};
var target=new URL(paths[page]||'./',window.location.href);
target.search='';
new URL(window.location.href).searchParams.forEach(function(value,key){
if(key.indexOf('filter_')===0)target.searchParams.append(key,value);
});
return target.toString();
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
