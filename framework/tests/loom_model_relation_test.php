<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/framework/bootstrap.php';

$configRoot = sys_get_temp_dir() . '/loom-relation-config-' . bin2hex(random_bytes(6));
mkdir($configRoot . '/config', 0700, true);
file_put_contents($configRoot . '/config/database.php', '<?php return ["dsn" => getenv("LOOM_DATABASE") ?: "mysql:host=127.0.0.1;dbname=loom_test"];');
$scope = new \Loom\ServiceScope(new \Loom\Config($configRoot));

#[\Database\Annotation\Table('rel_authors')]
final class Author extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public string $name;

    public function posts(): \Loom\HasMany
    {
        return $this->hasMany(Post::class);
    }
}

#[\Database\Annotation\Table('rel_posts')]
final class Post extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $author_id;
    public string $title;

    public function author(): \Loom\BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function profile(): \Loom\HasOne
    {
        return $this->hasOne(PostProfile::class);
    }

    public function comments(): \Loom\HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function notes(): \Loom\HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tags(): \Loom\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'rel_post_tag');
    }
}

#[\Database\Annotation\Table('rel_post_profiles')]
final class PostProfile extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $post_id;
    public string $bio;
}

#[\Database\Annotation\Table('rel_comments')]
final class Comment extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $post_id;
    public string $body;
}

#[\Database\Annotation\Table('rel_notes')]
final class Note extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $post_id;
    public string $text;
}

#[\Database\Annotation\Table('rel_tags')]
final class Tag extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public string $label;
}

#[\Database\Annotation\Table('rel_post_tag')]
final class CompositeMap extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $post_id;
    #[\Database\Annotation\Id]
    public int $tag_id;

    public function comments(): \Loom\HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}

#[\Database\Annotation\Table('rel_accounts')]
final class Account extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $user_id;

    public function user(): \Loom\BelongsTo
    {
        return $this->belongsTo(Author::class, 'user_id');
    }
}

#[\Database\Annotation\Table('rel_orders')]
final class Order extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;

    public function lines(): \Loom\HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}

#[\Database\Annotation\Table('rel_order_lines')]
final class OrderLine extends \Loom\Model
{
    #[\Database\Annotation\Id]
    public int $id;
    public int $order_id;
    public string $sku;
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
    $database->execute('CREATE TEMPORARY TABLE rel_notes(id BIGINT PRIMARY KEY AUTO_INCREMENT, post_id BIGINT NOT NULL, text TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_tags(id BIGINT PRIMARY KEY AUTO_INCREMENT, label TEXT NOT NULL)');
    $database->execute('CREATE TEMPORARY TABLE rel_post_tag(post_id BIGINT NOT NULL, tag_id BIGINT NOT NULL, PRIMARY KEY(post_id, tag_id))');
    $database->execute('CREATE TEMPORARY TABLE rel_accounts(user_id BIGINT PRIMARY KEY)');
    $database->execute('CREATE TEMPORARY TABLE rel_orders(id BIGINT PRIMARY KEY AUTO_INCREMENT)');
    $database->execute('CREATE TEMPORARY TABLE rel_order_lines(id BIGINT PRIMARY KEY AUTO_INCREMENT, order_id BIGINT NOT NULL, sku TEXT NOT NULL)');

    $author = Author::create(['name' => 'Ada']);
    $post = Post::create(['author_id' => $author->id, 'title' => 'First']);
    PostProfile::create(['post_id' => $post->id, 'bio' => 'About']);
    Comment::create(['post_id' => $post->id, 'body' => 'Nice']);
    Comment::create(['post_id' => $post->id, 'body' => 'More']);
    Note::create(['post_id' => $post->id, 'text' => 'memo']);
    $tag = Tag::create(['label' => 'news']);
    $post->setRelationDatabase($database);
    $post->tags()->attach($tag->id);

    $order = Order::create([]);
    $line = OrderLine::create(['order_id' => $order->id, 'sku' => 'SKU-1']);
    relCheck($order->lines()->first()?->id === $line->id, 'default hasMany key inference uses order_id');

    $loaded = Post::find($post->id);
    $loaded->setRelationDatabase($database);
    $user = $loaded->author()->first();
    relCheck($user instanceof Author && $user->name === 'Ada', 'belongsTo lazy first');
    $profile = $loaded->profile()->first();
    relCheck($profile instanceof PostProfile && $profile->bio === 'About', 'hasOne lazy first');

    $comments = $loaded->comments()->get();
    relCheck(count($comments) === 2 && $comments[0] instanceof Comment, 'hasMany lazy get');
    $tags = $loaded->tags()->get();
    relCheck(count($tags) === 1 && $tags[0] instanceof Tag, 'belongsToMany lazy get');
    relCheck($loaded->hasRelationCache('comments') && $loaded->hasRelationCache('tags'), 'distinct relation cache keys');
    relCheck($comments !== $tags, 'different relations do not share cache storage');

    $secondComments = $loaded->comments()->get();
    relCheck($secondComments === $comments, 'unconstrained repeat get uses instance cache');

    $notes = $loaded->notes()->get();
    relCheck(count($notes) === 1 && $notes !== $comments, 'second hasMany keeps its own cache');

    relCheck(count($loaded->comments()->where('body', 'Nice')->get()) === 1, 'constrained relation issues a fresh query');

    $eager = Post::with('author', 'comments')->get();
    relCheck($eager[0]->author()->first() instanceof Author && count($eager[0]->comments()->get()) === 2, 'eager load belongsTo and hasMany');

    $nestedAuthor = Author::create(['name' => 'Grace']);
    Post::create(['author_id' => $nestedAuthor->id, 'title' => 'Nested']);
    $nested = Post::with('author.posts')->get();
    relCheck($nested[1]->author()->first()->name === 'Grace', 'nested with loads belongsTo');
    relCheck(count($nested[1]->author()->first()->posts()->get()) >= 1, 'nested with loads hasMany on related model');

    $sibling = Post::create(['author_id' => $author->id, 'title' => 'Sibling']);
    Comment::create(['post_id' => $sibling->id, 'body' => 'Unrelated']);

    $has = Post::has('comments')->pluck('id');
    relCheck(in_array($post->id, $has, true) && in_array($sibling->id, $has, true), 'has relation filter');
    $matching = Post::whereHas('comments', static fn (\Loom\ModelQuery $q) => $q->where('body', 'Nice'))->pluck('id');
    relCheck(in_array($post->id, $matching, true) && !in_array($sibling->id, $matching, true), 'whereHas applies constraint inside EXISTS');
    $missing = Post::whereDoesntHave('comments')->pluck('id');
    relCheck(!in_array($post->id, $missing, true) && !in_array($sibling->id, $missing, true), 'whereDoesntHave excludes parents with children');

    $empty = Post::whereHas('tags', static fn (\Loom\ModelQuery $q) => $q->whereIn('id', []))->count();
    relCheck($empty === 0, 'empty whereIn lists match no rows');

    $constrainedEager = Post::with(['comments' => static fn (\Loom\ModelQuery $q) => $q->where('body', 'Nice')])->whereId($post->id)->get();
    relCheck(count($constrainedEager) === 1 && count($constrainedEager[0]->comments()->get()) === 1
        && $constrainedEager[0]->comments()->get()[0]->body === 'Nice', 'with constraint eager-loads filtered comments');

    $compositeRejected = false;
    try {
        $map = new CompositeMap();
        $map->post_id = 1;
        $map->tag_id = 1;
        $map->setRelationDatabase($database);
        $map->comments();
    } catch (LogicException $error) {
        $compositeRejected = str_contains($error->getMessage(), 'Composite keys');
    }
    relCheck($compositeRejected, 'composite parent without explicit key is rejected');

    Account::create(['user_id' => $author->id]);
    $account = Account::find($author->id);
    $account->setRelationDatabase($database);
    relCheck($account->user()->first()?->id === $author->id, 'belongsTo when foreign key is primary key');

    $countBeforeCreate = count($loaded->comments()->get());
    $loaded->comments()->create(['body' => 'Created via relation']);
    $afterCreate = $loaded->comments()->get();
    relCheck(count($afterCreate) === $countBeforeCreate + 1, 'hasMany create clears stale relation cache');

    $bare = Post::create(['author_id' => $author->id, 'title' => 'Bare']);
    $bare->setRelationDatabase($database);
    relCheck($bare->profile()->first() === null, 'hasOne empty before create');
    $bare->profile()->create(['bio' => 'Fresh']);
    relCheck($bare->profile()->first() instanceof PostProfile, 'hasOne create clears stale relation cache');

    $post->tags()->detach($tag->id);
    relCheck($post->tags()->get() === [], 'detach removes pivot rows');
    $post->tags()->sync([$tag->id]);
    relCheck(count($post->tags()->get()) === 1, 'sync attaches pivot rows');

    $extra = Tag::create(['label' => 'extra']);
    $tagsBeforeAttach = count($post->tags()->get());
    $post->tags()->attach($extra->id);
    relCheck(count($post->tags()->get()) === $tagsBeforeAttach + 1, 'attach clears stale relation cache');

    $beforeCount = count($post->tags()->get());
    try {
        $database->transaction(function () use ($post, $database): void {
            $post->setRelationDatabase($database);
            $tagId = Tag::create(['label' => 'rollback-me'])->id;
            $post->tags()->attach($tagId);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    $post->setRelationDatabase($database);
    relCheck(count($post->tags()->get()) === $beforeCount && Tag::whereLabel('rollback-me')->first() === null, 'pivot attach rolls back with transaction');
} finally {
    $scope->close();
}

echo "loom_model_relation_test: OK\n";
