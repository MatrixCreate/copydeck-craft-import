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
        $this->addColumn('{{%copydeck_entry_syncs}}', 'notes', $this->text()->after('synced_at'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%copydeck_entry_syncs}}', 'notes');

        return true;
    }
}
