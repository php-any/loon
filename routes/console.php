<?php
declare(strict_types=1);

use App\Models\StudioTask;
use App\Models\StudioProject;

use Loom\Console\Input;
use Loom\Console\Output;
use Loom\Console\InputError;
use Loom\Database;
use Loom\Queue;

$console->command('inspire {name=世界 : 问候的名字}', function (Input $input, Output $output) {
    $output->info('你好，' . $input->argument('name') . '。让每一个想法，向前一步。');
}, '一条不依赖数据库的脚本命令');

$console->command('studio:tasks {user : 用户 ID} {--completed : 仅显示已完成任务} {--P|project= : 按项目 ID 筛选} {--json : 输出 JSON}', function (Input $input, Output $output) {
    $id = consoleUser($input->argument('user'));
    $query = StudioTask::query()->where('user_id', '=', $id);
    if ($input->option('completed')) { $query = $query->where('completed', '=', 1); }
    if ($input->option('project') !== null) {
        $project = $input->option('project');
        if (!ctype_digit($project) || (int) $project < 1) { throw new InputError('project 必须是正整数'); }
        StudioProject::query()->where('user_id', '=', $id)->where('id', '=', (int) $project)->firstOrFail();
        $query = $query->where('project_id', '=', (int) $project);
    }
    $rows = $query->orderBy('id', 'DESC')->rows();
    if ($input->option('json')) { $output->json($rows); return 0; }
    $table = []; foreach ($rows as $task) { $table[] = [$task['id'], $task['completed'] ? '已完成' : '待完成', $task['title']]; }
    $output->table(['编号', '状态', '任务'], $table); $output->line(count($rows) . ' 个任务'); return 0;
}, '通过 ORM 查询某个用户的任务');

$console->command('studio:digest {user : 用户 ID} {--delay=0 : 延迟秒数，0–86400} {--dry-run : 只检查，不入队}', function (Input $input, Output $output) {
    $id = consoleUser($input->argument('user')); $delay = $input->option('delay');
    if (!ctype_digit($delay) || (int) $delay > 86400) { throw new InputError('delay 必须是 0–86400 的整数'); }
    if ($input->option('dry-run')) { $output->info('检查通过：将为用户 ' . $id . ' 生成摘要，延迟 ' . $delay . ' 秒。'); return 0; }
    $job = Queue::dispatch('studio.digest', [], $id, (int) $delay);
    $output->info('已入队 Job #' . $job . '；由运行中的 Studio worker 执行。'); return 0;
}, '把进展摘要交给持久化队列');

$console->command('make:command {name : PascalCase 命令类名} {--command= : 命令签名}', function (Input $input, Output $output) use ($console) {
    $name = $input->argument('name');
    if (!preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/D', $name)) { throw new InputError('name 必须是单个 PascalCase 类名，不接受路径或命名空间'); }
    $reserved = explode(' ', 'abstract and array as bool break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum eval exit extends false final finally float fn for foreach function global goto if implements include include_once instanceof insteadof int interface isset iterable list match mixed namespace never new null object or parent print private protected public readonly require require_once resource return self static string switch throw trait true try unset use var void while xor yield');
    if (in_array(strtolower($name), $reserved, true)) { throw new InputError('name 不能是 PHP 保留字'); }
    $signature = $input->option('command') ?? ('app:' . strtolower($name));
    try { $definition = new \Loom\Console\Definition($signature, ''); }
    catch (\LogicException $error) { throw new InputError($error->getMessage()); }
    if ($console->has($definition->name)) { throw new InputError('命令名已存在：' . $definition->name); }
    $directory = dirname(__DIR__) . '/app/Console/Commands';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('无法创建命令目录'); }
    $path = $directory . '/' . $name . '.php';
    if (file_exists($path)) { throw new InputError('命令文件已存在，未覆盖：' . $path); }
    $source = "<?php\ndeclare(strict_types=1);

use App\Models\StudioTask;
use App\Models\StudioProject;\n\nnamespace App\\Console\\Commands;\n\nuse Loom\\Console\\Command;\n\nfinal class " . $name . " extends Command\n{\n    protected string \$signature = " . var_export($signature, true) . ";\n    protected string \$description = '请填写命令说明';\n\n    public function handle(): int\n    {\n        \$this->info('命令已就绪，请在 handle 方法中实现业务逻辑。');\n        return 0;\n    }\n}\n";
    // 使用独占创建模式，避免并发执行时覆盖已有文件。
    $handle = fopen($path, 'x'); if ($handle === false) { throw new RuntimeException('无法独占创建命令文件'); }
    try { if (fwrite($handle, $source) !== strlen($source)) { throw new RuntimeException('命令文件写入不完整'); } }
    finally { fclose($handle); }
    $output->info('已创建：' . $path);
}, '生成自动注册的 PHP 命令类，不覆盖已有文件');

function consoleUser(string $value): int
{
    if (!ctype_digit($value) || (int) $value < 1) { throw new InputError('user 必须是正整数'); }
    $id = (int) $value;
    if (Database::query('SELECT id FROM loom_users WHERE id=?', [$id]) === []) { throw new InputError('用户不存在：' . $value); }
    return $id;
}
