<?php

namespace Tests\Feature;

use PharData;
use Tests\TestCase;

class AgentDownloadTest extends TestCase
{
    public function test_the_agent_bundle_is_publicly_downloadable_as_a_tarball(): void
    {
        $response = $this->get('/agent/download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/gzip');
        $response->assertHeader('content-disposition');

        $path = $response->baseResponse->getFile()->getPathname();

        $this->assertFileExists($path);
        $this->assertSame("\x1f\x8b", file_get_contents($path, length: 2));

        $archive = new PharData($path);

        foreach ([
            'noc-agent/install.sh',
            'noc-agent/uninstall.sh',
            'noc-agent/collector.sh',
            'noc-agent/config/agent.conf.example',
            'noc-agent/lib/common.sh',
            'noc-agent/panels/cpanel.sh',
            'noc-agent/modules/mysql.sh',
            'noc-agent/systemd/noc-agent.service',
        ] as $file) {
            $this->assertTrue(isset($archive[$file]), "The agent bundle is missing {$file}.");
        }

        $this->assertStringContainsString('NOC monitoring agent installer', $archive['noc-agent/install.sh']->getContent());
    }
}
