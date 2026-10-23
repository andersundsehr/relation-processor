<?php

declare(strict_types=1);

namespace AUS\RelationProcessor\Tests\Unit;

use AUS\RelationProcessor\DataProcessing\RelationProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Frontend\ContentObject\ContentDataProcessor;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final class RelationProcessorTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTca = null;

    protected function setUp(): void
    {
        $this->previousTca = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousTca === null) {
            unset($GLOBALS['TCA']);
        } else {
            $GLOBALS['TCA'] = $this->previousTca;
        }
    }

    public function testRejectsMissingTcaConfiguration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCA config for tt_content.items not found');

        $this->processor()->getRelation($this->createMock(ContentObjectRenderer::class), 'tt_content', 'items', 1);
    }

    public function testRejectsMissingForeignTable(): void
    {
        $this->configureRelation([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCA config foreign_table not found');

        $this->processor()->getRelation($this->createMock(ContentObjectRenderer::class), 'tt_content', 'items', 1);
    }

    public function testRejectsMmHasUidField(): void
    {
        $this->configureRelation(['foreign_table' => 'pages', 'MM' => 'test_mm', 'MM_hasUidField' => true]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCA config MM_hasUidField not supported');

        $this->processor()->getRelation($this->createMock(ContentObjectRenderer::class), 'tt_content', 'items', 1);
    }

    public function testRejectsMmIsForeign(): void
    {
        $this->configureRelation(['foreign_table' => 'pages', 'MM' => 'test_mm', 'MM_is_foreign' => true]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCA config MM_is_foreign not supported');

        $this->processor()->getRelation($this->createMock(ContentObjectRenderer::class), 'tt_content', 'items', 1);
    }

    public function testRejectsMmOppositeUsage(): void
    {
        $this->configureRelation(['foreign_table' => 'pages', 'MM' => 'test_mm', 'MM_oppositeUsage' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCA config MM_oppositeUsage not supported');

        $this->processor()->getRelation($this->createMock(ContentObjectRenderer::class), 'tt_content', 'items', 1);
    }

    private function processor(): RelationProcessor
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        return new RelationProcessor($connectionPool, $this->createMock(ContentDataProcessor::class));
    }

    /** @param array<string, mixed> $configuration */
    private function configureRelation(array $configuration): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['items']['config'] = $configuration;
    }
}
