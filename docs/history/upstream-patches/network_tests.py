from pathlib import Path
r=Path('D:/gitcode.com/origami')
def put(p,s): (r/p).write_text(s,encoding='utf-8')
put('std/net/http/server_limits_test.go',r'''package http
import("bytes";"io";"net/http";"net/http/httptest";"testing";"time")
func TestBodyLimitBeforeHandlerIncludingChunked(t *testing.T){
 for _,length:=range []int64{5,-1}{called:=false;h:=limitedHandler(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){called=true}),map[string]int{"maxRequests":1,"maxBodyBytes":4,"requestTimeoutMs":1000})
 r:=httptest.NewRequest("POST","/",bytes.NewBufferString("12345"));r.ContentLength=length;w:=httptest.NewRecorder();h.ServeHTTP(w,r);if w.Code!=413||called{t.Fatalf("length=%d status=%d called=%v",length,w.Code,called)}}
 h:=limitedHandler(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){b,e:=io.ReadAll(r.Body);if e!=nil||string(b)!="1234"{t.Errorf("body=%q err=%v",b,e)}}),map[string]int{"maxRequests":1,"maxBodyBytes":4,"requestTimeoutMs":1000})
 h.ServeHTTP(httptest.NewRecorder(),httptest.NewRequest("POST","/",bytes.NewBufferString("1234")))
}
func TestConcurrentRequestLimitAndDeadline(t *testing.T){
 entered:=make(chan struct{});done:=make(chan struct{})
 h:=limitedHandler(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){close(entered);<-r.Context().Done()}),map[string]int{"maxRequests":1,"maxBodyBytes":4,"requestTimeoutMs":100})
 go func(){h.ServeHTTP(httptest.NewRecorder(),httptest.NewRequest("GET","/",nil));close(done)}();<-entered
 w:=httptest.NewRecorder();h.ServeHTTP(w,httptest.NewRequest("GET","/",nil));if w.Code!=503{t.Fatal(w.Code)}
 select{case <-done:case <-time.After(time.Second):t.Fatal("request deadline not propagated")}
}
''')
put('std/net/tcp/tcp_test.go',r'''package tcp
import("context";"net";"testing";"time";"io")
func TestReadTimeoutAndCancellationReleaseConnection(t *testing.T){
 ln,e:=net.Listen("tcp","127.0.0.1:0");if e!=nil{t.Fatal(e)};defer ln.Close()
 ctx,cancel:=context.WithCancel(context.Background());defer cancel();done:=make(chan error,1);entered:=make(chan struct{},2)
 go func(){done<-serve(ctx,ln,map[string]int{"maxConnections":1,"readTimeoutMs":100,"writeTimeoutMs":100,"connectionTimeoutMs":1000},func(ctx context.Context,c net.Conn)error{entered<-struct{}{};b:=make([]byte,1);_,e:=c.Read(b);return e})}()
 c,e:=net.DialTimeout("tcp",ln.Addr().String(),time.Second);if e!=nil{t.Fatal(e)};defer c.Close();<-entered
 other,e:=net.DialTimeout("tcp",ln.Addr().String(),time.Second);if e!=nil{t.Fatal(e)};defer other.Close();other.SetReadDeadline(time.Now().Add(time.Second));if _,e=other.Read(make([]byte,1));e!=io.EOF{t.Fatalf("excess connection not closed: %v",e)}
 c.SetReadDeadline(time.Now().Add(time.Second));if _,e=c.Read(make([]byte,1));e!=io.EOF{t.Fatalf("idle connection not closed: %v",e)}
 next,e:=net.DialTimeout("tcp",ln.Addr().String(),time.Second);if e!=nil{t.Fatal(e)};defer next.Close();<-entered;cancel()
 select{case e:=<-done:if e!=nil{t.Fatal(e)};case <-time.After(time.Second):t.Fatal("shutdown blocked")}
}
func TestWriteDeadline(t *testing.T){a,b:=net.Pipe();defer a.Close();defer b.Close();c:=&timedConn{Conn:a,write:20*time.Millisecond};_,e:=c.Write([]byte("blocked"));if n,ok:=e.(net.Error);!ok||!n.Timeout(){t.Fatalf("expected timeout: %v",e)}}
''')
put('std/net/grpc/server_test.go',r'''package grpc
import(
 "context";"net";"os";"path/filepath";"strings";"testing";"time"
 grpcsrc "google.golang.org/grpc"
 "google.golang.org/grpc/codes"
 "google.golang.org/grpc/status"
 "google.golang.org/grpc/credentials/insecure"
 "google.golang.org/grpc/test/bufconn"
 "google.golang.org/grpc/encoding/gzip"
 rv1 "google.golang.org/grpc/reflection/grpc_reflection_v1"
 "google.golang.org/protobuf/types/known/wrapperspb"
)
func TestDynamicUnaryWireReflectionAndLimits(t *testing.T){
 dir:=t.TempDir();e:=os.WriteFile(filepath.Join(dir,"echo.proto"),[]byte(`syntax="proto3";package generic;import "google/protobuf/wrappers.proto";service Echo{rpc Say(google.protobuf.StringValue)returns(google.protobuf.StringValue);}`),0600);if e!=nil{t.Fatal(e)}
 s,e:=compile(context.Background(),[]string{"echo.proto"},[]string{dir});if e!=nil{t.Fatal(e)}
 o:=map[string]int{};for k,v:=range defaults{o[k]=v};o["maxRecvBytes"]=128;o["maxSendBytes"]=128;o["requestTimeoutMs"]=100;o["maxCalls"]=1
 entered:=make(chan struct{},1);release:=make(chan struct{})
 srv,e:=makeServer(s,o,true,func(ctx context.Context,c *rpcCall)error{
 switch c.input{case `"bad"`:c.err=status.Error(codes.InvalidArgument,"bad input");case `"wait"`:entered<-struct{}{};select{case <-release:case <-ctx.Done():return ctx.Err()};case `"large"`:c.output=`"`+strings.Repeat("x",200)+`"`;default:c.output=c.input};c.answered=true;return nil
 });if e!=nil{t.Fatal(e)};defer srv.Stop();ln:=bufconn.Listen(1<<20);defer ln.Close();go srv.Serve(ln)
 cc,e:=grpcsrc.NewClient("passthrough:///test",grpcsrc.WithTransportCredentials(insecure.NewCredentials()),grpcsrc.WithContextDialer(func(context.Context,string)(net.Conn,error){return ln.Dial()}));if e!=nil{t.Fatal(e)};defer cc.Close()
 invoke:=func(text string,options ...grpcsrc.CallOption)error{ctx,cancel:=context.WithTimeout(context.Background(),time.Second);defer cancel();reply:=new(wrapperspb.StringValue);e:=cc.Invoke(ctx,"/generic.Echo/Say",wrapperspb.String(text),reply,options...);if e==nil&&reply.Value!=text{t.Errorf("reply %q != %q",reply.Value,text)};return e}
 if e:=invoke("echo",grpcsrc.UseCompressor(gzip.Name));e!=nil{t.Fatal(e)}
 for _,test:=range []struct{input string;code codes.Code}{{"bad",codes.InvalidArgument},{strings.Repeat("x",129),codes.ResourceExhausted},{"large",codes.ResourceExhausted},{"wait",codes.DeadlineExceeded}}{if e:=invoke(test.input);status.Code(e)!=test.code{t.Fatalf("%s: %v",test.input,e)}}
 <-entered
 done:=make(chan error,1);go func(){done<-invoke("wait")}();<-entered
 if e:=invoke("overflow");status.Code(e)!=codes.ResourceExhausted{t.Fatalf("concurrency: %v",e)};close(release);<-done
 ctx,cancel:=context.WithTimeout(context.Background(),time.Second);defer cancel()
 stream,e:=rv1.NewServerReflectionClient(cc).ServerReflectionInfo(ctx);if e!=nil{t.Fatal(e)}
 if e=stream.Send(&rv1.ServerReflectionRequest{MessageRequest:&rv1.ServerReflectionRequest_FileContainingSymbol{FileContainingSymbol:"generic.Echo"}});e!=nil{t.Fatal(e)}
 answer,e:=stream.Recv();if e!=nil||len(answer.GetFileDescriptorResponse().GetFileDescriptorProto())!=2{t.Fatalf("reflection: %v %v",answer,e)};stream.CloseSend()
}
''')
put('std/net/internal/script/listener_test.go',r'''package script
import("context";"net";"testing";"time")
func TestListenerSlotReleasedOnce(t *testing.T){
 raw,e:=net.Listen("tcp","127.0.0.1:0");if e!=nil{t.Fatal(e)};ln:=LimitListener(raw,1);defer ln.Close()
 accepted:=make(chan net.Conn,1);go func(){c,_:=ln.Accept();accepted<-c}();client,e:=net.DialTimeout("tcp",ln.Addr().String(),time.Second);if e!=nil{t.Fatal(e)};defer client.Close();server:=<-accepted;server.Close();server.Close()
 go func(){c,_:=ln.Accept();accepted<-c}();ctx,cancel:=context.WithTimeout(context.Background(),time.Second);defer cancel();next,e:=(&net.Dialer{}).DialContext(ctx,"tcp",ln.Addr().String());if e!=nil{t.Fatal(e)};defer next.Close();select{case c:=<-accepted:c.Close();case <-ctx.Done():t.Fatal("slot leaked")}
}
''')
