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

namespace g_g\php_sdb2;

class SimpleDBRequest {

    private $sdb, $verb, $resource = '';
    protected $parameters = array();
    public $response;

    /**
     * Constructor
     *
     * @param string $domain Domain name
     * @param string $action SimpleDB action
     * @param string $verb HTTP verb
     * @param SimpleDB $sdb the calling object
     * @return mixed
     */
    function __construct($domain, $action, $verb, SimpleDB $sdb) {
        if ($domain != '') {
            $this->parameters['DomainName'] = $domain;
        }

        $this->parameters['Action'] = $action;
        $this->parameters['Version'] = '2009-04-15';
        $this->parameters['SignatureVersion'] = '2';
        $this->parameters['SignatureMethod'] = 'HmacSHA256';
        $this->parameters['AWSAccessKeyId'] = $sdb->getAccessKey();

        $this->verb = $verb;
        $this->sdb = $sdb;
        $this->response = new \STDClass;
        $this->response->error = false;
    }

    /**
     * Set request parameter
     *
     * @param string  $key Key
     * @param string  $value Value
     * @param boolean $replace Whether to replace the key if it already exists (default true)
     * @return void
     */
    public function setParameter($key, $value, $replace = true) {
        if (!$replace && isset($this->parameters[$key])) {
            $temp = (array) ($this->parameters[$key]);
            $temp[] = $value;
            $this->parameters[$key] = $temp;
        } else {
            $this->parameters[$key] = $value;
        }
    }

    /**
     * Get the response
     *
     * @return object | false
     */
    public function getResponse() {

        $this->parameters['Timestamp'] = gmdate('c');

        $params = array();
        foreach ($this->parameters as $var => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $params[] = $var . '=' . $this->_customUrlEncode($v);
                }
            } else {
                $params[] = $var . '=' . $this->_customUrlEncode($value);
            }
        }

        sort($params, SORT_STRING);

        $query = implode('&', $params);

        $strtosign = $this->verb . "\n" . $this->sdb->getHost() . "\n/\n" . $query;
        $query .= '&Signature=' . $this->_customUrlEncode($this->_getSignature($strtosign));

        $ssl = ($this->sdb->useSSL() && extension_loaded('openssl'));
        $url = ($ssl ? 'https://' : 'http://') . $this->sdb->getHost() . '/?' . $query;

        // Basic setup
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_USERAGENT, 'SimpleDB/php');

        if ($ssl) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, ($this->sdb->verifyHost() ? 2 : 0));
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, ($this->sdb->verifyPeer() ? 1 : 0));
        }
//        echo "$url\n";
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, array(&$this, '_responseWriteCallback'));
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

        // Request types
        switch ($this->verb) {
            case 'GET': break;
            case 'PUT': case 'POST':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->verb);
                break;
            case 'HEAD':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
                curl_setopt($curl, CURLOPT_NOBODY, true);
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            default: break;
        }

        $retry = 0;
        $attempts = 0;
        $errors = new SimpleDBError();
        do {
            $retry = 0;
            // Execute, grab errors
            if (curl_exec($curl)) {
                $this->response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($this->response->code == 503) {
                    $attempts++;
                    $retry = $this->sdb->executeServiceTemporarilyUnavailableRetryDelay($attempts);
                    if ($retry > 0) {
                        usleep($retry);
                        $this->response->body = '';
                    }
                }
            } else {
                $errors->addError($this->parameters['Action'], curl_errno($curl), curl_error($curl),$this->resource);
                $this->response->rawXML = "";
            }
        } while ($retry > 0);

        @curl_close($curl);

        // Parse body into XML
        if (count($errors) == 0 && isset($this->response->body)) {
            $this->response->rawXML = (string) $this->response->body;
            $this->response->body = simplexml_load_string($this->response->body);

            // Grab SimpleDB errors
            if (!in_array($this->response->code, array(200, 204))
                    && isset($this->response->body->Errors)) {
                foreach ($this->response->body->Errors->Error as $e) {
                    $errors->addError($this->parameters['Action'], (string) ($e->Code),(string) ($e->Message), array('BoxUsage' => (string) ($e->BoxUsage)));
                }
                unset($this->response->body);
            }
        }
        if (count($errors) == 0 && $this->response->code !== 200) {
            $errors->addError($this->parameters['Action'], $response->code, 'Unexpected HTTP status');
        }
        if (count($errors)) {
            $errors->setResponse($this->response);
            return $errors;
        }
        return $this->response;
    }

    /**
     * CURL write callback
     *
     * @param resource &$curl CURL resource
     * @param string &$data Data
     * @return integer
     */
    private function _responseWriteCallback(&$curl, &$data) {
        if (!isset($this->response->body)) {
            $this->response->body = '';
        }
        $this->response->body .= $data;
        return strlen($data);
    }

    /**
     * Contributed by afx114
     * URL encode the parameters as per http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/index.html?Query_QueryAuth.html
     * PHP's rawurlencode() follows RFC 1738, not RFC 3986 as required by Amazon. The only difference is the tilde (~), so convert it back after rawurlencode
     * See: http://www.morganney.com/blog/API/AWS-Product-Advertising-API-Requires-a-Signed-Request.php
     *
     * @param string $var String to encode
     * @return string
     */
    private function _customUrlEncode($var) {
        return str_replace('%7E', '~', rawurlencode($var));
    }

    /**
     * Generate the auth string using Hmac-SHA256
     *
     * @internal Used by SimpleDBRequest::getResponse()
     * @param string $string String to sign
     * @return string
     */
    private function _getSignature($string) {
        return base64_encode(hash_hmac('sha256', $string, $this->sdb->getSecretKey(), true));
    }

}
