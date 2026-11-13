<?php
namespace FreePBX\modules\Qcallback;

use FreePBX\modules\Backup as Base;

class Backup extends Base\BackupBase {
    public function runBackup($id, $transaction) {
        $this->addConfigs($this->FreePBX->Qcallback->backup());
    }
}
