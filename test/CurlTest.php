<?php

use PHPUnit\Framework\TestCase;
use S25\Curl\
{Curl, Cookie, Exception};

final class CurlTest extends TestCase
{
  private function initCurl()
  {
    $cookieFile = tempnam(sys_get_temp_dir(), 's25curl_test_cookies_');

    $curl = new Curl([
      CURLOPT_COOKIEFILE => $cookieFile,
      CURLOPT_COOKIEJAR => $cookieFile,
    ]);

    return $curl;
  }

  /**
   * @throws Exception
   */
  public function testCookies()
  {
    $curl = $this->initCurl();

    $curl->addCookies([
      ['name' => 'fruit', 'value' => 'apple', 'host' => 'example.com', 'sub' => true],
      ['name' => 'colour', 'value' => 'red', 'host' => 'example.com', 'sub' => true],
      ['name' => 'material', 'value' => 'rock', 'host' => 'example.net', 'sub' => true],
      ['name' => 'taste', 'value' => 'sweat', 'host' => 'www.example.com', 'sub' => true],
      ['name' => 'weight', 'value' => 'bold', 'host' => 'example.com', 'sub' => false],
    ]);
    $curl->expireCookies([
      ['name' => 'colour', 'host' => 'example.com', 'sub' => true],
    ]);

//    $t->comment('s25Curl->get("example.com")');
    $response = $curl->get('example.com');

//    $t->info('Response status: ' . $response->getStatus());
//    $t->info('Response headers: #' . count($response->getHeaders()) . ': ' . implode(', ', $response->getHeaders()));
//    $t->info('Response body length: ' . strlen($response->getBody()) . ' bytes');
    $this->assertTrue($response->getStatus() === '200', 'Status is 200');

//    $t->comment('$curl->getCookies()');
    $cookies = $curl->getCookies();
    $cookieNames = array_map(function (Cookie $cookie) { return $cookie->name; }, $cookies);
    $this->assertTrue($curl->getLastUrl() === 'http://example.com/', 'Last url is right: http://example.com/');

    $this->assertTrue(in_array('fruit', $cookieNames), 'Fruit is available');
    $this->assertTrue(in_array('colour', $cookieNames) === false, 'Colour is deleted');

//    $t->comment('$curl->getCookies(\'example.com\')');
    $cookies = $curl->getCookies('example.com');
    $cookieNames = array_map(function (Cookie $cookie) { return $cookie->name; }, $cookies);
    $this->assertTrue(in_array('fruit', $cookieNames), 'Fruit is available');
    $this->assertTrue(in_array('taste', $cookieNames) === false, 'Taste is filtered as for subdomain');
    $this->assertTrue(in_array('material', $cookieNames) === false, 'Material is filtered by domain');
    $this->assertTrue(in_array('weight', $cookieNames), 'Weight is available as for main domain');

//      $t->comment('$curl->getCookies(\'www.example.com\')');
    $cookies = $curl->getCookies('www.example.com');
    $cookieNames = array_map(function (Cookie $cookie) { return $cookie->name; }, $cookies);
    $this->assertTrue(in_array('fruit', $cookieNames), 'Fruit is available');
    $this->assertTrue(in_array('taste', $cookieNames), 'Taste is available as for subdomain');
    $this->assertTrue(in_array('material', $cookieNames) === false, 'Material is filtered by domain');
    $this->assertTrue(in_array('weight', $cookieNames) === false, 'Weight is filtered as for main domain');
  }

}
