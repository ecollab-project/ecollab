<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/JarredResponseFormat.php';
function expectFormat(bool $ok): void { if (!$ok) throw new RuntimeException('Response format regression'); }
$number=JarredResponseFormat::forPrompt('[QA 2026-11-16] Answer only with the number: 17 + 25.');
expectFormat($number!==null && $number['schema']['properties']['answer']['type']==='number');
expectFormat(JarredResponseFormat::decode('{"answer":42}',$number)==='42');
expectFormat(JarredResponseFormat::decode('{"answer":-2.5}',$number)==='-2.5');
$sentences=JarredResponseFormat::forPrompt('[QA 2026-11-16] Explain sessions and cookies in exactly two short sentences.');
expectFormat($sentences!==null && $sentences['count']===2);
expectFormat(JarredResponseFormat::decode('{"sentences":["A session holds data on the server.","A cookie is stored by the browser."]}',$sentences)==='A session holds data on the server. A cookie is stored by the browser.');
foreach ([[$number,'17 + 25 = 42'],[$number,'{"answer":"42"}'],[$sentences,'{"sentences":["Only one."]}'],[$sentences,'{"sentences":["First. Extra sentence.","Second."]}']] as [$format,$bad]) {
    try { JarredResponseFormat::decode($bad,$format);throw new LogicException('Invalid response published'); }
    catch (RuntimeException $error) { expectFormat($error->getCode()===503); }
}
expectFormat(JarredResponseFormat::forPrompt('Explain PHP sessions.')===null);
expectFormat(JarredResponseFormat::decode(' Ordinary answer. ',null)==='Ordinary answer.');
echo "PASS: numeric-only and bounded sentence schemas, decoding, malformed-output rejection and ordinary replies\n";
