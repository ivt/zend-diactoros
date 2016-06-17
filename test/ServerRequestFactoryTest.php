<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @see       http://github.com/zendframework/zend-diactoros for the canonical source repository
 * @copyright Copyright (c) 2015-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-diactoros/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Diactoros;

use PHPUnit_Framework_TestCase as TestCase;
use ReflectionMethod;
use ReflectionProperty;
use UnexpectedValueException;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;
use Zend\Diactoros\UploadedFile;
use Zend\Diactoros\Uri;

class ServerRequestFactoryTest extends TestCase
{
    public function testGetWillReturnValueIfPresentInArray()
    {
        $array = array(
            'foo' => 'bar',
            'bar' => '',
            'baz' => null,
        );

        foreach ($array as $key => $value) {
            $this->assertSame($value, ServerRequestFactory::get($key, $array));
        }
    }

    public function testGetWillReturnDefaultValueIfKeyIsNotInArray()
    {
        $try   = array( 'foo', 'bar', 'baz' );
        $array = array(
            'quz'  => true,
            'quuz' => true,
        );
        $default = 'BAT';

        foreach ($try as $key) {
            $this->assertSame($default, ServerRequestFactory::get($key, $array, $default));
        }
    }

    public function testReturnsServerValueUnchangedIfHttpAuthorizationHeaderIsPresent()
    {
        $server = array(
            'HTTP_AUTHORIZATION' => 'token',
            'HTTP_X_Foo' => 'bar',
        );
        $this->assertSame($server, ServerRequestFactory::normalizeServer($server));
    }

    public function testMarshalsExpectedHeadersFromServerArray()
    {
        $server = array(
            'HTTP_COOKIE' => 'COOKIE',
            'HTTP_AUTHORIZATION' => 'token',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FOO_BAR' => 'FOOBAR',
            'CONTENT_MD5' => 'CONTENT-MD5',
            'CONTENT_LENGTH' => 'UNSPECIFIED',
        );

        $expected = array(
            'cookie' => 'COOKIE',
            'authorization' => 'token',
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'x-foo-bar' => 'FOOBAR',
            'content-md5' => 'CONTENT-MD5',
            'content-length' => 'UNSPECIFIED',
        );

        $this->assertEquals($expected, ServerRequestFactory::marshalHeaders($server));
    }

    public function testStripQueryStringReturnsUnchangedStringIfNoQueryStringDetected()
    {
        $path = '/foo/bar';
        $this->assertSame($path, ServerRequestFactory::stripQueryString($path));
    }

    public function testStripQueryStringReturnsNormalizedPathWhenQueryStringDetected()
    {
        $path = '/foo/bar?foo=bar';
        $this->assertSame('/foo/bar', ServerRequestFactory::stripQueryString($path));
    }

    public function testMarshalRequestUriUsesIISUnencodedUrlValueIfPresentAndUrlWasRewritten()
    {
        $server = array(
            'IIS_WasUrlRewritten' => '1',
            'UNENCODED_URL' => '/foo/bar',
        );

        $this->assertEquals($server['UNENCODED_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesHTTPXRewriteUrlIfPresent()
    {
        $server = array(
            'IIS_WasUrlRewritten' => null,
            'UNENCODED_URL' => '/foo/bar',
            'REQUEST_URI' => '/overridden',
            'HTTP_X_REWRITE_URL' => '/bar/baz',
        );

        $this->assertEquals($server['HTTP_X_REWRITE_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesHTTPXOriginalUrlIfPresent()
    {
        $server = array(
            'IIS_WasUrlRewritten' => null,
            'UNENCODED_URL' => '/foo/bar',
            'REQUEST_URI' => '/overridden',
            'HTTP_X_REWRITE_URL' => '/bar/baz',
            'HTTP_X_ORIGINAL_URL' => '/baz/bat',
        );

        $this->assertEquals($server['HTTP_X_ORIGINAL_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriStripsSchemeHostAndPortInformationWhenPresent()
    {
        $server = array(
            'REQUEST_URI' => 'http://example.com:8000/foo/bar',
        );

        $this->assertEquals('/foo/bar', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesOrigPathInfoIfPresent()
    {
        $server = array(
            'ORIG_PATH_INFO' => '/foo/bar',
        );

        $this->assertEquals('/foo/bar', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriFallsBackToRoot()
    {
        $server = array();

        $this->assertEquals('/', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalHostAndPortUsesHostHeaderWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withMethod('GET');
        $request = $request->withHeader('Host', 'example.com');

        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, array(), $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortWillDetectPortInHostHeaderWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com:8000/'));
        $request = $request->withMethod('GET');
        $request = $request->withHeader('Host', 'example.com:8000');

        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, array(), $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortReturnsEmptyValuesIfNoHostHeaderAndNoServerName()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, array(), $request->getHeaders());
        $this->assertEquals('', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerNameForHostWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));

        $server  = array(
            'SERVER_NAME' => 'example.com',
        );
        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerPortForPortWhenPresentWithServerName()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = array(
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => 8000,
        );
        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerNameForHostIfServerAddrPresentButHostIsNotIpv6Address()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));

        $server  = array(
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => 'example.com',
        );
        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
    }

    public function testMarshalHostAndPortReturnsServerAddrForHostIfPresentAndHostIsIpv6Address()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = array(
            'SERVER_ADDR' => 'FE80::0202:B3FF:FE1E:8329',
            'SERVER_NAME' => '[FE80::0202:B3FF:FE1E:8329]',
            'SERVER_PORT' => 8000,
        );
        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('[FE80::0202:B3FF:FE1E:8329]', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortWillDetectPortInIpv6StyleHost()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = array(
            'SERVER_ADDR' => 'FE80::0202:B3FF:FE1E:8329',
            'SERVER_NAME' => '[FE80::0202:B3FF:FE1E:8329:80]',
        );
        $accumulator = (object) array('host' => '', 'port' => null);
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('[FE80::0202:B3FF:FE1E:8329]', $accumulator->host);
        $this->assertEquals(80, $accumulator->port);
    }

    public function testMarshalUriDetectsHttpsSchemeFromServerValue()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server  = array(
            'HTTPS' => true,
        );

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testMarshalUriUsesHttpSchemeIfHttpsServerValueEqualsOff()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server  = array(
            'HTTPS' => 'off',
        );

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testMarshalUriDetectsHttpsSchemeFromXForwardedProtoValue()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');
        $request = $request->withHeader('X-Forwarded-Proto', 'https');

        $server  = array();

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testMarshalUriStripsQueryStringFromRequestUri()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server = array(
            'REQUEST_URI' => '/foo/bar?foo=bar',
        );

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('/foo/bar', $uri->getPath());
    }

    public function testMarshalUriInjectsQueryStringFromServer()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server = array(
            'REQUEST_URI' => '/foo/bar?foo=bar',
            'QUERY_STRING' => 'bar=baz',
        );

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('bar=baz', $uri->getQuery());
    }

    public function testMarshalUriInjectsFragmentFromServer()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server = array(
            'REQUEST_URI' => '/foo/bar#foo',
        );

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Zend\Diactoros\Uri', $uri);
        $this->assertEquals('foo', $uri->getFragment());
    }

    public function testCanCreateServerRequestViaFromGlobalsMethod()
    {
        $server = array(
            'SERVER_PROTOCOL' => '1.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_ACCEPT' => 'application/json',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/foo/bar',
            'QUERY_STRING' => 'bar=baz',
        );

        $cookies = $query = $body = $files = array(
            'bar' => 'baz',
        );

        $cookies['cookies'] = true;
        $query['query']     = true;
        $body['body']       = true;
        $files              = array( 'files' => array(
            'tmp_name' => 'php://temp',
            'size'     => 0,
            'error'    => 0,
            'name'     => 'foo.bar',
            'type'     => 'text/plain',
        ));
        $expectedFiles = array(
            'files' => new UploadedFile('php://temp', 0, 0, 'foo.bar', 'text/plain')
        );

        $request = ServerRequestFactory::fromGlobals($server, $query, $body, $cookies, $files);
        $this->assertInstanceOf('Zend\Diactoros\ServerRequest', $request);
        $this->assertEquals($cookies, $request->getCookieParams());
        $this->assertEquals($query, $request->getQueryParams());
        $this->assertEquals($body, $request->getParsedBody());
        $this->assertEquals($expectedFiles, $request->getUploadedFiles());
        $this->assertEmpty($request->getAttributes());
        $this->assertEquals('1.1', $request->getProtocolVersion());
    }

    public function testNormalizeServerUsesMixedCaseAuthorizationHeaderFromApacheWhenPresent()
    {
        $r = new ReflectionProperty('Zend\Diactoros\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return array('Authorization' => 'foobar');
        });

        $server = ServerRequestFactory::normalizeServer(array());

        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $server);
        $this->assertEquals('foobar', $server['HTTP_AUTHORIZATION']);
    }

    public function testNormalizeServerUsesLowerCaseAuthorizationHeaderFromApacheWhenPresent()
    {
        $r = new ReflectionProperty('Zend\Diactoros\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return array('authorization' => 'foobar');
        });

        $server = ServerRequestFactory::normalizeServer(array());

        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $server);
        $this->assertEquals('foobar', $server['HTTP_AUTHORIZATION']);
    }

    public function testNormalizeServerReturnsArrayUnalteredIfApacheHeadersDoNotContainAuthorization()
    {
        $r = new ReflectionProperty('Zend\Diactoros\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return array();
        });

        $expected = array('FOO_BAR' => 'BAZ');
        $server = ServerRequestFactory::normalizeServer($expected);

        $this->assertEquals($expected, $server);
    }

    /**
     * @group 57
     * @group 56
     */
    public function testNormalizeFilesReturnsOnlyActualFilesWhenOriginalFilesContainsNestedAssociativeArrays()
    {
        $files = array( 'fooFiles' => array(
            'tmp_name' => array('file' => 'php://temp'),
            'size'     => array('file' => 0),
            'error'    => array('file' => 0),
            'name'     => array('file' => 'foo.bar'),
            'type'     => array('file' => 'text/plain'),
        ));

        $normalizedFiles = ServerRequestFactory::normalizeFiles($files);

        $this->assertCount(1, $normalizedFiles['fooFiles']);
    }

    public function testMarshalProtocolVersionRisesExceptionIfVersionIsNotRecognized()
    {
        $method = new ReflectionMethod('Zend\Diactoros\ServerRequestFactory', 'marshalProtocolVersion');
        $method->setAccessible(true);
        $this->setExpectedException('UnexpectedValueException');
        $method->invoke(null, array('SERVER_PROTOCOL' => 'dadsa/1.0'));
    }

    public function testMarshalProtocolReturnsDefaultValueIfHeaderIsNotPresent()
    {
        $method = new ReflectionMethod('Zend\Diactoros\ServerRequestFactory', 'marshalProtocolVersion');
        $method->setAccessible(true);
        $version = $method->invoke(null, array());
        $this->assertEquals('1.1', $version);
    }

    /**
     * @dataProvider marshalProtocolVersionProvider
     */
    public function testMarshalProtocolVersionReturnsHttpVersions($protocol, $expected)
    {
        $method = new ReflectionMethod('Zend\Diactoros\ServerRequestFactory', 'marshalProtocolVersion');
        $method->setAccessible(true);
        $version = $method->invoke(null, array('SERVER_PROTOCOL' => $protocol));
        $this->assertEquals($expected, $version);
    }

    public function marshalProtocolVersionProvider()
    {
        return array(
            'HTTP/1.0' => array('HTTP/1.0', '1.0'),
            'HTTP/1.1' => array('HTTP/1.1', '1.1'),
            'HTTP/2'   => array('HTTP/2', '2'),
        );
    }
}
