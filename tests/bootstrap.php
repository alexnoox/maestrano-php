<?php

if (!defined('TEST_ROOT')) define('TEST_ROOT', dirname(__FILE__));

require_once TEST_ROOT . '/../lib/Maestrano.php';
require_once TEST_ROOT . '/support/saml/SamlTestHelper.php';
require_once TEST_ROOT . '/support/stubs/SamlMnoRespStub.php';

date_default_timezone_set('UTC');

