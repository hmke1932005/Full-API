<?php

namespace App\Services;

use App\Repositories\QueryBuilderRepository;
use RuntimeException;

/**
 * منقولة من app/Services/QueryBuilderService.php القديمة — بند 24
 * batch 4 (SQL Query Builder، enhancement spec section 3). طبقة
 * أوركستريشن فوق QueryBuilderRepository: بتحوّل طلب تشغيل (builder أو
 * نص SQL خام) لـ SQL اتفحص، بتنفذه، ودايمًا بتسجل المحاولة (نجحت أو
 * فشلت) في query_history. كمان بتعرض مجموعة صغيرة من قوالب استعلام
 * جاهزة (متطلب Query Templates) متولّدة من الكتالوج الحي بدل أسماء
 * جداول مكتوبة يدويًا، عشان عمرها ما تنحرف عن السكيما الحقيقية.
 */
class QueryBuilderService
{
    public function __construct(private QueryBuilderRepository $repo)
    {
    }

    public function catalog(): array
    {
        return $this->repo->catalog();
    }

    /** @return array<int,array<string,mixed>> علاقات الجداول الأساسية المعلنة، لـ builder الـ JOIN المرئي */
    public function relationshipHints(): array
    {
        $hints = [];
        foreach ($this->repo->catalog() as $key => $entry) {
            foreach ($entry['relationships'] ?? [] as $rel) {
                $hints[] = [
                    'from_table' => $entry['table'],
                    'from_key'   => $key,
                    'column'     => $rel['column'],
                    'to_table'   => $rel['references'],
                    'to_column'  => $rel['references_column'],
                    'label'      => $rel['label'] ?? null,
                ];
            }
        }
        return $hints;
    }

    /** كام قالب جاهز للتشغيل فوق الكتالوج الحي، لمتطلب "Query Templates". */
    public function templates(): array
    {
        $catalog = $this->repo->catalog();
        $templates = [];
        foreach ($catalog as $entry) {
            $cols = array_slice($entry['columns'], 0, 5);
            if (!$cols) {
                continue;
            }
            $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
            $templates[] = [
                'name'        => 'Preview: ' . ($entry['label']['en'] ?? $entry['table']),
                'description' => 'First 50 rows of ' . $entry['table'] . '.',
                'sql_text'    => "SELECT {$colList}\nFROM `{$entry['table']}`\nLIMIT 50",
            ];
            if (isset($entry['default_sort'])) {
                $templates[] = [
                    'name'        => 'Latest: ' . ($entry['label']['en'] ?? $entry['table']),
                    'description' => 'Most recent rows by ' . $entry['default_sort'] . '.',
                    'sql_text'    => "SELECT {$colList}\nFROM `{$entry['table']}`\nORDER BY `{$entry['default_sort']}` DESC\nLIMIT 50",
                ];
            }
        }
        return array_slice($templates, 0, 12);
    }

    /**
     * بتشغّل طلب في وضع الـ builder. بترجع
     * ['ok'=>true,'sql','rows','columns','row_count','execution_time_ms']
     * أو ['ok'=>false,'sql','error'] — عمرها ما بترمي؛ الكولر بيقرر الـ
     * HTTP status.
     */
    public function runBuilder(int $userId, array $spec, ?int $savedQueryId = null): array
    {
        try {
            ['sql' => $sql, 'params' => $params] = $this->repo->buildSql($spec);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, json_encode($spec), 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => null, 'error' => $e->getMessage()];
        }

        return $this->runValidated($userId, $sql, $params, $savedQueryId);
    }

    /** بتشغّل نص SQL خام مكتوب يدويًا. نفس شكل رجوع runBuilder(). */
    public function runRaw(int $userId, string $sqlText, ?int $savedQueryId = null): array
    {
        try {
            $safeSql = $this->repo->validateRawSql($sqlText);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, $sqlText, 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => $sqlText, 'error' => $e->getMessage()];
        }

        return $this->runValidated($userId, $safeSql, [], $savedQueryId);
    }

    private function runValidated(int $userId, string $sql, array $params, ?int $savedQueryId): array
    {
        try {
            $result = $this->repo->execute($sql, $params);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, $sql, 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => $sql, 'error' => $e->getMessage()];
        }

        $this->repo->logHistory($userId, $sql, 'success', null, $result['row_count'], $result['execution_time_ms'], $savedQueryId);

        return [
            'ok'                => true,
            'sql'               => $sql,
            'rows'              => $result['rows'],
            'columns'           => $result['columns'],
            'row_count'         => $result['row_count'],
            'execution_time_ms' => $result['execution_time_ms'],
        ];
    }

    public function saveQuery(int $userId, string $name, string $sqlText, ?string $description, ?array $builderState, ?string $datasetKey, bool $isShared)
    {
        $name = trim($name) !== '' ? trim($name) : 'Untitled query';
        return $this->repo->createSavedQuery($userId, $name, $sqlText, $description, $builderState, $datasetKey, $isShared);
    }

    public function savedQueries(int $userId): array
    {
        return $this->repo->savedQueriesFor($userId);
    }

    /** @return bool true لو اتحذف، false لو مش موجود أو مش ملك المستخدم ده */
    public function deleteSavedQuery(int $userId, $id): bool
    {
        $query = $this->repo->findSavedQuery($id);
        if (!$query || (string) $query->user_id !== (string) $userId) {
            return false;
        }
        return $this->repo->deleteSavedQuery($query);
    }

    public function findSavedQuery($id)
    {
        return $this->repo->findSavedQuery($id);
    }

    public function history(int $userId, int $limit = 30): array
    {
        return $this->repo->historyFor($userId, $limit);
    }
}
