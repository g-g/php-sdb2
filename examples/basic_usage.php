<?php
/**
 * This file shows basic php-sdb2 examples 
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

$simpleDB = new SimpleDB($awsKey, $awsSecretKey);
$simpleDB->setErrorHandling(SimpleDB::ERROR_HANDLING_THROW_EXCEPTION);

try {

// Let's create a domain
    $domain = 'MYDOMAIN';
    $simpleDB->createDomain($domain);

// Now let's add an item
    $simpleDB->putAttribute($domain, 'ITEM1', array('a' => 1, 'b' => 3, 'c' => 17));

// Some more items
    $simpleDB->putAttribute($domain, 'ITEM2', array('a' => 2, 'b' => 4, 'c' => 16));
    $simpleDB->putAttribute($domain, 'ITEM3', array('a' => 3, 'b' => 5, 'c' => 15));

// Find some items
// Don't forget to quote the values 
// (http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
    $results = $simpleDB->select("select * from $domain where a > '1'");
    print_r($results);

// Clean up!
// This isn't strictly necessary, because when we delete the domain, all data is lost anyway
// Don't forget to quote the values 
// (http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
// Basically this works like DELETE * FROM $domain WHERE a > 0
    $simpleDB->deleteWhere($domain, "a > '0'");
    $simpleDB->deleteDomain($domain);
} catch (SimpleDBException $e) {
    echo "Something went wrong: " . $e->getMessage();
}

// Was it expensive?
echo 'This example used up ' . $simpleDB->getTotalBoxUsage() . ' machine hours.';
?>


?>
