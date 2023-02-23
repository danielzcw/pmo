<?php

if ( ! file_exists($file = __DIR__.'/../vendor/autoload.php')) {
    echo "You must install the dev dependencies using:\n";
    echo "    composer install --dev\n";
    exit(1);
}

date_default_timezone_set('UTC');

$loader = require_once $file;

$map = array(
        'Danz\Pmo\Test\Model\Base'=> __DIR__."/Model/Base.php",
        'Danz\Pmo\Test\Model\User'=> __DIR__."/Model/User.php",
        'Danz\Pmo\Test\Model\Book'=> __DIR__."/Model/Book.php",
        'Danz\Pmo\Test\Model\Student'=> __DIR__."/Model/Student.php",
        'Danz\Pmo\Test\Model\Pupil'=> __DIR__."/Model/Pupil.php",
        'Danz\Pmo\Test\Model\Pet'=> __DIR__."/Model/Pet.php",
        'Danz\Pmo\Test\Model\DataType'=> __DIR__."/Model/DataType.php"
    );
$loader->addClassMap($map);

$loader->add('Danz\Pmo\Test', __DIR__);
