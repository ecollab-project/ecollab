<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true); $db=Database::getInstance();
$mode=(($_GET['mode']??'server')==='me')?'me':'server'; $sid=(int)($_GET['server_id']??0); $q=trim((string)($_GET['q']??'')); $readable=(($_GET['readable']??'1')!=='0'); $topics=[]; $label='';

try {
 if($mode==='server'){
  $st=$db->prepare("SELECT s.name,s.description,s.category,GROUP_CONCAT(DISTINCT it.name SEPARATOR ', ') tags FROM servers s JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid LEFT JOIN server_tags st ON st.server_id=s.id LEFT JOIN interest_tags it ON it.id=st.interest_tag_id WHERE s.id=:sid AND s.status='active' GROUP BY s.id");
  $st->execute([':uid'=>$user['id'],':sid'=>$sid]); $r=$st->fetch();
  if(!$r){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Server access denied']);exit;}
  $label=(string)$r['name']; $seed=strtolower(implode(' ',[$r['name'],$r['description'],$r['category'],$r['tags']]));
 } else {
  $st=$db->prepare("SELECT ap.name program,ap.code,up.year_level,GROUP_CONCAT(DISTINCT it.name SEPARATOR ', ') interests FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN academic_programs ap ON ap.id=up.academic_program_id LEFT JOIN user_interests ui ON ui.user_id=u.id LEFT JOIN interest_tags it ON it.id=ui.interest_tag_id WHERE u.id=:uid GROUP BY u.id,ap.name,ap.code,up.year_level");
  $st->execute([':uid'=>$user['id']]); $r=$st->fetch()?:[]; $year=(int)($r['year_level']??0);
  $label=trim((string)($r['code']??'').' '.($year?$year.' Year':''))?:'My profile'; $seed=strtolower(implode(' ',[$r['program']??'',$r['code']??'',$r['interests']??'']));
  $levels=[1=>['programming fundamentals','introduction to computing'],2=>['data structures','database fundamentals','web development'],3=>['software engineering','cybersecurity','advanced databases'],4=>['capstone','research methods','project management']];
  $topics=$levels[$year]??[];
 }
 $map=['database'=>['database','sql','relational database'],'program'=>['programming','software development'],'web'=>['web development'],'network'=>['computer networks'],'cyber'=>['cybersecurity'],'security'=>['information security'],'algorithm'=>['algorithms'],'software'=>['software engineering'],'bsit'=>['information technology','computer science'],'bscs'=>['computer science','programming'],'research'=>['research methods']];
 foreach($map as $needle=>$vals)if(str_contains($seed,$needle))$topics=array_merge($topics,$vals);
 $topics=array_values(array_unique($topics)); if(!$topics)$topics=['computer science'];
 $query=$q!==''?$q:implode(' ',array_slice($topics,0,4));
 $url='https://openlibrary.org/search.json?'.http_build_query(['q'=>$query,'fields'=>'key,title,author_name,cover_i,first_publish_year,subject,ebook_access,has_fulltext,ia,public_scan_b,availability','limit'=>24]);
 $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: eCollab-Academic-Library']]);
 $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
 if($raw===false||$code<200||$code>=300)throw new RuntimeException('Book catalog temporarily unavailable');
 $data=json_decode((string)$raw,true); $books=[];
 foreach(($data['docs']??[]) as $d){if($readable && empty($d['has_fulltext']) && !in_array((string)($d['ebook_access']??''),['public','borrowable'],true))continue;$key=(string)($d['key']??'');$books[]=['title'=>(string)($d['title']??'Untitled'),'authors'=>(array)($d['author_name']??[]),'cover'=>empty($d['cover_i'])?null:'https://covers.openlibrary.org/b/id/'.(int)$d['cover_i'].'-M.jpg','year'=>$d['first_publish_year']??null,'subjects'=>array_slice((array)($d['subject']??[]),0,6),'ebook_access'=>(string)($d['ebook_access']??''),'has_fulltext'=>(bool)($d['has_fulltext']??false),'url'=>$key?'https://openlibrary.org'.$key:null,'read_url'=>!empty($d['ia'][0])?'https://archive.org/details/'.rawurlencode((string)$d['ia'][0]):null,'action'=>((string)($d['ebook_access']??''))==='public'?'Read':(((string)($d['ebook_access']??''))==='borrowable'?'Borrow':'Details'),'source'=>'Open Library'];}
 echo json_encode(['success'=>true,'context'=>['label'=>$label,'topics'=>$topics],'books'=>$books],JSON_UNESCAPED_SLASHES);
} catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'error'=>'Unable to load the library right now.']);}
