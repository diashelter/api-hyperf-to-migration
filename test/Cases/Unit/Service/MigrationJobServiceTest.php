<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Cases\Unit\Service;

use App\Service\MigrationJobService;
use HyperfTest\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @internal
 */
#[CoversClass(MigrationJobService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationJobServiceTest extends UnitTestCase
{
    public function testMarkFailedPersistsTerminalFailurePayload(): void
    {
        $model = Mockery::mock('alias:App\Model\MigrationJob');
        $query = Mockery::mock();
        $job = new class {
            public string $status = 'processing';

            public ?string $current_entity = 'contracts';

            /** @var array<string, string>|null */
            public ?array $error_summary = null;

            public mixed $finished_at = null;

            public bool $saved = false;

            public function save(): void
            {
                $this->saved = true;
            }
        };

        $model->shouldReceive('query')->once()->andReturn($query);
        $query->shouldReceive('find')->once()->with('job-1')->andReturn($job);

        (new MigrationJobService())->markFailed('job-1', 'boom');

        $this->assertSame('failed', $job->status);
        $this->assertNull($job->current_entity);
        $this->assertSame(['message' => 'boom'], $job->error_summary);
        $this->assertNotNull($job->finished_at);
        $this->assertTrue($job->saved);
    }
}
