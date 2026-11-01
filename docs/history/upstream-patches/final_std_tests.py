from pathlib import Path
r=Path('D:/gitcode.com/origami')
(r/'std/net/websocket/limits_test.go').write_text(r'''package websocket
import(
 "context";"net/http";"net/http/httptest";"strings";"testing";"time"
 ws "github.com/gorilla/websocket"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/parser"
 "github.com/php-any/origami/runtime"
)
func TestLimitsAndContextCancellation(t *testing.T){
 for _,scenario:=range []string{"size","idle","context","write"}{t.Run(scenario,func(t *testing.T){
  result:=make(chan string,1)
  server:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
   conn,e:=(&ws.Upgrader{}).Upgrade(w,r,nil);if e!=nil{result<-e.Error();return};defer conn.Close()
   base:=runtime.NewVM(parser.NewParser());vm:=runtime.NewRequestVM(base).(*runtime.RequestVM);restore:=vm.Activate();defer restore();defer vm.RunShutdownCallbacks()
   ctx,cancel:=context.WithCancel(context.Background());defer cancel();vm.BindContext(ctx)
   class:=NewConnClassFrom(conn);m,_:=class.GetMethod("configure");scope:=vm.CreateContext(m.GetVariables());scope.SetIndexZVal(0,data.NewZVal(data.NewArrayValueFromSlots([]*data.ZVal{data.NewNamedZVal("maxMessageBytes",data.NewIntValue(16)),data.NewNamedZVal("readTimeoutMs",data.NewIntValue(50))})))
   if _,ctl:=m.Call(scope);ctl!=nil{result<-ctl.AsString();return}
   if scenario=="context"{cancel();conn.SetReadDeadline(time.Now().Add(time.Second))}
   if scenario=="write"{m,_=class.GetMethod("writeText");scope=vm.CreateContext(m.GetVariables());scope.SetIndexZVal(0,data.NewZVal(data.NewStringValue(strings.Repeat("x",17))))}else{m,_=class.GetMethod("readText");scope=vm.CreateContext(m.GetVariables())}
   _,ctl:=m.Call(scope);if ctl==nil{result<-"operation unexpectedly succeeded"}else{result<-"OK"}
  }));defer server.Close()
  client,_,e:=ws.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL,"http"),nil);if e!=nil{t.Fatal(e)};defer client.Close()
  if scenario=="size"{if e:=client.WriteMessage(ws.TextMessage,[]byte(strings.Repeat("x",17)));e!=nil{t.Fatal(e)}}
  select{case got:=<-result:if got!="OK"{t.Fatal(got)};case <-time.After(2*time.Second):t.Fatal("connection did not terminate")}
 })}
}
''',encoding='utf-8')
(r/'std/net/internal/script/binding_test.go').write_text(r'''package script
import("context";"testing";"github.com/php-any/origami/data";"github.com/php-any/origami/parser";"github.com/php-any/origami/runtime")
type callback struct{t *testing.T}
func(f *callback)GetName()string{return "isolated"}
func(f *callback)GetParams()[]data.GetValue{return nil}
func(f *callback)GetVariables()[]data.Variable{return nil}
func(f *callback)Call(c data.Context)(data.GetValue,data.Control){
 vm:=c.GetVM().(*runtime.RequestVM);if vm.EnsureGlobalZVal("probe").ReadValue().AsString()!=""{f.t.Error("state leaked")};vm.EnsureGlobalZVal("probe").StoreRaw(data.NewStringValue("private"));if c.GoContext().Err()!=context.Canceled{f.t.Error("cancellation was not bound")};return nil,nil
}
func TestInvokeIsolationAndCancellation(t *testing.T){base:=runtime.NewVM(parser.NewParser());ctx,cancel:=context.WithCancel(context.Background());cancel();for i:=0;i<2;i++{if e:=Invoke(base,&callback{t:t},ctx,func(data.Context)[]data.Value{return nil});e!=nil{t.Fatal(e)}}}
''',encoding='utf-8')
(r/'tests/php/net_api_test.php').write_text(r'''<?php
function expectInvalid(callable $fn): void { $caught=false;try{$fn();}catch(Throwable $e){$caught=true;}if(!$caught){throw new RuntimeException('invalid option accepted');} }
$http=new Net\Http\Server('127.0.0.1',0);
$http->configure(['maxBodyBytes'=>16,'maxRequests'=>2]);
expectInvalid(function()use($http){$http->configure(['unknown'=>10]);});
expectInvalid(function()use($http){$http->configure(['maxBodyBytes'=>0]);});
$tcp=new Net\Tcp\Server('127.0.0.1:0');
$tcp->configure(['maxConnections'=>2,'readTimeoutMs'=>50]);
expectInvalid(function()use($tcp){$tcp->configure(['readTimeoutMs'=>'50']);});
$path=tempnam(sys_get_temp_dir(),'generic-proto-');
try{
 file_put_contents($path,'syntax="proto3"; import "google/protobuf/wrappers.proto"; service Echo { rpc Say(google.protobuf.StringValue) returns(google.protobuf.StringValue); }');
 $schema=new Net\Grpc\Schema([basename($path)],[dirname($path)]);
 $methods=json_decode($schema->methodsJson(),true);
 if(count($methods)!==1||$methods[0]['path']!=='/Echo/Say'||$methods[0]['input']!=='google.protobuf.StringValue'){throw new RuntimeException('schema metadata');}
 $grpc=new Net\Grpc\Server('127.0.0.1:0',$schema);$grpc->configure(['maxCalls'=>2]);$grpc->reflection(true);
 expectInvalid(function()use($grpc){$grpc->configure(['maxCalls'=>-1]);});
}finally{unlink($path);}
echo "net_api_test: OK\n";
''',encoding='utf-8')
