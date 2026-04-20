<?php

namespace matrixcreate\copydeckimporter\migrations;

use craft\db\Migration;

/**
 * Adds a notes column to the copydeck_entry_syncs table.
 *
 * Stores aggregated block notes from Copydeck imports, displayed
 * in the sidebar widget on the entry edit screen.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class m250419_000000_add_notes_to_entry_syncs extends Migration
{
    public function safeUp(): bool
    {
        // If the table doesn't exist (e.g. Install ran before it was added),
        // create it with all columns known up to this migration.
        if (!$this->db->tableExists('{{%copydeck_entry_syncs}}')) {
            $this->createTable('{{%copydeck_entry_syncs}}', [
                'element_id' => $this->integer()->notNull(),
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

        if ($this->db->columnExists('{{%copydeck_entry_syncs}}', 'notes')) {
            return true;
        }

        $this->addColumn('{{%copydeck_entry_syncs}}', 'notes', $this->text()->after('synced_at'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%copydeck_entry_syncs}}', 'notes');

        return true;
    }
}
