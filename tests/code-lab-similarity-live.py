"""Actual pinned JPlag parser checks, with no program execution."""
import importlib.util,json,os,pathlib,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('bridge',root/'deploy/code-lab/similarity/bridge.py');bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
if os.environ.get('SIMILARITY_URL'):
    token=pathlib.Path(os.environ['SIMILARITY_TOKEN_FILE']).read_text().strip()
    for attempt in range(30):
        try:
            probe=urllib.request.Request(os.environ['SIMILARITY_URL'],data=b'{}',headers={'Content-Type':'application/json','X-Auth-Token':token})
            urllib.request.urlopen(probe,timeout=2)
        except urllib.error.HTTPError as error:
            if error.code==422:break
            raise
        except (OSError,urllib.error.URLError):pass
        time.sleep(1)
    else:raise RuntimeError('Parser did not become ready')
for language,filename in [('c','main.c'),('cpp','main.cpp'),('python','main.py'),('java','Main.java')]:
    source=(root/'examples/code-lab/advanced'/filename).read_text()
    body={'language':language,'sources':{'attempt_1':source,'attempt_2':source},'base_code':''}
    if os.environ.get('SIMILARITY_URL'):
        token=pathlib.Path(os.environ['SIMILARITY_TOKEN_FILE']).read_text().strip()
        request=urllib.request.Request(os.environ['SIMILARITY_URL'],data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-Auth-Token':token})
        with urllib.request.urlopen(request,timeout=150)as response:result=json.load(response)
    else:result=bridge.compare(body)
    assert result['engine']=='JPlag 6.3.0',result
    assert len(result['comparisons'])==1,result
    pair=result['comparisons'][0]
    assert pair['similarity']>=99 and pair['matches'],result
    assert {pair['first'],pair['second']}=={'attempt_1','attempt_2'}
    assert all(m['first_start']>=1 and m['first_end']>=m['first_start'] for m in pair['matches'])
    print(language+': actual JPlag parsing, identical-pair detection and line ranges passed')
