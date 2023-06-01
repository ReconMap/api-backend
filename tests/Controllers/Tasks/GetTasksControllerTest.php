<?php declare(strict_types=1);

namespace Reconmap\Controllers\Tasks;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Reconmap\ConsecutiveParamsTrait;
use Reconmap\Repositories\SearchCriterias\TaskSearchCriteria;
use Reconmap\Repositories\TaskRepository;
use Reconmap\Services\PaginationRequestHandler;

class GetTasksControllerTest extends TestCase
{
    use ConsecutiveParamsTrait;

    public function testGetRegularTasks()
    {
        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->expects($this->exactly(2))
            ->method('getAttribute')
            ->with(...$this->consecutiveParams(['userId'], ['role']))
            ->willReturnOnConsecutiveCalls(9, 'superuser');
        $mockRequest->expects($this->exactly(3))
            ->method('getQueryParams')
            ->willReturn([]);

        $mockSearchCriteria = $this->createMock(TaskSearchCriteria::class);
        $mockSearchCriteria->expects($this->once())
            ->method('addProjectIsNotTemplateCriterion');

        $paginator = new PaginationRequestHandler($mockRequest);

        $mockRepository = $this->createMock(TaskRepository::class);
        $mockRepository->expects($this->once())
            ->method('search')
            ->with($mockSearchCriteria, $paginator)
            ->willReturn([]);

        $controller = new GetTasksController($mockRepository, $mockSearchCriteria);
        $response = $controller($mockRequest);

        $this->assertEquals([], $response);
    }

    public function testGetTemplateProjectTasks()
    {
        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->expects($this->exactly(2))
            ->method('getAttribute')
            ->with(...$this->consecutiveParams(['userId'], ['role']))
            ->willReturnOnConsecutiveCalls(9, 'superuser');
        $mockRequest->expects($this->exactly(3))
            ->method('getQueryParams')
            ->willReturn(['isTemplate' => 'true']);

        $mockSearchCriteria = $this->createMock(TaskSearchCriteria::class);
        $mockSearchCriteria->expects($this->once())
            ->method('addProjectTemplateCriterion')
            ->with(true);

        $paginator = new PaginationRequestHandler($mockRequest);

        $mockRepository = $this->createMock(TaskRepository::class);
        $mockRepository->expects($this->once())
            ->method('search')
            ->with($mockSearchCriteria, $paginator)
            ->willReturn([]);


        $controller = new GetTasksController($mockRepository, $mockSearchCriteria);
        $response = $controller($mockRequest);

        $this->assertEquals([], $response);
    }
}
