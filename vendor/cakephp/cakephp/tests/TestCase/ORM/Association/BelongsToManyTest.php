<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\ORM\Association;

use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\Expression\TupleComparison;
use Cake\Database\TypeMap;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Tests BelongsToMany class
 *
 */
class BelongsToManyTest extends TestCase
{
    /**
     * Fixtures
     *
     * @var array
     */
    public $fixtures = ['core.articles', 'core.special_tags', 'core.articles_tags', 'core.tags'];

    /**
     * Set up
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->tag = $this->getMock(
            'Cake\ORM\Table',
            ['find', 'delete'],
            [['alias' => 'Tags', 'table' => 'tags']]
        );
        $this->tag->schema([
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['id']]
            ]
        ]);
        $this->article = $this->getMock(
            'Cake\ORM\Table',
            ['find', 'delete'],
            [['alias' => 'Articles', 'table' => 'articles']]
        );
        $this->article->schema([
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            '_constraints' => [
                'primary' => ['type' => 'primary', 'columns' => ['id']]
            ]
        ]);
    }

    /**
     * Tear down
     *
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        TableRegistry::clear();
    }

    /**
     * Tests that the association reports it can be joined
     *
     * @return void
     */
    public function testCanBeJoined()
    {
        $assoc = new BelongsToMany('Test');
        $this->assertFalse($assoc->canBeJoined());
    }

    /**
     * Tests sort() method
     *
     * @return void
     */
    public function testSort()
    {
        $assoc = new BelongsToMany('Test');
        $this->assertNull($assoc->sort());
        $assoc->sort(['id' => 'ASC']);
        $this->assertEquals(['id' => 'ASC'], $assoc->sort());
    }

    /**
     * Tests requiresKeys() method
     *
     * @return void
     */
    public function testRequiresKeys()
    {
        $assoc = new BelongsToMany('Test');
        $this->assertTrue($assoc->requiresKeys());
        $assoc->strategy(BelongsToMany::STRATEGY_SUBQUERY);
        $this->assertFalse($assoc->requiresKeys());
        $assoc->strategy(BelongsToMany::STRATEGY_SELECT);
        $this->assertTrue($assoc->requiresKeys());
    }

    /**
     * Tests that BelongsToMany can't use the join strategy
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid strategy "join" was provided
     * @return void
     */
    public function testStrategyFailure()
    {
        $assoc = new BelongsToMany('Test');
        $assoc->strategy(BelongsToMany::STRATEGY_JOIN);
    }

    /**
     * Tests the junction method
     *
     * @return void
     */
    public function testJunction()
    {
        $assoc = new BelongsToMany('Test', [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag
        ]);
        $junction = $assoc->junction();
        $this->assertInstanceOf('Cake\ORM\Table', $junction);
        $this->assertEquals('ArticlesTags', $junction->alias());
        $this->assertEquals('articles_tags', $junction->table());
        $this->assertSame($this->article, $junction->association('Articles')->target());
        $this->assertSame($this->tag, $junction->association('Tags')->target());

        $belongsTo = '\Cake\ORM\Association\BelongsTo';
        $this->assertInstanceOf($belongsTo, $junction->association('Articles'));
        $this->assertInstanceOf($belongsTo, $junction->association('Tags'));

        $this->assertSame($junction, $this->tag->association('ArticlesTags')->target());
        $this->assertSame($this->article, $this->tag->association('Articles')->target());

        $hasMany = '\Cake\ORM\Association\HasMany';
        $belongsToMany = '\Cake\ORM\Association\BelongsToMany';
        $this->assertInstanceOf($belongsToMany, $this->tag->association('Articles'));
        $this->assertInstanceOf($hasMany, $this->tag->association('ArticlesTags'));

        $this->assertSame($junction, $assoc->junction());
        $junction2 = TableRegistry::get('Foos');
        $assoc->junction($junction2);
        $this->assertSame($junction2, $assoc->junction());

        $assoc->junction('ArticlesTags');
        $this->assertSame($junction, $assoc->junction());
    }

    /**
     * Tests the junction method custom keys
     *
     * @return void
     */
    public function testJunctionCustomKeys()
    {
        $this->article->belongsToMany('Tags', [
            'joinTable' => 'articles_tags',
            'foreignKey' => 'article',
            'targetForeignKey' => 'tag'
        ]);
        $this->tag->belongsToMany('Articles', [
            'joinTable' => 'articles_tags',
            'foreignKey' => 'tag',
            'targetForeignKey' => 'article'
        ]);
        $junction = $this->article->association('Tags')->junction();
        $this->assertEquals('article', $junction->association('Articles')->foreignKey());
        $this->assertEquals('article', $this->article->association('ArticlesTags')->foreignKey());

        $junction = $this->tag->association('Articles')->junction();
        $this->assertEquals('tag', $junction->association('Tags')->foreignKey());
        $this->assertEquals('tag', $this->tag->association('ArticlesTags')->foreignKey());
    }

    /**
     * Tests it is possible to set the table name for the join table
     *
     * @return void
     */
    public function testJunctionWithDefaultTableName()
    {
        $assoc = new BelongsToMany('Test', [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ]);
        $junction = $assoc->junction();
        $this->assertEquals('TagsArticles', $junction->alias());
        $this->assertEquals('tags_articles', $junction->table());
    }

    /**
     * Tests saveStrategy
     *
     * @return void
     */
    public function testSaveStrategy()
    {
        $assoc = new BelongsToMany('Test');
        $this->assertEquals(BelongsToMany::SAVE_REPLACE, $assoc->saveStrategy());
        $assoc->saveStrategy(BelongsToMany::SAVE_APPEND);
        $this->assertEquals(BelongsToMany::SAVE_APPEND, $assoc->saveStrategy());
        $assoc->saveStrategy(BelongsToMany::SAVE_REPLACE);
        $this->assertEquals(BelongsToMany::SAVE_REPLACE, $assoc->saveStrategy());
    }

    /**
     * Tests that it is possible to pass the saveAssociated strategy in the constructor
     *
     * @return void
     */
    public function testSaveStrategyInOptions()
    {
        $assoc = new BelongsToMany('Test', ['saveStrategy' => BelongsToMany::SAVE_APPEND]);
        $this->assertEquals(BelongsToMany::SAVE_APPEND, $assoc->saveStrategy());
    }

    /**
     * Tests that passing an invalid strategy will throw an exception
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid save strategy "depsert"
     * @return void
     */
    public function testSaveStrategyInvalid()
    {
        $assoc = new BelongsToMany('Test', ['saveStrategy' => 'depsert']);
    }

    /**
     * Test cascading deletes.
     *
     * @return void
     */
    public function testCascadeDelete()
    {
        $articleTag = $this->getMock('Cake\ORM\Table', ['deleteAll'], []);
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'sort' => ['id' => 'ASC'],
        ];
        $association = new BelongsToMany('Tags', $config);
        $association->junction($articleTag);
        $this->article
            ->association($articleTag->alias())
            ->conditions(['click_count' => 3]);

        $articleTag->expects($this->once())
            ->method('deleteAll')
            ->with([
                'click_count' => 3,
                'article_id' => 1
            ]);

        $entity = new Entity(['id' => 1, 'name' => 'PHP']);
        $association->cascadeDelete($entity);
    }

    /**
     * Test cascading deletes with dependent=false
     *
     * @return void
     */
    public function testCascadeDeleteDependent()
    {
        $articleTag = $this->getMock('Cake\ORM\Table', ['delete', 'deleteAll'], []);
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'dependent' => false,
            'sort' => ['id' => 'ASC'],
        ];
        $association = new BelongsToMany('Tags', $config);
        $association->junction($articleTag);
        $this->article
            ->association($articleTag->alias())
            ->conditions(['click_count' => 3]);

        $articleTag->expects($this->never())
            ->method('deleteAll');
        $articleTag->expects($this->never())
            ->method('delete');

        $entity = new Entity(['id' => 1, 'name' => 'PHP']);
        $association->cascadeDelete($entity);
    }

    /**
     * Test cascading deletes with callbacks.
     *
     * @return void
     */
    public function testCascadeDeleteWithCallbacks()
    {
        $articleTag = TableRegistry::get('ArticlesTags');
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'cascadeCallbacks' => true,
        ];
        $association = new BelongsToMany('Tag', $config);
        $association->junction($articleTag);
        $this->article->association($articleTag->alias());

        $counter = $this->getMock('StdClass', ['__invoke']);
        $counter->expects($this->exactly(2))->method('__invoke');
        $articleTag->eventManager()->on('Model.beforeDelete', $counter);

        $this->assertEquals(2, $articleTag->find()->where(['article_id' => 1])->count());
        $entity = new Entity(['id' => 1, 'name' => 'PHP']);
        $association->cascadeDelete($entity);

        $this->assertEquals(0, $articleTag->find()->where(['article_id' => 1])->count());
    }

    /**
     * Test linking entities having a non persisted source entity
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Source entity needs to be persisted before proceeding
     * @return void
     */
    public function testLinkWithNotPersistedSource()
    {
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ];
        $assoc = new BelongsToMany('Test', $config);
        $entity = new Entity(['id' => 1]);
        $tags = [new Entity(['id' => 2]), new Entity(['id' => 3])];
        $assoc->link($entity, $tags);
    }

    /**
     * Test liking entities having a non persited target entity
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Cannot link not persisted entities
     * @return void
     */
    public function testLinkWithNotPersistedTarget()
    {
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ];
        $assoc = new BelongsToMany('Test', $config);
        $entity = new Entity(['id' => 1], ['markNew' => false]);
        $tags = [new Entity(['id' => 2]), new Entity(['id' => 3])];
        $assoc->link($entity, $tags);
    }

    /**
     * Tests that liking entities will validate data and pass on to _saveLinks
     *
     * @return void
     */
    public function testLinkSuccess()
    {
        $connection = ConnectionManager::get('test');
        $joint = $this->getMock(
            '\Cake\ORM\Table',
            ['save'],
            [['alias' => 'ArticlesTags', 'connection' => $connection]]
        );
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'through' => $joint,
            'joinTable' => 'tags_articles'
        ];

        $assoc = new BelongsToMany('Test', $config);
        $opts = ['markNew' => false];
        $entity = new Entity(['id' => 1], $opts);
        $tags = [new Entity(['id' => 2], $opts), new Entity(['id' => 3], $opts)];
        $saveOptions = ['foo' => 'bar'];

        $joint->expects($this->at(0))
            ->method('save')
            ->will($this->returnCallback(function ($e, $opts) use ($entity) {
                $expected = ['article_id' => 1, 'tag_id' => 2];
                $this->assertEquals($expected, $e->toArray());
                $this->assertEquals(['foo' => 'bar'], $opts);
                $this->assertTrue($e->isNew());
                return $entity;
            }));

        $joint->expects($this->at(1))
            ->method('save')
            ->will($this->returnCallback(function ($e, $opts) use ($entity) {
                $expected = ['article_id' => 1, 'tag_id' => 3];
                $this->assertEquals($expected, $e->toArray());
                $this->assertEquals(['foo' => 'bar'], $opts);
                $this->assertTrue($e->isNew());
                return $entity;
            }));

        $this->assertTrue($assoc->link($entity, $tags, $saveOptions));
        $this->assertSame($entity->test, $tags);
    }

    /**
     * Test liking entities having a non persited source entity
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Source entity needs to be persisted before proceeding
     * @return void
     */
    public function testUnlinkWithNotPersistedSource()
    {
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ];
        $assoc = new BelongsToMany('Test', $config);
        $entity = new Entity(['id' => 1]);
        $tags = [new Entity(['id' => 2]), new Entity(['id' => 3])];
        $assoc->unlink($entity, $tags);
    }

    /**
     * Test liking entities having a non persited target entity
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Cannot link not persisted entities
     * @return void
     */
    public function testUnlinkWithNotPersistedTarget()
    {
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ];
        $assoc = new BelongsToMany('Test', $config);
        $entity = new Entity(['id' => 1], ['markNew' => false]);
        $tags = [new Entity(['id' => 2]), new Entity(['id' => 3])];
        $assoc->unlink($entity, $tags);
    }

    /**
     * Tests that unlinking calls the right methods
     *
     * @return void
     */
    public function testUnlinkSuccess()
    {
        $joint = TableRegistry::get('SpecialTags');
        $articles = TableRegistry::get('Articles');
        $tags = TableRegistry::get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'sourceTable' => $articles,
            'targetTable' => $tags,
            'through' => $joint,
            'joinTable' => 'special_tags',
        ]);
        $entity = $articles->get(2, ['contain' => 'Tags']);
        $initial = $entity->tags;
        $this->assertCount(1, $initial);

        $assoc->unlink($entity, $entity->tags);
        $this->assertEmpty($entity->get('tags'), 'Property should be empty');

        $new = $articles->get(2, ['contain' => 'Tags']);
        $this->assertCount(0, $new->tags, 'DB should be clean');
        $this->assertSame(3, $tags->find()->count(), 'Tags should still exist');
    }

    /**
     * Tests that unlinking with last parameter set to false
     * will not remove entities from the association property
     *
     * @return void
     */
    public function testUnlinkWithoutPropertyClean()
    {
        $joint = TableRegistry::get('SpecialTags');
        $articles = TableRegistry::get('Articles');
        $tags = TableRegistry::get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'sourceTable' => $articles,
            'targetTable' => $tags,
            'through' => $joint,
            'joinTable' => 'special_tags',
            'conditions' => ['SpecialTags.highlighted' => true]
        ]);
        $entity = $articles->get(2, ['contain' => 'Tags']);
        $initial = $entity->tags;
        $this->assertCount(1, $initial);

        $assoc->unlink($entity, $initial, ['cleanProperty' => false]);
        $this->assertNotEmpty($entity->get('tags'), 'Property should not be empty');
        $this->assertEquals($initial, $entity->get('tags'), 'Property should be untouched');

        $new = $articles->get(2, ['contain' => 'Tags']);
        $this->assertCount(0, $new->tags, 'DB should be clean');
    }

    /**
     * Tests that replaceLink requires the sourceEntity to have primaryKey values
     * for the source entity
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Could not find primary key value for source entity
     * @return void
     */
    public function testReplaceWithMissingPrimaryKey()
    {
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'joinTable' => 'tags_articles'
        ];
        $assoc = new BelongsToMany('Test', $config);
        $entity = new Entity(['foo' => 1], ['markNew' => false]);
        $tags = [new Entity(['id' => 2]), new Entity(['id' => 3])];
        $assoc->replaceLinks($entity, $tags);
    }

    /**
     * Test that replaceLinks() can saveAssociated an empty set, removing all rows.
     *
     * @return void
     */
    public function testReplaceLinksUpdateToEmptySet()
    {
        $joint = TableRegistry::get('ArticlesTags');
        $articles = TableRegistry::get('Articles');
        $tags = TableRegistry::get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'sourceTable' => $articles,
            'targetTable' => $tags,
            'through' => $joint,
            'joinTable' => 'articles_tags',
        ]);

        $entity = $articles->get(1, ['contain' => 'Tags']);
        $this->assertCount(2, $entity->tags);

        $assoc->replaceLinks($entity, []);
        $this->assertSame([], $entity->tags, 'Property should be empty');
        $this->assertFalse($entity->dirty('tags'), 'Property should be cleaned');

        $new = $articles->get(1, ['contain' => 'Tags']);
        $this->assertSame([], $entity->tags, 'Should not be data in db');
    }

    /**
     * Tests that replaceLinks will delete entities not present in the passed,
     * array, maintain those are already persisted and were passed and also
     * insert the rest.
     *
     * @return void
     */
    public function testReplaceLinkSuccess()
    {
        $joint = TableRegistry::get('ArticlesTags');
        $articles = TableRegistry::get('Articles');
        $tags = TableRegistry::get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'sourceTable' => $articles,
            'targetTable' => $tags,
            'through' => $joint,
            'joinTable' => 'articles_tags',
        ]);
        $entity = $articles->get(1, ['contain' => 'Tags']);

        // 1=existing, 2=removed, 3=new link, & new tag
        $tagData = [
            new Entity(['id' => 1], ['markNew' => false]),
            new Entity(['id' => 3]),
            new Entity(['name' => 'net new']),
        ];

        $assoc->replaceLinks($entity, $tagData, ['associated' => false]);
        $this->assertSame($tagData, $entity->tags, 'Tags should match replaced objects');
        $this->assertFalse($entity->dirty('tags'), 'Should be clean');

        $fresh = $articles->get(1, ['contain' => 'Tags']);
        $this->assertCount(3, $fresh->tags, 'Records should be in db');

        $this->assertNotEmpty($tags->get(2), 'Unlinked tag should still exist');
    }

    /**
     * Tests that replaceLinks() will contain() the target table when
     * there are conditions present on the association.
     *
     * In this case the replacement will fail because the association conditions
     * hide the fixture data.
     *
     * @return void
     */
    public function testReplaceLinkWithConditions()
    {
        $joint = TableRegistry::get('SpecialTags');
        $articles = TableRegistry::get('Articles');
        $tags = TableRegistry::get('Tags');

        $assoc = $articles->belongsToMany('Tags', [
            'sourceTable' => $articles,
            'targetTable' => $tags,
            'through' => $joint,
            'joinTable' => 'special_tags',
            'conditions' => ['SpecialTags.highlighted' => true]
        ]);
        $entity = $articles->get(1, ['contain' => 'Tags']);

        $assoc->replaceLinks($entity, [], ['associated' => false]);
        $this->assertSame([], $entity->tags, 'Tags should match replaced objects');
        $this->assertFalse($entity->dirty('tags'), 'Should be clean');

        $fresh = $articles->get(1, ['contain' => 'Tags']);
        $this->assertCount(0, $fresh->tags, 'Association should be empty');

        $jointCount = $joint->find()->where(['article_id' => 1])->count();
        $this->assertSame(1, $jointCount, 'Non matching joint record should remain.');
    }

    /**
     * Provider for empty values
     *
     * @return array
     */
    public function emptyProvider()
    {
        return [
            [''],
            [false],
            [null],
            [[]]
        ];
    }

    /**
     * Test that saveAssociated() fails on non-empty, non-iterable value
     *
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Could not save tags, it cannot be traversed
     * @return void
     */
    public function testSaveAssociatedNotEmptyNotIterable()
    {
        $articles = TableRegistry::get('Articles');
        $assoc = $articles->belongsToMany('Tags', [
            'saveStrategy' => BelongsToMany::SAVE_APPEND,
            'joinTable' => 'articles_tags',
        ]);
        $entity = new Entity([
            'id' => 1,
            'tags' => 'oh noes',
        ], ['markNew' => true]);

        $assoc->saveAssociated($entity);
    }

    /**
     * Test that saving an empty set on create works.
     *
     * @dataProvider emptyProvider
     * @return void
     */
    public function testSaveAssociatedEmptySetSuccess($value)
    {
        $assoc = $this->getMock(
            '\Cake\ORM\Association\BelongsToMany',
            ['_saveTarget', 'replaceLinks'],
            ['tags']
        );
        $entity = new Entity([
            'id' => 1,
            'tags' => $value,
        ], ['markNew' => true]);

        $assoc->saveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->expects($this->never())
            ->method('replaceLinks');
        $assoc->expects($this->never())
            ->method('_saveTarget');
        $this->assertSame($entity, $assoc->saveAssociated($entity));
    }

    /**
     * Test that saving an empty set on update works.
     *
     * @dataProvider emptyProvider
     * @return void
     */
    public function testSaveAssociatedEmptySetUpdateSuccess($value)
    {
        $assoc = $this->getMock(
            '\Cake\ORM\Association\BelongsToMany',
            ['_saveTarget', 'replaceLinks'],
            ['tags']
        );
        $entity = new Entity([
            'id' => 1,
            'tags' => $value,
        ], ['markNew' => false]);

        $assoc->saveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->expects($this->once())
            ->method('replaceLinks')
            ->with($entity, [])
            ->will($this->returnValue(true));

        $assoc->expects($this->never())
            ->method('_saveTarget');

        $this->assertSame($entity, $assoc->saveAssociated($entity));
    }

    /**
     * Tests saving with replace strategy returning true
     *
     * @return void
     */
    public function testSaveAssociatedWithReplace()
    {
        $assoc = $this->getMock(
            '\Cake\ORM\Association\BelongsToMany',
            ['replaceLinks'],
            ['tags']
        );
        $entity = new Entity([
            'id' => 1,
            'tags' => [
                new Entity(['name' => 'foo'])
            ]
        ]);

        $options = ['foo' => 'bar'];
        $assoc->saveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->expects($this->once())->method('replaceLinks')
            ->with($entity, $entity->tags, $options)
            ->will($this->returnValue(true));
        $this->assertSame($entity, $assoc->saveAssociated($entity, $options));
    }

    /**
     * Tests saving with replace strategy returning true
     *
     * @return void
     */
    public function testSaveAssociatedWithReplaceReturnFalse()
    {
        $assoc = $this->getMock(
            '\Cake\ORM\Association\BelongsToMany',
            ['replaceLinks'],
            ['tags']
        );
        $entity = new Entity([
            'id' => 1,
            'tags' => [
                new Entity(['name' => 'foo'])
            ]
        ]);

        $options = ['foo' => 'bar'];
        $assoc->saveStrategy(BelongsToMany::SAVE_REPLACE);
        $assoc->expects($this->once())->method('replaceLinks')
            ->with($entity, $entity->tags, $options)
            ->will($this->returnValue(false));
        $this->assertFalse($assoc->saveAssociated($entity, $options));
    }

    /**
     * Test that saveAssociated() ignores non entity values.
     *
     * @return void
     */
    public function testSaveAssociatedOnlyEntitiesAppend()
    {
        $connection = ConnectionManager::get('test');
        $mock = $this->getMock(
            'Cake\ORM\Table',
            ['saveAssociated', 'schema'],
            [['table' => 'tags', 'connection' => $connection]]
        );
        $mock->primaryKey('id');

        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $mock,
            'saveStrategy' => BelongsToMany::SAVE_APPEND,
        ];

        $entity = new Entity([
            'id' => 1,
            'title' => 'First Post',
            'tags' => [
                ['tag' => 'nope'],
                new Entity(['tag' => 'cakephp']),
            ]
        ]);

        $mock->expects($this->never())
            ->method('saveAssociated');

        $association = new BelongsToMany('Tags', $config);
        $association->saveAssociated($entity);
    }

    /**
     * Tests that targetForeignKey() returns the correct configured value
     *
     * @return void
     */
    public function testTargetForeignKey()
    {
        $assoc = new BelongsToMany('Test', [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag
        ]);
        $this->assertEquals('tag_id', $assoc->targetForeignKey());
        $assoc->targetForeignKey('another_key');
        $this->assertEquals('another_key', $assoc->targetForeignKey());

        $assoc = new BelongsToMany('Test', [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'targetForeignKey' => 'foo'
        ]);
        $this->assertEquals('foo', $assoc->targetForeignKey());
    }

    /**
     * Tests that custom foreignKeys are properly trasmitted to involved associations
     * when they are customized
     *
     * @return void
     */
    public function testJunctionWithCustomForeignKeys()
    {
        $assoc = new BelongsToMany('Test', [
            'sourceTable' => $this->article,
            'targetTable' => $this->tag,
            'foreignKey' => 'Art',
            'targetForeignKey' => 'Tag'
        ]);
        $junction = $assoc->junction();
        $this->assertEquals('Art', $junction->association('Articles')->foreignKey());
        $this->assertEquals('Tag', $junction->association('Tags')->foreignKey());

        $inverseRelation = $this->tag->association('Articles');
        $this->assertEquals('Tag', $inverseRelation->foreignKey());
        $this->assertEquals('Art', $inverseRelation->targetForeignKey());
    }

    /**
     * Tests that property is being set using the constructor options.
     *
     * @return void
     */
    public function testPropertyOption()
    {
        $config = ['propertyName' => 'thing_placeholder'];
        $association = new BelongsToMany('Thing', $config);
        $this->assertEquals('thing_placeholder', $association->property());
    }

    /**
     * Test that plugin names are omitted from property()
     *
     * @return void
     */
    public function testPropertyNoPlugin()
    {
        $mock = $this->getMock('Cake\ORM\Table', [], [], '', false);
        $config = [
            'sourceTable' => $this->article,
            'targetTable' => $mock,
        ];
        $association = new BelongsToMany('Contacts.Tags', $config);
        $this->assertEquals('tags', $association->property());
    }

    /**
     * Tests that fetching belongsToMany association will not force
     * all fields being returned, but intead will honor the select() clause
     *
     * @see https://github.com/cakephp/cakephp/issues/7916
     * @return void
     */
    public function testEagerLoadingBelongsToManyLimitedFields()
    {
        $table = TableRegistry::get('Articles');
        $table->belongsToMany('Tags');
        $result = $table
            ->find()
            ->contain(['Tags' => function ($q) {
                return $q->select(['id']);
            }])
            ->first();

        $this->assertNotEmpty($result->tags[0]->id);
        $this->assertEmpty($result->tags[0]->name);
    }
}
