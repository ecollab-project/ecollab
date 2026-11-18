import http.client
import json
import threading
import unittest
from http.server import ThreadingHTTPServer

import numpy as np
from server import Scorer, make_handler


class FakeEncoder:
    def __init__(self):
        self.calls = 0

    def encode(self, texts, **kwargs):
        self.calls += 1
        return np.array([{'PHP': [1., 0.], 'Laravel': [.9, .1],
                          'Biology': [0., 1.], 'Opposite': [-1., 0.]}[t] for t in texts])


class ScorerTests(unittest.TestCase):
    def test_order_empty_negative_and_cache(self):
        encoder = FakeEncoder()
        scorer = Scorer(encoder)
        payload = {'query': 'PHP', 'candidates': ['Biology', '', 'Laravel', 'Opposite', 'PHP']}
        scores = scorer.score(payload)
        self.assertEqual(scores[:2], [0., None])
        self.assertGreater(scores[2], 90)
        self.assertEqual(scores[3:], [0., 100.])
        self.assertEqual(scores, scorer.score(payload))
        self.assertEqual(encoder.calls, 1)

    def test_validation(self):
        scorer = Scorer(FakeEncoder())
        for payload in [[], {'query':'PHP','candidates':[]},
                        {'query':'PHP','candidates':['x']*101},
                        {'query':'x'*1501,'candidates':['PHP']},
                        {'query':3,'candidates':['PHP']},
                        {'query':'PHP','candidates':[{}]}]:
            with self.subTest(payload=str(payload)[:60]), self.assertRaises(ValueError):
                scorer.score(payload)

    def test_empty_query_and_eviction(self):
        scorer = Scorer(FakeEncoder(), cache_size=1)
        self.assertEqual(scorer.score({'query':' ', 'candidates':['PHP']}), [None])
        self.assertGreater(scorer.score({'query':'PHP','candidates':['Laravel']})[0], 90)
        self.assertEqual(len(scorer.cache), 1)

    def test_warm_requests_complete_while_encoder_is_busy(self):
        entered = threading.Event()
        release = threading.Event()

        class BlockingEncoder(FakeEncoder):
            def encode(self, texts, **kwargs):
                if 'Biology' in texts:
                    entered.set()
                    if not release.wait(5):
                        raise RuntimeError('Test did not release encoder')
                return super().encode(texts, **kwargs)

        scorer = Scorer(BlockingEncoder())
        warm = {'query': 'PHP', 'candidates': ['Laravel']}
        expected = scorer.score(warm)
        server = ThreadingHTTPServer(('127.0.0.1', 0), make_handler(scorer, 'test-token'))
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()

        def request(payload):
            connection = http.client.HTTPConnection(*server.server_address, timeout=2)
            try:
                connection.request('POST', '/v1/similarity', json.dumps(payload),
                                   {'Authorization': 'Bearer test-token'})
                response = connection.getresponse()
                return response.status, json.loads(response.read())
            finally:
                connection.close()

        cold_result = []
        cold = threading.Thread(target=lambda: cold_result.append(
            request({'query': 'PHP', 'candidates': ['Biology']})), daemon=True)
        try:
            cold.start()
            self.assertTrue(entered.wait(2))
            self.assertEqual(request(warm), (200, {'version': 'semantic-v1', 'scores': expected}))
            self.assertEqual(request({'query': 'PHP', 'candidates': ['Opposite']}),
                             (503, {'error': 'Busy'}))
        finally:
            release.set()
            cold.join()
            server.shutdown()
            server.server_close()
            worker.join()
        self.assertEqual(cold_result, [(200, {'version': 'semantic-v1', 'scores': [0.]})])

    def test_http_auth_validation_and_success(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), make_handler(Scorer(FakeEncoder()), 'test-token'))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            for token, body, expected in [('', {}, 401), ('test-token', {}, 400),
                                        ('test-token', {'query':'PHP','candidates':['PHP']}, 200)]:
                conn = http.client.HTTPConnection(*server.server_address, timeout=2)
                conn.request('POST', '/v1/similarity', json.dumps(body),
                             {'Authorization': 'Bearer '+token})
                response = conn.getresponse()
                self.assertEqual(response.status, expected)
                data = json.loads(response.read())
                if expected == 200:
                    self.assertEqual(data, {'version':'semantic-v1','scores':[100.]})
                conn.close()
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


if __name__ == '__main__':
    unittest.main()
