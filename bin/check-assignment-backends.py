"""Read-only comparison of assignment contracts and opaque database identities."""
import argparse
import json
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def inspect(base):
    url = base.rstrip('/') + '/api.php?action=assignment.capabilities'
    try:
        with urlopen(Request(url, headers={'Accept': 'application/json'}), timeout=20) as response:
            payload = json.load(response)
        data = payload.get('data') or {}
        compatible = (payload.get('success') is True
                      and data.get('submission_contract', 0) >= 2
                      and data.get('atomic_assignment_submission') is True
                      and data.get('durable_idempotency') is True
                      and bool(data.get('backend_id')))
        return {'url': base, 'compatible': compatible, 'backend_id': data.get('backend_id')}
    except (HTTPError, URLError, ValueError, TimeoutError) as error:
        return {'url': base, 'compatible': False, 'error': str(error)}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('urls', nargs='*', default=['https://quizb.my.id', 'https://up.quizb.my.id'])
    args = parser.parse_args()
    reports = [inspect(url) for url in args.urls]
    compatible = all(item['compatible'] for item in reports)
    same = len({item.get('backend_id') for item in reports}) == 1 if compatible else None
    print(json.dumps({'backends': reports, 'same_database_identity': same}, indent=2))
    sys.exit(0 if compatible and same else 1)
