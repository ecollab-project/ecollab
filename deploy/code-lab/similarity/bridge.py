"""Authenticated loopback JPlag parser. Submitted programs are parsed, never run."""
import csv,hashlib,hmac,json,os,pathlib,re,subprocess,tempfile,threading,zipfile
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
LIMIT=12_000_000
LOCK=threading.Lock()
LANG={'c':('c','main.c'),'cpp':('cpp','main.cpp'),'python':('python3','main.py'),'java':('java','Main.java')}

def compare(body):
    language,filename=LANG[body['language']]
    sources=body['sources'];base=body.get('base_code','')
    if not isinstance(sources,dict) or not 2<=len(sources)<=100 or not isinstance(base,str) or len(base.encode())>100000:raise ValueError('Invalid batch')
    if any(not re.fullmatch(r'attempt_[1-9][0-9]{0,18}',key) or not isinstance(value,str) or len(value.encode())>100000 for key,value in sources.items()):raise ValueError('Invalid submission')
    with tempfile.TemporaryDirectory(prefix='jplag-')as temp:
        root=pathlib.Path(temp);inputs=root/'submissions';inputs.mkdir()
        for key,source in sources.items():
            directory=inputs/key;directory.mkdir();(directory/filename).write_text(source)
        command=['/opt/java/openjdk/bin/java','-Xmx512m','-Djava.util.concurrent.ForkJoinPool.common.parallelism=1','-jar','/runner/jplag.jar','--mode','RUN','-l',language,'--csv-export','--result-file',str(root/'report'),'--shown-comparisons','5000']
        if base.strip():
            basedir=root/'base';basedir.mkdir();(basedir/filename).write_text(base);command+=['--base-code',str(basedir)]
        command.append(str(inputs))
        completed=subprocess.run(command,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120,check=False)
        if completed.returncode:raise ValueError('Parser rejected this batch')
        reports=list(root.glob('*.jplag'))
        if len(reports)!=1:raise ValueError('Report missing')
        comparisons=[];failed=[]
        with zipfile.ZipFile(reports[0])as archive:
            for item in archive.infolist():
                if item.file_size>4_000_000 or not item.filename.endswith('.json'):continue
                record=json.loads(archive.read(item))
                if not isinstance(record,dict) or 'firstSubmissionId' not in record or 'secondSubmissionId' not in record:continue
                first=record['firstSubmissionId'];second=record['secondSubmissionId']
                # JPlag derives IDs from the simple attempt folder names.
                if first not in sources or second not in sources:raise ValueError('Unrecognized submission')
                score=record['similarities'].get('AVG',record['similarities'].get('averageSimilarity'))
                if score is None:score=(record['firstSimilarity']+record['secondSimilarity'])/2
                matches=[]
                for match in record.get('matches',[])[:100]:
                    matches.append({'first_start':int(match['startInFirst']['line']),'first_end':int(match['endInFirst']['line']),'second_start':int(match['startInSecond']['line']),'second_end':int(match['endInSecond']['line'])})
                comparisons.append({'first':first,'second':second,'similarity':round(100*float(score),2),'matches':matches})
        comparisons.sort(key=lambda row:row['similarity'],reverse=True)
        return {'engine':'JPlag 6.3.0','comparisons':comparisons[:1000],'base_code_excluded':bool(base.strip()),'report_scope':'Up to 1,000 highest-similarity pairs. Parser errors can omit submissions; an empty report is not proof of originality.'}

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def reply(self,status,data):
        raw=json.dumps(data).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
    def do_POST(self):
        expected=pathlib.Path('/runner/token').read_text().strip()
        if len(expected)<32 or not hmac.compare_digest(self.headers.get('X-Auth-Token',''),expected):return self.reply(401,{'error':'Unauthorized'})
        if self.path!='/compare':return self.reply(404,{'error':'Not found'})
        if not LOCK.acquire(blocking=False):return self.reply(429,{'error':'Worker busy'})
        try:
            length=int(self.headers.get('Content-Length','0'))
            if not 0<length<=LIMIT:raise ValueError()
            self.connection.settimeout(15);body=json.loads(self.rfile.read(length))
            if not isinstance(body,dict) or body.get('language') not in LANG:raise ValueError()
            self.reply(200,compare(body))
        except Exception:self.reply(422,{'error':'Comparison could not finish. Verify syntax and parser support.'})
        finally:LOCK.release()

if __name__=='__main__':ThreadingHTTPServer(('0.0.0.0',2359),Handler).serve_forever()
