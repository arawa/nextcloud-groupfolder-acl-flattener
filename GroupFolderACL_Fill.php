<?php
require_once './migrator-config.php';
require_once $nextcloudBinDir.'/lib/versioncheck.php';
require_once $nextcloudBinDir.'/lib/base.php';


use OCA\GroupFolders\ACL\RuleManager;
use OCA\GroupFolders\ACL\Rule;
use OCA\GroupFolders\Mount\MountProvider;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\Constants;

OC_App::loadApps();
// echo "All app loaded\n";

$rm = Server::get(RuleManager::class);
// echo "Get rulemanager\n";
$mountProvider = Server::get(MountProvider::class);
// echo "Get mountprovider\n";
$rootFolder = Server::get(IRootFolder::class);
// echo "Get root folder id:" . $rootFolder->getMountPoint()->getNumericStorageId() . "\n";
$filecache = $rootFolder->getMountPoint()->getStorage()->getCache();
// echo "Get filecache\n";

$rulesbypath = $rm->getAllRulesForPrefix($rootFolder->getMountPoint()->getNumericStorageId(),'__groupfolders');
// echo "all rules loaded\n";

# Copy from https://github.com/nextcloud/groupfolders/blob/master/lib/Command/ACL.php#L247C1-L256C3
function formatRulePermissions(int $mask, int $permissions): string {
  $PERMISSIONS_MAP = [
		'read' => Constants::PERMISSION_READ,
		'write' => Constants::PERMISSION_UPDATE,
		'create' => Constants::PERMISSION_CREATE,
		'delete' => Constants::PERMISSION_DELETE,
		'share' => Constants::PERMISSION_SHARE,
	];
  $result = [];
  foreach ($PERMISSIONS_MAP as $name => $value) {
    if (($mask & $value) === $value) {
      $type = ($permissions & $value) === $value ? '+' : '-';
      $result[] = $type . $name;
    }
  }
  return implode(', ', $result);
}

function findRule($path, $type, $id) {
  global $rulesbypath;
  if (array_key_exists($path,$rulesbypath)) {
    foreach($rulesbypath[$path] as $r){
      if ($r->getUserMapping()->getType() == $type && $r->getUserMapping()->getId() == $id){
        return $r;
      }
    }
  }
  return;
}

function findParentRule($rule) {
  global $filecache;
  $currentfilestr = $filecache->getPathById($rule->getFileId());

  while (true) {
    //echo "Current : " . $currentfilestr . "\n";

    // Find parent
    $parentfileid = $filecache->getParentId($currentfilestr);
    if ($parentfileid < 0){
      // No parent
      //echo "No parent\n";
      return;
    }
    $parentfilestr = $filecache->getPathById($parentfileid);
    //echo "Parent  : ". $parentfilestr . "\n";

    // Existing rule ?
    $r = findRule($parentfilestr, $rule->getUserMapping()->getType(), $rule->getUserMapping()->getId());
    if ($r) {
      return $r;
    }
    $currentfilestr = $parentfilestr;
  }
}

$nbtot = 0;
$nb = 0;
foreach($rulesbypath as $path => $rules){
  //echo "==> " . $path . "\n";
  foreach($rules as $r) {
    $nbtot++;
    if ($r->getMask() < 31) {
      $nb++;
      $rulesarr = array();
      $rulesarr[] = $r;
      $pr = $r;
      while ($pr = findParentRule($pr)) {
        $rulesarr[] = $pr;
      }
      $nr = new Rule($r->getUserMapping(), $r->getFileId(), 31, Rule::mergeRules($rulesarr)->applyPermissions(31));
      echo "Completed rules for \e[96m" . $r->getUserMapping()->getType() . '/' . $r->getUserMapping()->getId() . "\e[39m on \e[96m" . $path . "\e[39m before: \e[93m[" . formatRulePermissions($r->getMask(), $r->getPermissions()) . "]\e[39m, after: \e[92m[" . formatRulePermissions($nr->getMask(), $nr->getPermissions()) . "]\e[39m" . "\n";
	  if(!$dryRun) {
		$rm->saveRule($nr);
	  }
    }
  }
}
if($dryRun) {
	echo "\e[91mDry run mode enabled, nothing has been saved.\e[39m\n";
} else {
	echo $nb . " rules has been updated to include implicit permissions.\n";
}
echo "END\n";
