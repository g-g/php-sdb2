Tests
=====

Before running the tests copy phpunit.xml.dist to phpunit.xml and adjust the parameters to your needs.

The test has two modes, you can run it as a standard unit test, or you can test it against the real 
AWS SimpleDB to find out, if everything works well in combination with the real thing ;-)
Just set LIVE_TEST to yes in phpunit.xml. 