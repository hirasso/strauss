<?php

namespace BrianHenryIE\Strauss\Tests\Unit\Console;

use BrianHenryIE\Strauss\Console\Application;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\TestCase;

class ApplicationTest extends TestCase
{

    /**
     * Test the Symfony\Component\Console\Application instance contains the Compose command.
     */
    public function testInstantiation()
    {

        $version = '1.0.0';

        $sut = new Application($version);

        $commands = $sut->all();

        $containsComposeCommand = array_reduce($commands, function ($carry, $item) {
            return $carry || $item instanceof DependenciesCommand;
        }, false);

        self::assertTrue($containsComposeCommand);
    }
}
