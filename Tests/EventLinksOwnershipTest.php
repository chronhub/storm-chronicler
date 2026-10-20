<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The ownership boundary deptrac cannot see.
 *
 * The link bookkeeping belongs to the EventLinks module. Chronicler's knowledge of it would be a
 * reverse dependency hidden in a SQL string, and the declared package graph would then misstate the
 * runtime ownership graph.
 */
final class EventLinksOwnershipTest extends TestCase
{
    #[Test]
    public function chronicler_production_knows_nothing_of_the_link_schema(): void
    {
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Tests/')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, 'event_links') || str_contains($content, 'Storm\\EventLinks')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'Chronicler production must not name the link table nor import the EventLinks module.');
    }
}
