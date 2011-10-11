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

class SimpleDBException extends \Exception{
    protected $simpleDBErrors;
    
    public function __construct(SimpleDBError $error) {
        parent::__construct(sprintf('Error in SimpleDB::%s(): %s - %s', $error[0]['method'], $error[0]['code'], $error[0]['message']));
        $this->simpleDBErrors = $error;
    }
    
    public function getSimpleDBErrors() {
        return $this->simpleDBErrors;
    }
}

?>
