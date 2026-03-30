<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Unit;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\Tests\BaseTestCase;

class UuidPrimaryKeyTest extends BaseTestCase
{
    public function test_model_uses_auto_increment_by_default(): void
    {
        $model = new MakerCheckerRequest;

        $this->assertTrue($model->getIncrementing());
        $this->assertEquals('int', $model->getKeyType());
    }

    public function test_model_uses_uuid_primary_key_when_configured(): void
    {
        $this->app['config']->set('maker-checker.use_uuid_primary_key', true);

        $model = new MakerCheckerRequest;

        $this->assertFalse($model->getIncrementing());
        $this->assertEquals('string', $model->getKeyType());
    }

    public function test_model_respects_false_uuid_config(): void
    {
        $this->app['config']->set('maker-checker.use_uuid_primary_key', false);

        $model = new MakerCheckerRequest;

        $this->assertTrue($model->getIncrementing());
        $this->assertEquals('int', $model->getKeyType());
    }

    public function test_uuid_config_defaults_to_false(): void
    {
        $this->assertFalse(config('maker-checker.use_uuid_primary_key'));
    }
}
