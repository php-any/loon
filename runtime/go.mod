module github.com/php-any/loom/runtime

go 1.27.1

require (
	github.com/go-sql-driver/mysql v1.9.3
	github.com/gorilla/websocket v1.5.3
	github.com/php-any/origami v1.0.8
	golang.org/x/net v0.47.0
	google.golang.org/grpc v1.76.0
	google.golang.org/protobuf v1.36.11
)

require (
	filippo.io/edwards25519 v1.1.0 // indirect
	github.com/bufbuild/protocompile v0.14.1 // indirect
	github.com/dlclark/regexp2 v1.11.5 // indirect
	github.com/klauspost/cpuid/v2 v2.0.9 // indirect
	github.com/ncruces/go-strftime v1.0.0 // indirect
	github.com/zeebo/xxh3 v1.0.2 // indirect
	golang.org/x/crypto v0.45.0 // indirect
	golang.org/x/sync v0.20.0 // indirect
	golang.org/x/sys v0.42.0 // indirect
	golang.org/x/text v0.31.0 // indirect
	google.golang.org/genproto/googleapis/rpc v0.0.0-20250804133106-a7a43d27e69b // indirect
)

// 本机 Origami 检出：v1.0.8 加上执行帧 context 与 Database\bind。
replace github.com/php-any/origami => ../../origami-frame-context
