import unittest
from evaluate import prepare, metrics


def fixture():
    return {'version':1, 'users':[{'id':'u','features':[]},{'id':'v','features':[]}],
            'items':[{'id':i,'features':['subject:'+i],'available_at':1} for i in 'abc'],
            'events':[{'user':u,'item':i,'type':'click','timestamp':t}
                      for u,i,t in [('u','a',2),('v','b',3),('u','a',11),('u','b',12),('v','c',13)]]}


class EvaluationTests(unittest.TestCase):
    def test_repeat_pair_cannot_enter_holdout(self):
        catalog, train, test, _ = prepare(fixture(),10)
        self.assertEqual(set(train),{('u','a'),('v','b')})
        self.assertEqual(set(test),{('u','b'),('v','c')})
        self.assertFalse(set(train)&set(test))

    def test_future_catalog_excluded(self):
        data=fixture()
        data['items'][2]['available_at']=12
        catalog, _, test, excluded=prepare(data,10)
        self.assertNotIn('c',catalog)
        self.assertNotIn(('v','c'),test)
        self.assertEqual(excluded,1)

    def test_no_data_refuses_training(self):
        data=fixture();data['events']=[]
        with self.assertRaisesRegex(ValueError,'Insufficient'):
            prepare(data,10)

    def test_known_metrics(self):
        values=metrics(['a','b','c'],{'b'},2)
        self.assertEqual(values['precision'],.5)
        self.assertEqual(values['recall'],1.)
        self.assertAlmostEqual(values['ndcg'],.6309297536)

    def test_actual_lightfm_fit_when_installed(self):
        try:
            import lightfm
        except ImportError:
            self.skipTest('LightFM not installed; CI runs this test in Python 3.11')
        from evaluate import evaluate
        result=evaluate(fixture(),10,k=1,epochs=2)
        self.assertEqual(result['evaluated_users'],2)
        self.assertFalse(result['production_enabled'])
        for row in result['metrics'].values():
            for score in row.values():
                self.assertGreaterEqual(score,0)
                self.assertLessEqual(score,1)


if __name__=='__main__':
    unittest.main()
