"""Harmless loopback checks: source cannot be redirected or sent through env proxies."""
import importlib.util,json,os,pathlib,threading,unittest
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('bridge',ROOT/'deploy/code-lab/piston/bridge.py');bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
class BackendTests(unittest.TestCase):
 def test_no_proxy_redirect_or_unapproved_destination(self):
  hits=[];redirect=[False]
  class Handler(BaseHTTPRequestHandler):
   def log_message(self,*args):pass
   def do_GET(self):
    hits.append(self.path)
    if redirect[0]:self.send_response(307);self.send_header('Location','http://127.0.0.1:1/forbidden');self.end_headers();return
    body=b'[]';self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
  server=ThreadingHTTPServer(('127.0.0.1',0),Handler);threading.Thread(target=server.serve_forever,daemon=True).start()
  try:
   with patch.dict(os.environ,{'PISTON_URL':'http://127.0.0.1:'+str(server.server_port),'HTTP_PROXY':'http://127.0.0.1:1','http_proxy':'http://127.0.0.1:1','NO_PROXY':'','no_proxy':''}):
    self.assertEqual(bridge.piston_request('/runtimes'),[])
    redirect[0]=True
    with self.assertRaises(ValueError):bridge.piston_request('/runtimes')
    self.assertEqual(hits,['/api/v2/runtimes','/api/v2/runtimes'])
    with self.assertRaises(ValueError):bridge.piston_request('/../../packages')
   for url in ['http://192.0.2.1:2000','http://10.0.0.1:2000','http://user:pass@piston:2000','https://piston:2000','http://piston:2000?redirect=1']:
    with patch.dict(os.environ,{'PISTON_URL':url}):
     with self.assertRaises(ValueError):bridge.piston_request('/runtimes')
  finally:server.shutdown();server.server_close()
if __name__=='__main__':unittest.main()
