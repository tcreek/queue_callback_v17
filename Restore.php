<?php
namespace FreePBX\modules\Qcallback;

use FreePBX\modules\Backup as Base;

class Restore extends Base\RestoreBase {
    public function runRestore() {
        $configs = $this->getConfigs();
        if (!empty($configs['defaultFallback'])) {
            parent::runRestore();
            return;
        }
        $this->FreePBX->Qcallback->restore($configs);
    }
}
