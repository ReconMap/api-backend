<?php declare(strict_types=1);

namespace Reconmap\Repositories\Importers;

use PHPUnit\Framework\TestCase;
use Reconmap\Models\Vulnerability;
use Reconmap\Repositories\VulnerabilityRepository;

class VulnerabilitiesImporterTest extends TestCase
{
    public function testHappyPath()
    {
        $fakeVulnerability = new Vulnerability();
        $fakeVulnerability->creator_uid = 5;
        $fakeVulnerability->summary = 'Summary';
        $fakeVulnerability->description = 'Desc Crip Tion';
        $fakeVulnerability->tags = 'foo,bar';
        $fakeVulnerability->category_id = 5;
        $fakeVulnerability->is_template = false;
        $fakeVulnerability->proof_of_concept = 'PoC';
        $fakeVulnerability->impact = 'None';
        $fakeVulnerability->remediation = 'Turn off and on again';
        $fakeVulnerability->risk = 'low';
        $fakeVulnerability->cvss_score = 5;
        $fakeVulnerability->cvss_vector = 'VECTOR';

        $vulnerability = (object)[
            'summary' => 'Summary',
            'description' => 'Desc Crip Tion',
            'tags' => 'foo,bar',
            'category_id' => 5,
            'is_template' => false,
            'proof_of_concept' => 'PoC',
            'impact' => 'None',
            'remediation' => 'Turn off and on again',
            'risk' => 'low',
            'cvss_score' => 5,
            'cvss_vector' => 'VECTOR'
        ];

        $userId = 5;
        $vulnerabilities = [$vulnerability];

        $mockCommandRepository = $this->createMock(VulnerabilityRepository::class);
        $mockCommandRepository->expects($this->once())
            ->method('insert')
            ->with($fakeVulnerability);

        $importer = new VulnerabilitiesImporter($mockCommandRepository);
        $result = $importer->import($userId, $vulnerabilities);

        $this->assertEquals([], $result['errors']);
        $this->assertEquals(1, $result['count']);
    }
}