<?php

namespace Danz\Pmo\Test\Model;

class Pupil extends Student
{
    public static $collection = "pupil";

     protected static $attrs = array(

         'class' => array('default'=>"A",'type'=>'string'),

    );

}
