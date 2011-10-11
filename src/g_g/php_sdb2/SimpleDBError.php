<?php

/**
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

namespace g_g\php_sdb2;

/**
 * A container class that contains all errors that occured during a transaction.
 * It can be used as an array, and be iterated.
 */
class SimpleDBError implements \Countable, \ArrayAccess, \Iterator {

    protected $errors, $response, $current = 0;

    public function __construct($errors = array(), $response = false) {
        $this->errors = $errors;
        $this->response = $response;
    }

    public function addError($method, $code, $message, $additionalInfo = false) {
        $this->errors[] = array('method' => $method, 'code' => $code, 'message' => $message, 'additionalInfo' => $additionalInfo);
    }

    public function getErrors() {
        return $this->errors;
    }

    public function setResponse(\STDClass $response) {
        $this->response = $response;
    }

    public function getResponse() {
        return $this->response;
    }

    public function __toString() {
        $result = '';
        foreach ($this->errors as $error) {
            $result .= sprintf('SimpleDB::%s(): %s %s', $error['method'], $error['code'], $error['message']) . "\n";
        }
        return $result;
    }

    public function toHTML() {
        $result = '<ul>';
        foreach ($this->errors as $error) {
            $result .= '<li>' . sprintf('SimpleDB::%s(): %s %s', $error['method'], $error['code'], $error['message']) . '</li>';
        }
        return $result . '</ul>';
    }

    public function offsetExists($offset) {
        return isset($this->errors[$offset]);
    }

    public function offsetGet($offset) {
        return $this->errors[$offset];
    }

    public function offsetSet($offset, $value) {
        $this->errors[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->errors[$offset]);
    }

    public function count() {
        return count($this->errors);
    }

    public function current() {
        return current($this->errors);
    }

    public function key() {
        return key($this->errors);
    }

    public function next() {
        next($this->errors);
    }

    public function rewind() {
        reset($this->errors);
    }

    public function valid() {
        return key($this->errors) !== null;
    }

}

?>
