<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console;

use Symfony\Component\Console\Tester\CommandTester;

use function assert;
use function preg_replace;

trait CommandTesterAssertions
{
    /**
     * We don't care how the output is formatted, but we do care that our
     * sprintf()-style placeholders are replaced with the correct values.
     * Symfony may also wrap the output depending on the terminal width.
     */
    private static function assertUnwrappedDisplayContainsString(string $expected, CommandTester $tester): void
    {
        self::assertStringContainsString(
            self::unwrapDisplay($expected),
            self::unwrapDisplay($tester->getDisplay(true)),
        );
    }

    private static function assertUnwrappedErrorOutputContainsString(string $expected, CommandTester $tester): void
    {
        self::assertStringContainsString(
            self::unwrapDisplay($expected),
            self::unwrapDisplay($tester->getErrorOutput(true)),
        );
    }

    private static function unwrapDisplay(string $display): string
    {
        $display = preg_replace(
            '~
                (?:^|\n)   # start of a line
                \s*        # optional indentation
                (?:\/\/|!) # "//" or "!" decoration
                \s+        # whitespace after the decoration
            ~x',
            ' ',
            $display,
        );
        assert($display !== null);

        $display = preg_replace('/\s+/', '', $display);
        assert($display !== null);

        return $display;
    }
}
