<?php declare(strict_types=1);

namespace Reconmap\Tasks;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Reconmap\Services\ApplicationConfig;

class EmailTaskProcessorTest extends TestCase
{
    public function testSuccess()
    {
        $mockSettings = ['host' => 'amazonses', 'port' => 589, 'username' => 'foo', 'password' => 'bar', 'verifyPeer' => 'false',
            'fromEmail' => 'some@email.com',
            'fromName' => 'Some Name'];

        $mockAppConfig = $this->createMock(ApplicationConfig::class);
        $mockAppConfig->expects($this->once())
            ->method('getSettings')
            ->willReturn($mockSettings);

        $mockLogger = $this->createMock(Logger::class);

        $mockItem = new \stdClass();
        $mockItem->to = ['so@body.com' => 'So body'];
        $mockItem->subject = 'Spam!';
        $mockItem->body = 'Buy this now';

        $controller = new EmailTaskProcessor($mockAppConfig, $mockLogger);
        $controller->process($mockItem);
    }
}
