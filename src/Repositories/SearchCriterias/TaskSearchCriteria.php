<?php declare(strict_types=1);

namespace Reconmap\Repositories\SearchCriterias;

use Ponup\SqlBuilders\SearchCriteria;

class TaskSearchCriteria extends SearchCriteria
{
    public function addProjectCriterion(int $projectId)
    {
        $this->addCriterion('t.project_id = ?', [$projectId]);
    }

    public function addStatusCriterion(string $status)
    {
        $this->addCriterion('t.status = ?', [$status]);
    }

    public function addTemplateCriterion(int $isTemplate)
    {
        $this->addCriterion('p.is_template = ?', [$isTemplate]);
    }

    public function addIsNotTemplateCriterion()
    {
        $this->addTemplateCriterion(0);
    }

    public function addAssigneeCriterion(int $assigneeUid)
    {
        $this->addCriterion('t.assignee_uid = ?', [$assigneeUid]);
    }

    public function addPriorityCriterion(string $priority)
    {
        $this->addCriterion('t.priority = ?', [$priority]);
    }

    public function addKeywordsCriterion(string $keywords)
    {
        $keywordsLike = "%$keywords%";

        $this->addCriterion('t.summary LIKE ? OR t.description LIKE ?', [$keywordsLike, $keywordsLike]);
    }
}
