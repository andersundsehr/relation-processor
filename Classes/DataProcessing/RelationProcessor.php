<?php

declare(strict_types=1);

namespace AUS\RelationProcessor\DataProcessing;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentDataProcessor;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class RelationProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ContentDataProcessor $contentDataProcessor
    ) {
    }

    /**
     * Process content object data
     *
     * @param ContentObjectRenderer $cObj The data of the content element or page
     * @param array<string, mixed> $contentObjectConfiguration The configuration of Content Object
     * @param array<string, mixed> $processorConfiguration The configuration of this processor
     * @param array<string, mixed> $processedData Key/value store of processed data (e.g. to be passed to a Fluid View)
     * @return array<string, mixed> the processed data as key/value store
     */
    public function process(ContentObjectRenderer $cObj, array $contentObjectConfiguration, array $processorConfiguration, array $processedData)
    {
        $table = $cObj->getCurrentTable();
        $uid = $cObj->data['uid'];
        $field = $processorConfiguration['field'];

        $relations = $this->getRelation($cObj, $table, $field, $uid);
        $request = $cObj->getRequest();
        $processedRecordVariables = [];

        foreach ($relations as $key => $record) {
            $recordContentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $recordContentObjectRenderer->start($record, $GLOBALS['TCA'][$table]['columns'][$field]['config']['foreign_table'], $request);
            $processedRecordVariables[$key] = ['data' => $record];
            $processedRecordVariables[$key] = $this->contentDataProcessor->process(
                $recordContentObjectRenderer,
                $processorConfiguration,
                $processedRecordVariables[$key]
            );
        }

        $processedData['data'][$field] = $processedRecordVariables;
        return $processedData;
    }

    /**
     * @return list<array<string, string|int|float|bool>>
     */
    public function getRelation(ContentObjectRenderer $cObj, string $table, string $field, int $uid): array
    {
        $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? throw new RuntimeException(
            'TCA config for ' . $table . '.' . $field . ' not found'
        );

        $foreignTable = $tcaConfig['foreign_table'] ?? throw new RuntimeException('TCA config foreign_table not found');

        if (isset($tcaConfig['foreign_field'])) {
            $rows = $this->getRowsForeignField($tcaConfig, $foreignTable, $uid);
        } elseif (isset($tcaConfig['MM'])) {
            $rows = $this->getRowsMM($tcaConfig, $foreignTable, $uid);
        } else {
            $rows = $this->getRowsLocalField($tcaConfig, $foreignTable, $table, $field, $uid);
        }

        $records = [];

        $pageRepository = $cObj->getTypoScriptFrontendController()?->sys_page;
        $pageRepository instanceof PageRepository ?: throw new RuntimeException('PageRepository not found');

        foreach ($rows as $row) {
            // Versioning preview:
            $pageRepository->versionOL($foreignTable, $row, true);

            if (!$row) {
                continue;
            }

            // Language overlay:
            $row = $pageRepository->getLanguageOverlay($foreignTable, $row);

            if (!$row) {
                continue; // Might be unset in the language overlay
            }

            $records[] = $row;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $tcaConfig
     * @return list<array<string, string|int|float|bool>>
     */
    private function getRowsForeignField(array $tcaConfig, string $foreignTable, int $uid): array
    {
        $foreignField = $tcaConfig['foreign_field'];
        $foreignSortby = $tcaConfig['foreign_sortby'] ?? null;
        $maxitems = (int)($tcaConfig['maxitems'] ?? 0);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $queryBuilder
            ->select('*')
            ->from($foreignTable)
            ->where($queryBuilder->expr()->eq($foreignField, $uid));

        if ($foreignSortby) {
            $queryBuilder->orderBy($foreignSortby);
        }

        if ($maxitems) {
            $queryBuilder->setMaxResults($maxitems);
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $tcaConfig
     * @return list<array<string, string|int|float|bool>>
     */
    private function getRowsLocalField(array $tcaConfig, string $foreignTable, string $table, string $field, int $uid): array
    {
        $maxitems = (int)($tcaConfig['maxitems'] ?? 0);
        if (($tcaConfig['renderType'] ?? null) === 'selectSingle') {
            $maxitems = 1;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $expr = $queryBuilder->expr();
        $whereUidMatched = $expr->inSet('local.' . $field, $queryBuilder->quoteIdentifier('relation.uid'), true);
        if ($maxitems === 1) {
            $whereUidMatched = $expr->eq('local.' . $field, $queryBuilder->quoteIdentifier('relation.uid'));
        }

        $queryBuilder
            ->select('relation.*')
            ->from($foreignTable, 'relation')
            ->join(
                'relation',
                $table,
                'local',
                (string)$expr->and($whereUidMatched, $expr->eq('local.uid', $uid))
            );

        if ($maxitems !== 1) {
            $queryBuilder->getConcreteQueryBuilder()->orderBy($whereUidMatched);
        }

        if ($maxitems) {
            $queryBuilder->setMaxResults($maxitems);
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return list<array<string, string|int|float|bool>>
     */
    private function getRowsMM(mixed $tcaConfig, mixed $foreignTable, int $uid): array
    {
        if (isset($tcaConfig['MM_hasUidField'])) {
            throw new RuntimeException('TCA config MM_hasUidField not supported');
        }

        if (isset($tcaConfig['MM_is_foreign'])) {
            throw new RuntimeException('TCA config MM_is_foreign not supported');
        }

        if (isset($tcaConfig['MM_oppositeUsage'])) {
            throw new RuntimeException('TCA config MM_oppositeUsage not supported');
        }

        $mmTable = $tcaConfig['MM'] ?? throw new RuntimeException('TCA config MM not found');

        $matchFields = $tcaConfig['MM_match_fields'] ?? [];

        $otherWay = isset($tcaConfig['MM_opposite_field']);

        if ($otherWay) {
            $selfField = 'uid_foreign';
            $otherField = 'uid_local';
            $sorting = 'sorting_foreign';
        } else {
            $selfField = 'uid_local';
            $otherField = 'uid_foreign';
            $sorting = 'sorting';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $queryBuilder
            ->select('relation.*')
            ->from($foreignTable, 'relation')
            ->join('relation', $mmTable, 'mm', $queryBuilder->expr()->eq('relation.uid', 'mm.' . $otherField))
            ->where($queryBuilder->expr()->eq('mm.' . $selfField, $uid))
            ->orderBy('mm.' . $sorting);

        $transOrigPointerField = $GLOBALS['TCA'][$foreignTable]['ctrl']['transOrigPointerField'] ?? null;
        if ($transOrigPointerField) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('relation.' . $transOrigPointerField, 0));
        }

        foreach ($matchFields as $matchField => $value) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq($matchField, $queryBuilder->createNamedParameter($value, Connection::PARAM_STR))
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }
}
