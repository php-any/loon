from pathlib import Path
root=Path('D:/gitcode.com/origami')
def put(path,source):
 p=root/path; p.parent.mkdir(parents=True,exist_ok=True); p.write_text(source,encoding='utf-8')
def replace(path,old,new):
 p=root/path; s=p.read_text(encoding='utf-8'); assert old in s,path; p.write_text(s.replace(old,new),encoding='utf-8')
put('std/net/internal/script/binding.go',r'''// Package script contains native binding helpers shared by network modules.
package script
import (
 "context"
 "fmt"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/node"
 "github.com/php-any/origami/runtime"
 "github.com/php-any/origami/utils"
)
type Method struct { Name string; Args []string; Run func(data.Context)(data.GetValue,error) }
func (m *Method) GetName() string{return m.Name}
func (m *Method) GetModifier() data.Modifier{return data.ModifierPublic}
func (m *Method) GetIsStatic() bool{return false}
func (m *Method) GetReturnType() data.Types{return data.NewBaseType("mixed")}
func (m *Method) GetParams() []data.GetValue { a:=[]data.GetValue{};for i,n:=range m.Args {a=append(a,node.NewParameter(nil,n,i,nil,nil))};return a }
func (m *Method) GetVariables() []data.Variable { a:=[]data.Variable{};for i,n:=range m.Args {a=append(a,node.NewVariable(nil,n,i,nil))};return a }
func (m *Method) Call(c data.Context)(data.GetValue,data.Control){v,e:=m.Run(c);if e!=nil{return nil,utils.NewThrow(e)};if v==nil{v=data.NewNullValue()};return v,nil}
type Class struct { node.Node; Name string; Factory func()data.ClassStmt; Methods []data.Method; Source any }
func(c *Class)GetName()string{return c.Name}
func(c *Class)GetExtend()*string{return nil}
func(c *Class)GetImplements()[]string{return nil}
func(c *Class)AsString()string{return c.Name+"{}"}
func(c *Class)GetSource()any{return c.Source}
func(c *Class)GetValue(ctx data.Context)(data.GetValue,data.Control){if c.Factory==nil{return nil,utils.NewThrowf("%s cannot be constructed directly",c.Name)};return data.NewProxyValue(c.Factory(),ctx.CreateBaseContext()),nil}
func(c *Class)GetMethod(n string)(data.Method,bool){for _,m:=range c.Methods{if m.GetName()==n{return m,true}};return nil,false}
func(c *Class)GetMethods()[]data.Method{return c.Methods}
func(c *Class)GetConstruct()data.Method{m,_:=c.GetMethod("__construct");return m}
func(c *Class)GetProperty(string)(data.Property,bool){return nil,false}
func(c *Class)GetPropertyList()[]data.Property{return nil}
func Options(ctx data.Context,index int,defaults map[string]int)(map[string]int,error){
 v,ok:=ctx.GetIndexValue(index);if !ok{return nil,fmt.Errorf("options required")};a,ok:=v.(*data.ArrayValue);if !ok{return nil,fmt.Errorf("options must be an array")}
 out:=map[string]int{};for k,v:=range defaults{out[k]=v};for _,z:=range a.Range(){if _,ok:=out[z.Name];!ok{return nil,fmt.Errorf("unknown option %s",z.Name)};v,ok:=z.ReadValue().(*data.IntValue);if !ok{return nil,fmt.Errorf("%s must be an integer",z.Name)};n,e:=utils.Convert[int](v);if e!=nil||n<1||n>1<<30{return nil,fmt.Errorf("%s must be between 1 and 1073741824",z.Name)};out[z.Name]=n};return out,nil
}
// Invoke 创建与 HTTP 相同的隔离语言作用域，并支持取消。
func Invoke(base data.VM,fn data.FuncStmt,goctx context.Context,args func(data.Context)[]data.Value)(err error){
 vm,ok:=runtime.NewRequestVM(base).(*runtime.RequestVM);if !ok{return fmt.Errorf("request VM required")}
 restore:=vm.Activate();defer restore();defer vm.RunShutdownCallbacks();vm.BindContext(goctx)
 defer func(){if p:=recover();p!=nil{err=fmt.Errorf("callback panic: %v",p)}}()
 c:=vm.CreateContext(fn.GetVariables());for i,v:=range args(c){c.SetIndexZVal(i,data.NewZVal(v))};_,ctl:=fn.Call(c);if ctl!=nil{return fmt.Errorf("callback failed: %s",ctl.AsString())};return nil
}
func Callback(ctx data.Context,index int)(data.FuncStmt,error){v,ok:=ctx.GetIndexValue(index);if ok{if f,ok:=v.(*data.FuncValue);ok{return f.Value,nil}};return nil,fmt.Errorf("callback must be a closure")}
''')
replace('runtime/request_vm.go','"fmt"','"fmt"\n "context"')
replace('runtime/request_vm.go','func (vm *RequestVM) BindHTTP(','''// BindContext binds cancellation to a non-HTTP request scope.
func (vm *RequestVM) BindContext(ctx context.Context) {
 st := vm.requestCall()
 if st.deadline == nil { st.deadline = newRequestCancellation(ctx, st.ignoreUserAbort) }
}
func (vm *RequestVM) BindHTTP(''')
put('std/net/tcp/tcp.go',r'''// Package tcp provides bounded stream servers without imposing a wire protocol.
package tcp
import (
 "context"
 "errors"
 "io"
 "net"
 "sync"
 "time"
 "fmt"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/std/net/internal/script"
 "github.com/php-any/origami/utils"
)
func Load(vm data.VM){vm.AddClass(NewServer());vm.AddClass(connection(nil))}
func NewServer()data.ClassStmt{
 address:=""; opts:=map[string]int{"maxConnections":128,"readTimeoutMs":10000,"writeTimeoutMs":10000,"connectionTimeoutMs":60000}
 c:=&script.Class{Name:"Net\\Tcp\\Server",Factory:NewServer}
 c.Methods=[]data.Method{
 &script.Method{Name:"__construct",Args:[]string{"address"},Run:func(ctx data.Context)(data.GetValue,error){var e error;address,e=utils.ConvertFromIndex[string](ctx,0);return nil,e}},
 &script.Method{Name:"configure",Args:[]string{"options"},Run:func(ctx data.Context)(data.GetValue,error){v,e:=script.Options(ctx,0,opts);if e==nil{opts=v};return nil,e}},
 &script.Method{Name:"serve",Args:[]string{"callback"},Run:func(ctx data.Context)(data.GetValue,error){fn,e:=script.Callback(ctx,0);if e!=nil{return nil,e};ln,e:=net.Listen("tcp",address);if e!=nil{return nil,e};defer ln.Close()
 return nil,serve(ctx.GoContext(),ln,opts,func(gc context.Context,n net.Conn)error{return script.Invoke(ctx.GetVM(),fn,gc,func(c data.Context)[]data.Value{return []data.Value{data.NewProxyValue(connection(n),c)}})})}},
 };return c
}
func serve(ctx context.Context,ln net.Listener,opts map[string]int,callback func(context.Context,net.Conn)error)error{
 slots:=make(chan struct{},opts["maxConnections"]);var wg sync.WaitGroup
 stop:=context.AfterFunc(ctx,func(){ln.Close()});defer stop();defer wg.Wait()
 for {conn,e:=ln.Accept();if e!=nil{if ctx.Err()!=nil{return nil};return e};select{case slots<-struct{}{}:default:conn.Close();continue}
 wg.Add(1);go func(){defer wg.Done();defer func(){<-slots}();defer conn.Close()
 gc,cancel:=context.WithTimeout(ctx,time.Duration(opts["connectionTimeoutMs"])*time.Millisecond);defer cancel();stop:=context.AfterFunc(gc,func(){conn.Close()});defer stop()
 wrapped:=&timedConn{Conn:conn,read:time.Duration(opts["readTimeoutMs"])*time.Millisecond,write:time.Duration(opts["writeTimeoutMs"])*time.Millisecond}
 // 脚本负责报告应用错误；未捕获的错误会关闭此流。
 _=callback(gc,wrapped)
 }()}
}
type timedConn struct{net.Conn;read,write time.Duration}
func(c *timedConn)Read(p []byte)(int,error){if e:=c.Conn.SetReadDeadline(time.Now().Add(c.read));e!=nil{return 0,e};return c.Conn.Read(p)}
func(c *timedConn)Write(p []byte)(int,error){if e:=c.Conn.SetWriteDeadline(time.Now().Add(c.write));e!=nil{return 0,e};return c.Conn.Write(p)}
func connection(n net.Conn)data.ClassStmt{
 c:=&script.Class{Name:"Net\\Tcp\\Conn",Source:n}
 c.Methods=[]data.Method{
 &script.Method{Name:"read",Args:[]string{"maxBytes"},Run:func(ctx data.Context)(data.GetValue,error){if n==nil{return nil,fmt.Errorf("uninitialized connection")};size,e:=utils.ConvertFromIndex[int](ctx,0);if e!=nil{return nil,e};if size<1||size>16<<20{return nil,fmt.Errorf("read size must be 1..16777216")};b:=make([]byte,size);k,e:=n.Read(b);if errors.Is(e,io.EOF){e=nil};return data.NewStringValue(string(b[:k])),e}},
 &script.Method{Name:"write",Args:[]string{"bytes"},Run:func(ctx data.Context)(data.GetValue,error){if n==nil{return nil,fmt.Errorf("uninitialized connection")};b,e:=utils.ConvertFromIndex[string](ctx,0);if e!=nil{return nil,e};total:=0;for total<len(b){k,e:=io.WriteString(n,b[total:]);total+=k;if e!=nil{return nil,e};if k==0{return nil,io.ErrShortWrite}};return data.NewIntValue(total),nil}},
 &script.Method{Name:"close",Run:func(data.Context)(data.GetValue,error){if n==nil{return nil,nil};return nil,n.Close()}},
 };return c
}
''')
replace('zy.go','"github.com/php-any/origami/std/net/websocket"','"github.com/php-any/origami/std/net/websocket"\n "github.com/php-any/origami/std/net/tcp"')
replace('zy.go','websocket.Load(vm)','websocket.Load(vm)\n tcp.Load(vm)')
put('std/net/internal/script/listener.go',r'''package script
import("net";"sync")
// LimitListener 限制已接受的活动连接数，包括空闲和已升级的连接。
func LimitListener(ln net.Listener,max int)net.Listener{return &limitedListener{Listener:ln,slots:make(chan struct{},max)}}
type limitedListener struct{net.Listener;slots chan struct{}}
func(l *limitedListener)Accept()(net.Conn,error){for{c,e:=l.Listener.Accept();if e!=nil{return nil,e};select{case l.slots<-struct{}{}:return &limitedConn{Conn:c,release:func(){<-l.slots}},nil;default:c.Close()}}}
type limitedConn struct{net.Conn;once sync.Once;release func()}
func(c *limitedConn)Close()error{e:=c.Conn.Close();c.once.Do(c.release);return e}
''')
replace('std/net/http/server_class.go','Prefix string','limits map[string]int\n Prefix string')
replace('std/net/http/server_class.go','case "run":','case "configure":\n return s.configureMethod(), true\n case "run":')
replace('std/net/http/server_class.go','&ServerRunMethod{server: s},','s.configureMethod(),\n &ServerRunMethod{server: s},')
put('std/net/http/server_limits.go',r'''package http
import(
 "bytes"
 "context"
 "errors"
 "io"
 "net/http"
 "time"
 "github.com/php-any/origami/data"
 "github.com/php-any/origami/std/net/internal/script"
)
func(s *ServerClass)configureMethod()data.Method{return &script.Method{Name:"configure",Args:[]string{"options"},Run:func(c data.Context)(data.GetValue,error){
 defaults:=map[string]int{"maxConnections":256,"maxRequests":128,"maxBodyBytes":1048576,"maxHeaderBytes":16384,"readHeaderTimeoutMs":5000,"readTimeoutMs":10000,"writeTimeoutMs":10000,"idleTimeoutMs":30000,"requestTimeoutMs":10000}
 if s.limits!=nil{defaults=s.limits};v,e:=script.Options(c,0,defaults);if e==nil{s.limits=v};return nil,e
}}}
func limitedHandler(next http.Handler,limits map[string]int)http.Handler{
 slots:=make(chan struct{},limits["maxRequests"])
 return http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
 select{case slots<-struct{}{}:defer func(){<-slots}();default:http.Error(w,"Service Unavailable",503);return}
 ctx,cancel:=context.WithTimeout(r.Context(),time.Duration(limits["requestTimeoutMs"])*time.Millisecond);defer cancel();r=r.WithContext(ctx)
 if r.ContentLength>int64(limits["maxBodyBytes"]){http.Error(w,"Request Entity Too Large",413);return}
 // 在语言超全局变量或请求体辅助函数读取之前，限制分块请求体大小。
 body:=http.MaxBytesReader(w,r.Body,int64(limits["maxBodyBytes"]));defer body.Close()
 b,e:=io.ReadAll(body);if e!=nil{var large *http.MaxBytesError;if errors.As(e,&large){http.Error(w,"Request Entity Too Large",413)}else{http.Error(w,"Cannot read request body",400)};return}
 r.Body=io.NopCloser(bytes.NewReader(b));next.ServeHTTP(w,r)
 })
}
''')
replace('std/net/http/server_run.go','"fmt"','"fmt"\n "net"\n "time"\n "github.com/php-any/origami/std/net/internal/script"')
replace('std/net/http/server_run.go','ctx.GetVM().AddShutdownCallback', '''if l:=h.server.limits;l!=nil {
 srv.Handler=limitedHandler(srv.Handler,l)
 srv.MaxHeaderBytes=l["maxHeaderBytes"]
 srv.ReadHeaderTimeout=time.Duration(l["readHeaderTimeoutMs"])*time.Millisecond
 srv.ReadTimeout=time.Duration(l["readTimeoutMs"])*time.Millisecond
 srv.WriteTimeout=time.Duration(l["writeTimeoutMs"])*time.Millisecond
 srv.IdleTimeout=time.Duration(l["idleTimeoutMs"])*time.Millisecond
 }
 ctx.GetVM().AddShutdownCallback''')
replace('std/net/http/server_run.go','err := srv.ListenAndServe()', '''ln,err:=net.Listen("tcp",net.JoinHostPort(h.server.Host,fmt.Sprint(h.server.Port)))
 if err!=nil{return nil,utils.NewThrow(err)}
 if h.server.limits!=nil{ln=script.LimitListener(ln,h.server.limits["maxConnections"])}
 defer ln.Close()
 err = srv.Serve(ln)''')
replace('std/net/websocket/conn_class.go','"errors"','"errors"\n "time"\n "fmt"\n "github.com/php-any/origami/std/net/internal/script"')
replace('std/net/websocket/conn_class.go','source *websocket.Conn\n}', 'source *websocket.Conn\n limits map[string]int\n}')
replace('std/net/websocket/conn_class.go','case "readText":', 'case "configure":\n return s.configureMethod(),true\n case "readText":')
replace('std/net/websocket/conn_class.go','&ConnReadTextMethod{source: s.source}', '&ConnReadTextMethod{source: s.source, limits:s.limits}')
replace('std/net/websocket/conn_class.go','&ConnWriteTextMethod{source: s.source}', '&ConnWriteTextMethod{source: s.source, limits:s.limits}')
replace('std/net/websocket/conn_class.go','return []data.Method{','return []data.Method{\n s.configureMethod(),')
replace('std/net/websocket/conn_class.go','type ConnReadTextMethod struct {\n\tsource *websocket.Conn','type ConnReadTextMethod struct {\n limits map[string]int\n source *websocket.Conn')
replace('std/net/websocket/conn_class.go','type ConnWriteTextMethod struct {\n\tsource *websocket.Conn','type ConnWriteTextMethod struct {\n limits map[string]int\n source *websocket.Conn')
replace('std/net/websocket/conn_class.go','_, payload, err := h.source.ReadMessage()', '''if h.limits!=nil{if e:=h.source.SetReadDeadline(time.Now().Add(time.Duration(h.limits["readTimeoutMs"])*time.Millisecond));e!=nil{return nil,utils.NewThrow(e)}}
 _, payload, err := h.source.ReadMessage()''')
replace('std/net/websocket/conn_class.go','if err = h.source.WriteMessage', '''if h.limits!=nil{if len(msg)>h.limits["maxMessageBytes"]{return nil,utils.NewThrowf("message exceeds maxMessageBytes")};if e:=h.source.SetWriteDeadline(time.Now().Add(time.Duration(h.limits["writeTimeoutMs"])*time.Millisecond));e!=nil{return nil,utils.NewThrow(e)}}
 if err = h.source.WriteMessage''')
p=root/'std/net/websocket/conn_class.go'
p.write_text(p.read_text()+r'''
// configure 使用毫秒设置 I/O 截止时间；读取限制在分配内存之前生效。
func(s *ConnClass)configureMethod()data.Method{return &script.Method{Name:"configure",Args:[]string{"options"},Run:func(c data.Context)(data.GetValue,error){
 if s.source==nil{return nil,fmt.Errorf("uninitialized websocket")}
 v,e:=script.Options(c,0,map[string]int{"maxMessageBytes":1048576,"readTimeoutMs":10000,"writeTimeoutMs":10000});if e==nil{s.limits=v;s.source.SetReadLimit(int64(v["maxMessageBytes"]))};return nil,e
}}}
''',encoding='utf-8')
