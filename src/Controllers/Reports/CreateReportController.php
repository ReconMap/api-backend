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
use Reconmap\Utils\ArrayUtils;
use PhpOffice\PhpWord\Shared\Converter;

class CreateReportController extends Controller
{
    protected $word;
    protected $templateProcessor;
    protected $severityColors;
    
    public function __construct(
        private readonly AttachmentFilePath   $attachmentFilePathService,
        private readonly ProjectRepository    $projectRepository,
        private readonly ReportRepository     $reportRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly ReportDataCollector  $reportDataCollector
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
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

        $attachmentIds = [];

        try {
            $templateFilePath = $this->attachmentFilePathService->generateFilePathFromAttachment($reportTemplateAttachment);
            $this->word = new PhpWord();
            $this->templateProcessor = new TemplateProcessor($templateFilePath);
            $this->templateProcessor->setUpdateFields(true);

            $this->severityColors = [
                'low'       => 'fbc800',
                'medium'    => 'db732e',
                'high'      => 'd42820',
                'critical'  => '000000',
            ];
            
            $this->setValue('date', $vars['date']);
            foreach (ArrayUtils::flatten($vars['project'], 'project.') as $key => $value) {
                if (is_null($value)) {
                    continue;
                }

                switch ($key) {
                    case 'project.description':
                        $this->advancedParser('MarkdownText', $key, $value);
                        break;

                    case 'project.management_summary':
                        $this->advancedParser('MarkdownText', $key, $value);
                        break;

                    case 'project.management_conclusion':
                        $this->advancedParser('MarkdownText', $key, $value);
                        break;

                    default:
                        $this->setValue($key, $value);
                        break;
                }
            }
            foreach (ArrayUtils::flatten($vars['client'], 'client.') as $key => $value) {
                $this->setValue($key, $value);
            }
            foreach (ArrayUtils::flatten($vars['org'], 'org.') as $key => $value) {
                $this->setValue($key, $value);
            }

            $attachments = $vars['project']['attachments'];
            if(count($attachments) > 0) {
                $this->templateProcessor->cloneBlock('attachments', count($attachments), true, true);
                foreach ($attachments as $index => $attach) {
                    if (is_null($attach)) {
                        continue;
                    }
                    $this->templateProcessor->setImageValue('attachment.image#' . ($index + 1), $attach);
                }
            }

            try {
                if(count($vars['users']) > 0) {
                    $this->templateProcessor->cloneRow('user.full_name', count($vars['users']), true, true);
                    foreach ($vars['users'] as $index => $user) {
                        $this->setValue('user.full_name#' . ($index + 1), $user['full_name']);
                        $this->setValue('user.short_bio#' . ($index + 1), $user['short_bio']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in user section: [$msg]");
            }

            try {
                if (isset($vars["logos"]["org_logo"])) {
                    $this->templateProcessor->setImageValue('org.logo', $vars["logos"]["org_logo"]);
                }
                if (isset($vars["logos"]["org_small_logo"])) {
                    $this->templateProcessor->setImageValue('org.small_logo', $vars["logos"]["org_small_logo"]);
                }
                if (isset($vars["logos"]["client_logo"])) {
                    $this->templateProcessor->setImageValue('client.logo', $vars["logos"]["client_logo"]);
                }
                if (isset($vars["logos"]["client_small_logo"])) {
                    $this->templateProcessor->setImageValue('client.small_logo', $vars["logos"]["client_small_logo"]);
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in logo section: [$msg]");
            }

            try {
                if(count($vars['vault']) > 0) {
                    $this->templateProcessor->cloneRow('vault.name', count($vars['vault']));
                    foreach ($vars['vault'] as $index => $item) {
                        $indexPlusOne = $index + 1;
                        $this->setValue('vault.name#' . $indexPlusOne, $item['name']);
                        $this->setValue('vault.note#' . $indexPlusOne, $item['note']);
                        $this->setValue('vault.type#' . $indexPlusOne, $item['type']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in vault section: [$msg]");
            }

            try {
                if(count($vars['targets']) > 0) {
                    $this->templateProcessor->cloneRow('target.name', count($vars['targets']));
                    foreach ($vars['targets'] as $index => $target) {
                        $indexPlusOne = $index + 1;
                        $this->setValue('target.name#' . $indexPlusOne, $target['name']);
                        $this->setValue('target.kind#' . $indexPlusOne, $target['kind']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in target section: [$msg]");
            }

            $chartX = $chartY = [];
            foreach ($vars['findingsOverview'] as $stat) {
                $this->setValue('findings.count.' . $stat['severity'], $stat['count']);
                $chartX[] = $stat['severity'];
                $chartY[] = $stat['count'];
                $chartColors[] = $this->severityColors[$stat['severity']];
            }

            if(!empty($chartX)) {
                $chart = $this->word->addSection()->addChart('column', $chartX,$chartY);
                $chart->getStyle()->setWidth(Converter::inchToEmu(7))->setHeight(Converter::inchToEmu(5));
                $chart->getStyle()->setColors($chartColors);
                $chart->getStyle()->setShowAxisLabels(true);
                $chart->getStyle()->setShowGridX(true);
                $chart->getStyle()->setShowGridY(true);
                $chart->getStyle()->setCategoryLabelPosition('low');
                $this->templateProcessor->setChart('findingsChart', $chart);
            }

            try {
                if(count($vars['vulnerabilities']) > 0) {
                    $this->templateProcessor->cloneBlock('vulnerabilities', count($vars['vulnerabilities']), true, true);
                    foreach ($vars['vulnerabilities'] as $index => $vulnerability) {
                        if(!is_null($vulnerability['risk'])) {
                            $this->advancedParser('TitleWithSeverity', 'vulnerability.name#' . ($index + 1), $vulnerability['summary'], ['severity' => $vulnerability['risk']]);
                        } else {
                            $this->setValue('vulnerability.name#' . ($index + 1), $vulnerability['summary']);
                        }
                        $this->advancedParser('MarkdownText', 'vulnerability.description#' . ($index + 1), $vulnerability['description']);
                        $this->advancedParser('MarkdownSourceCode', 'vulnerability.remediation#' . ($index + 1), $vulnerability['remediation']);
                        $this->setValue('vulnerability.remediation_complexity#' . ($index + 1), $vulnerability['remediation_complexity']);
                        $this->setValue('vulnerability.remediation_priority#' . ($index + 1), $vulnerability['remediation_priority']);
                        
                        $attachments = $vulnerability['attachments'];
                        $this->templateProcessor->cloneBlock('vulnerability.attachments#' . ($index + 1), count($attachments), true, true);
                        foreach ($attachments as $i => $attach) {
                            $name = 'vulnerability.attachment.image#' . ($index + 1) . "#" . ($i + 1);
                            $this->templateProcessor->setImageValue($name, $attach);
                        }

                        $this->advancedParser('MarkdownSourceCode', 'vulnerability.proof_of_concept#' . ($index + 1), $vulnerability['proof_of_concept']);
                        $this->setValue('vulnerability.category_name#' . ($index + 1), $vulnerability['category_name']);
                        $this->setValue('vulnerability.cvss_score#' . ($index + 1), $vulnerability['cvss_score']);
                        $this->setValue('vulnerability.cvss_vector#' . ($index + 1), $vulnerability['cvss_vector']);
                        $this->setValue('vulnerability.owasp_vector#' . ($index + 1), $vulnerability['owasp_vector']);
                        $this->setValue('vulnerability.owasp_overall#' . ($index + 1), $vulnerability['owasp_overall']);
                        $this->setValue('vulnerability.owasp_likelihood#' . ($index + 1), $vulnerability['owasp_likehood']);
                        $this->setValue('vulnerability.owasp_impact#' . ($index + 1), $vulnerability['owasp_impact']);
                        $this->setValue('vulnerability.severity#' . ($index + 1), $vulnerability['risk']);
                        $this->advancedParser('MarkdownSourceCode', 'vulnerability.impact#' . ($index + 1), $vulnerability['impact']);
                        $this->advancedParser('MarkdownText', 'vulnerability.references#' . ($index + 1), $vulnerability['external_refs']);
                    }
                }

                if(count($vars['vulnerabilities']) > 0) {
                    $this->templateProcessor->cloneRow('vuln', count($vars['vulnerabilities']));
                    foreach ($vars['vulnerabilities'] as $index => $item) {
                        $indexPlusOne = $index + 1;
                        $this->setValue('vuln#' . $indexPlusOne, $item['summary']);
                        $this->setValue('vulnerability.owasp_overall#' . $indexPlusOne, $item['owasp_overall']);
                        $this->setValue('vulnerability.description#' . $indexPlusOne, $item['description']);
                        $this->setValue('vulnerability.category_name#' . $indexPlusOne, $item['category_name']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in vulnerabilties section: [$msg]");
            }

            try {
                if(count($vars['contacts']) > 0) {
                    $this->templateProcessor->cloneRow('contact.name', count($vars['contacts']), true, true);
                    foreach ($vars['contacts'] as $index => $vulnerability) {
                        $this->setValue('contact.kind#' . ($index + 1), $vulnerability['kind']);
                        $this->setValue('contact.name#' . ($index + 1), $vulnerability['name']);
                        $this->setValue('contact.phone#' . ($index + 1), $vulnerability['phone']);
                        $this->setValue('contact.email#' . ($index + 1), $vulnerability['email']);
                        $this->setValue('contact.role#' . ($index + 1), $vulnerability['role']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in contacts section: [$msg]");
            }

            try {
                if(count($vars['parentCategories']) > 0) {
                    $this->templateProcessor->cloneRow('category.group', count($vars['parentCategories']), true, true);
                    foreach ($vars['parentCategories'] as $index => $category) {
                        $this->setValue('category.group#' . ($index + 1), $category['name']);
                        $this->setValue('category.severity#' . ($index + 1), $category['owasp_overall']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in parent categories section: [$msg]");
            }

            try {
                if(count($vars['categories']) > 0) {
                    $this->templateProcessor->cloneRow('category.name', count($vars['categories']), true, true);
                    foreach ($vars['categories'] as $index => $category) {
                        $this->setValue('category.name#' . ($index + 1), $category['name']);
                        $this->setValue('category.status#' . ($index + 1), $category['status']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in categories section: [$msg]");
            }

            try {
                if(count($vars['reports']) > 0) {
                    $this->templateProcessor->cloneRow('revisionHistoryDateTime', count($vars['reports']));
                    foreach ($vars['reports'] as $index => $reportRevision) {
                        $indexPlusOne = $index + 1;
                        $this->setValue('revisionHistoryDateTime#' . $indexPlusOne, $reportRevision['insert_ts']);
                        $this->setValue('revisionHistoryVersionName#' . $indexPlusOne, $reportRevision['version_name']);
                        $this->setValue('revisionHistoryVersionDescription#' . $indexPlusOne, $reportRevision['version_description']);
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->logger->warning("Error in reports section: [$msg");
            }

            $fileName = uniqid(gethostname());
            $basePath = $this->attachmentFilePathService->generateBasePath();
            $filePath = $basePath . $fileName;

            $this->templateProcessor->saveAs($filePath);

            $projectName = str_replace(' ', '_', strtolower($project['name']));
            $clientName = str_replace(' ', '_', strtolower($vars['client']->getName()));
            $clientFileName = $this->sanitizeString("{$clientName}-{$projectName}-v{$versionName}.docx");

            $attachment->file_name = $fileName;
            $attachment->file_mimetype = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $attachment->file_hash = hash_file('md5', $filePath);
            $attachment->file_size = filesize($filePath);
            $attachment->client_file_name = $clientFileName;

            $attachmentIds[] = $this->attachmentRepository->insert($attachment);

        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->logger->error("General error: [$msg]");
        }

        return $attachmentIds;
    }

    private function advancedParser($type, $blockName, $textToParse, $options = []): void
    {
        if (is_null($textToParse)) {
            return;
        }

        switch ($type) {
            case 'MarkdownText':
                $markdownConverter = new GithubFlavoredMarkdownConverter();
                $convertedText = $markdownConverter->convert($textToParse);

                $tempTable = $this->word->addSection()->addTable();
                $cell = $tempTable->addRow()->addCell();
                Html::addHtml($cell, nl2br(strval($convertedText)));                

                $this->templateProcessor->setComplexBlock($blockName, $tempTable);

                break;

            case 'MarkdownSourceCode':
                $markdownConverter = new GithubFlavoredMarkdownConverter();
                $convertedText = $markdownConverter->convert($textToParse);

                $dom = new \DomDocument();
                $dom->loadHTML(mb_convert_encoding(strval($convertedText), 'ISO-8859-1', 'UTF-8'));
                $xpath = new \DOMXpath($dom);

                $tableStyle = [
                    'cellMargin'  => 50
                ];
                $tempTable = $this->word->addSection()->addTable($tableStyle);

                $elements = $xpath->evaluate('//body/*');
                foreach ($elements as $node) {
                    switch ($node->tagName) {
                        case 'pre':
                            $cellStyle = array(
                            'bgColor'     => 'eeeeee',
                            'borderColor' => 'dddddd',
                            'borderSize'  => 1,
                            );
                            $cell = $tempTable->addRow()->addCell(null, $cellStyle);
                            $nodeDiv = $dom->createElement("p", $node->nodeValue);
                            $nodeDiv->setAttribute('style', 'font-family: Arial Narrow, Courier New; font-size: 10px;');
                            Html::addHtml($cell, nl2br($node->ownerDocument->saveXML($nodeDiv)));
                            break;
                        
                        default:
                            $cell = $tempTable->addRow()->addCell();
                            $nodeDiv = $dom->createElement("div", $node->nodeValue);
                            Html::addHtml($cell, nl2br($node->ownerDocument->saveXML($nodeDiv)));
                            break;
                    }
                }

                $this->templateProcessor->setComplexBlock($blockName, $tempTable);

                break;

            case 'TitleWithSeverity':
                $colors = [
                    'low'       => [ 'bg' => $this->severityColors['low'],      'text' => 'ffffff'],
                    'medium'    => [ 'bg' => $this->severityColors['medium'],   'text' => 'ffffff'],
                    'high'      => [ 'bg' => $this->severityColors['high'],     'text' => 'ffffff'],
                    'critical'  => [ 'bg' => $this->severityColors['critical'], 'text' => 'ffffff'],
                ];

                $tableStyle = [
                    'cellMargin'  => 100
                ];
                
                $cellStyle = [
                    'bgColor'     => $colors[$options['severity']]['bg'],
                    'borderColor' => 'dddddd',
                    'borderSize'  => 1,
                ];

                $fontStyle = [
                    'color'     => $colors[$options['severity']]['text'],
                    'name'      => 'Arial',
                ];

                $tempTable = $this->word->addSection()->addTable($tableStyle);
                $cell = $tempTable->addRow()->addCell(null, $cellStyle)->addText($textToParse, $fontStyle);

                $this->templateProcessor->setComplexBlock($blockName, $tempTable);

                break;
        }
    }
    
    private function setValue($blockName, $value) {
        if (is_null($value)) {
            return;
        }
        $this->templateProcessor->setValue($blockName, $value);
    }
    
    private function sanitizeString($string) {
        return \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')->transliterate($string);
    }
}
