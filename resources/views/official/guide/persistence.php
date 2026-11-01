<section id="orm"><div class="eyebrow">09 / MODELS WITH MEANING</div><h2>让数据，读起来像业务。</h2><p>继承 Model，使用原生数据库注解定义映射。框架根据配置自动初始化连接，模型和原始 SQL 共用当前事务，业务无需手动注册连接。</p><pre class="guide-code"><code>#[\Database\Annotation\Table('studio_projects')]
final class Project extends \Loom\Model
{
    #[\Database\Annotation\Id]
    #[\Database\Annotation\GeneratedValue]
    public int $id;
    public int $user_id;
    public string $name;
}

$project = Project::create([
    'user_id' =&gt; $user['id'], 'name' =&gt; 'TEST',
]);
$project = Project::whereUserId($user['id'])
    -&gt;whereName('TEST')-&gt;firstOrFail();
$tasks = Task::whereUserId($user['id'])
    -&gt;whereProjectId($project-&gt;id)-&gt;orderBy('id', 'DESC')-&gt;rows();
Task::whereUserId($user['id'])
    -&gt;whereId($taskId)-&gt;update(['completed' =&gt; 0]);</code></pre><p>支持 create、find、动态 whereXxx、链式 where、orderBy、limit、get、first、firstOrFail、count、exists、update、delete。条件按 AND 累积并返回新查询，保留用户归属限制。NULL 使用 IS NULL / IS NOT NULL，值始终绑定；更新和删除必须有条件，不能携带排序或分页。</p><p>项目和任务关系由 ProjectRepository 表达。没有自动预加载或实例 save 的脏字段追踪；大表应显式设置 limit。模型字段声明在具体类上。</p></section>
<section id="migrations"><div class="eyebrow">10 / SCHEMA WITH A HISTORY</div><h2>成长，可以有进有退。</h2><p>每个迁移由一对版本化 SQL 文件组成。应用迁移 001_initial 接纳旧任务清单，002_projects 创建项目与关联列，已有任务保留为“暂不归类”。</p><pre class="guide-code"><code>database/migrations/
  001_initial.up.sql
  001_initial.down.sql
  002_projects.up.sql
  002_projects.down.sql

# 查看版本与批次（不启动 HTTP 服务）
./build/loom.exe migrate:status
# 应用所有待执行版本
./build/loom.exe migrate:up
# 回滚最后一个批次；先停服务、备份并确认 down SQL
./build/loom.exe migrate:down</code></pre><p>迁移器校验已应用版本和 Up/Down 校验和。MySQL 使用迁移锁，DDL 会隐式提交，失败后须检查实际表结构。down 按逆序撤销最后一批。新部署的一批可能包含多个版本，不能把 down 理解为只回滚一个文件。</p><p>应用启动自动升级，之后不应编辑已应用迁移；新增版本来表达变更。状态记录包含 namespace、version、checksum、batch 与 applied_at。迁移 SQL 是可信代码，不得包含事务控制语句。迁移的升级、回滚与失败恢复由隔离测试验证。</p></section>
<section id="cron"><div class="eyebrow">11 / TIME, MADE EXPLICIT</div><h2>你的节奏，你的时区。</h2><pre class="guide-code"><code>// 每个工作日，上海时间 18:00 生成摘要。
$schedule-&gt;cron('studio.digest.' . $user['id'],
    '0 18 * * 1-5', 'Asia/Shanghai',
    'studio.digest', [], $user['id']);

// 与调度器使用相同解析器，返回带 UTC 偏移的五次运行时间。
$times = Schedule::preview('0 18 * * 1-5', 'Asia/Shanghai');</code></pre><p>五字段依次为分、时、日、月、周，支持数字、*、逗号列表、范围与步长（如 */15）。周日可写 0 或 7；日和周都受限制时，匹配任意一项即可。当前不支持月份/星期英文名、秒字段、L/W/# 或宏。</p><p>使用 IANA 时区，运行时包含时区数据库。夏令时跳过的本地时间不执行，回拨后的重复时间可各执行一次；预览包含时区偏移以区分。相同规则重复保存不推迟下一次执行，修改规则则重新计算。停机错过的周期仍合并为一次。</p><p>这些日历规则参考 <a class="text-link" href="https://www.man7.org/linux/man-pages/man5/crontab.5.html">crontab(5) →</a>。到期规则与队列入库在同一事务完成，任务仍须实现幂等。</p></section>
