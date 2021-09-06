<?php declare(strict_types=1);

namespace Shopware\Core\Content\Flow\Indexing;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Flow\Dispatching\FlowBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal (flag:FEATURE_NEXT_8225)
 */
class FlowPayloadUpdater
{
    private Connection $connection;

    private FlowBuilder $flowBuilder;

    public function __construct(Connection $connection, FlowBuilder $flowBuilder)
    {
        $this->connection = $connection;
        $this->flowBuilder = $flowBuilder;
    }

    public function update(array $ids): array
    {
        $listFlowSequence = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`flow_sequence`.`flow_id`)) as array_key,
            LOWER(HEX(`flow`.`id`)) as `flow_id`,
            LOWER(HEX(`flow_sequence`.`id`)) as `sequence_id`,
            LOWER(HEX(`flow_sequence`.`parent_id`)) as `parent_id`,
            LOWER(HEX(`flow_sequence`.`rule_id`)) as `rule_id`,
            `flow_sequence`.`display_group` as `display_group`,
            `flow_sequence`.`position` as `position`,
            `flow_sequence`.`action_name` as `action_name`,
            `flow_sequence`.`config` as `config`,
            `flow_sequence`.`true_case` as `true_case`
            FROM `flow_sequence`
            LEFT JOIN `flow` ON `flow`.`id` = `flow_sequence`.`flow_id`
            WHERE `flow`.`active` = 1
                AND (`flow_sequence`.`rule_id` IS NOT NULL OR `flow_sequence`.`action_name` IS NOT NULL)
                AND `flow_sequence`.`flow_id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => Connection::PARAM_STR_ARRAY]
        );

        $listFlowSequence = FetchModeHelper::group($listFlowSequence);

        $update = new RetryableQuery(
            $this->connection->prepare('UPDATE `flow` SET payload = :payload, invalid = :invalid WHERE `id` = :id')
        );

        $updated = [];
        foreach ($listFlowSequence as $flowId => $flowSequences) {
            usort($flowSequences, function (array $first, array $second) {
                return [$first['display_group'], $first['parent_id'], $first['true_case'], $first['position']]
                    <=> [$second['display_group'], $second['parent_id'], $second['true_case'], $second['position']];
            });

            $invalid = false;
            $serialized = null;

            try {
                $serialized = serialize($this->flowBuilder->build($flowId, $flowSequences));
            } catch (\Throwable $exception) {
                $invalid = true;
            } finally {
                $update->execute([
                    'id' => Uuid::fromHexToBytes($flowId),
                    'payload' => $serialized,
                    'invalid' => (int) $invalid,
                ]);
            }

            $updated[$flowId] = ['payload' => $serialized, 'invalid' => $invalid];
        }

        return $updated;
    }
}
