<?php

namespace MotorORM\Tests;

use MotorORM\Query;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Scratch;
use MotorORM\Tests\Models\Story;
use MotorORM\Tests\Models\User;

/**
 * A model is the row it reads: whatever a row of the table can answer lives in
 * the model, and every read gives that model back. What a row does not carry
 * is the query that found it — conditions belong to Query alone
 */
final class ModelRowTest extends TestCase
{
    private string $path = __DIR__ . '/data/scratch.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Every read gives back the model, not something else
     */
    public function testReadsGiveBackTheModel(): void
    {
        $this->assertInstanceOf(Article::class, Article::query()->find(1));
        $this->assertInstanceOf(Article::class, Article::query()->first());
        $this->assertInstanceOf(Article::class, Article::query()->limit(1)->get()->first());
        $this->assertInstanceOf(Article::class, Article::query()->limit(1)->paginate(1)->first());

        foreach (Article::query()->limit(1)->cursor() as $article) {
            $this->assertInstanceOf(Article::class, $article);
        }
    }

    /**
     * A method of the model answers for the row it was read into
     */
    public function testModelMethodAnswersForTheRow(): void
    {
        $this->assertSame('ЗАГОЛОВОК1', Article::query()->find(1)->shout());
    }

    /**
     * A relation is read into its own model
     */
    public function testRelationIsTheRelatedModel(): void
    {
        $story = Story::query()->with('user')->find(1);

        $this->assertInstanceOf(User::class, $story->user);
        $this->assertSame('admin', $story->user->login);
        $this->assertInstanceOf(User::class, Story::query()->find(1)->user);
    }

    /**
     * A hasOne that found nobody is an empty model, never null
     */
    public function testEmptyRelationIsAnEmptyModel(): void
    {
        $orphan = Story::query()->find(3);
        $orphan->user_id = 999;

        $this->assertInstanceOf(User::class, $orphan->user);
        $this->assertNull($orphan->user->id);
    }

    /**
     * A model that is only a table holds no values
     */
    public function testDeclarationHoldsNoValues(): void
    {
        $article = new Article();

        $this->assertSame([], $article->toArray());
        $this->assertNull($article->key());
        $this->assertNull($article->title);
    }

    /**
     * One row changed leaves the rows read with it alone
     */
    public function testRowsDoNotShareValues(): void
    {
        $articles = Article::query()->limit(2)->get();

        $articles->first()->title = 'Изменённый';

        $this->assertSame('Изменённый', $articles->first()->title);
        $this->assertNotSame('Изменённый', $articles->last()->title);
        $this->assertNotSame('Изменённый', Article::query()->find(1)->title);
    }

    /**
     * A row nothing has written yet is inserted by save()
     */
    public function testSaveInsertsANewRow(): void
    {
        file_put_contents($this->path, "id,title\n");

        $row = new Scratch();
        $row->title = 'Заметка';

        $this->assertTrue($row->save());
        $this->assertSame(1, $row->key());
        $this->assertSame('Заметка', Scratch::query()->find(1)->title);
    }

    /**
     * A row already in the table is written back by save()
     */
    public function testSaveWritesAnExistingRowBack(): void
    {
        file_put_contents($this->path, "id,title\n1,Первый\n");

        $row = Scratch::query()->find(1);
        $row->title = 'Правленый';

        $this->assertTrue($row->save());
        $this->assertSame('Правленый', Scratch::query()->find(1)->title);
    }

    /**
     * A row carries no conditions: reading is asked of a query
     */
    public function testRowCarriesNoConditions(): void
    {
        $article = Article::query()->find(1);

        $this->assertFalse(method_exists($article, 'where'));
        $this->assertInstanceOf(Query::class, $article->newQuery());
        $this->assertInstanceOf(Query::class, Article::query());
    }

    /**
     * A row reads itself again, dropping what was not written
     */
    public function testFreshDropsUnsavedChanges(): void
    {
        $article = Article::query()->find(1);
        $article->title = 'Не сохранено';

        $this->assertNotSame('Не сохранено', $article->fresh()->title);
    }
}
