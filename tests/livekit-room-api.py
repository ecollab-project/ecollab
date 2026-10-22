"""Regression for the actual PHP cURL request shape; mock Twirp receiver only."""
import http.server, json, os, pathlib, subprocess, threading
ROOT = pathlib.Path(__file__).resolve().parents[1]
seen = []
class Receiver(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        seen.append((self.path.rsplit('/', 1)[-1], body))
        self.send_response(200 if isinstance(body, dict) else 400)
        self.send_header('Content-Type', 'application/json'); self.end_headers()
        self.wfile.write(b'{"rooms":[]}' if isinstance(body, dict) else b'{"code":"malformed"}')
    def log_message(self, *args): pass
server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Receiver)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    env = os.environ | {'LIVEKIT_API_KEY':'fixture-key', 'LIVEKIT_API_SECRET':'s'*64,
                       'LIVEKIT_URL':f'ws://127.0.0.1:{server.server_port}'}
    code = 'require '+repr(str(ROOT/'services/LiveKitService.php'))+';$s=new LiveKitService();$s->roomRequest("ListRooms",[],["roomList"=>true]);$s->roomRequest("ListParticipants",["room"=>"ecollab-channel-10"],["roomAdmin"=>true,"room"=>"ecollab-channel-10"]);'
    subprocess.run([os.environ.get('PHP_BIN','php'), '-r', code], env=env, check=True, timeout=10)
    assert seen == [('ListRooms', {}), ('ListParticipants', {'room':'ecollab-channel-10'})]
    print('PASS actual PHP cURL: empty Twirp request is an object; nonempty request preserved (mock receiver)')
finally:
    server.shutdown(); server.server_close()
