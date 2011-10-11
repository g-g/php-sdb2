<?php
/**
 * This file gives an example of the php-sdb2 select method.
 * We assume that we have a lot of data in the domain
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

// Mission: Select howto
$simpleDB = new SimpleDB($awsKey, $awsSecretKey);
$domain = 'mydomain';

// let's be certain that the domain name is valid, maybe it's user data
if (!$simpleDB->validDomainName($domain)) {
    die('Bad domain name');
}


$value = 34;

// The followin select will return the first 100 items (default limit) where a1 = 34, and $simpleDB->nextToken will be set if there are more items
// AWS SimpleDB allows you to get a maximum of 2500 in one run, but if the result size would be larger then 2MB, a lower number would be returned
// be careful to quote everything correctly (http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
$items = $simpleDB->select('select * from `' . $domain . "` where a1 = '" . str_replace("'", "''", $value) . "'");

if ($simpleDB->nextToken) { // we have more items
    $next100Items = $simpleDB->select('select * from `' . $domain . "` where a1 = '" . str_replace("'", "''", $value) . "'", $simpleDB->nextToken);
}

// if you want all items in one run:
$allItems = $simpleDB->select('select * from `' . $domain . "` where a1 = '" . str_replace("'", "''", $value) . "'", null, false, true);

// if you use $returnTotalResult = true, you can add a limit clause to specify how many items you want. 
$first5000Items = $simpleDB->select('select * from `' . $domain . "` where a1 = '" . str_replace("'", "''", $value) . "' limit 5000", null, false, true);

echo 'This example used up ' . $simpleDB->getTotalBoxUsage() . ' machine hours.';