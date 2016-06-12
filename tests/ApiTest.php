<?php

namespace Narcoticfresh\Shotwell\Tests;

use Narcoticfresh\Shotwell\Api;

/**
 * API Test
 *
 * @package Narcoticfresh\Shotwell
 * @author  Dario Nuevo
 * @license https://opensource.org/licenses/gpl-3.0.html GPL-3.0
 * @link    https://github.com/narcoticfresh/shotwell
 */
class ApiTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Api
     */
    private $sut;

    public function setUp()
    {
        $db = __DIR__.'/resources/shotwell-0220-ubuntu-1604.db';

        // copy it away as we may modify it
        $tmpLoc = './tmp.db';
        copy($db, $tmpLoc);

        $this->sut = new Api($tmpLoc);
    }

    public function tearDown()
    {
        $this->sut = null;
        unlink('./tmp.db');
    }

    /**
     * @expectedException \Exception
     */
    public function testConstructNotExistentDb()
    {
        new Api('not-existing');
    }

    public function testSimpleItemGets()
    {
        $this->assertSame(3, count($this->sut->getAll()));
        $this->assertSame(2, count($this->sut->getAllPhotos()));
        $this->assertSame(1, count($this->sut->getAllVideos()));
    }

    public function testTagGetters()
    {
        $allTags = $this->sut->getAllTags();

        $this->assertSame(3, count($allTags));

        $this->assertSame('1', $allTags[0]['id']);
        $this->assertSame('2', $allTags[1]['id']);
        $this->assertSame('3', $allTags[2]['id']);

        $this->assertSame('boracay', $allTags[0]['name']);
        $this->assertSame('philippines', $allTags[1]['name']);
        $this->assertSame('diving', $allTags[2]['name']);

        // with items
        $withItems = $this->sut->getAllTags(true);

        $this->assertSame(2, count($withItems[0]['items']));
        $this->assertArraySubset(['id' => '1', 'type' => Api::TYPE_PHOTO], $withItems[0]['items'][0]);
        $this->assertArraySubset(['id' => '2', 'type' => Api::TYPE_PHOTO], $withItems[0]['items'][1]);

        $this->assertSame(2, count($withItems[1]['items']));
        $this->assertArraySubset(['id' => '1', 'type' => Api::TYPE_PHOTO], $withItems[1]['items'][0]);
        $this->assertArraySubset(['id' => '2', 'type' => Api::TYPE_PHOTO], $withItems[1]['items'][1]);

        $this->assertSame(1, count($withItems[2]['items']));
        $this->assertArraySubset(['id' => '1', 'type' => Api::TYPE_VIDEO], $withItems[2]['items'][0]);
    }

    public function testCreateAndGetTagAndTagSomething()
    {
        // create already existing shall return false
        $this->assertFalse($this->sut->createTag('diving'));

        // normal create
        $ret = $this->sut->createTag('test');
        $this->assertTrue($ret);

        // is it there?
        $tag = $this->sut->getTag('test');
        $this->assertSame('4', $tag['id']);
        $this->assertSame('test', $tag['name']);
        $this->assertSame('', $tag['photo_id_list']);

        // id correct?
        $this->assertSame('4', $this->sut->getTagId('test'));
        $this->assertFalse($this->sut->getTagId('test2'));

        // tag it to something
        $video = $this->sut->getVideoById(1);
        $objectId = $video['object_id'];

        $this->sut->setItemTags($objectId, ['test']);
        $video = $this->sut->getVideoById(1);
        $this->assertSame(['test'], $video['tags']);

        $this->sut->setItemTags($objectId, ['diving', 'test']);
        $video = $this->sut->getVideoById(1);
        $this->assertSame(['diving', 'test'], $video['tags']);
        $this->assertSame(['diving', 'test'], $this->sut->getTagsByObjectId($objectId));

        // in item list?
        $tag = $this->sut->getTag('test', false, true);

        $this->assertSame(1, count($tag['items']));
        $this->assertSame($objectId, $tag['items'][0]['object_id']);

        // get list also?
        $items = $this->sut->getItemsByTag('test');
        $this->assertSame(1, count($tag['items']));
        $this->assertSame($objectId, $items[0]['object_id']);
    }

    public function testTagNotExisting()
    {
        $this->assertFalse($this->sut->setItemTags('1', ['test']));
        $this->assertTrue($this->sut->setItemTags($this->sut->getObjectIdByNumericId(Api::TYPE_VIDEO, 1), ['test']));
    }

    public function testAutoCreateTag()
    {
        // null when no auto create
        $this->assertNull($this->sut->getTag('not-existant'));

        $tag = $this->sut->getTag('not-existant', true);
        $this->assertSame('4', $tag['id']);
        $this->assertSame('not-existant', $tag['name']);
    }

    public function testEmptyTags()
    {
        $objectId = $this->sut->getObjectIdByNumericId(APi::TYPE_PHOTO, 1);
        $this->sut->removeAllItemTags($objectId);

        $item = $this->sut->getItemByObjectId($objectId);
        $this->assertEmpty($item['tags']);
    }

    public function testRating()
    {
        $objectId = $this->sut->getObjectIdByNumericId(APi::TYPE_PHOTO, 1);
        $this->assertSame('thumb0000000000000001', $objectId);
        $this->assertTrue($this->sut->setItemRating($objectId, 5));
        $item = $this->sut->getItemByObjectId($objectId);
        $this->assertSame('5', $item['rating']);

        $objectId = $this->sut->getObjectIdByNumericId(APi::TYPE_VIDEO, 1);
        $this->assertSame('video-0000000000000001', $objectId);
        $this->assertTrue($this->sut->setItemRating($objectId, 4));
        $item = $this->sut->getItemByObjectId($objectId);
        $this->assertSame('4', $item['rating']);
    }

    /**
     * @expectedException \Exception
     */
    public function testRatingWrongValue()
    {
        $this->sut->setItemRating('a', 'a');
    }

    /**
     *
     */
    public function testRatingWrongObjectId()
    {
        $this->assertFalse($this->sut->setItemRating('video-0000000000000010', 3));
        $this->assertFalse($this->sut->setItemRating('a', 3));
    }

    public function testSearchWithPath()
    {
        $items = $this->sut->getItemsByPath('Desktop');
        $this->assertSame(3, count($items));

        $items = $this->sut->getItemsByPath('MP4');
        $this->assertSame(1, count($items));
    }
}
