<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

$configRoot = sys_get_temp_dir() . '/loom-relation-config-' . bin2hex(random_bytes(6));
mkdir($configRoot . '/config', 0700, true);
file_put_contents($configRoot . '/config/database.php', '<?php return ["dsn" => getenv("LOOM_DATABASE") ?: "mysql:host=127.0.0.1;dbname=loom_test"];');
$scope = new \Loom\ServiceScope(new \Loom\Config($configRoot));

#[\Database\Annotation\Table('rel_authors')]
final class RelAuthor extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public string $name;

    public function posts(): \Loom\HasMany
    {
        return $this->hasMany(RelPost::class, 'author_id');
    }
}

#[\Database\Annotation\Table('rel_posts')]
final class RelPost extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $author_id;
    public string $title;

    public function author(): \Loom\BelongsTo
    {
        return $this->belongsTo(RelAuthor::class);
    }

    public function profile(): \Loom\HasOne
    {
        return $this->hasOne(RelPostProfile::class);
    }

    public function comments(): \Loom\HasMany
    {
        return $this->hasMany(RelComment::class);
    }

    public function tags(): \Loom\BelongsToMany
    {
        return $this->belongsToMany(RelTag::class, 'rel_post_tag');
    }
}

#[\Database\Annotation\Table('rel_post_profiles')]
final class RelPostProfile extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $post_id;
    public string $bio;
}

#[\Database\Annotation\Table('rel_comments')]
final class RelComment extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $post_id;
    public string $body;
}

#[\Database\Annotation\Table('rel_tags')]
final class RelTag extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public string $label;
}

#[\Database\Annotation\Table('rel_post_tag')]
final class RelCompositeMap extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $post_id;
    #[\Database\Annotation\Id]
    public int $tag_id;

    public function comments(): \Loom\HasMany
    {
        return $this->hasMany(RelComment::class, 'post_id');
    }
}

#[\Database\Annotation\Table('rel_accounts')]
final class RelAccount extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $user_id;

    public function user(): \Loom\BelongsTo
    {
        return $this->belongsTo(RelAuthor::class, 'user_id');
    }
}

function relCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new LogicException($message);
    }
}

$database = $scope->container()->get(\Loom\Database::class);
try {
    $database->execute('CREATE TEMPORARY TABLE rel_authors(id BIGINT PRIMARY KEY AUTO_INCREMENT, name TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_posts(id BIGINT PRIMARY KEY AUTO_INCREMENT, author_id BIGINT NOT NULL, title TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_post_profiles(id BIGINT PRIMARY KEY AUTO_INCREMENT, post_id BIGINT NOT NULL, bio TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_comments(id BIGINT PRIMARY KEY AUTO_INCREMENT, post_id BIGINT NOT NULL, body TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_tags(id BIGINT PRIMARY KEY AUTO_INCREMENT, label TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_post_tag(post_id BIGINT NOT NULL, tag_id BIGINT NOT NULL, PRIMARY KEY(post_id, tag_id))');
    $database->execute('CREATE TEMPORARY TABLE rel_accounts(user_id BIGINT PRIMARY KEY)');

    $author = RelAuthor::create(['name' => 'Ada']);
    $post = RelPost::create(['author_id' => $author->id, 'title' => 'First']);
    RelPostProfile::create(['post_id' => $post->id, 'bio' => 'About']);
    RelComment::create(['post_id' => $post->id, 'body' => 'Nice']);
    RelComment::create(['post_id' => $post->id, 'body' => 'More']);
    $tag = RelTag::create(['label' => 'news']);
    $post->setRelationDatabase($database);
    $post->tags()->attach($tag->id);

    $loaded = RelPost::find($post->id);
    $loaded->setRelationDatabase($database);
    $user = $loaded->author()->first();
    relCheck($user instanceof RelAuthor && $user->name === 'Ada', 'belongsTo lazy first');
    $firstGet = $loaded->comments()->get();
    $secondGet = $loaded->comments()->get();
    relCheck($firstGet === $secondGet, 'unconstrained get uses instance cache');
    relCheck(count($loaded->comments()->where('body', 'Nice')->get()) === 1, 'constrained relation issues a fresh query');

    $eager = RelPost::with('author', 'comments')->get();
    relCheck($eager[0]->author()->first()->name === 'Ada' && count($eager[0]->comments()->get()) === 2, 'eager load belongsTo and hasMany');

    $nestedAuthor = RelAuthor::create(['name' => 'Grace']);
    $nestedPost = RelPost::create(['author_id' => $nestedAuthor->id, 'title' => 'Nested']);
    RelPostProfile::create(['post_id' => $nestedPost->id, 'bio' => 'Nested bio']);
    $nested = RelPost::with('author.posts')->get();
    relCheck($nested[0]->author()->first()->name === 'Ada', 'nested with loads belongsTo');
    relCheck(count($nested[0]->author()->first()->posts()->get()) >= 1, 'nested with loads hasMany on related model');

    $has = RelPost::has('comments')->pluck('id');
    relCheck(in_array($post->id, $has, true), 'has relation filter');
    $whereHas = RelPost::whereHas('comments', static fn (\Loom\ModelQuery $q) => $q->where('body', 'Nice'))->first();
    relCheck($whereHas?->id === $post->id, 'whereHas with constraint');
    $missing = RelPost::whereDoesntHave('comments')->pluck('id');
    relCheck(!in_array($post->id, $missing, true), 'whereDoesntHave excludes parents with children');

    $empty = RelPost::whereHas('tags', static fn (\Loom\ModelQuery $q) => $q->whereIn('id', []))->count();
    relCheck($empty === 0, 'empty whereIn lists match no rows');

    $compositeRejected = false;
    try {
        $map = new RelCompositeMap();
        $map->post_id = 1;
        $map->tag_id = 1;
        $map->setRelationDatabase($database);
        $map->comments();
    } catch (LogicException $error) {
        $compositeRejected = str_contains($error->getMessage(), 'Composite keys');
    }
    relCheck($compositeRejected, 'composite parent without explicit key is rejected');

    RelAccount::create(['user_id' => $author->id]);
    $account = RelAccount::find($author->id);
    $account->setRelationDatabase($database);
    relCheck($account->user()->first()?->id === $author->id, 'belongsTo when foreign key is primary key');

    $created = $post->comments()->create(['body' => 'Created via relation']);
    relCheck($created->post_id === $post->id, 'hasMany create fills foreign key');

    $post->tags()->detach($tag->id);
    relCheck($post->tags()->get() === [], 'detach removes pivot rows');
    $post->tags()->sync([$tag->id]);
    relCheck(count($post->tags()->get()) === 1, 'sync attaches pivot rows');

    $beforeCount = count($post->tags()->get());
    try {
        $database->transaction(function () use ($post, $database): void {
            $post->setRelationDatabase($database);
            $tagId = RelTag::create(['label' => 'rollback-me'])->id;
            $post->tags()->attach($tagId);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    $post->setRelationDatabase($database);
    relCheck(count($post->tags()->get()) === $beforeCount && RelTag::whereLabel('rollback-me')->first() === null, 'pivot attach rolls back with transaction');
} finally {
    $scope->close();
}

echo "loom_model_relation_test: OK\n";
