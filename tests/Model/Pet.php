<?php

namespace Danz\Pmo\Test\Model;

class Pet extends Base
{

    public static $collection = "pet";

    protected static $attrs = array(

        'name' => array('type'=>'string' ,'default'=>'Puppy'),

        'fieldMappingEmbed' => array('type'=>'string', 'field' => 'field_mapping_embed'),
        'fieldMappingEmbeds' => array('type'=>'string', 'field' => 'field_mapping_embeds'),

    );

}
