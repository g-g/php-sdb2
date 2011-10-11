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
use g_g\php_sdb2\SimpleDBError;

require_once dirname(__FILE__) . '/../../../src/g_g/php_sdb2/SimpleDBError.php';

class SimpleDBErrorTest extends PHPUnit_Framework_TestCase {

    public function testCountableInterface() {
        $instance = new SimpleDBError();
        $this->assertEquals(0, $instance->count());
        return $instance;
    }

    /**
     * @depends testCountableInterface
     */
    public function testAddError(SimpleDBError $instance) {
        $instance->addError('testMethod', '7474', 'The first error', array('data' => '....'));
        $instance->addError('testMethod2', '7475', 'The second error', array('data' => '...'));
        $this->assertEquals(2, $instance->count());
        return $instance;
    }

    /**
     * @depends testAddError
     */
    public function testToString(SimpleDBError $instance) {
        $this->assertEquals("SimpleDB::testMethod(): 7474 The first error\nSimpleDB::testMethod2(): 7475 The second error\n",
                $instance->__toString());
        return $instance;
    }

    /**
     * @depends testToString
     */
    public function testToHTML(SimpleDBError $instance) {
        $this->assertEquals('<ul><li>SimpleDB::testMethod(): 7474 The first error</li><li>SimpleDB::testMethod2(): 7475 The second error</li></ul>',
                $instance->toHTML());
        return $instance;
    }

    /**
     * @depends testToHTML
     */
    public function testIteratorInterface(SimpleDBError $instance) {
        $i = 0;
        foreach ($instance as $k => $error) {
            $this->assertEquals((string) (7474 + $k), $error['code']);
            $i++;
        }
        $this->assertEquals(2, $i);
        return $instance;
    }

    /**
     * @depends testIteratorInterface
     */
    public function testArrayAccessInterface(SimpleDBError $instance) {
        $this->assertFalse(isset($instance[7]));
        $this->assertEquals('7474', $instance[0]['code']);
        $instance[2] = array('method' => 'testMethod3', 'code' => '7476', 'message' => 'The third error');
        $this->assertEquals(3, $instance->count());
        unset($instance[1]);
        $this->assertEquals(array(0,2), array_keys($instance->getErrors()));
        return $instance;
    }
    
    /**
     * @depends testArrayAccessInterface
     */
    public function testGetResponse(SimpleDBError $instance) {
        $this->assertFalse($instance->getResponse());
    }

}

?>
