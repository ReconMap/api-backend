<?php declare(strict_types=1);

namespace Reconmap\Controllers\Reports;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\TemplateProcessor;
use Psr\Http\Message\ServerRequestInterface;
use Reconmap\Controllers\Controller;
use Reconmap\Models\Attachment;
use Reconmap\Models\Report;
use Reconmap\Repositories\AttachmentRepository;
use Reconmap\Repositories\ProjectRepository;
use Reconmap\Repositories\ReportRepository;
use Reconmap\Services\Filesystem\AttachmentFilePath;
use Reconmap\Services\Reporting\ReportDataCollector;
use Monolog\Logger;

function flatten($array, $prefix = '')
{
    if (is_null($array)) {
        return [];
    }
    $result = array();
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $result = $result + flatten($value, $prefix . $key . '.');
        } else {
            $result[$prefix . $key] = $value;
        }
    }
    return $result;
}

class CreateReportController extends Controller
{
    public function __construct(
        private AttachmentFilePath   $attachmentFilePathService,
        private ProjectRepository    $projectRepository,
        private ReportRepository     $reportRepository,
        private AttachmentRepository $attachmentRepository,
        private ReportDataCollector  $reportDataCollector
    )
    {
    }

    public function __invoke(ServerRequestInterface $request): array
    {
        $params = $this->getJsonBodyDecodedAsArray($request);
        $projectId = intval($params['projectId']);
        $reportTemplateId = intval($params['reportTemplateId']);

        $userId = $request->getAttribute('userId');

        $versionName = $params['name'];

        $attachments = $this->attachmentRepository->findByParentId('report', $reportTemplateId);
        if (empty($attachments)) {
            throw new \Exception("Report template with template id $reportTemplateId not found");
        }
        $reportTemplateAttachment = $attachments[0];

        $report = new Report();
        $report->generatedByUid = $userId;
        $report->projectId = $projectId;
        $report->versionName = $versionName;
        $report->versionDescription = $params['description'];

        $project = $this->projectRepository->findById($projectId);

        $reportId = $this->reportRepository->insert($report);

        $attachment = new Attachment();
        $attachment->parent_type = 'report';
        $attachment->parent_id = $reportId;
        $attachment->submitter_uid = $userId;

        $vars = $this->reportDataCollector->collectForProject($projectId);

        $attachmentIds = [];

        try {
            $templateFilePath = $this->attachmentFilePathService->generateFilePathFromAttachment($reportTemplateAttachment);
            $template = new TemplateProcessor($templateFilePath);
            $template->setUpdateFields(true);

            $template->setValue('date', $vars['date']);
            foreach (flatten($vars['project'], 'project.') as $key => $value) {
                $template->setValue($key, $value);
            }
            foreach (flatten($vars['client'], 'client.') as $key => $value) {
                $template->setValue($key, $value);
            }
            foreach (flatten($vars['org'], 'org.') as $key => $value) {
                $template->setValue($key, $value);
            }

            try {
                $template->cloneBlock('users', count($vars['users']), true, true);
                foreach ($vars['users'] as $index => $user) {
                    $template->setValue('user.full_name#' . ($index + 1), $user['full_name']);
                    $template->setValue('user.short_bio#' . ($index + 1), $user['short_bio']);
                }
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $this->logger->warning("Users block: [$message]");
            }

            try {
                $template->cloneRow('target.name', count($vars['targets']));
                foreach ($vars['targets'] as $index => $target) {
                    $indexPlusOne = $index + 1;
                    $template->setValue('target.name#' . $indexPlusOne, $target['name']);
                    $template->setValue('target.kind#' . $indexPlusOne, $target['kind']);
                }
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $this->logger->warning("Targets: [$message]");
            }

            foreach ($vars['findingsOverview'] as $stat) {
                $template->setValue('findings.count.' . $stat['severity'], $stat['count']);
            }

            $markdownParser = new GithubFlavoredMarkdownConverter();
            $word = new PhpWord();

            try {
                $template->cloneBlock('vulnerabilities', count($vars['vulnerabilities']), true, true);
                foreach ($vars['vulnerabilities'] as $index => $vulnerability) {
                    $template->setValue('vulnerability.name#' . ($index + 1), $vulnerability['summary']);

                    if (!is_null($vulnerability['description'])) {
                        $description = $markdownParser->convert($vulnerability['description']);

                        $tempTable = $word->addSection()->addTable();
                        $cell = $tempTable->addRow()->addCell();
                        Html::addHtml($cell, $description);

                        $template->setComplexBlock('vulnerability.description#' . ($index + 1), $tempTable);
                    }

                    $template->setValue('vulnerability.category_name#' . ($index + 1), $vulnerability['category_name']);
                    $template->setValue('vulnerability.cvss_score#' . ($index + 1), $vulnerability['cvss_score']);
                    $template->setValue('vulnerability.severity#' . ($index + 1), $vulnerability['risk']);
                    $template->setValue('vulnerability.proof_of_concept#' . ($index + 1), $vulnerability['proof_of_concept']);
                    $template->setValue('vulnerability.remediation#' . ($index + 1), $vulnerability['remediation']);
                }
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $this->logger->warning("Vulnerabilities block: [$message]");
            }

            try {
                $template->cloneRow('revisionHistoryDateTime', count($vars['reports']));
                foreach ($vars['reports'] as $index => $reportRevision) {
                    $indexPlusOne = $index + 1;
                    $template->setValue('revisionHistoryDateTime#' . $indexPlusOne, $reportRevision['insert_ts']);
                    $template->setValue('revisionHistoryVersionName#' . $indexPlusOne, $reportRevision['version_name']);
                    $template->setValue('revisionHistoryVersionDescription#' . $indexPlusOne, $reportRevision['version_description']);
                }
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $this->logger->warning("Revision history block: [$message]");
            }

            
            $fileName = uniqid(gethostname());
            $basePath = $this->attachmentFilePathService->generateBasePath();
            $filePath = $basePath . $fileName;

            $template->saveAs($filePath);

            $projectName = str_replace(' ', '_', strtolower($project['name']));
            $clientFileName = "reconmap-{$projectName}-v{$versionName}.docx";

            $attachment->file_name = $fileName;
            $attachment->file_mimetype = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $attachment->file_hash = hash_file('md5', $filePath);
            $attachment->file_size = filesize($filePath);
            $attachment->client_file_name = $clientFileName;

            $attachmentIds[] = $this->attachmentRepository->insert($attachment);

        } catch (\Exception $e) {
            $message = $e->getMessage();
            $this->logger->warning("Overall exception: [$message]");
        }

        return $attachmentIds;
    }
}
