<?php
/**
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

require_once 'BaseTest.php';
require_once 'Google/Auth/AssertionCredentials.php';
require_once 'Google/Auth/Exception.php';
require_once 'Google/Auth/Simple.php';
require_once "Google/Http/Request.php";
require_once 'Google/Signer/P12.php';
require_once 'Google/Utils.php';
require_once 'Google/Verifier/Pem.php';

class AuthTest extends BaseTest {
  const PRIVATE_KEY_FILE = "testdata/cert.p12";
  const PUBLIC_KEY_FILE_JSON = "testdata/cacert.json";
  const PUBLIC_KEY_FILE = "testdata/cacert.pem";
  const USER_ID = "102102479283111695822";

  /** @var Google_Signer_P12  */
  private $signer;

  /** @var string */
  private $pem;

  /** @var Google_Verifier_Pem */
  private $verifier;

  public function setUp() {
    $this->signer = new Google_Signer_P12(file_get_contents(self::PRIVATE_KEY_FILE, true), "notasecret");
    $this->pem = file_get_contents(self::PUBLIC_KEY_FILE, true);
    $this->verifier = new Google_Verifier_Pem($this->pem);
  }

  public function testCantOpenP12() {
    try {
      new Google_Signer_P12(file_get_contents(self::PRIVATE_KEY_FILE, true), "badpassword");
      $this->fail("Should have thrown");
    } catch (Google_Auth_Exception $e) {
      $this->assertContains("mac verify failure", $e->getMessage());
    }

    try {
      new Google_Signer_P12(file_get_contents(self::PRIVATE_KEY_FILE, true) . "foo", "badpassword");
      $this->fail("Should have thrown");
    } catch (Exception $e) {
      $this->assertContains("Unable to parse", $e->getMessage());
    }
  }

  public function testVerifySignature() {
    $binary_data = "\x00\x01\x02\x66\x6f\x6f";
    $signature = $this->signer->sign($binary_data);
    $this->assertTrue($this->verifier->verify($binary_data, $signature));

    $empty_string = "";
    $signature = $this->signer->sign($empty_string);
    $this->assertTrue($this->verifier->verify($empty_string, $signature));

    $text = "foobar";
    $signature = $this->signer->sign($text);
    $this->assertTrue($this->verifier->verify($text, $signature));

    $this->assertFalse($this->verifier->verify($empty_string, $signature));
  }

  // Creates a signed JWT similar to the one created by google authentication.
  private function makeSignedJwt($payload) {
    $header = array("typ" => "JWT", "alg" => "RS256");
    $segments = array();
    $segments[] = Google_Utils::urlSafeB64Encode(json_encode($header));
    $segments[] = Google_Utils::urlSafeB64Encode(json_encode($payload));
    $signing_input = implode(".", $segments);

    $signature = $this->signer->sign($signing_input);
    $segments[] = Google_Utils::urlSafeB64Encode($signature);

    return implode(".", $segments);
  }

  // Returns certificates similar to the ones used by google authentication.
  private function getSignonCerts() {
    return array("keyid" => $this->pem);
  }

  public function testVerifySignedJwtWithCerts() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "sub" => self::USER_ID,
        "iat" => time(),
        "exp" => time() + 3600));
    $certs = $this->getSignonCerts();
    $oauth2 = new Google_Auth_OAuth2($this->getClient());
    $ticket = $oauth2->verifySignedJwtWithCerts($id_token, $certs, "client_id");
    $this->assertEquals(self::USER_ID, $ticket->getUserId());
    // Check that payload and envelope got filled in.
    $attributes = $ticket->getAttributes();
    $this->assertEquals("JWT", $attributes["envelope"]["typ"]);
    $this->assertEquals("client_id", $attributes["payload"]["aud"]);
  }

  // Checks that the id token fails to verify with the expected message.
  private function checkIdTokenFailure($id_token, $msg) {
    $certs = $this->getSignonCerts();
    $oauth2 = new Google_Auth_OAuth2($this->getClient());
    try {
      $oauth2->verifySignedJwtWithCerts($id_token, $certs, "client_id");
      $this->fail("Should have thrown for $id_token");
    } catch (Google_Auth_Exception $e) {
      $this->assertContains($msg, $e->getMessage());
    }
  }

  public function testVerifySignedJwt_badJwt() {
    $this->checkIdTokenFailure("foo", "Wrong number of segments");
    $this->checkIdTokenFailure("foo.bar", "Wrong number of segments");
    $this->checkIdTokenFailure("foo.bar.baz",
        "Can't parse token envelope: foo");
  }

  public function testVerifySignedJwt_badSignature() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "iat" => time(),
        "exp" => time() + 3600));
    $id_token = $id_token . "a";
    $this->checkIdTokenFailure($id_token, "Invalid token signature");
  }

  public function testVerifySignedJwt_noIssueTime() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "exp" => time() + 3600));
    $this->checkIdTokenFailure($id_token, "No issue time");
  }

  public function testVerifySignedJwt_noExpirationTime() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "iat" => time()));
    $this->checkIdTokenFailure($id_token, "No expiration time");
  }

  public function testVerifySignedJwt_tooEarly() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "iat" => time() + 1800,
        "exp" => time() + 3600));
    $this->checkIdTokenFailure($id_token, "Token used too early");
  }

  public function testVerifySignedJwt_tooLate() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "iat" => time() - 3600,
        "exp" => time() - 1800));
    $this->checkIdTokenFailure($id_token, "Token used too late");
  }

  public function testVerifySignedJwt_lifetimeTooLong() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "client_id",
        "id" => self::USER_ID,
        "iat" => time(),
        "exp" => time() + 3600 * 25));
    $this->checkIdTokenFailure($id_token, "Expiration time too far in future");
  }

  public function testVerifySignedJwt_badAudience() {
    $id_token = $this->makeSignedJwt(array(
        "iss" => "federated-signon@system.gserviceaccount.com",
        "aud" => "wrong_client_id",
        "id" => self::USER_ID,
        "iat" => time(),
        "exp" => time() + 3600));
    $this->checkIdTokenFailure($id_token, "Wrong recipient");
  }

  public function testNoAuth() {
    /** @var $noAuth Google_Auth_Simple */
    $noAuth = new Google_Auth_Simple($this->getClient());
    $oldAuth = $this->getClient()->getAuth();
    $this->getClient()->setAuth($noAuth);
    $this->getClient()->setDeveloperKey(null);
    $req = new Google_Http_Request("http://example.com");

    $resp = $noAuth->sign($req);
    try {
      $noAuth->authenticate(null);
      $this->assertTrue(false, "Exception expected");
    } catch (Google_Auth_Exception $e) {

    }
    $noAuth->createAuthUrl(null);
    $noAuth->setAccessToken(null);
    $noAuth->getAccessToken();
    $noAuth->refreshToken(null);
    $noAuth->revokeToken();
    $this->assertEquals("http://example.com", $resp->getUrl());
    $this->getClient()->setAuth($oldAuth);
  }

  public function testAssertionCredentials() {
    $assertion = new Google_Auth_AssertionCredentials('name', 'scope',
        file_get_contents(self::PRIVATE_KEY_FILE, true));

    $token = explode(".", $assertion->generateAssertion());
    $this->assertEquals('{"typ":"JWT","alg":"RS256"}', base64_decode($token[0]));

    $jwt = json_decode(base64_decode($token[1]), true);
    $this->assertEquals('https://accounts.google.com/o/oauth2/token', $jwt['aud']);
    $this->assertEquals('scope', $jwt['scope']);
    $this->assertEquals('name', $jwt['iss']);

    $key = $assertion->getCacheKey();
    $this->assertTrue($key != false);
    $assertion = new Google_Auth_AssertionCredentials('name2', 'scope',
        file_get_contents(self::PRIVATE_KEY_FILE, true));
    $this->assertNotEquals($key, $assertion->getCacheKey());
  }

  public function testVerifySignedJWT() {
    $assertion = new Google_Auth_AssertionCredentials('issuer', 'scope',
        file_get_contents(self::PRIVATE_KEY_FILE, true));
    $client = $this->getClient();

    $this->assertInstanceOf('Google_Auth_LoginTicket', $client->verifySignedJwt(
      $assertion->generateAssertion(),
        __DIR__ . DIRECTORY_SEPARATOR .  self::PUBLIC_KEY_FILE_JSON,
        'https://accounts.google.com/o/oauth2/token',
        'issuer'
      ));
  }
}
