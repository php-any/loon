"""Loopback-only SMTP sink for account delivery tests; never forwards mail."""
import pathlib
import socket
import sys

port, output = int(sys.argv[1]), pathlib.Path(sys.argv[2])
with socket.socket() as server:
    server.bind(('127.0.0.1', port))
    server.listen()
    while True:
        conn, _ = server.accept()
        with conn:
            conn.settimeout(10)
            stream = conn.makefile('rwb')
            stream.write(b'220 localhost test SMTP\r\n'); stream.flush()
            body, data = [], False
            while line := stream.readline():
                line = line.rstrip(b'\r\n')
                if data:
                    if line == b'.':
                        output.write_bytes(b'\n'.join(body))
                        data = False
                        stream.write(b'250 accepted\r\n')
                    else:
                        body.append(line)
                elif line.startswith((b'EHLO', b'HELO', b'MAIL FROM', b'RCPT TO')):
                    stream.write(b'250 OK\r\n')
                elif line == b'DATA':
                    data = True
                    stream.write(b'354 send data\r\n')
                elif line == b'QUIT':
                    stream.write(b'221 bye\r\n'); stream.flush(); break
                else:
                    stream.write(b'500 unsupported\r\n')
                stream.flush()
