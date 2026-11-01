"""Queue -> Redis Stream -> authenticated WebSocket integration, using disposable services."""
import argparse
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import struct
import sys
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
FLAGS = getattr(subprocess, 'CREATE_NO_WINDOW', 0)


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class Client:
    def __init__(self, origin):
        self.origin = origin
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = ''

    def api(self, path, body=None):
        req = urllib.request.Request(self.origin + path, data=None if body is None else json.dumps(body).encode(), headers={'Origin': self.origin, 'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
        with self.opener.open(req, timeout=8) as response:
            return json.load(response)

    def cookie(self):
        return '; '.join(f'{cookie.name}={cookie.value}' for cookie in self.cookies)


class Socket:
    def __init__(self, port, client, origin=None, expected=101):
        self.socket = socket.create_connection(('127.0.0.1', port), timeout=6)
        self.file = self.socket.makefile('rb')
        key = base64.b64encode(os.urandom(16)).decode()
        request = f'GET /api/admin/notifications/socket HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nOrigin: {origin or client.origin}\r\nCookie: {client.cookie()}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {key}\r\n\r\n'
        self.socket.sendall(request.encode())
        status = self.file.readline().decode()
        assert int(status.split()[1]) == expected, status
        while self.file.readline() not in (b'\r\n', b''):
            pass
        if expected != 101:
            self.close()

    def message(self):
        header = self.file.read(2)
        assert len(header) == 2, 'WebSocket closed unexpectedly'
        length = header[1] & 127
        if length == 126:
            length = struct.unpack('!H', self.file.read(2))[0]
        elif length == 127:
            length = struct.unpack('!Q', self.file.read(8))[0]
        payload = self.file.read(length)
        assert header[0] & 15 == 1, f'Unexpected frame: {header[0] & 15}'
        return json.loads(payload)

    def until_notifications(self):
        deadline = time.time() + 6
        while time.time() < deadline:
            message = self.message()
            if message['type'] == 'notifications':
                return message['data']
            assert message['type'] == 'heartbeat', message
        raise AssertionError('No notification received')

    def close(self):
        self.file.close()
        self.socket.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--runtime', default=str(ROOT / 'build/loom.exe'))
    parser.add_argument('--redis', required=True)
    parser.add_argument('--database-dsn', default=os.environ.get('LOOM_DATABASE'))
    args = parser.parse_args()
    if not args.database_dsn or not args.database_dsn.startswith('mysql:'):
        parser.error('A disposable MySQL --database-dsn is required')
    port, redis_port = free_port(), free_port()
    origin = f'http://127.0.0.1:{port}'
    processes, sockets = [], []
    with tempfile.TemporaryDirectory(prefix='loom-notifications-') as tmp:
        env = dict(os.environ, LOOM_ADDRESS=f'127.0.0.1:{port}', LOOM_DATABASE=args.database_dsn, LOOM_REDIS_URL=f'redis://127.0.0.1:{redis_port}/0', LOOM_REDIS_PREFIX='test-notification:', LOOM_ADMIN_INITIAL_PASSWORD='admin test password')
        config = Path(tmp) / 'redis.conf'
        config.write_text(f'bind 127.0.0.1\nport {redis_port}\nsave ""\nappendonly no\n')
        with open(Path(tmp) / 'services.log', 'wb') as log:
            def spawn(command, cwd=ROOT):
                process = subprocess.Popen(command, cwd=cwd, env=env, stdout=log, stderr=log, creationflags=FLAGS)
                processes.append(process)
                return process

            def cli(*command):
                result = subprocess.run([args.runtime, 'loom.php', *command], cwd=ROOT, env=env, capture_output=True, timeout=20)
                assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')

            def drain_pushes():
                jobs = admin.api('/api/admin/operations/queue?q=notification.push')
                for job in jobs['items']:
                    if job['state'] == 'pending':
                        cli('queue:work', '--once')

            try:
                redis = spawn([args.redis, config.name], config.parent)
                spawn([args.runtime, 'loom.php', 'serve'])
                admin, customer, member = Client(origin), Client(origin), Client(origin)
                for _ in range(70):
                    try:
                        urllib.request.urlopen(origin, timeout=1).close()
                        break
                    except (OSError, urllib.error.URLError):
                        time.sleep(.1)
                cli('admin:create', 'admin@example.test', 'Admin')
                cli('admin:bootstrap', 'admin@example.test')
                cli('admin:create', 'member@example.test', 'Member')
                admin.csrf = admin.api('/api/studio/login', {'email': 'admin@example.test', 'password': 'admin test password'})['csrf']
                member.csrf = member.api('/api/studio/login', {'email': 'member@example.test', 'password': 'admin test password'})['csrf']
                customer.csrf = customer.api('/api/customer/register', {'name': 'Customer', 'email': 'customer@example.test', 'password': 'customer test password'})['csrf']
                Socket(port, Client(origin), expected=401)
                Socket(port, customer, expected=401)
                Socket(port, admin, origin='https://evil.example', expected=403)
                ws = Socket(port, admin); sockets.append(ws)
                isolated = Socket(port, member); sockets.append(isolated)
                assert ws.until_notifications()['items'] == []
                assert isolated.until_notifications()['items'] == []
                body = {'request_key': 'notification-test-00000001'}
                payment = customer.api('/api/checkout/simulate', body)
                assert customer.api('/api/checkout/simulate', body)['order']['id'] == payment['order']['id']
                jobs = admin.api('/api/admin/operations/queue?q=notification.push')
                # 种子管理员和测试管理员都可以查看订单。
                assert jobs['total'] == 2 and all(job['state'] == 'pending' for job in jobs['items']), jobs
                assert ws.message()['type'] == 'heartbeat', 'Push must wait for queue consumer'
                drain_pushes()
                data = ws.until_notifications()
                assert len(data['items']) == 1 and payment['order']['number'] in data['items'][0]['body']
                notification_id = data['items'][0]['id']
                assert isolated.message()['type'] == 'heartbeat', 'Another administrator received a private event'
                jobs = admin.api('/api/admin/operations/queue?q=notification.push')
                assert jobs['items'][0]['state'] == 'completed'
                # Redis 同时用于队列传输和管理员会话存储。服务中断时
                # 不得消耗重试次数，也不得静默回退到数据库队列。
                redis.terminate(); redis.wait(timeout=5)
                customer.api('/api/checkout/simulate', {'request_key': 'notification-test-00000002'})
                unavailable = subprocess.run([args.runtime, 'loom.php', 'queue:work', '--once'],
                                             cwd=ROOT, env=env, capture_output=True, timeout=20)
                assert unavailable.returncode != 0, 'Redis outage must stop queue consumption'
                redis = spawn([args.redis, config.name], config.parent)
                time.sleep(1)
                # 此 Redis 测试实例禁用了持久化；重新连接时需创建新会话。
                admin.csrf = admin.api('/api/studio/login', {'email': 'admin@example.test', 'password': 'admin test password'})['csrf']
                jobs = admin.api('/api/admin/operations/queue?q=notification.push')
                assert jobs['items'][0]['state'] == 'pending' and jobs['items'][0]['attempts'] == 0
                drain_pushes()
                jobs = admin.api('/api/admin/operations/queue?q=notification.push')
                assert jobs['items'][0]['state'] == 'completed' and jobs['items'][0]['attempts'] == 1
                # 即使临时流已消失，重连仍使用持久化收件箱。
                ws.close(); sockets.remove(ws)
                recovered = Socket(port, admin); sockets.append(recovered)
                restored = recovered.until_notifications()
                assert len(restored['items']) == 2 and any(item['id'] == notification_id for item in restored['items'])
                # 单个已认证套接字必须保持连接，时长远超原来的 10 秒
                # HTTP 截止时间。不允许定期轮换连接或再次握手。
                connected_until = time.time() + 35
                while time.time() < connected_until:
                    event = recovered.message()
                    assert event['type'] == 'heartbeat', event
                third = customer.api('/api/checkout/simulate', {'request_key': 'notification-test-00000003'})
                drain_pushes()
                continued = recovered.until_notifications()
                assert len(continued['items']) == 3 and third['order']['number'] in continued['items'][0]['body']
                # 退出登录会使已连接的套接字失效。
                admin.api('/api/studio/logout', {})
                while True:
                    event = recovered.message()
                    if event['type'] == 'unauthorized':
                        break
                    assert event['type'] == 'heartbeat', event
                print('Queue outage recovery, persistent WebSocket >35s, same-connection later payment, reconnect, identity isolation and revocation: OK')
            finally:
                if sys.exc_info()[0]:
                    log.flush()
                    print((Path(tmp) / 'services.log').read_text(errors='replace'))
                for ws in sockets:
                    ws.close()
                for process in reversed(processes):
                    if process.poll() is None:
                        process.terminate()
                    process.wait(timeout=10)


if __name__ == '__main__':
    main()
