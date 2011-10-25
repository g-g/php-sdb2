<?php

/**
 * 
 * @package php-sdb2
 *
 * @copyright Georg Gell
 * @copyright 2010, Dan Myers.
 * @copyright Parts copyright (c) 2008, Donovan Schonknecht.
 * @copyright Additional functionality by Rich Helms rich@webmasterinresidence.ca
 *
 * @license: For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code, or @link https://github.com/g-g/php-sdb2/blob/master/LICENSE.
 *
 * @link https://github.com/g-g/php-sdb2
 * This file is based on Dan Myers' php-sdb class
 * @link https://sourceforge.net/projects/php-sdb/
 */
/**
 * For notes on eventual consistency and the use of the ConsistentRead parameter,
 * see http://developer.amazonwebservices.com/connect/entry.jspa?externalID=3572
 *
 */

namespace g_g\php_sdb2;

require_once dirname(__FILE__) . '/SimpleDBRequest.php';
require_once dirname(__FILE__) . '/SimpleDBError.php';
require_once dirname(__FILE__) . '/SimpleDBException.php';

/**
 * Amazon SimpleDB PHP class
 *
 */
class SimpleDB {
    const MAX_ITEM_BATCH_SIZE = 25; // This is an AWS limit (http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/SDB_API_BatchPutAttributes.html)
    const MAX_SELECT_LIMIT = 2500; // AWS limit: Max number of items returned by a select query at once
    const ERROR_HANDLING_IGNORE = 0;
    const ERROR_HANDLING_TRIGGER_WARNING = 1;
    const ERROR_HANDLING_TRIGGER_ERROR = 2;
    const ERROR_HANDLING_THROW_EXCEPTION = 3;

    protected $_expectedErrors = array('ConditionalCheckFailed', 'AttributeDoesNotExist'); // All errors in this error will not result in a "false" return code. But they can still be checked by getLastError()
    protected $_accessKey; // AWS Access key
    protected $_secretKey; // AWS Secret key
    protected $_host;
    protected $_useSSL = true;
    protected $_verifyHost = 1;
    protected $_verifyPeer = 1;
    protected $_totalBoxUsage = 0;
    protected $_itemsToWrite = array();
    protected $_itemsToDelete = array();
    protected $_errorHandling;
    protected static $_connections = array();
    protected $_actionName = '';
    
    public $lastError = false;
    // information related to last request
    public $BoxUsage;
    public $RequestId;
    public $NextToken;
    public $ErrorCode;

    /**
     * Returns a single object for each aws key and host constructed with the parameters past for the first call to getInstance..
     *
     * @param string $accessKey Access key
     * @param string $secretKey Secret key
     * @param string $host the AWS region host
     * @param boolean $useSSL Enable SSL
     * @param int $errorHandling any of this class' error handling constants
     * @param boolean $useBuildin503Handler use SimpleDBs own service unavailable handler serviceUnavailable4RetriesCallback()
     * @return g_g\php_sdb2\SimpleDB
     */
    public static function getInstance($accessKey = null, $secretKey = null, $host = 'sdb.amazonaws.com', $useSSL = true, $errorHandling = self::ERROR_HANDLING_TRIGGER_WARNING, $useBuildin503Handler = false) {
        if (!isset($accessKey)) {
            if (count(self::$_connections) == 1) {
                return reset(self::$_connections);
            } else {
                trigger_error('SimpleDB::getInstance() failed, because ' . (count(self::$_connections) ? 'there are more than one connections.' : ' there is no connection.'),
                        E_USER_WARNING);
                return false;
            }
        }
        if (!isset(self::$_connections[$accessKey . $host])) {
            self::$_connections[$accessKey . $host] = new static($accessKey, $secretKey, $host, $useSSL, $errorHandling);
            if ($useBuildin503Handler) {
                self::$_connections[$accessKey . $host]->setServiceUnavailableRetryDelayCallback(array(self::$_connections[$accessKey . $host], 'serviceUnavailable4RetriesCallback'));
            }
        }
        return self::$_connections[$accessKey . $host];
    }

    public function getAccessKey() {
        return $this->_accessKey;
    }

    public function getSecretKey() {
        return $this->_secretKey;
    }

    public function getHost() {
        return $this->_host;
    }

    public function useSSL() {
        return $this->_useSSL;
    }

    public function enableUseSSL($enable = true) {
        $this->_useSSL = $enable;
    }

    // verifyHost and verifyPeer determine whether curl verifies ssl certificates.
    // It may be necessary to disable these checks on certain systems.
    // These only have an effect if SSL is enabled.
    public function verifyHost() {
        return $this->_verifyHost;
    }

    public function enableVerifyHost($enable = true) {
        $this->_verifyHost = $enable;
    }

    public function verifyPeer() {
        return $this->_verifyPeer;
    }

    public function enableVerifyPeer($enable = true) {
        $this->_verifyPeer = $enable;
    }

    /**
     * Callback that determines the retry delay for "service temporarily unavailable" errors, in microseconds.
     * The default behavior is to not retry.
     *
     * The retry delay must be a minimum of 1 microsecond.  A zero-delay retry is not permitted.
     * Returning <= 0 will abort the retry loop.
     *
     * @param $attempt The number of failed attempts so far
     * @return The number of microseconds to wait before retrying, or 0 to not retry.
     */
    protected $_serviceUnavailableRetryDelayCallback = "";

    // pass the name of the callback you want to use, as a callable.
    // @see http://www.php.net/manual/en/language.pseudo-types.php#language.types.callback
    public function setServiceUnavailableRetryDelayCallback($callback) {
        $this->_serviceUnavailableRetryDelayCallback = $callback;
    }

    public function clearServiceUnavailableRetryDelayCallback() {
        $this->_serviceUnavailableRetryDelayCallback = "";
    }

    /**
     * Constructor 
     *
     * @param string $accessKey Access key
     * @param string $secretKey Secret key
     * @param boolean $useSSL Enable SSL
     * @return void
     */
    public function __construct($accessKey = null, $secretKey = null, $host = 'sdb.amazonaws.com', $useSSL = true, $errorHandling = self::ERROR_HANDLING_TRIGGER_WARNING) {
        if ($accessKey !== null && $secretKey !== null) {
            $this->setAuth($accessKey, $secretKey);
        }
        $this->_useSSL = $useSSL;
        $this->_host = $host;
        $this->_errorHandling = $errorHandling;
    }

    /**
     * Set AWS access key and secret key
     *
     * @param string $accessKey Access key
     * @param string $secretKey Secret key
     * @return void
     */
    public function setAuth($accessKey, $secretKey) {
        $this->_accessKey = $accessKey;
        $this->_secretKey = $secretKey;
    }

    /**
     * Sets the way errors are handled
     * @param int $errorHandling
     * allowed values are 
     *      SimpleDBResult::ERROR_HANDLING_IGNORE
     *      SimpleDB::ERROR_HANDLING_TRIGGER_WARNING
     *      SimpleDB::ERROR_HANDLING_TRIGGER_ERROR
     *      SimpleDB::ERROR_HANDLING_THROW_EXCEPTION
     */
    public function setErrorHandling($errorHandling) {
        $this->_errorHandling = $errorHandling;
    }

    /**
     * Create a domain
     *
     * @param string $domain The domain to create
     * @return boolean
     */
    public function createDomain($domain) {
        $this->_checkDomainName($domain, 'createDomain');
        $this->_clearReturns();

        $rest = $this->_getSimpleDBRequest($domain, 'CreateDomain', 'POST');
        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Delete a domain
     *
     * @param string $domain The domain to delete
     * @return boolean
     */
    public function deleteDomain($domain) {
        $this->_checkDomainName($domain, 'deleteDomain');
        $this->_clearReturns();

        $rest = $this->_getSimpleDBRequest($domain, 'DeleteDomain', 'DELETE');
        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Get a list of domains
     *
     * @return array | false
     */
    public function listDomains($maxNumberOfDomains = null, $nexttoken = null) {
        $this->_clearReturns();

        $rest = $this->_getSimpleDBRequest('', 'ListDomains', 'GET');
        if ($maxNumberOfDomains !== null) {
            $rest->setParameter('MaxNumberOfDomains', $maxNumberOfDomains);
        }
        if ($nexttoken !== null) {
            $rest->setParameter('NextToken', $nexttoken);
        }
        $rest = $rest->getResponse();
        if (!$this->_checkResponse($rest)) {
            return false;
        }

        $results = array();
        if (!isset($rest->body->ListDomainsResult)) {
            return $results;
        }

        foreach ($rest->body->ListDomainsResult->DomainName as $d) {
            $results[] = (string) $d;
        }

        return $results;
    }

    /**
     * Get a domain's metadata
     *
     * @param string $domain The domain
     * @return array | false
     * 	Array returned
     * 	(
     * 		[ItemCount] => 3
     * 		[ItemNamesSizeBytes] => 16
     * 		[AttributeNameCount] => 9
     * 		[AttributeNamesSizeBytes] => 76
     * 		[AttributeValueCount] => 13
     * 		[AttributeValuesSizeBytes] => 65
     * 		[Timestamp] => 1247238402
     * 	)
     */
    public function domainMetadata($domain) {
        $this->_checkDomainName($domain, 'domainMetadata');
        $this->_clearReturns();

        $rest = $this->_getSimpleDBRequest($domain, 'DomainMetadata', 'GET');
        $rest = $rest->getResponse();
        if (!$this->_checkResponse($rest)) {
            return false;
        }

        $results = array();
        if (!isset($rest->body->DomainMetadataResult)) {
            return $results;
        }
        if (isset($rest->body->DomainMetadataResult->ItemCount)) {
            $results['ItemCount'] = (string) ($rest->body->DomainMetadataResult->ItemCount);
        }
        if (isset($rest->body->DomainMetadataResult->ItemNamesSizeBytes)) {
            $results['ItemNamesSizeBytes'] = (string) ($rest->body->DomainMetadataResult->ItemNamesSizeBytes);
        }
        if (isset($rest->body->DomainMetadataResult->AttributeNameCount)) {
            $results['AttributeNameCount'] = (string) ($rest->body->DomainMetadataResult->AttributeNameCount);
        }
        if (isset($rest->body->DomainMetadataResult->AttributeNamesSizeBytes)) {
            $results['AttributeNamesSizeBytes'] = (string) ($rest->body->DomainMetadataResult->AttributeNamesSizeBytes);
        }
        if (isset($rest->body->DomainMetadataResult->AttributeValueCount)) {
            $results['AttributeValueCount'] = (string) ($rest->body->DomainMetadataResult->AttributeValueCount);
        }
        if (isset($rest->body->DomainMetadataResult->AttributeValuesSizeBytes)) {
            $results['AttributeValuesSizeBytes'] = (string) ($rest->body->DomainMetadataResult->AttributeValuesSizeBytes);
        }
        if (isset($rest->body->DomainMetadataResult->Timestamp)) {
            $results['Timestamp'] = (string) ($rest->body->DomainMetadataResult->Timestamp);
        }

        return $results;
    }

    /**
     * Evaluate a select expression
     *
     * Function provided by Matthew Lanham
     *
     * @param string  $select The select query to evaluate. Be careful to escape correctly (@see http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
     * @param string  $nexttoken The token to start from when retrieving results
     * @param boolean $ConsistentRead force consistent read = true
     * @param boolean $returnTotalResult if true, the total result is returned. All nexttoken calls are handled automatically. Be careful, this might consume a lot of memory, if the result is large.
     * @return array | false
     */
    public function select($select, $nexttoken = null, $ConsistentRead = false, $returnTotalResult = false) {
        return $this->_doSelect($select, $nexttoken, $ConsistentRead, $returnTotalResult, false);
    }

    /**
     * Get attributes associated with an item
     *
     * @param string  $domain The domain containing the desired item
     * @param string  $item The desired item
     * @param integer $attribute A specific attribute to retrieve, or all if unspecified.
     * @param boolean ConsistentRead - force consistent read = true
     * @return boolean
     */
    public function getAttributes($domain, $item, $attribute = null, $ConsistentRead = false) {
        $this->_checkDomainName($domain, 'getAttributes');
        $this->_clearReturns();
        $rest = $this->_getSimpleDBRequest($domain, 'GetAttributes', 'GET');
        $rest->setParameter('ItemName', $item);
        if ($attribute !== null) {
            $rest->setParameter('AttributeName', $attribute);
        }
        if ($ConsistentRead == true) {
            $rest->setParameter('ConsistentRead', 'true');
        }
        $rest = $rest->getResponse();
        if (!$this->_checkResponse($rest)) {
            return false;
        }
        $results = array();
        if (!isset($rest->body->GetAttributesResult)) {
            return $results;
        }
        foreach ($rest->body->GetAttributesResult->Attribute as $a) {
            if (isset($results[(string) ($a->Name)])) {
                $temp = (array) ($results[(string) ($a->Name)]);
                $temp[] = (string) ($a->Value);
                $results[(string) ($a->Name)] = $temp;
            } else {
                $results[(string) ($a->Name)] = (string) ($a->Value);
            }
        }
        return $results;
    }

    /**
     * Create or update attributes on an item
     *
     * @param string  $domain The domain containing the desired item
     * @param string  $item The desired item
     * @param array $attributes An array of (name => (value [, replace])),
     * 							where replace is a boolean of whether to replace the item.
     * 							replace is optional, and defaults to $defaultReplace (below).
     * 							If value is an array, multiple values are put.
     * @param array $expected An array of (name => (value)), or (name => (exists = "false"))
     * @param boolean $defaultReplace Specifies the default value to use for 'replace'
     * 							for each attribute.  Defaults to false. Setting this to true
     * 							will cause all attributes to replace any existing attributes.
     * @return boolean
     */
    public function putAttributes($domain, $item, $attributes, $expected = null, $defaultReplace = false) {
        $this->_checkDomainName($domain, 'putAttributes');
        $this->_clearReturns();

        if ($defaultReplace) {
            $attributes = $this->_addMissingReplaceFlags($attributes);
        }

        $rest = $this->_getSimpleDBRequest($domain, 'PutAttributes', 'POST');

        $rest->setParameter('ItemName', $item);

        $this->_setParameterForAttributes($rest, $attributes);
        $this->_setParameterForExpected($rest, $expected);

        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Create or update attributes on multiple items
     * MAX 25 items per write (SimpleDB limit)
     *
     * Function provided by Matthew Lanham
     *
     * @param string $domain The domain containing the desired item
     * @param array  $items An array of items of (item_name => attributes (@see putAttributes))
     * 	If replace is omitted it defaults to false.
     * 	Optionally, attributes may just be a single string value, and replace will default to false.
     * @param boolean $defaultReplace Specifies the default value to use for 'replace'
     * 							for each attribute.  Defaults to false. Setting this to true
     * 							will cause all attributes to replace any existing attributes.
     * @return boolean
     */
    public function batchPutAttributes($domain, $items, $defaultReplace = false) {
        $this->_checkDomainName($domain, 'batchPutAttributes');
        if ($this->_isMoreThanMaxBatchItems($items, 'batchPutAttributes')) {
            return false;
        }

        $this->_clearReturns();

        if ($defaultReplace) {
            $updatedItems = array();
            foreach ($items as $name => $attributes) {
                $updatedItems[$name] = $this->_addMissingReplaceFlags($attributes);
            }
            $items = $updatedItems;
        }

        $rest = $this->_getSimpleDBRequest($domain, 'BatchPutAttributes', 'POST');

        $ii = 0;
        foreach ($items as $name => $attributes) {
            $rest->setParameter('Item.' . $ii . '.ItemName', $name);

            $this->_setParameterForAttributes($rest, $attributes, 'Item.' . $ii . '.');
            $ii++;
        }

        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Delete attributes associated with an item
     *
     * @param string  $domain The domain containing the desired item
     * @param string  $item The desired item
     * @param array $attributes An array of names or (name => value)
     * 				value is either a specific value or null.
     * 				setting the value will erase the attribute with the given
     * 				name and value (for multi-valued attributes).
     * 				If array is unspecified, all attributes are deleted.
     * @return boolean
     */
    public function deleteAttributes($domain, $item, $attributes = null, $expected = null) {
        $this->_checkDomainName($domain, 'deleteAttributes');
        $this->_clearReturns();
        $rest = $this->_getSimpleDBRequest($domain, 'DeleteAttributes', 'DELETE');
        $rest->setParameter('ItemName', $item);
        $this->_setParameterForAttributes($rest, $attributes);
        $this->_setParameterForExpected($rest, $expected);
        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Delete multiple items or their associated attributes
     *
     * @param string  $domain The domain containing the desired item
     * @param array  An array of ($itemName => $attributes: Either null or an array of (name => value)
     * 				value is either a specific value, an array of specific values or null.
     * 				setting the value will erase the attribute with the given
     * 				name and value (for multi-valued attributes).
     * 				If array is unspecified, all attributes are deleted.
     * @return boolean
     */
    public function batchDeleteAttributes($domain, $items) {
        $this->_checkDomainName($domain, 'batchDeleteAttributes');
        if ($this->_isMoreThanMaxBatchItems($items, 'batchDeleteAttributes')) {
            return false;
        }
        $this->_clearReturns();

        $rest = $this->_getSimpleDBRequest($domain, 'BatchDeleteAttributes', 'POST');
        $ii = 0;
        foreach ($items as $itemName => $attributes) {
            $i = 0;
            $rest->setParameter('Item.' . $ii . '.ItemName', $itemName);
            if (isset($attributes)) {
                foreach ($attributes as $name => $value) {
                    if (!isset($value)) {
                        $rest->setParameter('Item.' . $ii . '.Attribute.' . $i . '.Name', $name);
                        $i++;
                    } else {
                        if (!is_array($value)) {
                            $value = array($value);
                        }
                        foreach ($value as $val) {
                            $rest->setParameter('Item.' . $ii . '.Attribute.' . $i . '.Name', $name);
                            $rest->setParameter('Item.' . $ii . '.Attribute.' . $i . '.Value', $val);
                            $i++;
                        }
                    }
                }
            }
            $ii++;
        }

        $rest = $rest->getResponse();
        return $this->_checkResponse($rest);
    }

    /**
     * Clear public parameters
     *
     */
    public function _clearReturns() {
        $this->BoxUsage = null;
        $this->RequestId = null;
        $this->NextToken = null;
        $this->ErrorCode = null;
        $this->lastError = false;
        return true;
    }

    /**
     * Callback handler for 503 retries.
     *
     * @internal Used by SimpleDBRequest to call the user-specified callback, if set
     * @param $attempt The number of failed attempts so far
     * @return The retry delay in microseconds, or 0 to stop retrying.
     */
    public function executeServiceTemporarilyUnavailableRetryDelay($attempt) {
        if (is_callable($this->_serviceUnavailableRetryDelayCallback)) {
            return call_user_func($this->_serviceUnavailableRetryDelayCallback, $attempt);
        }
        return 0;
    }

    /**
     * Retries 4 times before giving up, when the the AWS service is temporarily unavailable. The waiting intervals
     * before retrying are 0.25, 0.5, 1, 2 seconds
     * 
     * @param int $attempts the n-th attempt
     * @return int waiting time before retry, 0 means no more retries
     */
    public function serviceUnavailable4RetriesCallback($attempts) {
        $wait = array(1 => 250, 2 => 500, 3 => 1000, 4 => 2000, 5 => 0);
        return $wait[$attempts];
    }

    /**
     * Returns the total box usage in machine hours over the life time of this class
     * 
     * @param string|boolean $format a sprintf format to format the time. Defaults to %.10f. If $format is false, the numeric value is returned
     * @return string|int 
     */
    public function getTotalBoxUsage($format = '%.10f') {
        return $format ? sprintf($format, $this->_totalBoxUsage) : $this->_totalBoxUsage;
    }

    /**
     * Deletes all items that fit the condition
     *
     * @param type $domain
     * @param string $condition The condition you would use in a SQL delete statement after where. A limit may also be used. Be careful to escape correctly (@see http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/QuotingRulesSelect.html)
     */
    public function deleteWhere($domain, $condition, $ConsistentRead = false) {
        $this->_checkDomainName($domain, 'deleteWhere');
        $result = $this->_doSelect('select itemName() from `' . $domain . '` where ' . $condition, null,
                $ConsistentRead, true, array($this, '_queueItemsForDelete'));
        return $result && $this->flushDeleteAttributesQueue($domain);
    }

    /**
     * Queue a delete operation to minimize the number of connections to AWS SimpleDB
     * 
     * @param string $domain
     * @param string $itemName
     * @param array|null $attributes @see batchDeleteAttributes
     * @return boolean 
     */
    public function queueDeleteAttributes($domain, $itemName, $attributes = null) {
        $this->_checkDomainName($domain, 'queueDeleteAttributes');
        $this->_itemsToDelete[$domain][$itemName] = $attributes;
        if (count($this->_itemsToDelete[$domain]) == self::MAX_ITEM_BATCH_SIZE) {
            return $this->flushDeleteAttributesQueue($domain);
        }
        return true;
    }

    /**
     * This sends all delete commands in the queue and clears it
     * 
     * @param string $domain
     * @return boolean 
     */
    public function flushDeleteAttributesQueue($domain) {
        $this->_checkDomainName($domain, 'flushDeleteAttributesQueue');
        $result = true;
        if (isset($this->_itemsToDelete[$domain]) && count($this->_itemsToDelete[$domain])) {
            $result = $this->batchDeleteAttributes($domain, $this->_itemsToDelete[$domain]);
            $this->_itemsToDelete[$domain] = array();
        }
        return $result;
    }

    /**
     * Queue a putAttributes operation to minimize the number of connections to AWS SimpleDB
     *
     * @param string $domain
     * @param string $itemName
     * @param array $attributes
     * @param boolean $replace
     * @return boolean 
     */
    public function queuePutAttributes($domain, $itemName, $attributes, $replace = false) {
        $this->_checkDomainName($domain, 'queuePutAttributes');
        $this->_itemsToWrite[$domain][$itemName] = $replace ? $this->_addMissingReplaceFlags($attributes, true) : $attributes;
        if (count($this->_itemsToWrite[$domain]) == self::MAX_ITEM_BATCH_SIZE) {
            return $this->flushPutAttributesQueue($domain);
        }
        return true;
    }

    /**
     * This sends all putAttributes commands in the queue and clears it
     * 
     * @param string $domain
     * @return boolean 
     */
    public function flushPutAttributesQueue($domain) {
        $this->_checkDomainName($domain, 'flushPutAttributesQueue');
        $result = true;
        if (isset($this->_itemsToWrite[$domain]) && count($this->_itemsToWrite[$domain])) {
            $result = $this->batchPutAttributes($domain, $this->_itemsToWrite[$domain]);
            $this->_itemsToWrite[$domain] = array();
        }
        return $result;
    }

    /**
     * Send all items in all putAttribute queues and clear them
     *
     * @return boolean
     */
    public function flushPutAttributesQueues() {
        $result = true;
        foreach ($this->_itemsToWrite as $domain => $items) {
            if (count($items)) {
                $result &= $this->flushPutAttributesQueue($domain);
            }
        }
        return $result;
    }

    /**
     * Delete all items in all delete queues and clear them
     *
     * @return boolean
     */
    public function flushDeleteAttributesQueues() {
        $result = true;
        foreach ($this->_itemsToDelete as $domain => $items) {
            if (count($items)) {
                $result &= $this->flushDeleteAttributesQueue($domain);
            }
        }
        return $result;
    }

    /**
     * Send all items in all queues and clear them
     *
     * @return boolean
     */
    public function flushQueues() {
        $result = true;
        $result = $result && $this->flushPutAttributesQueues();
        $result = $result && $this->flushDeleteAttributesQueues();
        return $result;
    }

    /**
     * Check if the domain name is valid
     * 
     * @param type $domain
     * @return type 
     */
    public function validDomainName($domain) {
        return preg_match('/^[-a-zA-Z0-9_.]{3,255}$/', $domain);
    }

    /**
     * gets the status of the last operation
     * 
     * @return false|SimpleDBError
     */
    public function getLastError() {
        return $this->lastError;
    }

    protected function _queueItemsForDelete($items, $domain) {
        $result = true;
        foreach ($items as $itemName => $empty) {
            $result = $result && $this->queueDeleteAttributes($domain, $itemName);
        }
        return $result;
    }

    /**
     * Trigger an error message
     *
     * @internal Used by member functions to output errors
     * @param SimpleDBError $error containing error information
     * @return string
     */
    protected function _triggerError(SimpleDBError $errors) {
        $this->lastError = $errors;
        $severity = E_USER_WARNING;
        switch ($this->_errorHandling) {
            case self::ERROR_HANDLING_THROW_EXCEPTION:
                throw new SimpleDBException($errors);
            case self::ERROR_HANDLING_TRIGGER_ERROR:
                $severity = E_USER_ERROR;
            case self::ERROR_HANDLING_TRIGGER_WARNING:
                foreach ($errors as $error) {
                    $this->ErrorCode = $error['code'];
                    trigger_error(sprintf('SimpleDB::%s(): %s %s', $error['method'], $error['code'], $error['message']),
                            $severity);
                }
        }
    }

    protected function _doSelect($select, $nexttoken, $ConsistentRead, $returnTotalResult, $processor) {
        $this->_clearReturns();
        if ($nexttoken !== null) {
            $this->NextToken = $nexttoken;
        }
        $limit = false;
        if (preg_match('/limit (\\d+)/i', $select, $matches) && $returnTotalResult) {
            $limit = $matches[1];
            $select = preg_replace('/ limit \\d+/i', '', $select);
        }
        if (is_callable($processor)) {
            $result = true;
        } else {
            $result = array();
        }
        preg_match('/from `?(\\w+)`?/i', $select, $matches);
        $domain = $matches[1];
        do {
            $limitQuery = '';
            if ($limit) {
                if ($limit > self::MAX_SELECT_LIMIT && $returnTotalResult) {
                    $limitQuery = ' limit ' . self::MAX_SELECT_LIMIT ;
                } else {
                    $limitQuery = ' limit ' . $limit;
                }
            } elseif ($returnTotalResult) {
                $limitQuery = ' limit ' . self::MAX_SELECT_LIMIT; // if we want the whole result set, it doesn't make sense to limit the result to the default 100 items. MAX_SELECT_LIMIT is max for limit.
            }
            $values = $this->_doOneSelect($select . $limitQuery, $this->NextToken, $ConsistentRead);
            if ($values === false) {
                return false;
            }
            if ($limit) {
                $limit -= count($values);
            }
            if (is_callable($processor)) {
                $result = $result && call_user_func($processor, $values, $domain);
            } else {
                $result = array_merge($result, $values);
            }
        } while ($this->NextToken !== null && $returnTotalResult && ($limit === false || $limit > 0));
        return $result;
    }

    /**
     * Checks the response for errors and sets RequestId and BoxUsage
     * 
     * @param STDClass $response returned from SimpleDBRequest::getResponse()
     * @return boolean true if everything is fine 
     */
    protected function _checkResponse($response) {
        if ($response instanceof SimpleDBError) {
            $onlyExpectedErrors = true;
            foreach ($response as $error) {
                if (isset($error['additionalInfo']['BoxUsage'])) {
                    $this->BoxUsage = (string) ($error['additionalInfo']['BoxUsage']);
                    $this->_totalBoxUsage += $this->BoxUsage;
                }
                if (!in_array($error['code'], $this->_expectedErrors)) {
                    $onlyExpectedErrors = false;
                }
            }
            $this->lastError = $response;
            if ($onlyExpectedErrors) {
                return true;
            }
            $this->_triggerError($response);
            return false;
        } else {
            if (isset($response->body->ResponseMetadata->RequestId)) {
                $this->RequestId = (string) ($response->body->ResponseMetadata->RequestId);
            }
            if (isset($response->body->ResponseMetadata->BoxUsage)) {
                $this->BoxUsage = (string) ($response->body->ResponseMetadata->BoxUsage);
                $this->_totalBoxUsage += $this->BoxUsage;
            }
        }
        return true;
    }

    private function _setParameterForAttributes(SimpleDBRequest $rest, $attributes, $prefix = '') {
        if (!isset($attributes) || !is_array($attributes)) {
            return;
        }
        $i = 0;
        foreach ($attributes as $name => $v) {
            if (is_int($name)) {  // for deleteAttributes an array of names is allowed
                $rest->setParameter($prefix . 'Attribute.' . $i . '.Name', $v);
                $i++;
                continue;
            }
            $value = is_array($v) && isset($v['value']) ? $v['value'] : $v;
            if (is_array($value)) {
                foreach ($value as $val) {
                    $rest->setParameter($prefix . 'Attribute.' . $i . '.Name', $name);
                    $rest->setParameter($prefix . 'Attribute.' . $i . '.Value', $val, false);

                    if (isset($v['replace']) && $v['replace']) {
                        $rest->setParameter($prefix . 'Attribute.' . $i . '.Replace', 'true');
                    }
                    $i++;
                }
            } else {
                $rest->setParameter($prefix . 'Attribute.' . $i . '.Name', $name);
                $rest->setParameter($prefix . 'Attribute.' . $i . '.Value', $value);
                if (is_array($v) && isset($v['replace']) && $v['replace']) {
                    $rest->setParameter($prefix . 'Attribute.' . $i . '.Replace', 'true');
                }
                $i++;
            }
        }
    }

    private function _setParameterForExpected(SimpleDBRequest $rest, $expected) {
        if (!isset($expected) || !is_array($expected)) {
            return;
        }
        $i = 0;
        foreach ($expected as $name => $v) {
            if (is_array($v)) {
                if (isset($v['value']) && is_array($v['value'])) {  // expected value
                    foreach ($v['value'] as $val) {
                        $rest->setParameter('Expected.' . $i . '.Name', $name);
                        $rest->setParameter('Expected.' . $i . '.Value', $val);
                        $i++;
                    }
                } else if (isset($v['value'])) {
                    $rest->setParameter('Expected.' . $i . '.Name', $name);
                    $rest->setParameter('Expected.' . $i . '.Value', $v['value']);
                    $i++;
                }

                if (isset($v['exists']) && is_array($v['exists'])) {
                    foreach ($v['exists'] as $val) { // expected does not exist
                        $rest->setParameter('Expected.' . $i . '.Name', $name);
                        $rest->setParameter('Expected.' . $i . '.Exists', $val ? 'true' : 'false');
                        $i++;
                    }
                } else if (isset($v['exists'])) {
                    $rest->setParameter('Expected.' . $i . '.Name', $name);
                    $rest->setParameter('Expected.' . $i . '.Exists', $v['exists'] ? 'true' : 'false');
                    $i++;
                }
            } else {
                $rest->setParameter('Expected.' . $i . '.Name', $name);
                $rest->setParameter('Expected.' . $i . '.Value', $v);
                $i++;
            }
        }
    }

    protected function _doOneSelect($select, $nexttoken = null, $ConsistentRead = false) {
        $this->_clearReturns();
        $rest = $this->_getSimpleDBRequest('', 'Select', 'GET');
        if ($select != '') {
            $rest->setParameter('SelectExpression', $select);
        }
        if ($nexttoken !== null) {
            $rest->setParameter('NextToken', $nexttoken);
        }
        if ($ConsistentRead == true) {
            $rest->setParameter('ConsistentRead', "true");
        }
        $rest = $rest->getResponse();
        if (!$this->_checkResponse($rest)) {
            return false;
        }
        $results = array();
        if (!isset($rest->body->SelectResult)) {
            return $results;
        }
        if (isset($rest->body->SelectResult->NextToken)) {
            $this->NextToken = (string) $rest->body->SelectResult->NextToken;
        }
        foreach ($rest->body->SelectResult->Item as $i) {
            $attributes = array();
            if (isset($i->Attribute)) {
                foreach ($i->Attribute as $a) {
                    if (isset($attributes[(string) ($a->Name)])) {
                        $temp = (array) ($attributes[(string) ($a->Name)]);
                        $temp[] = (string) ($a->Value);
                        $attributes[(string) ($a->Name)] = $temp;
                    } else {
                        $attributes[(string) ($a->Name)] = (string) ($a->Value);
                    }
                }
            }
            $results[(string) ($i->Name)] = $attributes;
        }
        return $results;
    }

    /**
     * Fills in the 'replace' flag for each value in an attributes array.
     */
    private function _addMissingReplaceFlags($attributes, $defaultReplace = true) {
        if (!$defaultReplace) {
            return $attributes;
        }
        $fixed = array();
        foreach ($attributes as $name => $v) {
            $replace = (isset($v['replace']) ? $v['replace'] : $defaultReplace);
            // this should work wether or not $v['value'] is an array
            $newval = array('value' => isset($v['value']) ? $v['value'] : $v, 'replace' => $replace);
            $fixed[$name] = $newval;
        }
        return $fixed;
    }

    protected function _isMoreThanMaxBatchItems($items, $caller) {
        if (count($items) > SimpleDB::MAX_ITEM_BATCH_SIZE) {
            $error = new SimpleDBError();
            $error->addError($caller, 'NumberSubmittedItemsExceeded',
                    'Too many items in a single call. Up to 25 items per call allowed.');
            $this->_triggerError($error);
            return true;
        }
        return false;
    }

    /**
     * Get a new SimpleDBRequest object. To have this in a single place helps unit testing with a mock object
     *
     * @param string $domain
     * @param string $action
     * @param string $verb
     * @return SimpleDBRequest 
     */
    protected function _getSimpleDBRequest($domain, $action, $verb) {
        $this->_actionName = $action;
        return new SimpleDBRequest($domain, $action, $verb, $this);
    }

    protected function _checkDomainName($domain, $caller) {
        if (!$this->validDomainName($domain)) {
            $this->_triggerError(new SimpleDBError(array(array('method' => $caller, 'code' => 'InvalidDomainName', 'message' => 'The domain name "' . $domain . '" is invalid.'))));
        }
    }

}

