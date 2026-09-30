"""Offline warm-start experiment; never called by the web application."""
import argparse
import json
import math
from collections import Counter


def prepare(data, cutoff):
    if data.get('version') != 1:
        raise ValueError('Unsupported dataset version')
    users = {u['id']: u for u in data['users']}
    items = {i['id']: i for i in data['items']}
    if len(users) != len(data['users']) or len(items) != len(data['items']):
        raise ValueError('Duplicate IDs')
    if len(users) > 5000 or len(items) > 5000 or len(data['events']) > 100000:
        raise ValueError('Dataset exceeds offline prototype limits')
    # Catalog and features must have existed before the global time cutoff.
    catalog = {k: v for k, v in items.items() if v['available_at'] < cutoff}
    pairs = {}
    for e in sorted(data['events'], key=lambda e: e['timestamp']):
        if e['user'] not in users or e['item'] not in items:
            raise ValueError('Event references an unknown user or item')
        if e['type'] not in ('click', 'useful') or not math.isfinite(e['timestamp']):
            raise ValueError('Invalid event')
        if e['timestamp'] < items[e['item']]['available_at']:
            raise ValueError('Event precedes catalog availability')
        pair = (e['user'], e['item'])
        # First interaction only: repeat clicks cannot leak into holdout or amplify weight.
        pairs.setdefault(pair, e)
    train = {p: e for p, e in pairs.items() if e['timestamp'] < cutoff and p[1] in catalog}
    train_users = {u for u, _ in train}
    test = {p: e for p, e in pairs.items() if e['timestamp'] >= cutoff and p[0] in train_users and p[1] in catalog}
    if len(catalog) < 2 or not train or not test:
        raise ValueError('Insufficient chronological train/holdout data; continue collecting real events')
    return catalog, train, test, len(pairs) - len(train) - len(test)


def metrics(ranked, relevant, k):
    top = ranked[:k]
    hits = len(set(top) & relevant)
    dcg = sum(1 / math.log2(n+2) for n, item in enumerate(top) if item in relevant)
    ideal = sum(1 / math.log2(n+2) for n in range(min(k, len(relevant))))
    return {'precision': hits/k, 'recall': hits/len(relevant), 'ndcg': dcg/ideal}


def evaluate(data, cutoff, k=5, epochs=20):
    import numpy as np
    from lightfm import LightFM
    from lightfm.data import Dataset
    catalog, train, test, excluded = prepare(data, cutoff)
    user_ids = sorted({u for u, _ in train})
    item_ids = sorted(catalog)
    dataset = Dataset()
    dataset.fit(user_ids, item_ids, item_features=sorted({f for i in catalog.values() for f in i['features']}))
    features = dataset.build_item_features((i, catalog[i]['features']) for i in item_ids)
    interactions, _ = dataset.build_interactions(train.keys())
    model = LightFM(no_components=16, loss='warp', random_state=42)
    model.fit(interactions, item_features=features, epochs=epochs, num_threads=1)
    uid_map, _, iid_map, _ = dataset.mapping()
    popularity = Counter(i for _, i in train)
    results = {'lightfm': [], 'popularity': [], 'content_overlap': []}
    for user in sorted({u for u, _ in test}):
        seen = {i for u, i in train if u == user}
        candidates = [i for i in item_ids if i not in seen]
        relevant = {i for u, i in test if u == user}
        scores = model.predict(uid_map[user], np.array([iid_map[i] for i in candidates]), item_features=features, num_threads=1)
        if not np.isfinite(scores).all():
            raise ValueError('Model returned non-finite predictions')
        ranked = sorted(zip(candidates, scores), key=lambda x: (-x[1], x[0]))
        interests = {f for i in seen for f in catalog[i]['features']}
        def overlap(item):
            tags = set(catalog[item]['features'])
            return len(tags & interests) / max(1, len(tags | interests))
        baselines = {
            'lightfm': [i for i, _ in ranked],
            'popularity': sorted(candidates, key=lambda i: (-popularity[i], i)),
            'content_overlap': sorted(candidates, key=lambda i: (-overlap(i), i)),
        }
        for name, ranking in baselines.items():
            results[name].append(metrics(ranking, relevant, k))
    averages = {name: {m: sum(r[m] for r in rows)/len(rows) for m in ('precision','recall','ndcg')}
                for name, rows in results.items()}
    return {'cutoff':cutoff, 'k':k, 'seed':42, 'epochs':epochs,
            'training_pairs':len(train), 'test_pairs':len(test), 'evaluated_users':len(results['lightfm']),
            'excluded_pairs':excluded, 'catalog_items':len(catalog), 'metrics':averages,
            'production_enabled':False,
            'limitations':['Warm-start, first-interaction evaluation only',
                          'Clicked-item catalog only; exposure bias remains',
                          'Content overlap baseline is not the current Open Library ordering',
                          'No automatic deployment or quality claim']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('dataset')
    parser.add_argument('--cutoff', type=int, required=True, help='Global UTC Unix timestamp')
    parser.add_argument('--k', type=int, default=5)
    parser.add_argument('--epochs', type=int, default=20)
    args = parser.parse_args()
    if not 1 <= args.k <= 50 or not 1 <= args.epochs <= 100:
        parser.error('k must be 1..50; epochs must be 1..100')
    with open(args.dataset) as source:
        data = json.load(source)
    print(json.dumps(evaluate(data,args.cutoff,args.k,args.epochs),indent=2,allow_nan=False))
