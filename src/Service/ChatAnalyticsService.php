<?php declare(strict_types=1);

namespace AiCommerceAssistant\Service;

use Doctrine\DBAL\Connection;

class ChatAnalyticsService
{
    private const DEFAULT_TOP_LIMIT = 10;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = $this->buildRangeParams($from, $to);

        $sessionStats = $this->connection->fetchAssociative(
            sprintf(
                'SELECT
                    COUNT(*) AS sessionCount
                 FROM aica_chat_session s
                 WHERE %s',
                $this->buildSessionInRangeCondition('s'),
            ),
            $params,
        ) ?: [];

        $messageStats = $this->connection->fetchAssociative(
            'SELECT
                COALESCE(SUM(COALESCE(m.token_input, 0)), 0) AS totalInputTokens,
                COALESCE(SUM(COALESCE(m.token_output, 0)), 0) AS totalOutputTokens,
                COALESCE(
                    SUM(
                        COALESCE(
                            m.token_total,
                            CASE
                                WHEN m.token_input IS NULL AND m.token_output IS NULL THEN NULL
                                ELSE COALESCE(m.token_input, 0) + COALESCE(m.token_output, 0)
                            END
                        )
                    ),
                    0
                ) AS totalTokens,
                AVG(
                    CASE
                        WHEN m.role IN (\'assistant\', \'tool\') AND m.latency_ms IS NOT NULL THEN m.latency_ms
                        ELSE NULL
                    END
                ) AS averageLatencyMs,
                SUM(
                    CASE
                        WHEN m.role IN (\'assistant\', \'tool\') AND m.latency_ms IS NOT NULL THEN 1
                        ELSE 0
                    END
                ) AS latencySampleCount,
                SUM(
                    CASE
                        WHEN m.token_total IS NOT NULL OR m.token_input IS NOT NULL OR m.token_output IS NOT NULL THEN 1
                        ELSE 0
                    END
                ) AS messagesWithTokenData
             FROM aica_chat_message m
             WHERE m.created_at >= :fromDate AND m.created_at <= :toDate',
            $params,
        ) ?: [];

        $contactCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_contact_submission
             WHERE created_at >= :fromDate AND created_at <= :toDate',
            $params,
        );

        $assistantTopProducts = $this->fetchTopProductReferences(
            $from,
            $to,
            ['assistant', 'tool'],
            self::DEFAULT_TOP_LIMIT,
        );

        $userTopProducts = $this->fetchTopProductReferences(
            $from,
            $to,
            ['user', 'contact'],
            self::DEFAULT_TOP_LIMIT,
        );

        return [
            'range' => [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            'metrics' => [
                'totalChats' => (int) ($sessionStats['sessionCount'] ?? 0),
                'totalTokens' => (int) ($messageStats['totalTokens'] ?? 0),
                'totalInputTokens' => (int) ($messageStats['totalInputTokens'] ?? 0),
                'totalOutputTokens' => (int) ($messageStats['totalOutputTokens'] ?? 0),
                'averageResponseLatencyMs' => $messageStats['averageLatencyMs'] !== null
                    ? (float) $messageStats['averageLatencyMs']
                    : null,
                'responseLatencySampleCount' => (int) ($messageStats['latencySampleCount'] ?? 0),
                'contactSubmissions' => $contactCount,
            ],
            'dataAvailability' => [
                'hasTokenData' => (int) ($messageStats['messagesWithTokenData'] ?? 0) > 0,
                'hasLatencyData' => (int) ($messageStats['latencySampleCount'] ?? 0) > 0,
                'hasAssistantProductReferences' => $assistantTopProducts !== [],
                'hasUserProductReferences' => $userTopProducts !== [],
            ],
            'topAssistantProducts' => $assistantTopProducts,
            'topUserProducts' => $userTopProducts,
        ];
    }

    public function exportSessionsCsv(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $headers = [
            'id' => 'Session ID',
            'createdAt' => 'Created At',
            'lastMessageAt' => 'Last Message At',
            'customerId' => 'Customer ID',
            'customerName' => 'Customer Name',
            'customerEmail' => 'Customer Email',
            'guestFingerprintHash' => 'Guest Fingerprint',
            'totalMessageCount' => 'Total Messages',
            'totalInputTokens' => 'Input Tokens',
            'totalOutputTokens' => 'Output Tokens',
            'totalTokens' => 'Total Tokens',
            'averageResponseLatencyMs' => 'Average Latency (ms)',
            'modelName' => 'Model',
            'contactFormSubmitted' => 'Contact Submitted',
            'salesChannelId' => 'Sales Channel ID',
            'languageId' => 'Language ID',
        ];

        $rows = $this->iterateAssociativeRows(
            sprintf(
                'SELECT
                    LOWER(HEX(s.id)) AS id,
                    s.created_at AS createdAt,
                    s.last_message_at AS lastMessageAt,
                    LOWER(HEX(s.customer_id)) AS customerId,
                    TRIM(CONCAT(COALESCE(c.first_name, \'\'), \' \', COALESCE(c.last_name, \'\'))) AS customerName,
                    c.email AS customerEmail,
                    s.guest_fingerprint_hash AS guestFingerprintHash,
                    s.total_message_count AS totalMessageCount,
                    s.total_input_tokens AS totalInputTokens,
                    s.total_output_tokens AS totalOutputTokens,
                    s.total_tokens AS totalTokens,
                    s.average_response_latency_ms AS averageResponseLatencyMs,
                    s.model_name AS modelName,
                    s.contact_form_submitted AS contactFormSubmitted,
                    LOWER(HEX(s.sales_channel_id)) AS salesChannelId,
                    LOWER(HEX(s.language_id)) AS languageId
                 FROM aica_chat_session s
                 LEFT JOIN customer c ON c.id = s.customer_id
                 WHERE %s
                 ORDER BY COALESCE(s.last_message_at, s.created_at) DESC',
                $this->buildSessionInRangeCondition('s'),
            ),
            $this->buildRangeParams($from, $to),
        );

        return $this->buildCsv($headers, $rows);
    }

    public function exportContactsCsv(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $headers = [
            'id' => 'Submission ID',
            'createdAt' => 'Created At',
            'sessionId' => 'Session ID',
            'customerId' => 'Customer ID',
            'customerName' => 'Customer Name',
            'customerEmail' => 'Customer Email',
            'email' => 'Sender Email',
            'name' => 'Sender Name',
            'phone' => 'Phone',
            'company' => 'Company',
            'productReferenceText' => 'Product Reference',
            'sourceContext' => 'Source Context',
            'messageText' => 'Message',
            'mailTargetAddress' => 'Mail Target Address',
            'internalNotificationMailAttempted' => 'Internal Mail Attempted',
            'internalNotificationMailSent' => 'Internal Mail Sent',
            'internalNotificationMailError' => 'Internal Mail Error',
            'confirmationMailAttempted' => 'Confirmation Mail Attempted',
            'confirmationMailSent' => 'Confirmation Mail Sent',
            'confirmationMailError' => 'Confirmation Mail Error',
        ];

        $rows = $this->iterateAssociativeRows(
            'SELECT
                LOWER(HEX(cs.id)) AS id,
                cs.created_at AS createdAt,
                LOWER(HEX(cs.session_id)) AS sessionId,
                LOWER(HEX(cs.customer_id)) AS customerId,
                TRIM(CONCAT(COALESCE(c.first_name, \'\'), \' \', COALESCE(c.last_name, \'\'))) AS customerName,
                c.email AS customerEmail,
                cs.email,
                cs.name,
                cs.phone,
                cs.company,
                cs.product_reference_text AS productReferenceText,
                cs.source_context AS sourceContext,
                cs.message_text AS messageText,
                cs.mail_target_address AS mailTargetAddress,
                cs.internal_notification_mail_attempted AS internalNotificationMailAttempted,
                cs.internal_notification_mail_sent AS internalNotificationMailSent,
                cs.internal_notification_mail_error AS internalNotificationMailError,
                cs.confirmation_mail_attempted AS confirmationMailAttempted,
                cs.confirmation_mail_sent AS confirmationMailSent,
                cs.confirmation_mail_error AS confirmationMailError
             FROM aica_contact_submission cs
             LEFT JOIN customer c ON c.id = cs.customer_id
             WHERE cs.created_at >= :fromDate AND cs.created_at <= :toDate
             ORDER BY cs.created_at DESC',
            $this->buildRangeParams($from, $to),
        );

        return $this->buildCsv($headers, $rows);
    }

    public function exportMessagesCsv(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $headers = [
            'id' => 'Message ID',
            'createdAt' => 'Created At',
            'sessionId' => 'Session ID',
            'messageIndex' => 'Message Index',
            'role' => 'Role',
            'messageType' => 'Message Type',
            'modelName' => 'Model',
            'tokenInput' => 'Token Input',
            'tokenOutput' => 'Token Output',
            'tokenTotal' => 'Token Total',
            'latencyMs' => 'Latency (ms)',
            'containsProductSuggestions' => 'Contains Product Suggestions',
            'containsInfoBox' => 'Contains Info Box',
            'containsFormular' => 'Contains Formular',
            'containsToolPayload' => 'Contains Tool Payload',
            'isError' => 'Is Error',
            'rawText' => 'Raw Text',
        ];

        $rows = $this->iterateAssociativeRows(
            'SELECT
                LOWER(HEX(id)) AS id,
                created_at AS createdAt,
                LOWER(HEX(session_id)) AS sessionId,
                message_index AS messageIndex,
                role,
                message_type AS messageType,
                model_name AS modelName,
                token_input AS tokenInput,
                token_output AS tokenOutput,
                token_total AS tokenTotal,
                latency_ms AS latencyMs,
                contains_product_suggestions AS containsProductSuggestions,
                contains_info_box AS containsInfoBox,
                contains_formular AS containsFormular,
                contains_tool_payload AS containsToolPayload,
                is_error AS isError,
                raw_text AS rawText
             FROM aica_chat_message
             WHERE created_at >= :fromDate AND created_at <= :toDate
             ORDER BY created_at DESC',
            $this->buildRangeParams($from, $to),
        );

        return $this->buildCsv($headers, $rows);
    }

    /**
     * @return array{sessionsDeleted: int, relatedMessagesDeleted: int, relatedProductReferencesDeleted: int, contactLinksAffected: int}
     */
    public function deleteSessionsByRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = $this->buildRangeParams($from, $to);

        $sessionsDeleted = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_chat_session s
             WHERE s.created_at >= :fromDate AND s.created_at <= :toDate',
            $params,
        );

        if ($sessionsDeleted === 0) {
            return [
                'sessionsDeleted' => 0,
                'relatedMessagesDeleted' => 0,
                'relatedProductReferencesDeleted' => 0,
                'contactLinksAffected' => 0,
            ];
        }

        $relatedMessagesDeleted = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_chat_message m
             INNER JOIN aica_chat_session s ON s.id = m.session_id
             WHERE s.created_at >= :fromDate AND s.created_at <= :toDate',
            $params,
        );

        $relatedProductReferencesDeleted = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_product_reference pr
             INNER JOIN aica_chat_session s ON s.id = pr.session_id
             WHERE s.created_at >= :fromDate AND s.created_at <= :toDate',
            $params,
        );

        $contactLinksAffected = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_contact_submission cs
             INNER JOIN aica_chat_session s ON s.id = cs.session_id
             WHERE s.created_at >= :fromDate AND s.created_at <= :toDate',
            $params,
        );

        $this->connection->beginTransaction();

        try {
            // Product references with nullable message_id are not removed by FK cascade and must be cleaned manually.
            $this->connection->executeStatement(
                'DELETE pr
                 FROM aica_product_reference pr
                 INNER JOIN aica_chat_session s ON s.id = pr.session_id
                 WHERE s.created_at >= :fromDate AND s.created_at <= :toDate',
                $params,
            );

            $this->connection->executeStatement(
                'DELETE FROM aica_chat_session
                 WHERE created_at >= :fromDate AND created_at <= :toDate',
                $params,
            );

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return [
            'sessionsDeleted' => $sessionsDeleted,
            'relatedMessagesDeleted' => $relatedMessagesDeleted,
            'relatedProductReferencesDeleted' => $relatedProductReferencesDeleted,
            'contactLinksAffected' => $contactLinksAffected,
        ];
    }

    /**
     * @return array{contactsDeleted: int}
     */
    public function deleteContactsByRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = $this->buildRangeParams($from, $to);

        $contactsDeleted = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM aica_contact_submission
             WHERE created_at >= :fromDate AND created_at <= :toDate',
            $params,
        );

        if ($contactsDeleted === 0) {
            return ['contactsDeleted' => 0];
        }

        $this->connection->executeStatement(
            'DELETE FROM aica_contact_submission
             WHERE created_at >= :fromDate AND created_at <= :toDate',
            $params,
        );

        return ['contactsDeleted' => $contactsDeleted];
    }

    /**
     * @param list<string> $sources
     *
     * @return list<array{label: string, count: int, source: string, productName: ?string, productNumber: ?string, productId: ?string, referenceText: ?string}>
     */
    private function fetchTopProductReferences(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $sources,
        int $limit,
    ): array {
        if ($sources === []) {
            return [];
        }

        $sourcePlaceholders = [];
        $sourceParams = [];
        foreach ($sources as $index => $source) {
            $key = 'source' . $index;
            $sourcePlaceholders[] = ':' . $key;
            $sourceParams[$key] = $source;
        }

        $params = array_merge($this->buildRangeParams($from, $to), $sourceParams);

        $sql = sprintf(
            'SELECT
                source,
                NULLIF(TRIM(product_name), \'\') AS productName,
                NULLIF(TRIM(product_number), \'\') AS productNumber,
                NULLIF(TRIM(product_id), \'\') AS productId,
                NULLIF(TRIM(reference_text), \'\') AS referenceText,
                COUNT(*) AS referenceCount
             FROM aica_product_reference
             WHERE created_at >= :fromDate
               AND created_at <= :toDate
               AND source IN (%s)
               AND (
                    NULLIF(TRIM(product_name), \'\') IS NOT NULL
                    OR NULLIF(TRIM(product_number), \'\') IS NOT NULL
                    OR NULLIF(TRIM(product_id), \'\') IS NOT NULL
                    OR NULLIF(TRIM(reference_text), \'\') IS NOT NULL
               )
             GROUP BY source, productName, productNumber, productId, referenceText
             ORDER BY referenceCount DESC
             LIMIT %d',
            implode(', ', $sourcePlaceholders),
            max(1, $limit),
        );

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'label' => $this->resolveProductReferenceLabel($row),
                'count' => (int) ($row['referenceCount'] ?? 0),
                'source' => (string) ($row['source'] ?? ''),
                'productName' => $this->toNullableString($row['productName'] ?? null),
                'productNumber' => $this->toNullableString($row['productNumber'] ?? null),
                'productId' => $this->toNullableString($row['productId'] ?? null),
                'referenceText' => $this->toNullableString($row['referenceText'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function iterateAssociativeRows(string $sql, array $params): \Generator
    {
        $statement = $this->connection->executeQuery($sql, $params);

        while (($row = $statement->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    /**
     * @param array<string, string> $headers
     * @param iterable<array<string, mixed>> $rows
     */
    private function buildCsv(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'wb+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_values($headers));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($headers) as $key) {
                $line[] = $this->normalizeCsvValue($row[$key] ?? null);
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @return array{fromDate: string, toDate: string}
     */
    private function buildRangeParams(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'fromDate' => $from->format('Y-m-d H:i:s'),
            'toDate' => $to->format('Y-m-d H:i:s'),
        ];
    }

    private function buildSessionInRangeCondition(string $sessionAlias): string
    {
        return sprintf(
            '(EXISTS (
                    SELECT 1
                    FROM aica_chat_message m
                    WHERE m.session_id = %1$s.id
                      AND m.created_at >= :fromDate
                      AND m.created_at <= :toDate
                )
                OR (
                    %1$s.total_message_count = 0
                    AND %1$s.created_at >= :fromDate
                    AND %1$s.created_at <= :toDate
                )
            )',
            $sessionAlias,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveProductReferenceLabel(array $row): string
    {
        $parts = [];

        $name = $this->toNullableString($row['productName'] ?? null);
        if ($name !== null) {
            $parts[] = $name;
        }

        $number = $this->toNullableString($row['productNumber'] ?? null);
        if ($number !== null) {
            $parts[] = $number;
        }

        if ($parts !== []) {
            return implode(' | ', $parts);
        }

        $referenceText = $this->toNullableString($row['referenceText'] ?? null);
        if ($referenceText !== null) {
            return $referenceText;
        }

        $productId = $this->toNullableString($row['productId'] ?? null);

        return $productId ?? 'n/a';
    }

    private function normalizeCsvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function toNullableString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
