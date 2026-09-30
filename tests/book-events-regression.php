<?php
declare(strict_types=1);
require_once __DIR__.'/../services/BookRecommendationEvents.php';
// Dedicated disposable CI database only; never use the production DSN.
$dsn=getenv('BOOK_EVENTS_TEST_DSN');
if(!$dsn){fwrite(STDERR,"BOOK_EVENTS_TEST_DSN required (disposable database)\n");exit(1);}
$db=new PDO($dsn,'root','test-only-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, deleted_at DATETIME NULL, status VARCHAR(20), is_system INT) ENGINE=InnoDB");
$db->exec('CREATE TABLE user_settings (user_id BIGINT UNSIGNED PRIMARY KEY, ai_matching INT) ENGINE=InnoDB');
$db->exec(file_get_contents(__DIR__.'/../database/migrations/049_book_recommendation_events.sql'));
$db->exec("INSERT INTO users VALUES (1,NULL,'active',0),(2,NULL,'active',0)");
$db->exec('INSERT INTO user_settings VALUES (1,1),(2,0)');
function checkBook(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$_SESSION=[];
$books=[['url'=>'https://openlibrary.org/works/OL1W','subjects'=>['Databases']]];
$_ENV['BOOK_RECOMMENDATION_EVENTS_ENABLED']='false';
checkBook(!BookRecommendationEvents::offer($db,1,$books),'Disabled collection');
$_ENV['BOOK_RECOMMENDATION_EVENTS_ENABLED']='true';
checkBook(!BookRecommendationEvents::allowed($db,2),'Opt out respected');
checkBook(BookRecommendationEvents::offer($db,1,$books),'Eligible offer');
BookRecommendationEvents::record($db,1,'/works/OL1W','click');
BookRecommendationEvents::record($db,1,'/works/OL1W','click');
checkBook((int)$db->query('SELECT COUNT(*) FROM recommendation_book_events')->fetchColumn()===1,'Daily dedup');
BookRecommendationEvents::record($db,1,'/works/OL1W','useful');
checkBook((int)$db->query('SELECT COUNT(*) FROM recommendation_book_events')->fetchColumn()===2,'Explicit feedback');
foreach([['/works/OL2W','click'],['/works/OL1W','forged']] as [$key,$type]){
    try{BookRecommendationEvents::record($db,1,$key,$type);throw new RuntimeException('Invalid event accepted');}
    catch(InvalidArgumentException $expected){}
}
$_SESSION['recommendation_books'][1]['/works/OL1W']['expires']=time()-1;
try{BookRecommendationEvents::record($db,1,'/works/OL1W','click');throw new RuntimeException('Expired offer accepted');}
catch(InvalidArgumentException $expected){}
echo "Book events regression checks passed\n";
