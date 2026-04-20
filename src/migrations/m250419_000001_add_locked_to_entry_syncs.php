<?php

namespace matrixcreate\copydeckimporter\migrations;

use craft\db\Migration;

/**
 * Adds a locked column to copydeck_entry_syncs.
 *
 * When locked, the entry is skipped during batch/full syncs
 * and the sidebar Sync button is disabled.
 */
class m250419_000001_add_locked_to_entry_syncs extends Migration
{
    public function safeUp(): bool
    {
        // If the table doesn't exist (e.g. Install ran before it was added),
        // create it with all columns known up to this migration.
        if (!$this->db->tableExists('{{%copydeck_entry_syncs}}')) {
            $this->createTable('{{%copydeck_entry_syncs}}', [
                'element_id' => $this->integer()->notNull(),
                'locked'     => $this->boolean()->notNull()->defaultValue(false),
                'synced_at'  => $this->dateTime()->notNull(),
                'notes'      => $this->text(),
                'PRIMARY KEY([[element_id]])',
            ]);

            $this->addForeignKey(
                null,
                '{{%copydeck_entry_syncs}}',
                'element_id',
                '{{%elements}}',
                'id',
                'CASCADE',
            );

            return true;
        }

        if ($this->db->columnExists('{{%copydeck_entry_syncs}}', 'locked')) {
            return true;
        }

        $this->addColumn('{{%copydeck_entry_syncs}}', 'locked', $this->boolean()->notNull()->defaultValue(false)->after('element_id'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%copydeck_entry_syncs}}', 'locked');

        return true;
    }
}
