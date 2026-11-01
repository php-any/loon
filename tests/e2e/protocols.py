"""Real TCP/gRPC integration; requires grpcio, grpcio-tools and grpcio-reflection.
Run: python tests/e2e/protocols.py --origami build/loom.exe
"""
import argparse
import concurrent.futures
import importlib
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import grpc
from grpc_tools import protoc
from grpc_reflection.v1alpha import reflection_pb2, reflection_pb2_grpc
from google.protobuf.wrappers_pb2 import StringValue

ROOT = Path(__file__).resolve().parents[2]

def start(runtime, protocol, stack):
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ, **{f'LOOM_{protocol.upper()}_ADDRESS': f'127.0.0.1:{port}'})
    log = tempfile.TemporaryFile()
    process = subprocess.Popen([runtime, 'loom.php', protocol + ':serve'], cwd=ROOT,
                               env=env, stdout=log, stderr=log,
                               creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    stack.append((process, log))
    for _ in range(80):
        if process.poll() is not None:
            log.seek(0)
            raise AssertionError(log.read().decode())
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.2):
                return port
        except OSError:
            time.sleep(.1)
    raise AssertionError('server startup timeout')

def tcp_test(port):
    with socket.create_connection(('127.0.0.1', port), timeout=3) as conn:
        reader = conn.makefile('rb')
        conn.sendall(b'{"act')
        conn.sendall(b'ion":"ping"}\n{"action":"greet","name":"Ada"}\ninvalid\n')
        assert json.loads(reader.readline())['result']['pong'] is True
        assert json.loads(reader.readline())['error'] == 'unknown_action'
        assert json.loads(reader.readline())['error'] == 'invalid_message'
        conn.sendall(b'{"action":"ping"}\r\n')
        assert json.loads(reader.readline())['result']['pong'] is True
        reader.close()
    with socket.create_connection(('127.0.0.1', port), timeout=3) as conn:
        try:
            conn.sendall(b'x' * (1048576 + 1))
            assert conn.recv(1) == b''
        except ConnectionResetError:
            pass
    def ping(_):
        with socket.create_connection(('127.0.0.1', port), timeout=3) as conn:
            conn.sendall(b'{"action":"ping"}\n')
            return json.loads(conn.recv(1024))['result']['pong']
    with concurrent.futures.ThreadPoolExecutor(8) as executor:
        assert all(executor.map(ping, range(20)))

def grpc_test(port, temp):
    import grpc_tools
    include = str(Path(grpc_tools.__file__).parent / '_proto')
    assert protoc.main(['protoc', '-I' + str(ROOT / 'proto'), '-I' + include,
                        '--python_out=' + temp, '--grpc_python_out=' + temp,
                        'release.proto']) == 0
    sys.path.insert(0, temp)
    importlib.import_module('release_pb2')
    channel = grpc.insecure_channel(f'127.0.0.1:{port}')
    try:
        grpc.channel_ready_future(channel).result(timeout=5)
        evaluate = channel.unary_unary('/loom.examples.ReleasePolicy/Evaluate', request_serializer=StringValue.SerializeToString,
                                     response_deserializer=StringValue.FromString)
        def counts(total, completed):
            return StringValue(value=json.dumps({'total': total, 'completed': completed}))
        assert json.loads(evaluate(counts(3, 3), timeout=3).value)['ready'] is True
        assert json.loads(evaluate(counts(3, 2), timeout=3).value)['ready'] is False
        assert json.loads(evaluate(counts(0, 0), timeout=3).value)['ready'] is False
        assert json.loads(evaluate(counts(3, 3), timeout=3, compression=grpc.Compression.Gzip).value)['ready'] is True
        for call, request, code in [(evaluate, StringValue(), grpc.StatusCode.INVALID_ARGUMENT),
                                    (evaluate, counts(1, 2), grpc.StatusCode.INVALID_ARGUMENT),
                                    (evaluate, StringValue(value='x' * 1048577), grpc.StatusCode.RESOURCE_EXHAUSTED)]:
            try:
                call(request, timeout=3)
                raise AssertionError('expected ' + str(code))
            except grpc.RpcError as error:
                assert error.code() == code, error
        unknown = channel.unary_unary('/missing.Service/Call')
        try:
            unknown(b'', timeout=3)
            raise AssertionError('unknown RPC accepted')
        except grpc.RpcError as error:
            assert error.code() == grpc.StatusCode.UNIMPLEMENTED
        reflection = reflection_pb2_grpc.ServerReflectionStub(channel)
        requests = iter([reflection_pb2.ServerReflectionRequest(list_services=''),
                         reflection_pb2.ServerReflectionRequest(file_containing_symbol='loom.examples.ReleasePolicy')])
        responses = list(reflection.ServerReflectionInfo(requests, timeout=3))
        services = [s.name for s in responses[0].list_services_response.service]
        assert 'loom.examples.ReleasePolicy' in services
        assert 'loom.examples.Greeter' not in services and 'app.users.UserService' not in services
        assert len(responses[1].file_descriptor_response.file_descriptor_proto) >= 2
        with concurrent.futures.ThreadPoolExecutor(8) as executor:
            totals = list(range(20))
            replies = list(executor.map(lambda n: json.loads(evaluate(counts(n, n), timeout=3).value), totals))
            assert [r['total'] for r in replies] == totals
            assert [r['ready'] for r in replies] == [n > 0 for n in totals]
    finally:
        channel.close()

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--origami', default=str(ROOT / 'build' / ('loom.exe' if os.name == 'nt' else 'loom')))
    args = parser.parse_args()
    runtime = str(Path(args.origami).resolve()) if Path(args.origami).exists() else args.origami
    stack = []
    try:
        tcp_test(start(runtime, 'tcp', stack))
        with tempfile.TemporaryDirectory(prefix='loom-protocols-') as temp:
            grpc_test(start(runtime, 'grpc', stack), temp)
        print('TCP framing, gRPC protobuf/imports/reflection/gzip/status/limits/concurrency: OK')
    finally:
        for process, log in stack:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
            log.close()

if __name__ == '__main__':
    main()
