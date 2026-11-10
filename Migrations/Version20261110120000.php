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

final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MCP server: sessions, shared by every webserver';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_mcp_sessions')) {
            return;
        }

        $table = $schema->createTable('kimai2_mcp_sessions');
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => true]);
        $table->addColumn('data', 'text', ['notnull' => true]);
        $table->addColumn('updated_at', 'integer', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['updated_at'], 'IDX_MCP_SESSIONS_UPDATED');
        $table->addIndex(['user_id'], 'IDX_MCP_SESSIONS_USER');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_mcp_sessions')) {
            $schema->dropTable('kimai2_mcp_sessions');
        }
    }
}
