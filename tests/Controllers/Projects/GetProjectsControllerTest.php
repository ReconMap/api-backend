<?php declare(strict_types=1);

namespace Reconmap\Controllers\Projects;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Reconmap\ConsecutiveParamsTrait;
use Reconmap\Repositories\ProjectRepository;
use Reconmap\Repositories\SearchCriterias\ProjectSearchCriteria;
use Symfony\Component\EventDispatcher\EventDispatcher;

class GetProjectsControllerTest extends TestCase
{
    use ConsecutiveParamsTrait;

    public function testGetRegularProjects()
    {
        $mockProjects = [['title' => 'foo']];

        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->expects($this->exactly(2))
            ->method('getQueryParams')
            ->willReturn(['status' => 'archived', 'page' => 0]);
        $mockRequest->expects($this->exactly(2))
            ->method('getAttribute')
            ->with(...$this->consecutiveParams(['userId'], ['role']))
            ->willReturnOnConsecutiveCalls(9, 'administrator');

        $searchCriteria = new ProjectSearchCriteria();
        $searchCriteria->addCriterion('p.archived = ?', [true]);
        $searchCriteria->addCriterion('p.is_template = ?', [false]);

        $mockRepository = $this->createMock(ProjectRepository::class);
        $mockRepository->expects($this->once())
            ->method('search')
            ->with($searchCriteria)
            ->willReturn($mockProjects);

        $mockEventDispatcher = $this->createMock(EventDispatcher::class);

        $controller = new GetProjectsController($mockRepository, $searchCriteria, $mockEventDispatcher);
        $response = $controller($mockRequest);

        $this->assertEquals(json_encode($mockProjects), (string)$response->getBody());
    }

    public function testGetProjectTemplates()
    {
        $mockProjects = [['title' => 'foo']];

        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->expects($this->exactly(2))
            ->method('getQueryParams')
            ->willReturn(['status' => 'archived', 'isTemplate' => true, 'page' => 0]);
        $mockRequest->expects($this->exactly(2))
            ->method('getAttribute')
            ->with(...$this->consecutiveParams(['userId'], ['role']))
            ->willReturnOnConsecutiveCalls(9, 'administrator');

        $searchCriteria = new ProjectSearchCriteria();
        $searchCriteria->addCriterion('p.archived = ?', [true]);
        $searchCriteria->addCriterion('p.is_template = ?', [true]);

        $mockRepository = $this->createMock(ProjectRepository::class);
        $mockRepository->expects($this->once())
            ->method('search')
            ->with($searchCriteria)
            ->willReturn($mockProjects);

        $mockEventDispatcher = $this->createMock(EventDispatcher::class);

        $controller = new GetProjectsController($mockRepository, $searchCriteria, $mockEventDispatcher);
        $response = $controller($mockRequest);

        $this->assertEquals(json_encode($mockProjects), (string)$response->getBody());
    }
}
