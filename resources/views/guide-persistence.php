<section id="orm"><div class="eyebrow">09 / MODELS WITH MEANING</div><h2>让数据，读起来像业务。</h2><p>Model 声明表、列、允许写入的属性与类型转换。Query 是不可变的：追加条件会返回新查询，不会改变原有用户作用域。任务列表、项目筛选、任务创建与完成操作都已使用这套 ORM。</p><pre class="guide-code"><code>final class StudioProject extends Model
{
    protected static string $table = 'studio_projects';
    protected static array $columns = ['id', 'user_id', 'name', 'created'];
    protected static array $fillable = ['user_id', 'name'];
    protected static array $casts = ['id' =&gt; 'int', 'user_id' =&gt; 'int'];

    public function tasks(): Query
    {
        return StudioTask::query()
            -&gt;where('user_id', '=', $this-&gt;get('user_id'))
            -&gt;where('project_id', '=', $this-&gt;get('id'));
    }
}

$project = StudioProject::query()
    -&gt;where('user_id', '=', $user['id'])
    -&gt;where('id', '=', $projectId)-&gt;firstOrFail();
$tasks = $project-&gt;tasks()-&gt;orderBy('id', 'DESC')-&gt;rows();
$task-&gt;set('completed', 1)-&gt;save();</code></pre><p>支持 create、hydrate、get/set、脏属性保存、delete，以及 where、orderBy、limit、first、firstOrFail、count。NULL 使用 IS NULL / IS NOT NULL；值始终绑定，列名和操作符受校验。批量 update/delete 必须有条件，不能携带 order/limit。读取默认最多 1000 条。</p><p>关系由普通模型方法表达，Studio 同时实现 project() 和 tasks()。当前尚无自动关系预加载、复杂 JOIN DSL、乐观锁或跨数据库适配；用户归属条件仍须由业务明确提供。</p></section>
<section id="migrations"><div class="eyebrow">10 / SCHEMA WITH A HISTORY</div><h2>成长，可以有进有退。</h2><p>每个迁移由一对版本化 SQL 文件组成。Studio 的 001_initial 接纳旧任务清单，002_projects 创建项目与关联列，已有任务保留为“暂不归类”。</p><pre class="guide-code"><code>database/migrations/
  001_initial.up.sql
  001_initial.down.sql
  002_projects.up.sql
  002_projects.down.sql

# 查看版本与批次（不启动 HTTP 服务）
go run ./cmd/loom --migrate=status
# 应用所有待执行版本
go run ./cmd/loom --migrate=up
# 回滚最后一个批次；先停服务、备份并确认 down SQL
go run ./cmd/loom --migrate=down</code></pre><p>Runner 在 BEGIN IMMEDIATE 锁内校验已应用版本和 Up/Down 校验和。全部待执行版本同批提交，任何错误整批回滚；down 按逆序撤销最后一批。新部署的一批可能包含多个版本，不能把 down 理解为只回滚一个文件。</p><p>应用启动自动升级，之后不应编辑已应用迁移；新增版本来表达变更。状态记录包含 namespace、version、checksum、batch 与 applied_at。迁移 SQL 是可信代码，不得包含事务控制语句。Studio 的交互实验使用独立内存数据库，实际完成升级、回滚与失败恢复。</p></section>
<section id="cron"><div class="eyebrow">11 / TIME, MADE EXPLICIT</div><h2>你的节奏，你的时区。</h2><pre class="guide-code"><code>// 每个工作日，上海时间 18:00 生成摘要。
Schedule::cron('studio.digest.' . $user['id'],
    '0 18 * * 1-5', 'Asia/Shanghai',
    'studio.digest', [], $user['id']);

// 与调度器使用相同解析器，返回带 UTC 偏移的五次运行时间。
$times = Schedule::preview('0 18 * * 1-5', 'Asia/Shanghai');</code></pre><p>五字段依次为分、时、日、月、周，支持数字、*、逗号列表、范围与步长（如 */15）。周日可写 0 或 7；日和周都受限制时，匹配任意一项即可。当前不支持月份/星期英文名、秒字段、L/W/# 或宏。</p><p>使用 IANA 时区，运行时包含时区数据库。夏令时跳过的本地时间不执行，回拨后的重复时间可各执行一次；预览包含时区偏移以区分。相同规则重复保存不推迟下一次执行，修改规则则重新计算。停机错过的周期仍合并为一次。</p><p>这些日历规则参考 <a class="text-link" href="https://www.man7.org/linux/man-pages/man5/crontab.5.html">crontab(5) →</a>。到期规则与队列入库在同一事务完成，任务仍须实现幂等。</p></section>
