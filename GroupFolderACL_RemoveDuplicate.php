<?php
require_once './migrator-config.php';
require_once $nextcloudBinDir.'/lib/versioncheck.php';
require_once $nextcloudBinDir.'/lib/base.php';

use OCA\GroupFolders\ACL\RuleManager;
use OCA\GroupFolders\ACL\Rule;
use OCA\GroupFolders\Mount\MountProvider;
use OCP\Files\IRootFolder;
use OCP\Server;

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
    $pr = findParentRule($r);
    if (!$pr) {
      continue;
    }
    if ($pr->getPermissions() == $r->getPermissions() && $pr->getMask() == $r->getMask()){
      $nb++;
      echo "Duplicate found for \e[96m" . $r->getUserMapping()->getType() . '/' . $r->getUserMapping()->getId() . "\e[39m  between paths " . $path . " and " . $filecache->getPathById($pr->getFileId()) . "\n";
	  if(!$dryRun) {
		$rm->deleteRule($r);
	  }
    }
  }
}
if($dryRun) {
	echo "\e[91mDry run mode enabled, nothing has been deleted. (Should have deleted ".$nb." rules) \e[39m\n";
} else {
	echo $nb . " duplicated acl rules has been deleted \n";
}
echo "END\n";
