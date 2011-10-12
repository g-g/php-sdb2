php-sdk2
========

php-sdk2 is a single class PHP library that makes the Amazon AWS SimpleDB REST API available
as PHP methods. All 
[API operations](http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/SDB_API_Operations.html)
are available:

*    BatchDeleteAttributes
*    BatchPutAttributes
*    CreateDomain
*    DeleteAttributes
*    DeleteDomain
*    DomainMetadata
*    GetAttributes
*    ListDomains
*    PutAttributes
*    Select

Some additional functionality is available:

*    Default handler for service temporarily unavailable, which retries four times with different waiting intervals
     before giving up
*    A total machine hours counter, that adds all box usage returns over the life time of the SimpleDB object
*    A queue PutAttributes and a queue delete feature, because it is less expensive to send one batch request than many single 
     requests. The methods take care themselves to send data when the maximum item number for a batch job is reached.
*    The select method has an additional argument to return the whole result at once. No more NextTokens ;-) (if a large 
     result set is returned, this may need a lot of memory, be careful). 
*    The class has now an adaptable error handling. It can raise PHP warnings, errors, throw Exceptions or do nothing.



This project is a fork of the sourceforge [php-sdk project](https://sourceforge.net/projects/php-sdb/) 
from [Dan Myers(heronblademastr)](https://sourceforge.net/users/heronblademastr).
I forked it because it doesn't seem to be maintained anymore, and some functionality is missing 
(batch_delete, ...).





**Attention**: This class is not compatible with the original class. Find below the incompatibilities:

*    The first argument of the select method has been dropped, because it needed to be empty to work.
*    The select methods returns items as an array of name => attributes, and not as an array of 
     ('name' => name, 'attributes' => attributes)
*    The query and queryWithAttributes methods have been removed, because they are deprecated.
*    If the condition of a conditional putAttributes is not met, no error is triggered 
     (SimpleDB error codes 'ConditionalCheckFailed' and 'AttributeDoesNotExist')
*    batchPutAttributes: The items are exprected in the form $items = array('name1' => array(...), 'name2' => array(...)),
     and not like $items = array(array('name' => 'name1', 'attributes' => array(...)), ...) in the old version.

Install
-------

Just copy everything from the src directory to your project's directory.

Usage
-----

A basic example

```php
<?php
// Import the SimpleDB classes into your name space
use g_g\php_sdb2\SimpleDB;
use g_g\php_sdb2\SimpleDBException;
require_once('g_g\php_sdb2\sdb2.php');

$simpleDB = new SimpleDB('AWS_KEY', 'AWS_SECRET_KEY');
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

    // and let's query for some items
    // Don't forget to quote the values 
    // (http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
    $results = $simpleDB->select("select * from `$domain` where a > '1'");
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
```

You can find more examples in the [examples directory](https://github.com/g-g/php-sdb2/blob/master/examples).

There are some sample programs for the original library, which work also for this library, except the select and
batchPutAttributes method. Check out the [Webmaster In Residence](http://webmasterinresidence.ca/webmasterinresidence/?p=464)

You may also check the unit tests in SimpleDBTest. There you can find many examples how to use a method, and the 
getResultData() method provides the parameters that are send to AWS SimpleDB and what it returns in each case.

About
=====

Requirements
------------

- PHP >= 5.3
- [optional] PHPUnit 3.5+ to execute the test suite

Submitting bugs and feature requests
------------------------------------

Bugs and feature request are tracked on [GitHub](https://github.com/g-g/php-sdb2/issues).

PRs are welcome ;-)

Authors, copyright and license
------------------------------

php-sdb2 is licensed under a modified BSD license.
See the [LICENSE file](https://github.com/g-g/php-sdb2/blob/master/LICENSE) for details on the license and copyrights.

