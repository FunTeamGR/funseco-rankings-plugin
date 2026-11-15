<?php

namespace Funseco\Rankings\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Records\RecordsServiceProvider;
use Funseco\Rankings\RankingsServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            RecordsServiceProvider::class,
            RankingsServiceProvider::class,
        ];
    }
}
