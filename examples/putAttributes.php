<?php

/**
 * This file gives an example of the php-sdb2 putAttributes method.
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

// Let's create a domain
    $domain = 'MYDOMAIN';
    $simpleDB->createDomain($domain);

// Now let's add an item
    $simpleDB->putAttribute($domain, 'ITEM1', array('a' => 1, 'b' => 3));
/* MYDOMAIN content
 * 'ITEM1' => array('a' => 1, 'b' => 3)
 */
    
// We add some data to the same item
    $simpleDB->putAttribute($domain, 'ITEM1', array('a' => 2, 'b' => 4));
/* MYDOMAIN content
 * 'ITEM1' => array('a' => array(1, 2), 'b' => (3, 4))
 */

// We replace attribute a
    $simpleDB->putAttribute($domain, 'ITEM1', array('a' => array('value' => 5, 'replace' => true)));
/* MYDOMAIN content
 * 'ITEM1' => array('a' => 5, 'b' => (3, 4))
 */

// we replace everything
// ATTENTION: attribute b still exists after this
    $simpleDB->putAttribute($domain, 'ITEM1', array('a' => 6, 'c' => 17, 'd' => array(11,12,13)), true);
/* MYDOMAIN content
 * 'ITEM1' => array('a' => 6, 'b' => (3, 4), 'c' => 17, 'd' => array(11,12,13))
 */
    
echo 'This example used up ' . $simpleDB->getTotalBoxUsage() . ' machine hours.';