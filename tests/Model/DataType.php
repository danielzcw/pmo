<?php

namespace Danz\Pmo\Test\Model;

class DataType extends Base
{

  public static $collection = "dataType";

  protected static $attrs = array(
    \Danz\Pmo\Model::DATA_TYPE_ARRAY      => array('type'=>\Danz\Pmo\Model::DATA_TYPE_ARRAY,      'default'=>array(1,2,3)),
    \Danz\Pmo\Model::DATA_TYPE_BOOLEAN    => array('type'=>\Danz\Pmo\Model::DATA_TYPE_BOOLEAN,    'default'=>true),
    \Danz\Pmo\Model::DATA_TYPE_DATE       => array('type'=>\Danz\Pmo\Model::DATA_TYPE_DATE,       'default'=>"now"),
    \Danz\Pmo\Model::DATA_TYPE_DOUBLE     => array('type'=>\Danz\Pmo\Model::DATA_TYPE_DOUBLE,     'default'=>1.2345),
    \Danz\Pmo\Model::DATA_TYPE_EMBED      => array('type'=>\Danz\Pmo\Model::DATA_TYPE_EMBED,      'model'=>'Danz\Pmo\Test\Model\Embed'),
    \Danz\Pmo\Model::DATA_TYPE_EMBEDS     => array('type'=>\Danz\Pmo\Model::DATA_TYPE_EMBEDS,     'model'=>'Danz\Pmo\Test\Model\Embed'),
    \Danz\Pmo\Model::DATA_TYPE_INT        => array('type'=>\Danz\Pmo\Model::DATA_TYPE_INT,        'default'=>123),
    \Danz\Pmo\Model::DATA_TYPE_INTEGER    => array('type'=>\Danz\Pmo\Model::DATA_TYPE_INTEGER,    'default'=>456),
    \Danz\Pmo\Model::DATA_TYPE_MIXED      => array('type'=>\Danz\Pmo\Model::DATA_TYPE_MIXED,      'default'=>array(1,'a',array('b'=>'B'))),
    \Danz\Pmo\Model::DATA_TYPE_REFERENCE  => array('type'=>\Danz\Pmo\Model::DATA_TYPE_REFERENCE,  'model'=>'Danz\Pmo\Test\Model\Refernece'),
    \Danz\Pmo\Model::DATA_TYPE_REFERENCES => array('type'=>\Danz\Pmo\Model::DATA_TYPE_REFERENCES, 'model'=>'Danz\Pmo\Test\Model\Reference'),
    \Danz\Pmo\Model::DATA_TYPE_STRING     => array('type'=>\Danz\Pmo\Model::DATA_TYPE_STRING,     'default'=>'string'),
    \Danz\Pmo\Model::DATA_TYPE_TIMESTAMP  => array('type'=>\Danz\Pmo\Model::DATA_TYPE_TIMESTAMP,  'default'=>0),
    \Danz\Pmo\Model::DATA_TYPE_OBJECT     => array('type'=>\Danz\Pmo\Model::DATA_TYPE_OBJECT,     'default'=>array('a' => 'A')),
    'invalid'                                    => array('type'=>'invalid')
  );

}
