<?php //read and write         ?_page=edit
declare(strict_types=1);
///////////////////////
$csvreporting_dwlextpage = 'https://hey.com/csv.csv'; #http<und/oder"s">s://domain.tld/csv.csv<oder"index.php">
$csvreporting_dwlfilters=['row'=>['line'],];
$csvreporting_title='Csv Reporting';
$csvreporting_jsonfile='csv-reporting.json';
$csvreporting_jsondir=__DIR__.DIRECTORY_SEPARATOR.$csvreporting_jsonfile;
$csvreporting_editortitle='CSV Rules Editor';
$csvreporting_editorheading=$csvreporting_editortitle;
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

$csvreporting_page=$_GET['_page']??'edit';
if(!is_string($csvreporting_page)||!in_array($csvreporting_page,['edit'],true))$csvreporting_page='edit';
unset($_GET['_page'],$_REQUEST['_page']);
if(in_array($csvreporting_page,['edit'],true)){[$csvreporting_live_ok,$csvreporting_live_message,$csvreporting_csv_memory]=csvreporting_remote_fetch($csvreporting_dwlextpage,$csvreporting_dwlfilters);}

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
*{box-sizing:border-box}body{margin:0;padding:0}.ed-side{position:fixed;inset:0 auto 0 0;width:220px;background:var(--panel);border-right:1px solid var(--border);padding:10px;z-index:1000}.ed-side.closed{width:58px}.ed-side.closed .ed-text{display:none}.ed-toggle{width:36px}.ed-nav{margin-top:14px}.ed-nav button,.ed-nav a{display:flex;width:100%;padding:9px;border:0;background:transparent;color:var(--fg);text-decoration:none;text-align:left;border-radius:4px}.ed-nav button.active{background:var(--accent);color:var(--accent-fg)}.ed-main{margin-left:220px;padding:18px}.ed-side.closed~.ed-main{margin-left:58px}.ed-head{display:flex;justify-content:space-between;align-items:center}.ed-head-actions{display:flex;gap:7px}.ed-pane{display:none}.ed-pane.active{display:block}.ed-card{margin-top:14px;padding:14px;background:var(--panel);border:1px solid var(--border);border-radius:4px}.ed-save{margin-top:14px}.ed-side{display:none!important}.ed-main,.ed-side.closed~.ed-main{margin-left:0}.fk-menu-btn{display:block!important}.fk-menu-overlay{display:none}.fk-menu-overlay.is-visible{display:block}table th:nth-child(1){width:55px}table th:nth-child(2){width:230px}td:nth-child(2) button{margin-right:4px}@media(max-width:700px){.ed-side{width:58px}.ed-side .ed-text{display:none}.ed-main{margin-left:58px;padding:10px}}.ed-head{position:relative;justify-content:center!important;min-height:44px;padding-left:0!important;gap:14px}.ed-head .fk-menu-btn{display:block!important;position:static!important;top:auto!important;left:auto!important;padding:8px 12px!important;font-size:14px!important}.ed-head h1{margin:0;text-align:center}.ed-head-actions{position:absolute;right:0}.rules-table-layout{table-layout:auto}.rules-table-layout th,.rules-table-layout td{text-align:left}.rules-table-layout th:first-child,.rules-table-layout td:first-child{width:52px;white-space:nowrap}.rules-table-layout th:nth-child(2),.rules-table-layout td:nth-child(2){width:1%;white-space:nowrap}.rules-table-layout th:nth-child(3),.rules-table-layout td:nth-child(3){width:auto}.ed-head{display:grid!important;grid-template-columns:1fr auto 1fr!important;align-items:center!important;min-height:44px;padding-left:0!important;gap:12px}.ed-head h1{grid-column:1;margin:0!important;text-align:left!important}.ed-head .top-inline-nav{grid-column:2}.ed-head-actions{grid-column:3;position:static!important;justify-self:end}.top-menu-toggle{padding:7px 12px;border:1px solid var(--border);border-radius:3px;background:var(--panel);color:var(--fg);font-size:14px;cursor:pointer}.top-menu-toggle:hover{color:var(--accent);border-color:var(--accent)}.rule-title{font-weight:600}.rule-title.normal{color:var(--success)}.rule-title.inactive{color:var(--warn)}.rule-title.custom{color:var(--accent)}.info-pill{padding:2px 9px!important;border:1px solid var(--accent)!important;border-radius:999px!important;background:transparent!important;color:var(--accent)!important;font-weight:bold}.info-pill:hover{background:var(--accent)!important;color:var(--accent-fg)!important}#rule-modal .modal{width:min(1180px,calc(100vw - 32px));max-width:none}.rule-modal-actions{margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}.rule-modal-actions-right{display:flex;gap:6px}.help-legend{display:grid;gap:8px;margin:0 0 14px}.help-legend-row{display:flex;align-items:center;gap:9px}.help-color{width:12px;height:12px;border-radius:50%;flex:0 0 12px}.help-color.green{background:var(--success)}.help-color.blue{background:var(--accent)}.help-color.yellow{background:var(--warn)}.help-rule-logic{padding:10px 12px;border:1px solid var(--border);border-radius:4px;background:var(--bg);line-height:1.5}.ed-main{margin-left:0!important}.ed-head{grid-template-columns:minmax(220px,1fr) auto minmax(220px,1fr)!important}.edit-inline-nav{display:flex;align-items:center;justify-content:center;gap:20px;white-space:nowrap}.edit-inline-nav a,.edit-inline-nav a:visited,.edit-inline-nav a:hover,.edit-inline-nav a:active,.edit-inline-nav button,.edit-inline-nav button:hover,.edit-inline-nav button:active{display:inline!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:#fff!important;text-decoration:none!important;box-shadow:none!important;font:inherit!important;font-weight:400!important;cursor:pointer}.edit-inline-nav a.active,.edit-inline-nav button.active{font-weight:700!important}.rules-table-wrap{width:100%;overflow-x:auto}.rules-table-layout{width:100%;min-width:760px;max-width:100%;table-layout:fixed}.rules-table-layout col.rule-col-number{width:56px}.rules-table-layout col.rule-col-actions{width:260px}.rules-table-layout col.rule-col-title{width:auto}.rules-table-layout th,.rules-table-layout td{text-align:left;vertical-align:top}.rules-table-layout th:nth-child(1),.rules-table-layout td:nth-child(1){white-space:nowrap}.rules-table-layout th:nth-child(2),.rules-table-layout td:nth-child(2){white-space:nowrap}.rules-table-layout th:nth-child(3),.rules-table-layout td:nth-child(3){white-space:normal;overflow-wrap:anywhere}.ed-side,.fk-menu-btn,.fk-menu-overlay{display:none!important}@media(max-width:900px){.ed-head{grid-template-columns:1fr!important}.ed-head h1,.ed-head .edit-inline-nav,.ed-head-actions{grid-column:1!important;justify-self:start!important}.edit-inline-nav{flex-wrap:wrap;justify-content:flex-start}.rules-table-layout{min-width:700px}}</style>
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
<body><main class="ed-main"><div class="ed-head"><h1><?=$csvreporting_editorheading?></h1><nav class="top-inline-nav edit-inline-nav" aria-label="Editor-Navigation"><a href="?_page=index">Index</a><a href="?_page=map">Map</a><a href="?_page=raw">Raw</a><button type="button" data-editor-section="rules">Rules</button><button type="button" data-editor-section="settings">Settings</button></nav><div class="ed-head-actions" id="ruleTopActions" style="display:none"><button type="button" onclick="openHelp()">Hilfe</button><button type="button" class="primary" onclick="openRule(-1)">+ Regel</button></div></div>



<?php if($save_msg){?><div class="info"><?php echo h($save_msg);?></div><?php } ?>
<?php if($load_error){?><div class="error"><?php echo h($load_error);?></div><?php } ?>
<form method="post" id="rules-form" onkeydown="if(event.key==='Enter'&&event.target.tagName==='INPUT')event.preventDefault()">
<input type="hidden" name="action" value="save"><input type="hidden" name="json" id="rules-json"><input type="hidden" name="editor_section" id="editor-section" value="settings">

<div class="ed-pane active" data-editor-section-content="settings"><div class="ed-card">
<h2>Spalten ausblenden:</h2>
<div style="margin-top:8px"></div><!-- <- --><div style="display:flex;flex-wrap:wrap;gap:8px;"><?php foreach($cols as $c){$hidden=!in_array($c,$show_columns,true);?><label><input type="checkbox" class="show-col-cb" value="<?php echo h($c);?>"<?php if($hidden)echo' checked';?>><?php echo h($column_renames[$c]??$c);?></label><?php } ?></div>
</div><div class="ed-card">
<h2>Spalten umbenennen:</h2>
<div class="filter-item"><div class="filter-label"></div><!-- <- --><select id="rename-col-select" onchange="updateRenameInput()"><option value="">-- Spalte wählen --</option><?php foreach($cols as $c): ?><option value="<?php echo h($c);?>"><?php echo h($c);?></option><?php endforeach; ?></select></div><div class="filter-item" style="margin-top:8px"><div class="filter-label">Neuer Anzeigename</div><input type="text" id="rename-col-value"></div>
</div><div class="ed-card">
<h2>Generische Links</h2>
<label>
<input type="checkbox" id="enable-links"<?php if($enable_links)echo' checked';?>> aktiv</label><div class="filter-item" style="margin-top:8px"><div class="filter-label">Spalte</div><select id="link-col-select" onchange="updateLinkInput()"><option value="">-- Spalte wählen --</option><?php foreach($cols as $c): ?><option value="<?php echo h($c);?>"><?php echo h($column_renames[$c]??$c);?></option><?php endforeach; ?></select></div><div class="filter-item" style="margin-top:8px"><div class="filter-label">Link mit/ohne `*` oder *</div><input type="text" id="link-col-value" placeholder=""></div></div></div><div class="ed-pane" data-editor-section-content="rules"><div class="ed-card">
<h2>Regeln</h2>


<div class="rules-table-wrap"><table class="rules-table-layout"><colgroup><col class="rule-col-number"><col class="rule-col-actions"><col class="rule-col-title"></colgroup><thead><tr><th>#</th><th>Aktionen</th><th>Regel</th></tr></thead><tbody id="rules-body"></tbody></table></div>
</div></div>
<div class="ed-save"><button type="submit" class="primary">Speichern</button></div>
</form></main>
<div class="modal-backdrop" id="rule-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Regel bearbeiten</div><button type="button" onclick="closeRule()" class="modal-close">×</button></div>
<label style="display:block"><span>Titel <small id="r-title-mode">Automatisch</small></span><input type="text" id="r-title" style="width:100%" placeholder="Leer lassen für automatischen Titel" oninput="updateTitleMode()"></label><label style="display:block;margin-top:8px">Notiz im Index-Overlay<textarea id="r-notes" rows="3"></textarea></label><label style="display:block;margin-top:8px">Interne Info<textarea id="r-info" rows="3"></textarea></label><label style="display:block;margin-top:8px">Bedingungen</label><div id="r-conds"></div><button type="button" class="small" onclick="addCond()" style="margin-top:6px">+ Bedingung</button><input type="hidden" id="r-desc">
<div class="rule-modal-actions"><button type="button" onclick="openHelp()">Hilfe</button><div class="rule-modal-actions-right"><button type="button" onclick="closeRule()">Abbrechen</button><button type="button" class="primary" onclick="applyRule()">Übernehmen</button></div></div>
</div></div>
<div class="modal-backdrop" id="info-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Info</div><button type="button" onclick="closeInfo()" class="modal-close">×</button></div>
<textarea id="info-text" style="width:100%;min-height:120px" placeholder="Optionaler Infotext zur Regel..."></textarea>
<div style="margin-top:10px;display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeInfo()">Schließen</button><button type="button" class="primary" onclick="saveInfo()">Speichern</button></div>
</div></div>
<div class="modal-backdrop" id="delete-modal"><div class="modal" style="width:min(440px,calc(100vw - 30px))"><div class="modal-header"><div class="modal-title">Regel löschen</div><button type="button" onclick="closeDeleteRule()" class="modal-close">×</button></div><div style="margin:8px 0 16px;line-height:1.5">Soll diese Regel wirklich gelöscht werden?</div><div style="display:flex;justify-content:flex-end;gap:6px"><button type="button" onclick="closeDeleteRule()">Abbrechen</button><button type="button" class="primary" onclick="confirmDeleteRule()">Löschen</button></div></div></div>
<div class="modal-backdrop" id="help-modal"><div class="modal"><div class="modal-header"><div class="modal-title">Regel-Muster Hilfe</div><button type="button" onclick="closeHelp()" class="modal-close">×</button></div>
<div class="help-legend"><div class="help-legend-row"><span class="help-color green"></span><span>Grün: aktive Regel mit automatisch erzeugtem Titel</span></div><div class="help-legend-row"><span class="help-color blue"></span><span>Blau: aktive Regel mit individuellem Titel</span></div><div class="help-legend-row"><span class="help-color yellow"></span><span>Gelb: inaktive Regel</span></div></div><div class="help-rule-logic"><strong>Auswertung:</strong> Für die Anzeige einer CSV-Zeile muss mindestens eine aktive Regel zutreffen. Innerhalb einer Regel müssen alle Bedingungen zutreffen.</div><ul style="margin:14px 0 0;padding-left:18px;font-size:13px;line-height:1.5"><li><code>*</code> als Wildcard</li><li><code>n/a</code> für leer oder N/A</li><li><code>&gt;500</code>, <code>&gt;=500</code>, <code>&lt;500</code>, <code>&lt;=500</code></li><li><code>100..500</code> für Bereiche</li><li><code>500</code> für einen exakten Wert</li></ul>
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
sortRules();
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
var DELETE_RULE_INDEX=-1;
function deleteRule(i){DELETE_RULE_INDEX=i;document.getElementById("delete-modal").style.display="flex";}
function closeDeleteRule(){document.getElementById("delete-modal").style.display="none";DELETE_RULE_INDEX=-1;}
function confirmDeleteRule(){if(DELETE_RULE_INDEX<0)return closeDeleteRule();DATA.rules.splice(DELETE_RULE_INDEX,1);closeDeleteRule();render();}
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
function row(x,key){let r=x.r,i=x.i,en=Array.isArray(r.conditions)&&r.conditions.length>0;if(en)r.description=buildAutoDescFromConditions(r.conditions);let tr=document.createElement("tr");if(key)tr.setAttribute("data-group-item",key);tr.innerHTML="<td>"+(i+1)+"</td>";let ac=document.createElement("td");let e=document.createElement("button");e.type="button";e.className="small";e.textContent="Edit";e.disabled=!en;e.onclick=()=>openRule(i);let t=document.createElement("button");t.type="button";t.className="small";t.textContent=en?"Deaktivieren":"Aktivieren";t.onclick=()=>en?deactivateRule(i):activateRule(i);let d=document.createElement("button");d.type="button";d.className="small";d.textContent="X";d.onclick=()=>deleteRule(i);ac.append(e,t,d);if(r.info&&String(r.info).trim()!==""){let info=document.createElement("button");info.type="button";info.className="small info-pill";info.textContent="Info";info.onclick=()=>openInfo(i);ac.appendChild(info)}tr.appendChild(ac);let title=document.createElement("td");title.className="rule-title "+(!en?"inactive":(r.alternative_title&&String(r.alternative_title).trim()!==""?"custom":"normal"));title.textContent=effectiveTitle(r);tr.appendChild(title);tb.appendChild(tr)}
inactive.forEach(x=>row(x,""));active.forEach(x=>row(x,""));}


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
let rule=Object.assign({},previous,{conditions:list,description:list.length?buildAutoDescFromConditions(list):"!..."});
let title=document.getElementById("r-title").value.trim(),notes=document.getElementById("r-notes").value.trim(),info=document.getElementById("r-info").value.trim();
if(title)rule.alternative_title=title;else delete rule.alternative_title;if(notes)rule.overlay_notes=notes;else delete rule.overlay_notes;if(info)rule.info=info;else delete rule.info;
if(edit>=0)DATA.rules[edit]=rule;else DATA.rules.push(rule);
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
(function(){let actions=document.getElementById("ruleTopActions"),sectionInput=document.getElementById("editor-section");function showSection(name){name=name==="rules"?"rules":"settings";document.querySelectorAll("[data-editor-section-content]").forEach(x=>x.classList.toggle("active",x.dataset.editorSectionContent===name));document.querySelectorAll("[data-editor-section]").forEach(x=>x.classList.toggle("active",x.dataset.editorSection===name));if(sectionInput)sectionInput.value=name;actions.style.display=name==="rules"?"flex":"none";}document.querySelectorAll("[data-editor-section]").forEach(button=>button.addEventListener("click",function(){showSection(button.dataset.editorSection);}));showSection(<?php echo json_encode((string)($_POST['editor_section']??'settings'));?>);})();
render();
</script>
</body>
</html>

CSVREPORTING_EDIT_ROUTE;
eval($csvreporting_route);
exit;
}
http_response_code(404);
exit;
