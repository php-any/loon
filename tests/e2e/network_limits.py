"""Exercise std network limits over real sockets, without third-party dependencies."""
import argparse
import base64
import http.client
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]

def connect(port):
    return socket.create_connection(('127.0.0.1', port), timeout=2)

def response(port, data):
    with connect(port) as connection:
        connection.sendall(data)
        return connection.recv(4096)

def websocket(port):
    connection = connect(port)
    key = base64.b64encode(os.urandom(16)).decode()
    connection.sendall((f'GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {key}\r\n\r\n').encode())
    header = b''
    while b'\r\n\r\n' not in header:
        header += connection.recv(4096)
    assert b'101 Switching Protocols' in header, header
    return connection

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--origami', default=str(ROOT / 'build' / ('loom.exe' if os.name == 'nt' else 'loom')))
    args = parser.parse_args()
    runtime = str(Path(args.origami).resolve()) if Path(args.origami).exists() else args.origami
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        process = subprocess.Popen([runtime, 'tests/fixtures/network_limits.php', str(port)], cwd=ROOT, stdout=log, stderr=log,
                                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            for _ in range(60):
                if process.poll() is not None:
                    log.seek(0)
                    raise AssertionError(log.read().decode())
                try:
                    if b'200 OK' in response(port, b'GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n'):
                        break
                except OSError:
                    time.sleep(.1)
            else:
                raise AssertionError('server startup timeout')
            client = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
            try:
                client.request('POST', '/', body=iter([b'1234', b'56789']), encode_chunked=True)
                assert client.getresponse().status == 413, 'chunked request cap'
            finally:
                client.close()
            assert b'413' in response(port, b'POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 9\r\n\r\n'), 'early Content-Length rejection'
            assert b'431' in response(port, b'GET / HTTP/1.1\r\nHost: localhost\r\nX-Large: '+b'x'*20000+b'\r\n\r\n'), 'header limit'
            with connect(port) as slow:
                slow.sendall(b'GET / HTTP/1.1\r\nHost:')
                assert slow.recv(4096) == b'', 'slow header deadline'
            with connect(port) as slow:
                slow.sendall(b'POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\n\r\na')
                time.sleep(.03)
                assert b'503' in response(port, b'GET / HTTP/1.1\r\nHost: localhost\r\n\r\n'), 'global request admission'
                assert b'400' in slow.recv(4096), 'slow body deadline'
            time.sleep(.05)
            with websocket(port) as ws:
                payload=b'x'*17
                mask=b'abcd'
                ws.sendall(bytes([0x81,0x80|len(payload)])+mask+bytes(v^mask[i%4] for i,v in enumerate(payload)))
                frame=ws.recv(256)
                assert frame[0]&15 == 8 and int.from_bytes(frame[2:4],'big') == 1009, frame
            time.sleep(.03)
            with websocket(port) as ws:
                assert ws.recv(256) == b'', 'idle WebSocket deadline'
            time.sleep(.05)
            assert b'200 OK' in response(port,b'GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n'), 'slots reclaimed'
            print('HTTP chunked/header/body limits, slow clients, admission and WebSocket size/idle limits: OK')
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

if __name__ == '__main__':
    main()
