<?php
declare(strict_types=1);
$csvreporting_jsonfile=__DIR__.'/../data/data.json';
$csvreporting_defaults=[
'dwlextpage'=>'https://hey.com/csv.csv',
'dwlfilters'=>['row'=>['line']],
'editortitle'=>'CSV Rules Editor',
'editorheading'=>'CSV Rules Editor',
'editoricon'=>'../favicon.svg',
'indexicon'=>'./favicon.svg', //need to be checked ($csvreporting_defaults)
'theme'=>'auto'
];
$csvreporting_settings=$csvreporting_defaults;
if(is_readable($csvreporting_jsonfile)){
$csvreporting_boot_raw=file_get_contents($csvreporting_jsonfile);
$csvreporting_boot=json_decode((string)$csvreporting_boot_raw,true);
if(is_array($csvreporting_boot)&&isset($csvreporting_boot['app_settings'])&&is_array($csvreporting_boot['app_settings'])){
foreach($csvreporting_defaults as $csvreporting_key=>$csvreporting_default){
if(array_key_exists($csvreporting_key,$csvreporting_boot['app_settings']))$csvreporting_settings[$csvreporting_key]=$csvreporting_boot['app_settings'][$csvreporting_key];
}
}
}
$csvreporting_dwlextpage=is_string($csvreporting_settings['dwlextpage'])?trim($csvreporting_settings['dwlextpage']):$csvreporting_defaults['dwlextpage'];
$csvreporting_dwlfilters=is_array($csvreporting_settings['dwlfilters'])?$csvreporting_settings['dwlfilters']:$csvreporting_defaults['dwlfilters'];
$csvreporting_editortitle=is_string($csvreporting_settings['editortitle'])?$csvreporting_settings['editortitle']:$csvreporting_defaults['editortitle'];
$csvreporting_editorheading=is_string($csvreporting_settings['editorheading'])?$csvreporting_settings['editorheading']:$csvreporting_editortitle;
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

[, $csvreporting_live_message, $csvreporting_csv_memory]=csvreporting_remote_fetch($csvreporting_dwlextpage,$csvreporting_dwlfilters);
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
$ok=@file_put_contents($csvreporting_jsonfile,json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
$save_msg=$ok===false?'Fehler beim Speichern.':'Gespeichert.';$parsed=$decoded;}}
if(!isset($parsed)){
$raw=@file_get_contents($csvreporting_jsonfile);
if($raw===false){$parsed=$default;$load_error=is_file($csvreporting_jsonfile)?'data.json ist vorhanden, kann vom Webserver aber nicht gelesen werden: '.$csvreporting_jsonfile:'data.json wurde nicht gefunden: '.$csvreporting_jsonfile;}else{$parsed=json_decode((string)$raw,true);if(!is_array($parsed)){$parsed=$default;$load_error='data.json ist ungültig: '.json_last_error_msg().' – Standardwerte wurden nur im Editor geladen und nicht geschrieben.';}}}
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
$csvreporting_target_dir=dirname($csvreporting_jsonfile);
$csvreporting_json_writable=is_file($csvreporting_jsonfile)?is_writable($csvreporting_jsonfile):is_writable($csvreporting_target_dir);
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
<body><main class="ed-main"><div class="ed-head"><h1><?=$csvreporting_editorheading?></h1><nav class="top-inline-nav edit-inline-nav" aria-label="Editor-Bereiche"><button type="button" data-editor-section="rules">Rules</button><button type="button" data-editor-section="settings">Settings</button></nav><div class="ed-head-actions"><div id="settingsTopActions"><button type="button" onclick="openHelp('settings')">Hilfe</button><button type="submit" form="rules-form" class="primary"<?php if(!$csvreporting_json_writable)echo' disabled title="Konfigurationsdatei nicht schreibbar"';?>>Speichern</button></div><div id="ruleTopActions" style="display:none"><button type="button" onclick="openHelp('rules')">Hilfe</button><button type="submit" form="rules-form" class="primary"<?php if(!$csvreporting_json_writable)echo' disabled title="Konfigurationsdatei nicht schreibbar"';?>>Speichern</button></div><a href="../" class="editor-home">Zur Startseite</a></div></div>
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
<div class="help-context" data-help-context="rules"><div class="help-legend"><div class="help-legend-row"><span class="help-color green"></span><span>Grün: aktive Regel mit automatisch erzeugtem Titel</span></div><div class="help-legend-row"><span class="help-color blue"></span><span>Blau: aktive Regel mit individuellem Titel</span></div><div class="help-legend-row"><span class="help-color yellow"></span><span>Gelb: inaktive Regel</span></div></div></div>
<div class="help-context" data-help-context="settings"></div>
</div></div>
<script>
function copyAdminCommand(id){var el=document.getElementById(id);if(el&&navigator.clipboard)navigator.clipboard.writeText(el.textContent);}
var DATA=<?php echo $data_json;?>;var COLS=<?php echo $columns_json;?>;
if(!DATA||typeof DATA!=="object")DATA={};if(!Array.isArray(COLS))COLS=[];if(!Array.isArray(DATA.rules))DATA.rules=[];if(!Array.isArray(DATA.show_columns))DATA.show_columns=[];
if(!DATA.column_renames||Array.isArray(DATA.column_renames))DATA.column_renames={};if(!DATA.column_links||Array.isArray(DATA.column_links))DATA.column_links={};DATA.enable_links=!!DATA.enable_links;
var APP_DEFAULTS={dwlextpage:"https://hey.com/csv.csv",dwlfilters:{row:["line"]},title:"Csv Reporting",heading:"Csv Reporting System",editortitle:"CSV Rules Editor",editorheading:"CSV Rules Editor",icon:"../favicon.svg",editoricon:"./favicon.svg",theme:"auto"};
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
