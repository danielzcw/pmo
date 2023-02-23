<?php

/**
 * This file is part of the Pmo package.
 *
 * (c) Michael Gan <gc1108960@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @category Pmo
 * @package  Pmo
 * @author   Michael Gan <gc1108960@gmail.com>
 * @license  https://github.com/purekid/mongodm/blob/master/LICENSE.md MIT Licence
 * @link     https://github.com/purekid/mongodm
 */

namespace Danz\Pmo;

use MongoDB\BSON\Decimal128;
use \Danz\Pmo\Exception\InvalidDataTypeException;

/**
 * Pmo - A PHP Mongodb ORM
 *
 * @category Pmo
 * @package  Pmo
 */
abstract class Model
{


    const DATA_TYPE_ARRAY      = 'array';

    const DATA_TYPE_BOOL       = 'bool';
    const DATA_TYPE_BOOLEAN    = 'boolean';

    const DATA_TYPE_DATE       = 'date';

    const DATA_TYPE_DBL        = 'dbl';
    const DATA_TYPE_DOUBLE     = 'double';
    const DATA_TYPE_FLT        = 'flt';
    const DATA_TYPE_FLOAT      = 'float';

    const DATA_TYPE_EMBED      = 'embed';
    const DATA_TYPE_EMBEDS     = 'embeds';

    const DATA_TYPE_INT        = 'int';
    const DATA_TYPE_INTEGER    = 'integer';

    const DATA_TYPE_MIXED      = 'mixed';

    const DATA_TYPE_REFERENCE  = 'reference';
    const DATA_TYPE_REFERENCES = 'references';

    const DATA_TYPE_STR        = 'str';
    const DATA_TYPE_STRING     = 'string';

    const DATA_TYPE_TIMESTAMP  = 'timestamp';

    const DATA_TYPE_OBJ        = 'obj';
    const DATA_TYPE_OBJECT     = 'object';

    public $cleanData = array();

    public static $staticValues = [];

    protected static $redisIndex = [];

    protected static $real_table_id = "";

    protected static $primaryKey = ['_id'];

    protected static $virtual = [];

    protected static $updatedAt = 'updatedAt';

    protected static $createdAt = 'createdAt';

    /**
     * section choosen in the config
     */
    protected static $config = 'default';

    protected static $prefix = '';

    protected static $attrs = array();

    /**
     * Data modified
     */
    protected $dirtyData = array();

    /**
     * Data to unset
     */
    protected $unsetData = array();

    /**
     * Data ignores when saving
     */
    protected $ignoreData = array();

    /**
     * Whether to use type `_type`
     */
    protected static $useType = true;

    /**
     * Whether `_id` is MongoId
     */
    protected static $customIdType = false;

    /**
     * Cache for references data
     */
    protected $_cache = array();

    protected $_connection = null;

    /**
     * If $_isEmbed = true , this model can't save to database alone.
     */
    protected $_isEmbed = false;

    /**
     * Id for offline model (such as embed model)
     */
    protected $_tempId = null;

    /**
     *  record exists in the database
     */
    protected $_exist = false;

    /**
     * Model
     *
     * @param array $data      data
     * @param bool $mapFields  map the field names
     * @param bool $exists     record exists in DB
     */
    public function __construct( $data = array(), $mapFields = false, $exists = false)
    {
        if ($mapFields === true) {
            $data = self::mapFields($data, true);
        }

        if (is_null($this->_connection)) {
            if (isset($this::$config)) {
                $config = $this::$config;
            } else {
                $config = self::$config;
            }
            $this->_connection = ConnectionManager::instance($config);
        }

        $this->update($data, true);

        if ($exists) {
            $this->_exist = true;
        } else {
            $this->initAttrs();
        }

        $this->initTypes();
        $this->__init();

    }

    /**
     * Update data by a array
     *
     * @param array $cleanData clean data
     * @param bool  $isInit    is init
     *
     * @return boolean
     */
    public function update(array $cleanData, $isInit = false)
    {
        if ($isInit) {
            $attrs = $this->getAttrs();
            foreach ($cleanData as $key => $value) {
                if (($value instanceof Model) && isset($attrs[$key]) && isset($attrs[$key]['type'])
                    && ( $attrs[$key]['type'] == self::DATA_TYPE_REFERENCE || $attrs[$key]['type'] == self::DATA_TYPE_REFERENCES )
                ) {
                    $value = $this->setRef($key, $value);
                }
                $this->cleanData[$key] = $value;
            }
        } else {
            foreach ($cleanData as $key => $value) {
                $this->$key = $value;
            }
        }

        return true;
    }

    /**
     * Mutate data by direct query
     *
     * @param array $updateQuery update query
     * @param array $options options
     *
     * @throws \Exception
     * @return boolean
     */
    public function mutate($updateQuery, $options = array())
    {
        if(!is_array($updateQuery)) throw new \Exception('$updateQuery should be an array');
        if(!is_array($options)) throw new \Exception('$options should be an array');

        $default = array(
            'w' => 1
        );
        $options = array_merge($default, $options);

        try {
            $this->_connection->update($this->collectionName(), array('_id' => $this->cleanData['_id']), $updateQuery, $options);
        } catch (\MongoCursorException $e) {
            return false;
        }

        return true;
    }

    /**
     * get MongoId of this record
     *
     * @return \MongoId Object
     */
    public function getId()
    {
        $class = get_called_class();
        $id = $this->cleanData['_id'];
        if (isset($this->cleanData['mongodb_id'])) {
            $id = $this->cleanData['mongodb_id'];
        }
        if(!empty($id)) {
            if($class::$customIdType === true) return $id;

            return new \MongoId($id);
        } elseif (isset($this->_tempId)) {
            return $this->_tempId;
        }

        return null;
    }

    /**
     * Delete this record
     *
     * @param array $options options
     *
     * @return boolean
     */
    public function delete($options = array())
    {
        $this->__preDelete();

        if ($this->_exist) {
            $deleted =  $this->_connection->remove($this->collectionName(), array("_id" => $this->getId() ), $options);
            if ($deleted) {
                $this->_exist = false;
            }
        }
        $this->__postDelete();

        return true;
    }

    /**
     * Save to database
     *
     * @param array $options options
     *
     * @return array
     */
    public function save($options = array())
    {

        if ($this->isEmbed()) {
            return false;
        }

        $this->processReferencesChanged();
        $this->processEmbedsChanged();

        /* if no changes then do nothing */

        if ($this->_exist && empty($this->dirtyData) && empty($this->unsetData)) return true;

        $this->__preSave();

        if ($this->_exist) {
            $this->__preUpdate();
            $updateQuery = array();

            if (!empty($this->dirtyData)) {
                $updateQuery['$set'] = self::mapFields($this->dirtyData);
            };

            if (!empty($this->unsetData)) {
                $updateQuery['$unset'] = self::mapFields($this->unsetData);
            }

            $success = $this->_connection->update($this->collectionName(), array('_id' => $this->getId()), $updateQuery, $options);
            $this->__postUpdate();

        } else {
            $this->__preInsert();
            $data = self::mapFields($this->cleanData);
            $insert = $this->_connection->insert($this->collectionName(), $data, $options);
            $success = !is_null($this->cleanData['_id'] = $insert['_id']);
            if ($success) {
                $this->_exist = true ;
                $this->__postInsert();
            }

        }

        $this->dirtyData = array();
        $this->unsetData = array();
        $this->__postSave();

        return $success;

    }

    /**
     * Export datas to array
     *
     * @param array $ignore ignore
     * @param bool $recursive
     * @param int $deep
     *
     * @return array
     */
    public function toArray($ignore = array('_type'), $recursive = false, $deep = 3)
    {
        if (!empty($ignore)) {
            $ignores = [];
            foreach ($ignore as $val) {
                $ignores[$val] = 1;
            }
            $ignore = $ignores;
        }
        if ($recursive === true && $deep > 0) {
            $attrs = $this->getAttrs();
            foreach ($this->cleanData as $key => $value) {
                if (isset($attrs[$key])
                    && isset($attrs[$key]['type'])
                    && ( $attrs[$key]['type'] == self::DATA_TYPE_REFERENCE || $attrs[$key]['type'] == self::DATA_TYPE_REFERENCES )
                ) {
                    if ($attrs[$key]['type'] == self::DATA_TYPE_REFERENCE &&
                        isset($attrs[$key]['model']) && !empty($attrs[$key]['model'])) {
                        $model = $attrs[$key]['model'];
                        if (!is_array($value)) {
                            $value = (array)$value;
                        }
                        $obj = $model::id($value['$id']);
                        if ($obj) {
                            $this->cleanData[$key] = $obj->toArray($ignore, $recursive, --$deep);
                        }
                    } else if ($attrs[$key]['type'] == self::DATA_TYPE_REFERENCES &&
                        isset($attrs[$key]['model']) && !empty($attrs[$key]['model'])) {
                        $model = $attrs[$key]['model'];
                        $data = array();
                        foreach($value as $item) {
                            if (!is_array($item)) {
                                $item = (array)$item;
                            }
                            $obj = $model::id($item['$id']);
                            if ($obj) {
                               $data[]  = $obj->toArray($ignore, $recursive, --$deep);
                            }
                        }
                        if (!empty($data))
                            $this->cleanData[$key] = $data;
                    }
                }
            }
        }
        if(isset($this->cleanData['dym_id'])) {
            $this->cleanData['mongodb_id'] = $this->cleanData['_id'];
            $this->cleanData['_id'] = $this->cleanData['dym_id'];
//            unset($this->cleanData['dym_id']);
        }
        return array_diff_key($this->cleanData, $ignore);
    }
    /**
     * Determine if instance exists in the database
     *
     * @return integer
     */
    public function exists()
    {
        return $this->_exist;
    }

    /**
     * Create a Mongodb reference
     *
     * @return \MongoDBRef
     */
    public function makeRef()
    {
        $ref = \MongoDBRef::create($this->collectionName(), $this->getId());

        return $ref;
    }

    private static function getModelType($obj)
    {
        if($obj instanceof Decimal128) {
            return self::DATA_TYPE_INT;
        } elseif ($obj instanceof \MongoId) {
            return self::DATA_TYPE_STRING;
        } elseif (is_array($obj)) {
            return self::DATA_TYPE_ARRAY;
        }
    }

    public static function fieldsCovert($type, $item, $ignField)
    {
        if(empty($type)) {
            $type = self::getModelType($item);
        }
        switch ($type) {
            case self::DATA_TYPE_INTEGER:
            case self::DATA_TYPE_INT:
                $item = intval((string) $item);
                break;
            case self::DATA_TYPE_FLOAT:
            case self::DATA_TYPE_DOUBLE:
                $item = doubleval((string) $item);
                break;
            case self::DATA_TYPE_STRING:
            case self::DATA_TYPE_STR:
                $item = (string) $item;
                break;
            case self::DATA_TYPE_ARRAY:
            case self::DATA_TYPE_OBJECT:
                if(!empty($item)){
                    foreach ($item as $key => &$sItem) {
                        $sItem = self::fieldsCovert(self::getModelType($sItem), $sItem, $ignField);
                    }
                }
                break;
        }

        return $item;
    }

    /**
     * Map fields
     *
     * @param array $array   array
     * @param bool  $toModel to model
     *
     * @return array
     */
    public static function mapFields($array, $toModel = false)
    {
        $class = get_called_class();
        $class::cacheAttrsMap();
        $attrs = $class::getAttrs();
        if(!empty($attrs) && $toModel) {
            $ignField = [];
            foreach ($array as $key => &$item) {
                if(in_array($key, $ignField)) {
                    unset($array[$key]);
                    continue;
                }
                $item = self::fieldsCovert($attrs[$key]['type'] ?? '', $item, $ignField);
            }
        }
        $private = & $class::getPrivateData();
        $map = & $private['attrsMapCache']['db'];

        if ($toModel === true) {
            $map =& $private['attrsMapCache']['model'];
        }

        foreach ($map as $from => $to) {
            if (isset($array[$from])) {

                // Map embeds
                $key = $toModel ? $to : $from;
                if (isset($attrs[$key], $attrs[$key]['type'])) {
                    if ($attrs[$key]['type'] === $class::DATA_TYPE_EMBED) {
                        $array[$from] = call_user_func(array($attrs[$key]['model'], 'mapFields'), $array[$from], $toModel);
                    }
                    if (is_array($array[$from]) && $attrs[$key]['type'] === $class::DATA_TYPE_EMBEDS) {
                        $embedArray = array();
                        foreach ($array[$from] as $item) {
                            $embedArray[] = call_user_func(array($attrs[$key]['model'], 'mapFields'), $item, $toModel);
                        }
                        $array[$from] = $embedArray;
                    }
                }

                $array[$to] = $array[$from];
                unset($array[$from]);
            }
        }

        return $array;
    }

    /**
     * Retrieve a record by MongoId
     *
     * @param mixed $id id
     *
     * @return Model
     */
    public static function id($id)
    {
        $class = get_called_class();

        if ($class::$customIdType !== true) {
            if ($id  && strlen($id) == 24 ) {
                $id = new \MongoId($id);
            }
        }

        return self::one(array("_id" => $id));

    }

    /**
     * Retrieve a record
     *
     * @param array $criteria criteria
     * @param array $fields fields
     *
     * @return Model
     */
    public static function one($criteria = array(),$fields = array())
    {
        self::processCriteriaWithType($criteria);
        $result = self::connection()->findOne(static::$collection, $criteria, self::mapFields($fields));

        if ($result) {
            return  Hydrator::hydrate(get_called_class(), $result, Hydrator::TYPE_SINGLE , true);
        }

        return null;
    }

    public static function query($criteria = array(), $sort = array(), $fields = array() , $limit = null , $skip = null)
    {
        self::processCriteriaWithType($criteria);
        $results =  self::connection()->find(static::$collection, $criteria, self::mapFields($fields));
        if ( ! is_null($limit)) {
            $results->limit($limit);
        }

        if ( !  is_null($skip)) {
            $results->skip($skip);
        }

        if ( ! empty($sort)) {
            $results->sort(self::mapFields($sort));
        }
        
        return $results;
    }
    
    /**
     * Retrieve records
     *
     * @param array $criteria criteria
     * @param array $sort   sort
     * @param array $fields fields
     * @param int   $limit  limit
     * @param int   $skip   skip
     *
     * @return Collection
     */
    public static function find($criteria = array(), $sort = array(), $fields = array() , $limit = null , $skip = null)
    {
        $results = self::query($criteria, $sort, $fields, $limit, $skip);

        return Hydrator::hydrate(get_called_class(), $results , Hydrator::TYPE_COLLECTION , true);

    }
    
    public static function pagination($criteria = array(), $limit = null , $page = 1, $sort = array(), $fields = array())
    {
        $limit = $limit ?? 10;
        $page = $page ?? 1;
        $page = [
            'count' => 0,
            'total' => 0,
            'limit' => $limit,
            'page' => $page,
            'offset' => ($page - 1) * $limit
        ];
        $count = self::count($criteria);
        if($count == 0) {
            return [
                'data' => [],
                'page' => $page
            ];
        }
        $page['total'] = $count;
        $results = self::query($criteria, $sort, $fields, $limit, $page['offset']);
        $res = Hydrator::hydrate(get_called_class(), $results , Hydrator::TYPE_COLLECTION , true)->toArray();
        $page['count'] = count($res);

        return [
            'data' => $res,
            'page' => $page
        ];
    }

    /**
     * Retrieve all records
     *
     * @param array $sort   sort
     * @param array $fields fields
     *
     * @return Collection
     */
    public static function all( $sort = array() , $fields = array())
    {
        $criteria = array();
        self::processCriteriaWithType($criteria);
        return self::find($criteria, self::mapFields($sort), self::mapFields($fields));
    }

    /**
     * group
     *
     * @param array $keys    keys
     * @param array $query   query
     * @param mixed $initial initial
     * @param mixed $reduce  reduce
     *
     * @return mixed
     */
    public static function group(array $keys, array $query, $initial = null, $reduce = null)
    {

        if (!$reduce) $reduce = new \MongoCode('function (doc, out) { out.object = doc }');
        if(!$initial) $initial = array('object'=>0);

        return self::connection()->group(self::collectionName(), $keys, $initial, $reduce, array('condition'=>$query));

    }

    /**
     * aggreate
     *
     * @param array $query query
     *
     * @return array
     */
    public static function aggregate($query)
    {
        $rows = self::connection()->aggregate(self::collectionName(), $query);
        return $rows;
    }

    /**
     * Distinct records
     *
     * @param string $key key distinct key
     * @param array $criteria criteria
     *
     * @return string Records
     */
    public static function distinct( $key , $criteria = array() )
    {

        self::processCriteriaWithType($criteria);
        $query = array('distinct'=>self::collectionName() , 'key' => $key , 'query' => $criteria);
        return self::connection()->distinct($query);

    }

    /**
     * Has record
     *
     * A optimized way to see if a record exists in the database. Helps
     * the developer to avoid the extra latency of FindOne by using Find
     * and a limit of 1.
     *
     * @link https://blog.serverdensity.com/checking-if-a-document-exists-mongodb-slow-findone-vs-find/
     *
     * @param array $criteria criteria
     *
     * @return boolean
     */
    public static function has($criteria = array())
    {
        self::processCriteriaWithType($criteria);
        $results =  self::connection()->find(static::$collection, $criteria);
        $results->limit(1);

        if($results->count()) return true;

        return false;
    }

    /**
     * Count of records
     *
     * @param array $criteria
     *
     * @return integer
     */
    public static function count($criteria = array())
    {
        self::processCriteriaWithType($criteria);
        $count = self::connection()->count(self::collectionName(), $criteria);
        return $count;
    }

    /**
     * Get collection name
     *
     * @return string
     */
    public static function collectionName()
    {
        $class = get_called_class();
        $collection = $class::$collection;

        return $collection;
    }

    /**
     * Drop the collection
     *
     * @return boolean
     */
    public static function drop()
    {
        $class = get_called_class();

        return self::connection()->dropCollection($class::collectionName());
    }

    /**
     * Retrieve a record by MongoRef
     *
     * @param mixed $ref ref
     *
     * @return Model
     */
    public static function ref($ref)
    {
        if (isset($ref['$id'])) {
            if ($ref['$ref'] == self::collectionName()) {
                return self::id($ref['$id']);
            }
        }

        return null;
    }

    /**
     * Returns the name of a class using get_class with the namespaces stripped.
     *
     * @param boolean $with_namespaces with namespaces
     *
     * @return string Name of class with namespaces stripped
     */
    public static function getClassName($with_namespaces = true)
    {
        $class_name = get_called_class();
        if($with_namespaces) return $class_name;
        $class = explode('\\',  $class_name);

        return $class[count($class) - 1];
    }


    /**
     * Set the embed status of model.
     *
     * @param bool $is_embed
     *
     * @return null
     */
    public function setEmbed($is_embed)
    {
        $this->_isEmbed = $is_embed;
        if ($is_embed) {
            unset($this->_connection);
            unset($this->_exist);
        }
    }

    /**
     * Determine if the model instance is emeded
     *
     * @return bool
     */
    public function isEmbed()
    {
        return $this->_isEmbed;

    }

    /**
     * Set temp id
     *
     * @param $tempId int id
     *
     * @return null
     */
    public function setTempId($tempId)
    {
        $this->_tempId = $tempId;
    }

    /**
     * Ensure index
     * @deprecated use ensureIndex instead
     * @param mixed $keys    keys
     * @param array $options options
     *
     * @return boolean
     */
    public static function ensure_index($keys, $options = array())
    {
       return self::ensureIndex($keys,$options);
    }

    /**
     * Ensure index
     * @param mixed $keys    keys
     * @param array $options options
     *
     * @return boolean
     */
    public static function ensureIndex($keys, $options = array()){

        $result = self::connection()->ensureIndex(self::collectionName(), $keys, $options);

        return $result;

    }

    /**
     * @deprecated use getCollection instead
     * Return the current MongoCollection
     *
     * @return \MongoCollection|null
     */
    public function _getCollection()
    {
        return $this->getCollection();
    }

    /**
     * Return the connection
     *
     * @return ConnectionManager|null
     */
    public function getConnectionManager()
    {
        return $this->_connection;
    }

    /**
     * Return the current MongoCollection
     *
     * @return \MongoCollection|null
     */
    public function getCollection()
    {
        if($this->getConnectionManager()) {
            return $this->getConnectionManager()->getMongoDB()->{$this->collectionName()};
        }
        return null;
    }


    /**
     * Initialize the "_type" attribute for the model
     *
     * @return null
     */
    protected function initTypes()
    {
        $class = $this->getClassName(false);
        $types = $this->getModelTypes();
        $type = $this->_type;

        if (!$type || !is_array($type)) {
            if(!empty($types)){
                $this->_type = $types;
            }
        } elseif (!in_array($class, $type)) {
            $type[] = $class;
            $this->_type = $type;
        }

    }

    /**
     * Get Mongodb connection instance
     *
     * @return MongoDB
     */
    protected static function connection()
    {
        $class = get_called_class();
        $config = $class::$config;
        $manager = ConnectionManager::instance($config);
//        $conf = $manager->getConfig();
//        static::$collection = $conf['table_prefix'] . static::$collection;

        return $manager;
    }

    /**
     * Cache attrs map
     *
     * @return null
     */
    protected static function cacheAttrsMap()
    {
        $class = get_called_class();
        $attrs = $class::getAttrs();
        $private = & $class::getPrivateData();

        if (!isset($private['attrsMapCache'])) {
            $private['attrsMapCache'] = array();
        }

        $cache = & $private['attrsMapCache'];

        if (empty($cache)) {
            $cache = array(
                'db' => array(),
                'model' => array()
            );

            foreach ($attrs as $key => $value) {
                if (isset($value['field'])) {
                    $cache['db'][$key] = $value['field'];
                    $cache['model'][$value['field']] = $key;
                }
            }
        }
    }

    /**
     * Get current database name
     *
     * @return string
     */

    protected function dbName()
    {

        $dbName = "default";
        $config = $this::$config;
        $configs = ConnectionManager::config($config);
        if ($configs) {
            $dbName = $configs['connection']['database'];
        }

        return $dbName;
    }

    /**
     * Update the 'references' attribute for model's instance when that 'references' data  has changed.
     *
     * @return null
     */
    protected function processReferencesChanged()
    {
        $cache = $this->_cache;
        $attrs = $this->getAttrs();
        foreach ($cache as $key => $item) {
            if(!isset($attrs[$key])) continue;
            $attr = $attrs[$key];
            if ($attr['type'] == self::DATA_TYPE_REFERENCES) {
                if ($item instanceof Collection && (!isset($this->cleanData[$key]) || $this->cleanData[$key] !== $item->makeRef())) {
                    $this->__setter($key, $item);
                }
            }
        }
    }

    /**
     * Update the 'embeds' attribute for model's instance when that 'embeds' data  has changed.
     *
     * @return null
     */
    protected function processEmbedsChanged()
    {
        $cache = $this->_cache;
        $attrs = $this->getAttrs();
        foreach ($cache as $key => $item) {
            if(!isset($attrs[$key])) continue;
            $attr = $attrs[$key];
            if ($attr['type'] == self::DATA_TYPE_EMBED) {
                $item->processEmbedsChanged();
                if ( $item instanceof Model && $this->cleanData[$key] !== $item->toArray()) {
                    $this->__setter($key, $item);
                }
            } elseif ($attr['type'] == self::DATA_TYPE_EMBEDS) {
                if ( $item instanceof Collection && $this->cleanData[$key] !== $item->toEmbedsArray()) {
                    $this->__setter($key, $item);
                }
            }
        }
    }

    /**
     * Process the criteria , add _type to criteria in some cases.
     * @param $criteria array Criteria to process
     *
     * @return null
     */
    protected static function processCriteriaWithType(&$criteria){

        $class = get_called_class();
        $types = $class::getModelTypes();
        if(isset($class::$virtual_field)){
            $criteria[$class::$virtual_field] = $class::getClassName(false);
        }
        if(!empty($class::$staticValues)) {
            foreach ($class::$staticValues as $field => $val) {
                $cdVal = $val;
                if(str_starts_with($val, "/")) {
                    if($field == $class::$real_table_id && isset($criteria[$field])) {
                        $cdVal = $class::$virtual['table'] . '-' . $criteria[$field];
                    }else {
                        $cdVal = new \MongoRegex($val);
                    }
                }
                $criteria[$field] = $cdVal;
            }
        }
//        if (count($types) > 1) {
//            $criteria['_type'] = $class::getClassName(false);
//        }

    }

    /**
     *  If the attribute of $key is a reference ,
     *  save the attribute into database as MongoDBRef
     *
     * @param string $key key
     * @param string $value value
     *
     * @throws \Exception
     * @return array|null
     */
    protected function setRef($key, $value)
    {
        $attrs = $this->getAttrs();
        $cache = &$this->_cache;
        $reference = $attrs[$key];

        if(!isset($reference['model'])) {
            throw new \Exception("{$key} does not have a defined model");
        }
        if(!isset($reference['type'])) {
            throw new \Exception("{$key} does not have a defined type");
        }

        $model = $reference['model'];
        $type = $reference['type'];

        if ($type == self::DATA_TYPE_REFERENCE) {
            $model = $reference['model'];
            if ($value instanceof $model) {
                $ref = $value->makeRef();
                $return = $ref;
            } elseif ($value === null) {
                $return = null;
            } else {
                throw new \Exception("{$key} is not instance of '$model'");
            }

        } elseif ($type == self::DATA_TYPE_REFERENCES) {
            $arr = array();
            if (is_array($value)) {
                foreach ($value as $item) {
                    if(! ( $item instanceof Model ) ) continue;
                    $arr[] = $item->makeRef();
                }
                $return = $arr;
                $value = Collection::make($value);
            } elseif ($value instanceof Collection) {
                $return = $value->makeRef();
            } elseif ($value === null) {
                $return = null;
            } else {
                throw new \Exception("{$key} is not instance of '$model'");
            }
        }

        $cache[$key] = $value;

        return $return;
    }

    /**
     * Fill the Embed attribute.
     *
     * @param string $key key
     * @param string $value value
     *
     * @throws \Exception
     * @return array|null
     */
    protected function fillEmbed($key, $value)
    {
        $attrs = $this->getAttrs();
        $cache = &$this->_cache;
        $embed = $attrs[$key];
        $model = $embed['model'];
        $type = $embed['type'];

        if ($type == self::DATA_TYPE_EMBED) {
            $model = $embed['model'];
            if ($value instanceof $model) {
                $value->embed = true;
                $return  = $value->toArray(array('_type','_id'));
            } elseif ($value === null) {
                $return = null;
            } elseif (isset($value, $value['$ref'], $value['$id'])) {
                $value = $model::id($value['$id']);
                $ref = $value->makeRef();
                $return = $ref;
            } else {
                throw new \Exception("{$key} is not instance of '$model'");
            }

        } elseif ($type == self::DATA_TYPE_EMBEDS) {
            $arr = array();
            if (is_array($value)) {
                foreach ($value as $item) {
                    if(! ( $item instanceof Model ) ) continue;
                    $item->embed = true;
                    $arr[] = $item;
                }
                $value = Collection::make($arr);
            }

            if ($value instanceof Collection) {
                $return = $value->toEmbedsArray();
            } elseif ($value === null) {
                $return = null;
            } else {
                throw new \Exception("{$key} is not instance of '$model'");
            }
        }

        $cache[$key] = $value;

        return $return;
    }

    /**
     * Load the embed attribute
     *
     * @param string $key key
     *
     * @return null
     */
    protected function loadEmbed($key)
    {
        $attrs = $this->getAttrs();
        $embed = $attrs[$key];
        $cache = &$this->_cache;

        if (isset($this->cleanData[$key])) {
            $value = $this->cleanData[$key];
        } else {
            $value = null;
        }

        $model = $embed['model'];
        $type = $embed['type'];
        if ( isset($cache[$key]) ) {
            $obj = &$cache[$key];

            return $obj;
        } else {
            if (class_exists($model)) {
                if ($type == self::DATA_TYPE_EMBED) {
                    if ($value) {
                        $data = $value;
                        $object = new $model($data);
                        $object->embed = true;
                        $cache[$key] = $object;

                        return $object;
                    }

                    return null;
                } elseif ($type == self::DATA_TYPE_EMBEDS) {
                    $res = array();
                    if (!empty($value)) {
                        foreach ($value as $item) {
                            $data = $item;
                            $record = new $model($data);
                            $record->embed = true;
                            if ($record) {
                                $res[] = $record;
                            }
                        }
                    }
                    $set =  Collection::make($res);
                    $cache[$key] = $set;
                    $obj = &$cache[$key];

                    return $obj;
                }
            }
        }
    }

    /**
     * If the attribute of $key is a reference ,
     * load its original record from db and save to $_cache temporarily.
     *
     * @param string $key key
     *
     * @return null
     */
    protected function loadRef($key)
    {
        $attrs = $this->getAttrs();
        $reference = $attrs[$key];
        $cache = &$this->_cache;
        if (isset($this->cleanData[$key])) {
            $value = $this->cleanData[$key];
        } else {
            $value = null;
        }

        $model = $reference['model'];
        $type = $reference['type'];
        if ( isset($cache[$key]) ) {
            $obj = &$cache[$key];

            return $obj;
        } else {
            if (class_exists($model)) {
                if ($type == self::DATA_TYPE_REFERENCE) {
                    if (\MongoDBRef::isRef($value)) {
                        $object = $model::id($value['$id']);
                        $cache[$key] = $object;

                        return $object;
                    }

                    return null;
                } elseif ($type == self::DATA_TYPE_REFERENCES) {
                    $res = array();
                    if (!empty($value)) {
                        foreach ($value as $item) {
                            if(isset($item['$id'], $item['$ref'])) {
                                $record = $model::id($item['$id']);
                                if ($record) {
                                    $res[] = $record;
                                }
                            }
                        }
                    }
                    $set =  Collection::make($res);
                    $cache[$key] = $set;
                    $obj = &$cache[$key];

                    return $obj;
                }
            }
        }
    }

    /**
     * unset a attribute
     *
     * @param string $key key
     *
     * @deprecated see __unset magic method and __unsetter
     *
     * @return null
     */
    protected function _unset($key)
    {
        $this->__unset($key);
    }

    /**
     * Initialize attributes with default value
     *
     * @return null
     */
    protected function initAttrs()
    {
        $attrs = self::getAttrs();
        foreach ($attrs as $key => $attr) {
            if (! isset($attr['default'])) continue;
            if (! isset($this->cleanData[$key])) {
                $this->$key = $attr['default'];
            }
        }
    }

    /**
     * Get all defined attributes in $attrs ( extended by parent class )
     *
     * @return array
     */
    public static function getAttrs()
    {
        $class = get_called_class();
        $parent = get_parent_class($class);
        if ($parent) {
            $attrs_parent = $parent::getAttrs();
            $attrs = array_merge($attrs_parent, $class::$attrs);
        } else {
            $attrs = $class::$attrs;
        }
        if(empty($attrs)) $attrs = array();

        return array_diff_key($attrs, array('__private__'));

    }

    public static function getRedisIndex()
    {
        $class = get_called_class();

        return $class::$redisIndex ?? [];
    }

    /**
     * Get private data
     *
     * @return array
     */
    protected static function &getPrivateData()
    {
        $class = get_called_class();
        if (!isset($class::$attrs['__private__'])) {
            $class::$attrs['__private__'] = array();
        }

        return $class::$attrs['__private__'];
    }

    /**
     * Get types of model,type is the class_name without namespace of Model
     *
     * @return array
     */
    protected static function getModelTypes()
    {
        $class = get_called_class();

        if($class::$useType === false) {
            return array();
        }

        $class_name = $class::getClassName(false);
        $parent = get_parent_class($class);
        if ($parent) {
            $names_parent = $parent::getModelTypes();
            $names = array_merge($names_parent, array($class_name));
        } else {
            $names = array();
        }

        return $names;

    }


    /**
     * Parse value with specific definition in $attrs
     *
     * @param string $key key
     * @param mixed $value value
     *
     * @throws Exception\InvalidDataTypeException
     * @return mixed
     */
    public function parseValue($key, $value)
    {
        $attrs = $this->getAttrs();

        if (!isset($attrs[$key]) && is_object($value)) {
            // Handle dates
            if (!$value instanceof \MongoDate
                && !$value instanceof \MongoId
                && !$value instanceof \MongoDBRef) {

                if ($value instanceof \DateTime) {
                    $value = new \MongoDate($value->getTimestamp());
                } else if (method_exists($value, 'toArray')) {
                    $value = (array) $value->toArray();
                } elseif (method_exists($value, 'to_array')) {
                    $value = (array) $value->to_array();
                }else{
                    //ingore this object when saving
                    $this->ignoreData[$key] = $value;
                }
            }
        } elseif (isset($attrs[$key]) && isset($attrs[$key]['type'])) {


            switch ($attrs[$key]['type']) {
                case self::DATA_TYPE_INT:
                case self::DATA_TYPE_INTEGER:
                    $value = (integer) $value;
                    break;
                case self::DATA_TYPE_STR:
                case self::DATA_TYPE_STRING:
                    $value = (string) $value;
                    break;
                case self::DATA_TYPE_FLT:
                case self::DATA_TYPE_FLOAT:
                case self::DATA_TYPE_DBL;
                case self::DATA_TYPE_DOUBLE:
                    $value = (float) $value;
                    break;
                case self::DATA_TYPE_TIMESTAMP:
                    if (! ($value instanceof \MongoTimestamp)) {
                        try {
                            $value = new \MongoTimestamp($value);
                        } catch (\Exception $e) {
                            echo 'bbb';exit();
                            throw new InvalidDataTypeException('$key cannot be parsed by \MongoTimestamp', $e->getCode(), $e);
                        }
                    }
                    break;
                case self::DATA_TYPE_DATE:
                    if (! ($value instanceof \MongoDate)) {
                        try {
                            if (!$value instanceof \MongoDate) {
                                if (is_numeric($value)) {
                                    $value = '@'.$value;
                                }
                                if (!$value instanceof \DateTime) {
                                    $value = new \DateTime($value);
                                }
                                $value = new \MongoDate($value->getTimestamp());
                            }
                        } catch (\Exception $e) {
                            throw new InvalidDataTypeException('$key cannot be parsed by \DateTime', $e->getCode(), $e);
                        }
                    }
                    break;
                case self::DATA_TYPE_BOOL:
                case self::DATA_TYPE_BOOLEAN:
                    $value = (boolean) $value;
                    break;
                case self::DATA_TYPE_OBJ:
                case self::DATA_TYPE_OBJECT:
                    if (!empty($value) && !is_array($value) && !is_object($value)) {
                        throw new InvalidDataTypeException("[$key] is not an object");
                    }
                    $value = (object) $value;
                    break;
                case self::DATA_TYPE_ARRAY:
                    if (!empty($value) && !is_array($value)) {
                        throw new InvalidDataTypeException("[$key] is not an array");
                    }
                    $value = (array) $value;
                    break;
                case self::DATA_TYPE_EMBED:
                case self::DATA_TYPE_EMBEDS:
                case self::DATA_TYPE_MIXED:
                case self::DATA_TYPE_REFERENCE:
                case self::DATA_TYPE_REFERENCES:
                    break;
                default:
                    throw new InvalidDataTypeException("{$attrs[$key]['type']} is not a valid type");
                    break;
            }
        }

        return $value;
    }


    /*** ----------- Hooks ----------- ***/

    /**
     * init hook
     *
     * @return true
     */
    protected function __init()
    {
        return true;
    }

    /**
     * pre save hook
     *
     * @return true
     */
    protected function __preSave()
    {
        return true;
    }

    /**
     * pre update hook
     *
     * @return true
     */
    protected function __preUpdate()
    {
        return true;
    }

    /**
     * pre insert hook
     *
     * @return true
     */
    protected function __preInsert()
    {
        return true;
    }

    /**
     * pre delete hook
     *
     * @return true
     */
    protected function __preDelete()
    {
        return true;
    }

    /**
     * post save hook
     *
     * @return true
     */
    protected function __postSave()
    {
        return true;
    }

    /**
     * post update hook
     *
     * @return true
     */
    protected function __postUpdate()
    {
        return true;
    }

    /**
     * post insert hook
     *
     * @return true
     */
    protected function __postInsert()
    {
        return true;
    }

    /**
     * post delete hook
     *
     * @return true
     */
    protected function __postDelete()
    {
        return true;
    }


    /*** ----------- Magic Methods ----------- ***/

    /**
     * Interface for __get magic method
     *
     * @param string $key key
     *
     * @return mixed
     */
    public function __getter($key)
    {
        if (isset($key, $this->ignoreData[$key])) {
            return $this->ignoreData[$key];
        }

        $attrs = $this->getAttrs();

        $value = null;

        if (isset($attrs[$key], $attrs[$key]['type'])) {
            if (in_array($attrs[$key]['type'], array(self::DATA_TYPE_REFERENCE, self::DATA_TYPE_REFERENCES))) {
                return $this->loadRef($key);
            } elseif (in_array($attrs[$key]['type'], array(self::DATA_TYPE_EMBED, self::DATA_TYPE_EMBEDS))) {
                return $this->loadEmbed($key);
            }
        }

        if (isset($this->cleanData[$key])) {
            $value = $this->parseValue($key, $this->cleanData[$key],$this->getAttrs());
            return $value;
        }

    }

    /**
     * __get
     *
     * @param string $key key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $value = $this->__getter($key);

        if (method_exists($this, 'get'.ucfirst($key))) {
            return call_user_func(array($this, 'get'.ucfirst($key)), $value);
        }

        return $value;
    }

    /**
     * Interface for __set magic method
     *
     * @param string $key   key
     * @param mixed  $value value
     *
     * @return null
     */
    public function __setter($key, $value)
    {
        if(isset($this->ignoreData[$key])){

            $this->ignoreData[$key] = $value;

        }else{

            $attrs = $this->getAttrs();

            if (isset($attrs[$key]) && isset($attrs[$key]['type']) ) {
                if (in_array($attrs[$key]['type'], array(self::DATA_TYPE_REFERENCE, self::DATA_TYPE_REFERENCES))) {
                    $value = $this->setRef($key, $value);
                } elseif (in_array($attrs[$key]['type'], array(self::DATA_TYPE_EMBED, self::DATA_TYPE_EMBEDS))) {
                    $value = $this->fillEmbed($key, $value);
                }
            }

            $value = $this->parseValue($key, $value, $this->getAttrs());

            if ( !isset($this->ignoreData[$key]) && ( !isset($this->cleanData[$key]) || $this->cleanData[$key] !== $value )) {
                $this->cleanData[$key] = $value;
                $this->dirtyData[$key] = $value;
            }

        }

    }


    /**
     * __set
     *
     * @param string $key   key
     * @param mixed  $value value
     *
     * @return null
     */
    public function __set($key, $value)
    {
        if (method_exists($this, 'set'.ucfirst($key))) {
            return call_user_func(array($this, 'set'.ucfirst($key)), $value);
        } else {
            $this->__setter($key, $value);
        }
    }

    /**
     * Interface for __unset magic method
     *
     * @param string $key key
     *
     * @throws \Exception
     * @return null
     */
    public function __unsetter($key)
    {
        if (strpos($key, ".") !== false) {
            throw new \Exception('The key to unset can\'t contain a "." ');
        }

        if (isset($this->cleanData[$key])) {
            unset($this->cleanData[$key]);
        }

        if (isset($this->dirtyData[$key])) {
            unset($this->dirtyData[$key]);
        }

        $this->unsetData[$key] = 1;
    }

    /**
     * __unset
     *
     * @param string $key key
     *
     * @return null
     */
    public function __unset($key)
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                $this->__unset($item);
            }
        } else {
            if (method_exists($this, 'unset'.ucfirst($key))) {
                return call_user_func(array($this, 'unset'.ucfirst($key)));
            } else {
                $this->__unsetter($key);
            }
        }
    }

    /**
     * __isset
     *
     * @param string $key key
     *
     * @return null
     */
    public function __isset($key)
    {
        return isset($this->cleanData[$key])
        || isset($this->dirtyData[$key])
        || isset($this->ignoreData[$key]);
    }

    /**
     * __call
     *
     * @param string $func func
     * @param mixed  $args args
     *
     * @return null
     */
    public function __call($func, $args)
    {
        // Chain methods
        if(strrpos($func, 'Chain') && strlen($func) > 5) {
            $func = substr($func, 0, strrpos($func, 'Chain'));
            call_user_func_array(array($this, $func), $args);
            return $this;
        }

        if ($func == 'unset' && isset($args[0])) {
            $this->__unset($args[0]);
        }

        if (strpos($func, 'get') === 0 && strlen($func) > 3) {
            $key = strtolower(substr($func, 3));
            if (method_exists($this, $func)) {
                return call_user_func(array($this, $func));
            }

            return $this->__get($key);
        }

        if (strpos($func, 'set') === 0 && strlen($func) > 3) {
            $key = strtolower(substr($func, 3));
            if (method_exists($this, $func)) {
                return call_user_func(array($this, $func), isset($args[0]) ? $args[0] : null);
            }

            return $this->__set($key, isset($args[0]) ? $args[0] : null);
        }
    }

}

