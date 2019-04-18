<?php

use UzerpPhinx\UzerpMigration;

class VatReturnColumnSpellingFix extends UzerpMigration
{
    public function change()
    {
        $table = $this->table('vat_return');
        $table->renameColumn('vat_due_aquisitions', 'vat_due_acquisitions')
              ->renameColumn('total_aquisitions_ex_vat', 'total_acquisitions_ex_vat')
              ->save();
    }
}
