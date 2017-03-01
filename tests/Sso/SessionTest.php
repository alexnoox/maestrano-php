<?php

class Maestrano_Sso_SessionTest extends PHPUnit_Framework_TestCase
{
    private $marketplace;
    private $httpSession;
    private $subject;
    private $httpClient;

    /**
     * Initializes the Test Suite
     */
    public function setUp()
    {
        $config = MaestranoTestHelper::getConfig();
        $this->marketplace = 'some-marketplace';
        Maestrano::with($this->marketplace)->configure($config['marketplaces'][0]);

        $this->mnoSession = array(
            "uid" => "usr-1",
            "group_uid" => "cld-1",
            "session" => "sessiontoken",
            "session_recheck" => "2017-02-28T08:10:20Z"
        );

        $this->httpSession = array();
        SessionTestHelper::setMnoEntry($this->httpSession, $this->mnoSession);

        $this->httpClient = new MnoHttpClientStub();
    }

    public function testContructsAnInstanceFromHttpSession()
    {
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        $this->assertEquals($this->httpSession, $this->subject->getHttpSession());
        $this->assertEquals($this->mnoSession["uid"], $this->subject->getUid());
        $this->assertEquals($this->mnoSession["group_uid"], $this->subject->getGroupUid());
        $this->assertEquals($this->mnoSession["session"], $this->subject->getSessionToken());
        $this->assertEquals(new DateTime($this->mnoSession["session_recheck"]), $this->subject->getRecheck());
    }

    public function testContructsAnInstanceFromHttpSessionAndSsoUser()
    {
        $samlResp = new SamlMnoRespStub();
        $user = new Maestrano_Sso_User($samlResp);
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession, $user);

        $this->assertEquals($this->httpSession, $this->subject->getHttpSession());
        $this->assertEquals($user->getUid(), $this->subject->getUid());
        $this->assertEquals($user->getGroupUid(), $this->subject->getGroupUid());
        $this->assertEquals($user->getSsoSession(), $this->subject->getSessionToken());
        $this->assertEquals($user->getSsoSessionRecheck(), $this->subject->getRecheck());
    }

    public function testIsRemoteCheckRequiredReturnsTrueIfRecheckIsBeforeNow()
    {
        $date = new DateTime();
        $date->sub(new DateInterval('PT1M'));
        $this->mnoSession["session_recheck"] = $date->format(DateTime::ISO8601);
        SessionTestHelper::setMnoEntry($this->httpSession, $this->mnoSession, $this->marketplace);

        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        // test
        $this->assertTrue($this->subject->isRemoteCheckRequired());
    }

    public function testRemoteCheckRequiredReturnsFalseIfRecheckIsAfterNow()
    {
        $date = new DateTime();
        $date->add(new DateInterval('PT1M'));
        $this->mnoSession["session_recheck"] = $date->format(DateTime::ISO8601);
        SessionTestHelper::setMnoEntry($this->httpSession, $this->mnoSession, $this->marketplace);

        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        // test
        $this->assertFalse($this->subject->isRemoteCheckRequired());
    }

    public function testPerformRemoteCheckWhenValidReturnsTrueAndAssignRecheckIfValid()
    {
        // Response preparation
        $date = new DateTime();
        $date->add(new DateInterval('PT1M'));

        $resp = array();
        $resp["valid"] = "true";
        $resp["recheck"] = $date->format(DateTime::ISO8601);

        $this->httpClient->setResponseStub($resp);
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        // Tests
        $this->assertTrue($this->subject->performRemoteCheck($this->httpClient));
        $this->assertEquals($date->format(DateTime::ISO8601), $this->subject->getRecheck()->format(DateTime::ISO8601));
    }

    public function testPerformRemoteCheckWhenInvalidReturnsFalseAndLeaveRecheckUnchanged()
    {
        // Response preparation
        $date = new DateTime();
        $date->add(new DateInterval('PT1M'));
        $resp = array();
        $resp["valid"] = "false";
        $resp["recheck"] = $date->format(DateTime::ISO8601);

        $this->httpClient->setResponseStub($resp);
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $recheck = $this->subject->getRecheck();

        $this->assertFalse($this->subject->performRemoteCheck($this->httpClient));
        $this->assertEquals($recheck, $this->subject->getRecheck());
    }

    public function testSaveSavesTheMaestranoSessionInHttpSession()
    {

        $oldSubject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $oldSubject->setUid($oldSubject->getUid() + "aaa");
        $oldSubject->setGroupUid($oldSubject->getGroupUid() + "aaa");
        $oldSubject->setSessionToken($oldSubject->getSessionToken() + "aaa");
        $date = new DateTime();
        $date->add(new DateInterval('PT100M'));
        $oldSubject->setRecheck($date);
        $oldSubject->save();

        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        $this->assertEquals($oldSubject->getUid(), $this->subject->getUid());
        $this->assertEquals($oldSubject->getGroupUid(), $this->subject->getGroupUid());
        $this->assertEquals($oldSubject->getSessionToken(), $this->subject->getSessionToken());
        $this->assertEquals($oldSubject->getRecheck(), $this->subject->getRecheck());
    }

    public function testIsValidWhenIfSessionSpecifiedAndNoMaestranoSsoSessionReturnsTrue()
    {
        // Http context
        $this->httpSession["some-marketplace"] = null;

        // test
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $this->assertTrue($this->subject->isValid(true));
    }

    public function testIsValidWhenNoRecheckRequiredReturnsTrue()
    {
        // Make sure any remote response is negative
        $date = new DateTime();
        $date->add(new DateInterval('PT100M'));
        $resp = array();
        $resp["valid"] = "false";
        $resp["recheck"] = $date->format(DateTime::ISO8601);
        $this->httpClient->setResponseStub($resp);

        // Set local recheck in the future
        $localRecheck = new DateTime();
        $localRecheck->add(new DateInterval('PT1M'));

        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $this->subject->setRecheck($localRecheck);

        // test
        $this->assertTrue($this->subject->isValid(false, $this->httpClient));
    }


    public function testIsValidWhenRecheckRequiredAndValidReturnsTrueAndSaveTheSession()
    {
        // Make sure any remote response is negative
        $date = new DateTime();
        $date->add(new DateInterval('PT100M'));
        $resp = array();
        $resp["valid"] = "true";
        $resp["recheck"] = $date->format(DateTime::ISO8601);
        $this->httpClient->setResponseStub($resp);

        // Set local recheck in the past
        $localRecheck = new DateTime();
        $localRecheck->sub(new DateInterval('PT1M'));
        $oldSubject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $oldSubject->setRecheck($localRecheck);

        // test 1 - validity
        $this->assertTrue($oldSubject->isValid(false, $this->httpClient));

        // Create a new subject to test session persistence
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        // test 2 - session persistence
        $this->assertEquals($date->format(DateTime::ISO8601), $this->subject->getRecheck()->format(DateTime::ISO8601));
    }


    public function isValid_WhenRecheckRequiredAndInvalid_ItShouldReturnFalse()
    {
        // Make sure any remote response is negative
        $date = new DateTime();
        $date->add(new DateInterval('PT100M'));
        $resp = array();
        $resp["valid"] = "false";
        $resp["recheck"] = $date->format(DateTime::ISO8601);
        $this->httpClient->setResponseStub($resp);

        // Set local recheck in the past
        $localRecheck = new DateTime();
        $localRecheck->sub(new DateInterval('PT1M'));

        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);
        $this->subject->setRecheck($localRecheck);

        // test 1 - validity
        $this->assertFalse($this->subject->isValid(false, $this->httpClient));
    }

    public function ssoTokenExists_IfSsoTokenExists_ItShouldReturnTrue()
    {
        $this->subject = Maestrano_Sso_Session::create($this->marketplace, $this->httpSession);

        // test 1 - validity
        $this->assertTrue($this->subject->ssoTokenExists());
    }

    public function ssoTokenExists_IfNoSsoTokenExists_ItShouldReturnFalse()
    {
        $emptySession = array();
        $this->subject = new Maestrano_Sso_Session($emptySession);

        // test 1 - validity
        $this->assertFalse($this->subject->ssoTokenExists());
    }

    public function isValid_WhenNoSsoTokenIsPresent_ItShouldReturnFalse()
    {
        $emptySession = array();
        $this->subject = new Maestrano_Sso_Session($emptySession);

        // test 1 - validity
        $this->assertFalse($this->subject->isValid());
    }
}
