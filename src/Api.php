<?php

namespace Narcoticfresh\Shotwell;

/**
 * Simple API for Shotwell sqlite databases
 *
 * @package Narcoticfresh\Shotwell
 * @author  Dario Nuevo
 * @license https://opensource.org/licenses/gpl-3.0.html GPL-3.0
 * @link    https://github.com/narcoticfresh/shotwell
 */
class Api
{

    /**
     * Constant for photo type
     */
    const TYPE_PHOTO = "PHOTO";

    /**
     * Constant for video type
     */
    const TYPE_VIDEO = "VIDEO";

    /**
     * Type map, mapping the ObjectID prefixes to our constants
     *
     * @var array
     */
    private $typeMap = array(
        self::TYPE_PHOTO => 'thumb',
        self::TYPE_VIDEO => 'video-'
    );

    /**
     * database
     *
     * @var \PDO
     */
    private $db;

    /**
     * Local cache array
     *
     * @var array
     */
    private $tagMap = null;

    /**
     * Api constructor.
     *
     * @param string $dbPath Path to sqlite file
     *
     * @throws \Exception
     */
    public function __construct($dbPath)
    {
        if (!file_exists($dbPath) || !is_readable($dbPath)) {
            throw new \Exception("Database with path '" . $dbPath ."' doesn't exist or isn't readable!");
        }

        $this->db = new \PDO('sqlite:' . $dbPath);
    }

    /**
     * Returns an array of everything in the database, videos and photos mixed
     *
     * @return array array of simple array structures containing the data from the database
     */
    public function getAll()
    {
        return $this->searchAllWithCondition();
    }

    /**
     * Returns an array of all photos in the database
     *
     * @return array array of simple array structures containing the data from the database
     */
    public function getAllPhotos()
    {
        return $this->getItem(self::TYPE_PHOTO);
    }

    /**
     * Returns an array of all videos in the database
     *
     * @return array array of simple array structures containing the data from the database
     */
    public function getAllVideos()
    {
        return $this->getItem(self::TYPE_VIDEO);
    }

    /**
     * Returns a given photo by it's ID in PhotoTable
     *
     * @param string $id ID
     *
     * @return array
     */
    public function getPhotoById($id)
    {
        return $this->getItem(self::TYPE_PHOTO, $id);
    }

    /**
     * Returns a given video by it's ID in VideoTable
     *
     * @param string $id ID
     *
     * @return array
     */
    public function getVideoById($id)
    {
        return $this->getItem(self::TYPE_VIDEO, $id);
    }

    /**
     * Returns all tags associated with a given Object ID. Make sure to get an Object ID first by
     * calling getObjectIdByNumericId().
     *
     * @param string $objectId Object ID.
     *
     * @return bool Either an array of tags or false if the object doesn't exist
     */
    public function getTagsByObjectId($objectId)
    {
        if (is_null($this->tagMap)) {
            $this->tagMap = $this->getItemTagMap();
        }

        if (isset($this->tagMap[$objectId])) {
            $ret = $this->tagMap[$objectId];
        } else {
            return false;
        }

        return $ret;
    }

    /**
     * Sets tags on a given item using an Oject ID. Make sure to get an Object ID first by
     * calling getObjectIdByNumericId(). This sets the tags to the ones you pass.
     *
     * @param string $objectId Object ID
     * @param array  $tags     An array of strings containing your tags
     *
     * @return bool true if all is good, false otherwise
     */
    public function setItemTags($objectId, array $tags)
    {
        $currentTags = $this->getTagsByObjectId($objectId);

        if ($currentTags === false) {
            return false;
        }

        // get diff - those need to be added
        $tagDiffAdd = array_diff($tags, $currentTags);

        // get diff otherway - those need to be removed..
        $tagDiffRemove = array_diff($currentTags, $tags);

        foreach ($tagDiffAdd as $tagName) {
            $this->manipulateItemOnTag($tagName, 'add', $objectId);
        }

        foreach ($tagDiffRemove as $tagName) {
            $this->manipulateItemOnTag($tagName, 'remove', $objectId);
        }

        $this->tagMap = null;

        return true;
    }

    /**
     * This is a private function for doing common stuff on the tag. So not from item perspective.
     *
     * @param string $tagName  the tag
     * @param string $whatToDo What you want to do (either 'add' or 'remove')
     * @param string $objectId Object ID
     *
     * @throws Exception
     *
     * @return boolean true if all seems ok, false otherwise
     */
    private function manipulateItemOnTag($tagName, $whatToDo, $objectId)
    {
        $thisTagData = $this->getTag($tagName, true);
        $ret = false;

        if (is_array($thisTagData)) {
            $thisTagItems = explode(',', $thisTagData['photo_id_list']);
            $setKey = array_search($objectId, $thisTagItems);

            // what to do?
            if ($whatToDo == 'add') {
                if ($setKey === false) {
                    $thisTagItems[] = $objectId;
                }
            } elseif ($whatToDo == 'remove') {
                if ($setKey !== false) {
                    unset($thisTagItems[$setKey]);
                }
            }

            $ret = $this->setTagItems($tagName, $thisTagItems);
        }

        return $ret;
    }

    /**
     * Sets items on a tag
     *
     * @param string $tag   the tag
     * @param array  $items items
     *
     * @return bool true if all ok, false otherwise
     */
    private function setTagItems($tag, array $items)
    {
        $thisTagId = $this->getTagId($tag);
        $ret = false;

        if ($thisTagId !== false) {
            $q = "UPDATE TagTable SET `photo_id_list` = ? WHERE id = ?";

            $res = $this->db->prepare($q);

            $saveString = implode(',', $items).',';

            // ensure we don't save ",," somewhere..
            $saveString = str_replace(',,', ',', $saveString);

            $res->execute([
                $saveString,
                $thisTagId
            ]);

            $ret = true;
        }

        return $ret;
    }

    /**
     * Sets the rating on a tag using the Object ID. Make sure to get an Object ID first by
     * calling getObjectIdByNumericId().
     *
     * @param string $objectId Object ID
     * @param int    $rating   Rating (1-5)
     *
     * @return bool true if all ok, false otherwise
     * @throws \Exception
     */
    public function setItemRating($objectId, $rating)
    {
        if (!is_numeric($rating)) {
            throw new \Exception(sprintf("Rating must be numeric, '%s' given", $rating));
        }

        $thisTable = null;
        if ($this->getTypeByObjectId($objectId) == self::TYPE_PHOTO) {
            $thisTable = 'PhotoTable';
        }
        if ($this->getTypeByObjectId($objectId) == self::TYPE_VIDEO) {
            $thisTable = 'VideoTable';
        }

        if (is_null($thisTable)) {
            return false;
        }

        $item = $this->getItemByObjectId($objectId);

        if (!isset($item['id'])) {
            return false;
        }

        $q = "UPDATE `" . $thisTable . "` SET rating=? WHERE id=?";

        $res = $this->db->prepare($q);
        $ret = $res->execute(array(
            $rating,
            $item['id']
        ));

        return $ret;
    }

    /**
     * Removes all tags from a given item using Object ID. Make sure to get an Object ID first by
     * calling getObjectIdByNumericId().
     *
     * @param string $objectId Object ID
     *
     * @return void
     */
    public function removeAllItemTags($objectId)
    {
        return $this->setItemTags($objectId, []);
    }

    /**
     * Gets an item
     *
     * @param string      $itemType What type of item
     * @param null|string $objectId The object ID
     *
     * @return array the item
     */
    private function getItem($itemType, $objectId = null)
    {
        if ($itemType == self::TYPE_PHOTO) {
            $tableName = 'PhotoTable';
        } elseif ($itemType == self::TYPE_VIDEO) {
            $tableName = 'VideoTable';
        }

        if (!is_null($objectId)) {
            $query = 'SELECT * FROM `' . $tableName . '` WHERE id=?';
            $params = [$objectId];
        } else {
            $query = 'SELECT * FROM ' . $tableName;
            $params = [];
        }

        $res = $this->db->prepare($query);
        $res->execute($params);

        $ret = array();

        while (($data = $res->fetch(\PDO::FETCH_ASSOC))) {
            if (is_array($data) && isset($data['id'])) {
                $data['object_id'] = $this->getObjectIdByNumericId($itemType, $data['id']);
                $data['tags'] = $this->getTagsByObjectId($data['object_id']);
                if ($data['tags'] === false) {
                    $data['tags'] = [];
                }
                $data['type'] = $itemType;
                $ret[] = $data;
            }
        }

        if (!is_null($objectId) && isset($ret[0])) {
            return $ret[0];
        }

        return $ret;
    }

    /**
     * Gets an item by Object ID
     *
     * @param string $objectId Object ID
     *
     * @return array|bool Either the item data or false
     */
    public function getItemByObjectId($objectId)
    {
        $ret = false;
        $type = $this->getTypeByObjectId($objectId);

        if ($type !== false) {
            $numId = $this->getNumericIdByObjectId($objectId);
            if ($type == self::TYPE_PHOTO) {
                $ret = $this->getPhotoById($numId);
            } elseif ($type == self::TYPE_VIDEO) {
                $ret = $this->getVideoById($numId);
            }

            if (is_array($ret)) {
                $ret['type'] = $type;
                $ret['object_id'] = $objectId;
            }
        }

        return $ret;
    }

    /**
     * Returns all tags in the database, optionally with the items attached to each tag.
     *
     * @param bool $withItems true to attach items
     *
     * @return array tags
     */
    public function getAllTags($withItems = false)
    {
        $q = 'SELECT * FROM TagTable';
        $res = $this->db->prepare($q);
        $res->execute();
        $ret = [];

        while (($data = $res->fetch(\PDO::FETCH_ASSOC))) {
            if ($withItems === true) {
                $data = $this->appendItemsToTag($data);
            }
            $ret[] = $data;
        }

        return $ret;
    }

    /**
     * Appends items to a tag
     *
     * @param string $tag tag
     *
     * @return array the altered tag
     */
    private function appendItemsToTag($tag)
    {
        if (isset($tag['photo_id_list'])) {
            $thisItems = array_map('trim', explode(',', $tag['photo_id_list']));
            foreach ($thisItems as $objectId) {
                if (!empty($objectId) > 0) {
                    $tag['items'][] = $this->getItemByObjectId($objectId);
                }
            }
        }
        return $tag;
    }

    /**
     * Gets items that are linked to a given tag
     *
     * @param string $tagName name of the tag
     *
     * @return array items
     */
    public function getItemsByTag($tagName)
    {
        $tagData = $this->getTag($tagName);
        $ret = array();

        if (is_array($tagData) && isset($tagData['photo_id_list'])) {
            $foundItems = explode(",", $tagData['photo_id_list']);

            foreach ($foundItems as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $thisItem = $this->getItemByObjectId($item);
                    if (is_array($thisItem)) {
                        $ret[] = $thisItem;
                    }
                }
            }
        }

        return $ret;
    }

    /**
     * Gets a given tag, possibly creating it when not existent and maybe attaching the items to it.
     *
     * @param string $tagName tag name
     * @param bool $autoCreate shall it be auto created if it doesn't exist?
     * @param bool $withItems  shall items be attached to it ('items' element)
     *
     * @return array|mixed|null tag
     */
    public function getTag($tagName, $autoCreate = false, $withItems = false)
    {
        $q = 'SELECT * FROM TagTable WHERE name=?';
        $res = $this->db->prepare($q);
        $res->execute(array(
                $tagName
        ));

        $ret = null;
        $data = $res->fetch(\PDO::FETCH_ASSOC);

        if (is_array($data) && isset($data['id'])) {
            $ret = $data;
            if ($withItems === true) {
                $ret = $this->appendItemsToTag($ret);
            }
        } else {
            // autocreate?
            if ($autoCreate === true) {
                $this->createTag($tagName);
                return $this->getTag($tagName, $autoCreate, $withItems);
            }
        }

        return $ret;
    }

    /**
     * Gets the ID from a tag name
     *
     * @param $tagName tag name
     *
     * @return bool|string either the id or false if it doesn't exist
     */
    public function getTagId($tagName)
    {
        $ret = false;
        $tagData = $this->getTag($tagName);

        if (isset($tagData['id'])) {
            $ret = $tagData['id'];
        }

        return $ret;
    }

    /**
     * Create a new tag
     *
     * @param string $tagName tag name
     *
     * @return bool true if all good, false otherwise
     */
    public function createTag($tagName)
    {
        $ret = false;

        if (is_null($this->getTag($tagName))) {
            $insQ = "
                 INSERT INTO TagTable
                    (`name`, `photo_id_list`, `time_created`)
                    VALUES (?, ?, ?)
                    ";
            $res = $this->db->prepare($insQ);
            $res->execute([$tagName, '', time()]);
            $ret = true;
        }

        return $ret;
    }

    /**
     * Gets items whose filename (whole path) matches a given string
     *
     * @param string $path any part of the filename
     *
     * @return array matching items
     */
    public function getItemsByPath($path)
    {
        return $this->searchAllWithCondition('filename', $path);
    }

    /**
     * Flexible search on the database
     *
     * @param string $field Which field
     * @param string $value Which value
     *
     * @return array matching items
     */
    private function searchAllWithCondition($field = null, $value = null)
    {
        if ($field != null && $field != null) {
            $q = "
            SELECT id, 'video' as type FROM VideoTable
            WHERE `" . $field . "`  LIKE ?
            UNION ALL
            SELECT id, 'photo' FROM PhotoTable
            WHERE `" . $field . "` LIKE ?";

            $res = $this->db->prepare($q);
            $res->execute(['%' . $value . '%', '%' . $value . '%']);
        } else {
            $q = "
            SELECT id, 'video' as type FROM VideoTable
            UNION ALL
            SELECT id, 'photo' FROM PhotoTable";

            $res = $this->db->prepare($q);
            $res->execute([]);
        }

        $ret = array();

        while (($data = $res->fetch(\PDO::FETCH_ASSOC))) {
            if ($data['type'] == 'video') {
                $item = $this->getVideoById($data['id']);
                $ret[] = $item;
            }
            if ($data['type'] == 'photo') {
                $item = $this->getPhotoById($data['id']);
                $ret[] = $item;
            }
        }

        return $ret;
    }

    /**
     * Returns an array that has every item as key (Object IDs) and the value
     * is an array of tags associated to that item.
     *
     * @return array
     */
    public function getItemTagMap()
    {
        $tagData = $this->getAllTags();
        $ret = array();

        foreach ($tagData as $tag) {
            $thisItems = explode(",", $tag['photo_id_list']);
            foreach ($thisItems as $objectId) {
                if (strlen($objectId) > 0) {
                    $ret[$objectId][] = $tag['name'];
                }
            }
        }

        return $ret;
    }

    /**
     * Gets the object type (video or photo, compare with the constants) of a given Object ID
     *
     * @param string $objectId Object ID
     *
     * @return bool|int|string type
     */
    public function getTypeByObjectId($objectId)
    {
        $foundType = false;
        foreach ($this->typeMap as $intName => $idPart) {
            if (substr($objectId, 0, strlen($idPart)) == $idPart) {
                $foundType = $intName;
                break;
            }
        }

        return $foundType;
    }

    /**
     * Returns the Object ID with a type and a numeric ID
     *
     * @param string $type      Either photo or video, use the class constants
     * @param string $numericId Numeric ID
     *
     * @return string Object ID
     */
    public function getObjectIdByNumericId($type, $numericId)
    {
        $ret = $this->typeMap[$type];
        $ret .= str_pad(dechex($numericId), 16, "0", STR_PAD_LEFT);
        return $ret;
    }

    /**
     * Gets the type prefix for a given type
     *
     * @param string $type type
     *
     * @return bool|string prefix or false
     */
    public function getObjectIdPartIdByType($type)
    {
        if (isset($this->typeMap[$type])) {
            return $this->typeMap[$type];
        }
        return false;
    }

    /**
     * Returns the numeric ID of a given Object ID
     *
     * @param string $objectId Object ID
     *
     * @return bool|number|string numeric id
     */
    public function getNumericIdByObjectId($objectId)
    {
        $ret = false;
        $thisType = $this->getTypeByObjectId($objectId);
        if ($thisType !== false) {
            $ret = ltrim(
                substr(
                    $objectId,
                    strlen($this->getObjectIdPartIdByType($thisType))
                ),
                ' 0'
            );
            $ret = hexdec($ret);
        }

        return $ret;
    }
}
