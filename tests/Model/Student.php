<?php

namespace Danz\Pmo\Test\Model;

class Student extends User
{

     protected static $attrs = array(

         'grade' => array('type'=>'string'),

    );

}
