"""Runs a localhost broker mock and the queue crash fixture; no live services."""
import http.server, threading, subprocess, os, json
requests=[]
class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        requests.append(body)
        self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers()
        self.wfile.write(json.dumps({'result':{'responses':[{} for _ in body['channels']]}}).encode())
    def log_message(self,*args): pass
server=http.server.HTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
try:
    env=os.environ|{'REVIEW_BROKER_API':f'http://127.0.0.1:{server.server_port}/api'}
    subprocess.run(['php','tests/review-jarred-crashes.php'],env=env,check=True)
    assert len(requests)==3,requests
    assert requests[0]['data']['event_id']==requests[1]['data']['event_id']
    assert all(set(r['data'])=={'type','kind','target_id','message_id','event_id'} for r in requests)
    print('PASS at-least-once hint replay: same event ID; metadata only; one persisted assistant reply')
finally: server.shutdown()
