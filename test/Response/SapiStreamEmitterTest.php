<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @see       http://github.com/zendframework/zend-diactoros for the canonical source repository
 * @copyright Copyright (c) 2015-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-diactoros/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Diactoros\Response;

use PHPUnit_Framework_TestCase as TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\SapiStreamEmitter;
use ZendTest\Diactoros\TestAsset\HeaderStack;

class SapiStreamEmitterTest extends SapiEmitterTest
{
    public function setUp()
    {
        HeaderStack::reset();
        $this->emitter = new SapiStreamEmitter();
    }

    public function testDoesNotInjectContentLengthHeaderIfStreamSizeIsUnknown()
    {
        $stream = $this->prophesize('Psr\Http\Message\StreamInterface');
        $stream->__toString()->willReturn('Content!');
        $stream->isSeekable()->willReturn(false);
        $stream->eof()->willReturn(true);
        $stream->rewind()->willReturn(true);
        $stream->getSize()->willReturn(null);
        $response = new Response();
        $response = $response
            ->withStatus(200)
            ->withBody($stream->reveal());

        ob_start();
        $this->emitter->emit($response);
        ob_end_clean();
        foreach (HeaderStack::stack() as $header) {
            $this->assertNotContains('Content-Length:', $header);
        }
    }

    public function contentRangeProvider()
    {
        return array(
            array('bytes 0-2/*', 'Hello world', 'Hel'),
            array('bytes 3-6/*', 'Hello world', 'lo w'),
        );
    }

    /**
     * @dataProvider contentRangeProvider
     */
    public function testContentRange($header, $body, $expected)
    {
        $response = new Response();
        $response = $response
            ->withHeader('Content-Range', $header);

        $response->getBody()->write($body);

        ob_start();
        $this->emitter->emit($response);
        $this->assertEquals($expected, ob_get_clean());
    }
}
