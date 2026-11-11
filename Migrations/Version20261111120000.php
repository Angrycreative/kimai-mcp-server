<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MCP server: the call log, one row per tool call';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_mcp_call_log')) {
            return;
        }

        $table = $schema->createTable('kimai2_mcp_call_log');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        // no foreign key: the log outlives a deleted user, which is part of what it is for
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('client', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('tool', 'string', ['length' => 100, 'notnull' => true]);
        $table->addColumn('arguments', 'text', ['notnull' => true]);
        $table->addColumn('is_write', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('success', 'boolean', ['notnull' => true, 'default' => true]);
        $table->addColumn('error', 'text', ['notnull' => false]);
        $table->addColumn('duration_ms', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('changed', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['created_at'], 'IDX_MCP_CALL_LOG_CREATED');
        $table->addIndex(['user_id'], 'IDX_MCP_CALL_LOG_USER');
        $table->addIndex(['tool'], 'IDX_MCP_CALL_LOG_TOOL');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_mcp_call_log')) {
            $schema->dropTable('kimai2_mcp_call_log');
        }
    }
}
