<?php

namespace MotorORM\Tests;

use MotorORM\Record;
use MotorORM\Tests\Models\Article;
use MotorORM\Tests\Models\Impostor;
use MotorORM\Tests\Models\Note;
use MotorORM\Tests\Models\Publication;
use MotorORM\Tests\Records\AuthorRecord;
use MotorORM\Tests\Records\PublicationRecord;
use UnexpectedValueException;

/**
 * A model may name the class its rows are read into. Whatever brings a record
 * into being — a lookup, a whole result, a cursor, an insert or a relation
 * that found nobody — has to give back the class the model named
 */
final class RecordClassTest extends TestCase
{
    private string $path = __DIR__ . '/data/scratch.csv';

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * A model that names nothing is read into a plain record, as before
     */
    public function testDefaultRecord(): void
    {
        $article = Article::query()->find(1);

        $this->assertInstanceOf(Record::class, $article);
        $this->assertNotInstanceOf(PublicationRecord::class, $article);
    }

    /**
     * find() gives back the named class
     */
    public function testFind(): void
    {
        $publication = Publication::query()->find(1);

        $this->assertInstanceOf(PublicationRecord::class, $publication);
        $this->assertSame('ЗАГОЛОВОК1', $publication->shout());
    }

    /**
     * first() gives back the named class
     */
    public function testFirst(): void
    {
        $this->assertInstanceOf(PublicationRecord::class, Publication::query()->first());
    }

    /**
     * Every row of a whole result is the named class
     */
    public function testGet(): void
    {
        $publications = Publication::query()->limit(3)->get();

        $this->assertCount(3, $publications);
        foreach ($publications as $publication) {
            $this->assertInstanceOf(PublicationRecord::class, $publication);
        }
    }

    /**
     * A cursor yields the named class
     */
    public function testCursor(): void
    {
        foreach (Publication::query()->limit(2)->cursor() as $publication) {
            $this->assertInstanceOf(PublicationRecord::class, $publication);
        }
    }

    /**
     * A page of a result holds the named class
     */
    public function testPaginate(): void
    {
        foreach (Publication::query()->paginate(2) as $publication) {
            $this->assertInstanceOf(PublicationRecord::class, $publication);
        }
    }

    /**
     * An insert gives back the named class
     */
    public function testCreate(): void
    {
        file_put_contents($this->path, "id,title\n");

        $note = Note::query()->create(['title' => 'Заметка']);

        $this->assertInstanceOf(PublicationRecord::class, $note);
        $this->assertSame('ЗАМЕТКА', $note->shout());
        $this->assertInstanceOf(PublicationRecord::class, Note::query()->find($note->key()));
    }

    /**
     * A record read again is still the named class
     */
    public function testFresh(): void
    {
        $this->assertInstanceOf(PublicationRecord::class, Publication::query()->find(1)->fresh());
    }

    /**
     * A relation is read into the class the related model names
     */
    public function testRelation(): void
    {
        $publication = Publication::query()->with('author')->find(1);

        $this->assertInstanceOf(AuthorRecord::class, $publication->author);
        $this->assertTrue($publication->author->isKnown());
    }

    /**
     * The empty record of a relation that found nobody is the named class too
     */
    public function testEmptyRelation(): void
    {
        $publication = Publication::query()->with('author')->find(3);

        $this->assertInstanceOf(AuthorRecord::class, $publication->author);
        $this->assertFalse($publication->author->isKnown());
    }

    /**
     * A relation read lazily is the named class as well
     */
    public function testLazyRelation(): void
    {
        $this->assertInstanceOf(AuthorRecord::class, Publication::query()->find(1)->author);
    }

    /**
     * Naming something that is not a record is an error, not a fatal
     */
    public function testNotARecord(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('is not a MotorORM\Record');

        Impostor::query()->find(1);
    }
}
