"""Local semantic similarity service. Run one CPU model; never log profile text."""
import hmac
import json
import os
from collections import OrderedDict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Lock

import numpy as np
from sklearn.metrics.pairwise import cosine_similarity

MAX_BODY = 700_000


class Scorer:
    def __init__(self, model, cache_size=2048):
        self.model = model
        self.cache = OrderedDict()
        self.cache_size = cache_size

    def score(self, payload):
        if not isinstance(payload, dict) or set(payload) != {'query', 'candidates'}:
            raise ValueError('Expected query and candidates')
        query, candidates = payload['query'], payload['candidates']
        if not isinstance(candidates, list) or not 1 <= len(candidates) <= 100:
            raise ValueError('Expected 1 to 100 candidates')
        texts = [query, *candidates]
        if any(not isinstance(t, str) or len(t) > 1500 for t in texts):
            raise ValueError('Text exceeds limits')
        texts = [t.strip() for t in texts]
        if not texts[0]:
            return [None] * len(candidates)
        missing = list(dict.fromkeys(t for t in texts if t and t not in self.cache))
        if missing:
            vectors = self.model.encode(missing, batch_size=32, normalize_embeddings=True,
                                        convert_to_numpy=True, show_progress_bar=False)
            for text, vector in zip(missing, vectors):
                self.cache[text] = vector
        # Gather before eviction, including when a test uses a very small cache.
        vectors = {t: self.cache[t] for t in texts if t}
        for text in vectors:
            self.cache.move_to_end(text)
        while len(self.cache) > self.cache_size:
            self.cache.popitem(last=False)
        nonempty = [t for t in texts[1:] if t]
        values = iter(cosine_similarity([vectors[texts[0]]], [vectors[t] for t in nonempty])[0]
                      if nonempty else [])
        scores = [round(float(np.clip(next(values), 0, 1)) * 100, 2) if t else None
                  for t in texts[1:]]
        if any(s is not None and not np.isfinite(s) for s in scores):
            raise RuntimeError('Non-finite embedding output')
        return scores


def make_handler(scorer, token):
    gate = Lock()

    class Handler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup()
            self.connection.settimeout(3)

        def log_message(self, *args):
            pass

        def reply(self, status, body):
            data = json.dumps(body, allow_nan=False).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def authorized(self):
            return hmac.compare_digest(self.headers.get('Authorization', '').encode(),
                                       ('Bearer ' + token).encode())

        def do_GET(self):
            if self.path != '/health':
                return self.reply(404, {'error': 'Not found'})
            self.reply(200, {'ready': True, 'version': 'semantic-v1'})

        def do_POST(self):
            if not self.authorized():
                return self.reply(401, {'error': 'Unauthorized'})
            if self.path != '/v1/similarity':
                return self.reply(404, {'error': 'Not found'})
            if not gate.acquire(blocking=False):
                return self.reply(503, {'error': 'Busy'})
            try:
                length = int(self.headers.get('Content-Length', '0'))
                if not 0 < length <= MAX_BODY:
                    return self.reply(413, {'error': 'Invalid request size'})
                payload = json.loads(self.rfile.read(length))
                scores = scorer.score(payload)
                self.reply(200, {'version': 'semantic-v1', 'scores': scores})
            except (ValueError, UnicodeError):
                self.reply(400, {'error': 'Invalid request'})
            except (BrokenPipeError, ConnectionResetError, TimeoutError):
                pass
            except Exception:
                self.reply(503, {'error': 'Scoring unavailable'})
            finally:
                gate.release()

    return Handler


def main():
    token = os.environ.get('PEER_ML_TOKEN', '')
    if len(token) < 32:
        raise SystemExit('PEER_ML_TOKEN must contain at least 32 characters')
    import torch
    from sentence_transformers import SentenceTransformer
    torch.set_num_threads(1)
    # Install model locally first. Startup and requests never download models.
    model = SentenceTransformer(os.environ['PEER_ML_MODEL_PATH'], device='cpu',
                                local_files_only=True, trust_remote_code=False)
    model.max_seq_length = 256
    scorer = Scorer(model)
    ThreadingHTTPServer(('127.0.0.1', 8091), make_handler(scorer, token)).serve_forever()


if __name__ == '__main__':
    main()
