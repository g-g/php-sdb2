<?php

/**
 * This file gives an example of the php-sdb2 queue* methods
 * They store write/delete calls and send them all at once in the end.
 * This reduces the time needed, because only one connection to the SimpleDB has to be made.
 * For this, the batchPutAttributes and bachDeleteAttributes methods are used.
 * 
 * @package php-sdb2
 *
 * @author Georg Gell
 *
 * @license: For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code, or @link https://github.com/g-g/php-sdb2/blob/master/LICENSE.
 *
 * @link https://github.com/g-g/php-sdb2
 */

// Import the SimpleDB classes into your name space
use g_g\php_sdb2\SimpleDB;
use g_g\php_sdb2\SimpleDBException;
require_once('g_g\php_sdb2\sdb2.php');
$awsKey = '';
$awsSecretKey = '';

if (empty($awsKey) || empty($awsSecretKey)) {
    die('Please set you AWS credentials before running this script');
}

// Mission: log activities
$simpleDB = new SimpleDB($awsKey, $awsSecretKey);
$domain = 'Log';
$simpleDB->createDomain($domain);
$simpleDB->queuePutAttributes($domain, 'Start', array('time' => $start));

// to some work ...
$simpleDB->queuePutAttributes($domain, 'Do some work', array('time' => time() - $start));

$start = time();
// to some other work ...
$simpleDB->queuePutAttributes($domain, 'Do some other work', array('time' => time() - $start));

// Log the close down
$simpleDB->queuePutAttributes($domain, 'End', array('time' => time()));
// here we send all logs at once
$simpleDb->flushPutAttributesQueue($domain);
$simpleDB->deleteDomain($domain);

echo 'This example used up ' . $simpleDB->getTotalBoxUsage() . ' machine hours.';
