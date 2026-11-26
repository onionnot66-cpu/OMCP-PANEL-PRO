import json
import os
import pathlib
import socket
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.request


class AccessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.root = pathlib.Path(cls.tmp.name)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}'
        project = pathlib.Path(__file__).resolve().parents[1]
        cls.process = subprocess.Popen(
            [sys.executable, str(project / 'agent/omcp_agent.py')],
            env={**os.environ, 'OMCP_DATA': str(cls.root), 'OMCP_PORT': str(port),
                 'OMCP_SFTP_PORT': '0', 'OMCP_WEB': str(project / 'index.html')},
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                cls.call('/api/health')
                break
            except OSError:
                time.sleep(.05)
        else:
            cls.process.terminate()
            raise RuntimeError('Agent did not start')
        _, result = cls.call('/api/setup', data={'username': 'administrator',
            'email': 'admin@example.test', 'password': 'test-only-password-123'})
        cls.admin = result['token']
        cls.call('/api/users', cls.admin, {'username': 'alice',
            'email': 'alice@example.test', 'password': 'test-only-password-456'})
        cls.call('/api/users', cls.admin, {'username': 'bob',
            'email': 'bob@example.test', 'password': 'test-only-password-789'})
        _, result = cls.call('/api/login', data={'username': 'alice',
            'password': 'test-only-password-456'})
        cls.user = result['token']
        _, result = cls.call('/api/login', data={'username': 'bob',
            'password': 'test-only-password-789'})
        cls.bob = result['token']
        for sid, owner in [('aaaaaaaa', 'alice'), ('bbbbbbbb', 'administrator')]:
            folder = cls.root / 'servers' / sid
            folder.mkdir()
            (folder / 'omcp.json').write_text(json.dumps({'name': sid, 'version': '1.21.1',
                'memory': 1, 'port': 25565, 'owner': owner}))
            (folder / 'hello.txt').write_text('private content')
        (cls.root / 'deployment.json').write_text(json.dumps({'mode': 'ipv4',
            'public_ipv4': '127.0.0.1', 'panel_host': 'localhost'}))

    @classmethod
    def call(cls, path, token=None, data=None):
        request = urllib.request.Request(cls.base + path,
            data=json.dumps(data).encode() if data is not None else None,
            headers={'Content-Type': 'application/json', **({'Authorization': 'Bearer ' + token} if token else {})})
        try:
            response = urllib.request.urlopen(request, timeout=5)
        except urllib.error.HTTPError as e:
            response = e
        return response.status, json.loads(response.read())

    def test_anonymous_denied(self):
        self.assertEqual(self.call('/api/servers')[0], 401)

    def test_owner_listing(self):
        self.assertEqual([s['id'] for s in self.call('/api/servers', self.user)[1]['servers']], ['aaaaaaaa'])

    def test_cross_owner_file_denied(self):
        self.assertEqual(self.call('/api/servers/bbbbbbbb/files?path=hello.txt', self.user)[0], 401)
        self.assertEqual(self.call('/api/servers/aaaaaaaa/files?path=hello.txt', self.user)[1]['content'], 'private content')

    def test_admin_only_resources(self):
        for path in ['/api/users', '/api/admin/metadata', '/api/backups']:
            self.assertEqual(self.call(path, self.user)[0], 401)
        self.assertEqual(self.call('/api/servers', self.user, {})[0], 401)

    def test_local_node(self):
        node = self.call('/api/admin/metadata', self.admin)[1]['nodes'][0]
        self.assertEqual(node['id'], 'local')
        self.assertFalse(node['connectorRequired'])

    def test_subuser_permissions_and_audit(self):
        status, result = self.call('/api/servers/aaaaaaaa/subusers', self.user,
            {'username': 'bob', 'permissions': ['files.read']})
        self.assertEqual(status, 200, result)
        self.assertEqual(self.call('/api/servers/aaaaaaaa/files?path=hello.txt', self.bob)[1]['content'], 'private content')
        self.assertEqual(self.call('/api/servers/aaaaaaaa/start', self.bob, {})[0], 401)
        listed = self.call('/api/servers', self.bob)[1]['servers']
        self.assertEqual([s['id'] for s in listed], ['aaaaaaaa'])
        events = self.call('/api/admin/audit', self.admin)[1]['events']
        self.assertEqual(events[0]['action'], 'server.subuser.saved')

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.tmp.cleanup()


if __name__ == '__main__':
    unittest.main()
