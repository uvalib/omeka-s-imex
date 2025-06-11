<?php
// Uvaimex, version 1.0.1

// path to the ini file of the two omeka-s sites
error_reporting(E_ERROR | E_WARNING | E_PARSE);
iconv_set_encoding('internal_encoding', 'UTF-8');
iconv_set_encoding('output_encoding', 'UTF-8');
set_error_handler('error_handler');
date_default_timezone_set('America/New_York');
ini_set('memory_limit','2G');


$options = getopt('',array('help', 'test', 'action:', 'siteslug:', 'file:', 'config:', ':writefiles'));

if (isset($options['help'])) {
  echo('
Uvaimex, or Omeka-s Import and Export
=====================================

This script is used in relation to Omeka-s installations, to learn more about Omeka-s check their website www.omeka.org.
Omeka-s allows mutiple sites instances within a singe installation, but does not provide an easy way to import or export singe sites from an installation to another. 
Uvaimex is able to export and then import a single Omeka-s site into separate installations.
It perform the export function by interacting directly with the Omeka-s detabases and by generating a single json file that contains all records needed for the selected site.
In order to perform the export function you need to know the siteslug of the site you want to export.
 
To list the siteslugs available:
# php ./uvaimex.php  --action="siteslug" --config="omaka-s-dabacase.ini-location"

To export a site use this sintax:
# php ./uvaimex.php --action="export" --siteslug="the-site-slug-you-want-to-export" --file="export-file-name" --config="omaka-s-dabacase.ini-location"

Once a site has been exported you can import it into another Omake-s installation.
To import a site use this sintax:
# php ./uvaimex.php  --action="import" --file="import-file-name" --config="omaka-s-dabacase.ini-location"

Because new versions of Omeka-s and modules installed might modify the database, it is recommended that both sites are upgraded to the same version of both the core Omeka-s version and the installed modules.
In addition both sites should have an identical set of mudules installed.

Additional Options and Considerations
-------------------------------------

Sometimes it might be useful to check the modules versions and active theme from the command line, this command will list the installed modules and versions in a given installation:
# php ./uvaimex.php  --action="info" --config="omaka-s-dabacase.ini-location"

This command will list the installed modules and versions stored in a given exported file:
# php ./uvaimex.php  --action="info" --file="import-file-name"

Always make sure you have a backup of the database and files of you Omeka-s installation before running this script.

When importing a site you might find an issue with writing the assets and item files in the tipical Omeka-s directory normally located in a directory (named: /files) located under the root foleder of the site (often /public_html).
This is because this directory needs to be writable by apache or nginx or whatevrr webserver is being used and might not be writable by the user running the script. In this situation you could temporarily change the permissions of this directory and allow the user running the script to write files in that directory, another option is to write the files elesewhere and move them after the script has ran, consult with your IT to detrmine what is teh best option.
If you choose to write the files in a different location and move then later you need to add an additional configuration option when running the import script.

# php ./uvaimex.php  --action="import" --file="import-file-name" --config="omaka-s-dabacase.ini-location" --writefiles="directory-where-the-files-should-be-written"

Another possible issue you might encounter is an out of memory error, which would look something like this: "PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 42426368 bytes)".
This kind of error can occur when a site you are exporting is too large compared to the amount of memory allocated to php in your system.
In this case you will need to edit the uvaimex.php itself and increase the value of line 9 "ini_set(\'memory_limit\',\'1G\');"
You can change 1G to 2G or more as long as your system has sufficient physical memory.
');
  exit;
}

// db class
class transfer_db {
  public $db;
  private $pref;
  function __construct($pref) {
    $this->pref = $pref;
    $this->db = new PDO('mysql:host='.$this->pref['host'].';dbname='.$this->pref['dbname'].';charset=utf8', $this->pref['user'], $this->pref['password'],array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
  }
}

// guid
function _generate_guid($num=32) {
  return bin2hex(openssl_random_pseudo_bytes($num));
}

// preferences class
function _read_pref($ini) {
  return parse_ini_file($ini,true);
}




// no need for a config file if i'm only trying to get info on a previously exported json file
if (isset($options['action']) && $options['action'] == 'info' && isset($options['file'])) {
  $json_file = file_get_contents($options['file']);
  $data = json_decode($json_file, true);

  if (!isset($data['module'])) {
    trigger_error('No module data available, not a valid uvaimex export file?', E_USER_ERROR);
    die;
  }

  $info = 'List of modules:'."\n\n";
  foreach ($data['module'] as $module) {
    $info .= '`module` => "'.$module['module'].'" `version` => "'.$module['version'].'"'."\n";
  }
  
  if (isset($data['theme'])) {
    $info .= "\n\n".'Theme info:'."\n";
    foreach ($data['theme'] as $theme) {
      foreach ($theme as $key => $value) {
        $info .= "$key => $value\n";
      }
    }
  }
  echo($info."\n\n");
  exit;

}




// checkig for database.ini file
if (!isset($options['config'])) {
  trigger_error('The location of the Omeka-s installation database.ini file is required, it it usually under [omeka-s root]confg/database.ini', E_USER_ERROR);
  die;
} 
if (!is_file($options['config'])) {
  trigger_error('The location of the Omeka-s installation database.ini file you provided is incorrect', E_USER_ERROR);
  die;
}
if (!is_readable($options['config'])) {
  trigger_error('The database.ini file you provided is not readable by this script.', E_USER_ERROR);
  die;
}

// database.ini file seems to be ok, we will parse it
$preferences = _read_pref($options['config']);






$dbsetup = new transfer_db($preferences);
$db = $dbsetup->db;

if (!isset($options['action'])) {
  trigger_error('A --action="" option must be set in order for this script to know what to do, valid optins are: siteslug, export, import', E_USER_ERROR);
  die;
} 


if (isset($options['action']) && !in_array($options['action'], array('siteslug','export','import','info'))) {
  trigger_error('--action="" valid options are: siteslug, export, import. Please check your value.', E_USER_ERROR);
  die;
} 

if ($options['action'] == 'siteslug') {
  $siteslug = 'List of site slugs and corresponding titles:'."\n\n";
  $query = $db->prepare("SELECT `slug`, `title` FROM `site`");
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $siteslug .= '`slug` => "'.$row['slug'].'" `title` => "'.$row['title'].'"'."\n";
    }
  }
  echo($siteslug."\n");
  exit;
}



if ($options['action'] == 'info') {
  if (isset($options['file'])) {
    // if file is set we want to report on then file, not the site, so here we return ''
    return '';
  }
  $info = 'List of installed modules:'."\n\n";
  $query = $db->prepare("SELECT * FROM `module` WHERE `is_active` = 1");
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $info .= '`module` => "'.$row['id'].'" `version` => "'.$row['version'].'"'."\n";
    }
  }
  $query = $db->prepare("SELECT `title`, `theme` FROM `site`");
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $info .= "\n".'Theme for site: '.$row['title']."\n";
      foreach (_get_theme_info($row['theme']) as $key => $value) {
        $info .= "$key => $value\n";
      }
    }
  }
 
  
  
  echo($info."\n");
  exit;
}


if (!isset($options['file'])) {
  trigger_error('A --file="" option must be set in order for this script to export or import', E_USER_ERROR);
  die;
}




if ($options['action'] == 'export') {
  $data = uvaimex_export($options,$db,$preferences);
  // write the json export file
  file_put_contents($options['file'],json_encode($data));
  exit;

}


if ($options['action'] == 'import') {
  uvaimex_import($options,$db,$preferences);
}




function uvaimex_export($options,$db,$preferences) {
  if (!isset($options['siteslug'])) {
    trigger_error('A valid --siteslug="" option must be set in order for this script to export the appropriate site', E_USER_ERROR);
    die;
  }

  // data array
  $data = array();
  
  
  // modules
  $data['module'] = array();
  $query = $db->prepare("SELECT * FROM `module` WHERE `is_active` = 1");
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $data['module'][$row['id']] = array('module' => $row['id'], 'version' => $row['version']);
    }
  }









  
  // create (if needed) the uvaimexguid table
  $guid = _create_uvaimexguid_table($db,$preferences);
  
  // these array relate the newly created guid to the regular omeka id and viceversa
  $guid_to_id = array();
  $id_to_guid = array();

  // these arrays keeps a list of records that shuld be included in our data export since they are related to the site directly or indirectly
  $item_set_id = array();
  $site_page_id = array();
  $item_id = array();
  $media_id = array();
  $block_id = array();
  $asset_id = array();
  $user_id = array();
  $resource_class_id = array();
  $resource_template_id = array();
  $vocabulary_id = array();
  $property_id = array();
  
  
  $data['site'] = array();
  $data['theme'] = array();
  $query = $db->prepare("SELECT * FROM `site` WHERE `slug` = :slug");
  $query->bindValue(':slug', $options['siteslug'], PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $data['site'][$row['id']] = $row;
      if ($row['owner_id'] != NULL) {
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
      if ($row['thumbnail_id'] != NULL) {
        $asset_id[$row['thumbnail_id']] = $row['thumbnail_id'];
      }
      // we will use this value
      $site_id = $row['id'];
      $data['theme'] = _get_theme_info($row['theme']);
    }
  }
  
  if (count($data['site']) == 0) {
    trigger_error('The siteslug provided was not found in this installation.', E_USER_ERROR);
    die;
  }

  
  // insert site into uvaimexguid table
  foreach ($data['site'] as $key => $value) {
    $data['site'][$key]['site_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'site' ");
    $query->bindValue(':related_id', $data['site'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site'][$key]['site_guid'] = $row['guid'];
      }
    }
    if ($data['site'][$key]['site_guid'] === false) {
      $data['site'][$key]['site_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'site' ");
      $query->bindValue(':guid', $data['site'][$key]['site_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['site'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site'][$data['site'][$key]['site_guid']] = $data['site'][$key]['id'];
    $id_to_guid['site'][$data['site'][$key]['id']] = $data['site'][$key]['site_guid'];
    if ($value['thumbnail_id'] != NULL) {
      $asset_id[$value['thumbnail_id']] = $value['thumbnail_id'];
    }
    if ($value['owner_id'] != NULL) {
      $user_id[$value['owner_id']] = $value['owner_id'];
    }
    $navigation = json_decode($data['site'][$key]['navigation'],true);
    foreach ($navigation as $ky => $nav) {
      if ($nav['type'] == 'browse') {
        if (preg_match('/^item_set_id%5B%5D=(\d+)/', $nav['data']['query'], $match)) {
          $item_set_id[$match[1]] = $match[1];
        }
      }
    }
    unset($navigation);
  }

  
  
  
  // site_item_set
  $data['site_item_set'] = array();
  $query = $db->prepare("SELECT * FROM `site_item_set` WHERE `site_id` = :site_id");
  $query->bindValue(':site_id', $site_id, PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $item_set_id[$row['item_set_id']] = $row['item_set_id'];
      $data['site_item_set'][$row['id']] = $row;
    }
  }

  // insert site_item_set into uvaimexguid table
  foreach ($data['site_item_set'] as $key => $value) {
    $data['site_item_set'][$key]['site_item_set_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'site_item_set' ");
    $query->bindValue(':related_id', $data['site_item_set'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site_item_set'][$key]['site_item_set_guid'] = $row['guid'];
      }
    }
    if ($data['site_item_set'][$key]['site_item_set_guid'] === false) {
      $data['site_item_set'][$key]['site_item_sete_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'site_item_set' ");
      $query->bindValue(':guid', $data['site_item_set'][$key]['site_item_set_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['site_item_set'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site_item_set'][$data['site_item_set'][$key]['site_item_set_guid']] = $data['site_item_set'][$key]['id'];
    $id_to_guid['site_item_set'][$data['site_item_set'][$key]['id']] = $data['site_item_set'][$key]['site_item_set_guid'];
  }










  
  // site_page
  $data['site_page'] = array();
  $query = $db->prepare("SELECT * FROM `site_page` WHERE `site_id` = :site_id");
  $query->bindValue(':site_id', $site_id, PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $site_page_id[$row['id']] = $row['id'];
      $data['site_page'][$row['id']] = $row;
    }
  }



  // insert site_page into uvaimexguid table
  foreach ($data['site_page'] as $key => $value) {
    $data['site_page'][$key]['site_page_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'site_page' ");
    $query->bindValue(':related_id', $data['site_page'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site_page'][$key]['site_page_guid'] = $row['guid'];
      }
    }
    if ($data['site_page'][$key]['site_page_guid'] === false) {
      $data['site_page'][$key]['site_page_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'site_page' ");
      $query->bindValue(':guid', $data['site_page'][$key]['site_page_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['site_page'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site_page'][$data['site_page'][$key]['site_page_guid']] = $data['site_page'][$key]['id'];
    $id_to_guid['site_page'][$data['site_page'][$key]['id']] = $data['site_page'][$key]['site_page_guid'];
  }







  // site_page_block
  $data['site_page_block'] = array();
  if (count($site_page_id) > 0) {
    $query = $db->prepare("SELECT * FROM `site_page_block` WHERE `page_id` IN (".implode(',', $site_page_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $block_id[$row['id']] = $row['id'];
        $data['site_page_block'][$row['id']] = $row;
      }
    }
  }

  // insert site_page_block into uvaimexguid table
  foreach ($data['site_page_block'] as $key => $value) {
    $data['site_page_block'][$key]['site_page_block_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'site_page_block' ");
    $query->bindValue(':related_id', $data['site_page_block'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site_page_block'][$key]['site_page_block_guid'] = $row['guid'];
      }
    }
    if ($data['site_page_block'][$key]['site_page_block_guid'] === false) {
      $data['site_page_block'][$key]['site_page_block_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'site_page_block' ");
      $query->bindValue(':guid', $data['site_page_block'][$key]['site_page_block_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['site_page_block'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site_page_block'][$data['site_page_block'][$key]['site_page_block_guid']] = $data['site_page_block'][$key]['id'];
    $id_to_guid['site_page_block'][$data['site_page_block'][$key]['id']] = $data['site_page_block'][$key]['site_page_block_guid'];
    
    if ($data['site_page_block'][$key]['layout'] == 'asset') {
      foreach (json_decode($data['site_page_block'][$key]['data'], true) as $a) {
        $asset_id[$a['id']] = $a['id'];
      }
    }  
  }


  
  
  
  // site_block_attachment
  $data['site_block_attachment'] = array();
  if (count($block_id) > 0) {
    $query = $db->prepare("SELECT * FROM `site_block_attachment` WHERE `block_id` IN (".implode(',', $block_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
       if (is_numeric($row['item_id'])) {
          $item_id[$row['item_id']] = $row['item_id'];
        }
        if (is_numeric($row['media_id'])) {
          $media_id[$row['media_id']] = $row['media_id'];
        }
        $data['site_block_attachment'][$row['id']] = $row;
      }
    }
  }
  // insert site_block_attachment into uvaimexguid table
  foreach ($data['site_block_attachment'] as $key => $value) {
    $data['site_block_attachment'][$key]['site_block_attachment_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'site_block_attachment' ");
    $query->bindValue(':related_id', $data['site_block_attachment'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site_block_attachment'][$key]['site_block_attachment_guid'] = $row['guid'];
      }
    }
    if ($data['site_block_attachment'][$key]['site_block_attachment_guid'] === false) {
      $data['site_block_attachment'][$key]['site_block_attachment_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'site_block_attachment' ");
      $query->bindValue(':guid', $data['site_block_attachment'][$key]['site_block_attachment_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['site_block_attachment'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site_block_attachment'][$data['site_block_attachment'][$key]['site_block_attachment_guid']] = $data['site_block_attachment'][$key]['id'];
    $id_to_guid['site_block_attachment'][$data['site_block_attachment'][$key]['id']] = $data['site_block_attachment'][$key]['site_block_attachment_guid'];
  }







  // site_setting
  $data['site_setting'] = array();
  $query = $db->prepare("SELECT * FROM `site_setting` WHERE `site_id` = :site_id");
  $query->bindValue(':site_id', $site_id, PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      if ($row['id'] == 'theme_settings_uva') {
        $theme_settings = json_decode($row['value'], true);
        if (isset($theme_settings['banner']) && is_numeric($theme_settings['banner'])) {
          $asset_id[$theme_settings['banner']] = $theme_settings['banner'];
        }
      }
      $data['site_setting'][$row['id']] = $row;
       
    }
  }
  // insert site_setting into uvaimexguid table
  foreach ($data['site_setting'] as $key => $value) {
    $data['site_setting'][$key]['site_setting_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `related_varchar` = :related_varchar AND `relatedID` = :relatedID AND `related_table` = 'site_setting' ");
    $query->bindValue(':related_varchar', $data['site_setting'][$key]['id'], PDO::PARAM_STR);
    $query->bindValue(':relatedID', $data['site_setting'][$key]['site_id'], PDO::PARAM_STR);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['site_setting'][$key]['site_setting_guid'] = $row['guid'];
      }
    }
    if ($data['site_setting'][$key]['site_setting_guid'] === false) {
      $data['site_setting'][$key]['site_setting_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `related_varchar` = :related_varchar, `relatedID` = :relatedID, `related_table` = 'site_setting' ");
      $query->bindValue(':guid', $data['site_setting'][$key]['site_setting_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_varchar', $data['site_setting'][$key]['id'], PDO::PARAM_STR);
      $query->bindValue(':relatedID', $data['site_setting'][$key]['site_id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['site_setting'][$data['site_setting'][$key]['site_setting_guid']] = $data['site_setting'][$key]['id'];
    $id_to_guid['site_setting'][$data['site_setting'][$key]['id']] = $data['site_setting'][$key]['site_setting_guid'];
  }
  















  
  // item_set
  $data['item_set'] = array();
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `item_set` WHERE `id` IN (".implode(',', $item_set_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['item_set'][$row['id']] = $row;
      }
    }
  }
  // insert item_set into uvaimexguid table
  /* We are doing this later
  foreach ($data['item_set'] as $key => $value) {
    $data['item_set'][$key]['item_set_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'item_set' ");
    $query->bindValue(':related_id', $data['item_set'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['item_set'][$key]['item_set_guid'] = $row['guid'];
      }
    }
    if ($data['item_set'][$key]['item_set_guid'] === false) {
      $data['item_set'][$key]['item_set_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'item_set' ");
      $query->bindValue(':guid', $data['item_set'][$key]['item_set_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['item_set'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['item_set'][$data['item_set'][$key]['item_set_guid']] = $data['item_set'][$key]['id'];
    $id_to_guid['item_set'][$data['item_set'][$key]['id']] = $data['item_set'][$key]['item_set_guid'];
  }
  */







  
  
  
  // item_item_set
  $data['item_item_set'] = array();
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `item_item_set` WHERE `item_set_id` IN (".implode(',', $item_set_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $item_id[$row['item_id']] = $row['item_id'];
        $data['item_item_set'][$row['item_id'].'-'.$row['item_set_id']] = $row;
      }
    }
  }


  // item_site
  $data['item_site'] = array();
  $query = $db->prepare("SELECT * FROM `item_site` WHERE `site_id` = :site_id");
  $query->bindValue(':site_id', $site_id, PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $item_id[$row['item_id']] = $row['item_id'];
      $data['item_site'][] = $row;
    }
  }


  
  // more item_item_set
  //$data['item_item_set'] = array(); we are now adding to it
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `item_item_set` WHERE `item_id` IN (".implode(',', $item_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $item_set_id[$row['item_set_id']] = $row['item_set_id'];
        $data['item_item_set'][$row['item_id'].'-'.$row['item_set_id']] = $row;
      }
    }
  }








  // item_set second round
  //$data['item_set'] = array();
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `item_set` WHERE `id` IN (".implode(',', $item_set_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['item_set'][$row['id']] = $row;
      }
    }
  }
  // insert item_set into uvaimexguid table
  foreach ($data['item_set'] as $key => $value) {
    $data['item_set'][$key]['item_set_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'item_set' ");
    $query->bindValue(':related_id', $data['item_set'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['item_set'][$key]['item_set_guid'] = $row['guid'];
      }
    }
    if ($data['item_set'][$key]['item_set_guid'] === false) {
      $data['item_set'][$key]['item_set_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'item_set' ");
      $query->bindValue(':guid', $data['item_set'][$key]['item_set_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['item_set'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['item_set'][$data['item_set'][$key]['item_set_guid']] = $data['item_set'][$key]['id'];
    $id_to_guid['item_set'][$data['item_set'][$key]['id']] = $data['item_set'][$key]['item_set_guid'];
  }






  // item
  $data['item'] = array();
  if (count($item_id) > 0) {
    $query = $db->prepare("SELECT * FROM `item` WHERE `id` IN (".implode(',', $item_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($row['primary_media_id'] != null) {
          $media_id[$row['primary_media_id']] = $row['primary_media_id'];
        }
        $data['item'][$row['id']] = $row;
      }
    }
  }
  // insert item into uvaimexguid table
  foreach ($data['item'] as $key => $value) {
    $data['item'][$key]['item_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'item' ");
    $query->bindValue(':related_id', $data['item'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['item'][$key]['item_guid'] = $row['guid'];
      }
    }
    if ($data['item'][$key]['item_guid'] === false) {
      $data['item'][$key]['item_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'item' ");
      $query->bindValue(':guid', $data['item'][$key]['item_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['item'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['item'][$data['item'][$key]['item_guid']] = $data['item'][$key]['id'];
    $id_to_guid['item'][$data['item'][$key]['id']] = $data['item'][$key]['item_guid'];
  }
  
  
  // media
  $data['media'] = array();
  if (count($item_id) > 0) {
    $query = $db->prepare("SELECT * FROM `media` WHERE `item_id` IN (".implode(',', $item_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if (is_numeric($row['id'])) {
          $media_id[$row['id']] = $row['id'];
        }
        $data['media'][$row['id']] = $row;
      }
    }
  }
  if (count($media_id) > 0) {
    $query = $db->prepare("SELECT * FROM `media` WHERE `id` IN (".implode(',', $media_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['media'][$row['id']] = $row;
      }
    }
  }
  // insert media into uvaimexguid table
  foreach ($data['media'] as $key => $value) {
    $data['media'][$key]['media_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'media' ");
    $query->bindValue(':related_id', $data['media'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['media'][$key]['media_guid'] = $row['guid'];
      }
    }
    if ($data['media'][$key]['media_guid'] === false) {
      $data['media'][$key]['media_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'media' ");
      $query->bindValue(':guid', $data['media'][$key]['media_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['media'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['media'][$data['media'][$key]['media_guid']] = $data['media'][$key]['id'];
    $id_to_guid['media'][$data['media'][$key]['id']] = $data['media'][$key]['media_guid'];
  }

  // resource
  // resource is particular and we run it three times:
  // Omeka\Entity\ItemSet
  // Omeka\Entity\Item
  // Omeka\Entity\Media
  $data['resource_item'] = array();
  $data['resource_item_set'] = array();
  $data['resource_media'] = array();
  if (count($item_id) > 0) {
    $sql = "SELECT * FROM `resource` WHERE `id` IN (".implode(',', $item_id).") AND `resource_type` LIKE '%Item' ";
    //echo($sql);
    $query = $db->prepare($sql);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_item'][$row['id']] = $row;
        if ($row['owner_id'] != NULL) {
          $user_id[$row['owner_id']] = $row['owner_id'];
        }
        if ($row['thumbnail_id'] != NULL) {
          $asset_id[$row['thumbnail_id']] = $row['thumbnail_id'];
        }
        if ($row['resource_class_id'] != NULL) {
          $resource_class_id[$row['resource_class_id']] = $row['resource_class_id'];
        }
        if ($row['resource_template_id'] != NULL) {
          $resource_template_id[$row['resource_template_id']] = $row['resource_template_id'];
        }
      }
    }
  }
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `resource` WHERE `id` IN (".implode(',', $item_set_id).") AND `resource_type` LIKE '%ItemSet' ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_item_set'][$row['id']] = $row;
        if ($row['owner_id'] != NULL) {
          $user_id[$row['owner_id']] = $row['owner_id'];
        }
        if ($row['thumbnail_id'] != NULL) {
          $asset_id[$row['thumbnail_id']] = $row['thumbnail_id'];
        }
        if ($row['resource_class_id'] != NULL) {
          $resource_class_id[$row['resource_class_id']] = $row['resource_class_id'];
        }
        if ($row['resource_template_id'] != NULL) {
          $resource_template_id[$row['resource_template_id']] = $row['resource_template_id'];
        }
      }
    }
  }
  if (count($media_id) > 0) {
    $sql = "SELECT * FROM `resource` WHERE `id` IN (".implode(',', $media_id).") AND `resource_type` LIKE '%Media' ";
//    echo($sql);
    $query = $db->prepare($sql);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_media'][$row['id']] = $row;
        if ($row['owner_id'] != NULL) {
          $user_id[$row['owner_id']] = $row['owner_id'];
        }
        if ($row['thumbnail_id'] != NULL) {
          $asset_id[$row['thumbnail_id']] = $row['thumbnail_id'];
        }
        if ($row['resource_class_id'] != NULL) {
          $resource_class_id[$row['resource_class_id']] = $row['resource_class_id'];
        }
        if ($row['resource_template_id'] != NULL) {
          $resource_template_id[$row['resource_template_id']] = $row['resource_template_id'];
        }
      }
    }
  }
  // insert resource into uvaimexguid table
  foreach ($data['resource_item'] as $key => $value) {
    $data['resource_item'][$key]['resource_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource' AND `related_varchar` = 'item' ");
    $query->bindValue(':related_id', $data['resource_item'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_item'][$key]['resource_guid'] = $row['guid'];
      }
    }
    if ($data['resource_item'][$key]['resource_guid'] === false) {
      $data['resource_item'][$key]['resource_guid'] = $id_to_guid['item'][$data['resource_item'][$key]['id']];
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource', `related_varchar` = 'item' ");
      $query->bindValue(':guid', $data['resource_item'][$key]['resource_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_item'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_item'][$data['resource_item'][$key]['resource_guid']] = $data['resource_item'][$key]['id'];
    $id_to_guid['resource_item'][$data['resource_item'][$key]['id']] = $data['resource_item'][$key]['resource_guid'];
  }

  foreach ($data['resource_item_set'] as $key => $value) {
    $data['resource_item_set'][$key]['resource_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource' AND `related_varchar` = 'item_set' ");
    $query->bindValue(':related_id', $data['resource_item_set'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_item_set'][$key]['resource_guid'] = $row['guid'];
      }
    }
    if ($data['resource_item_set'][$key]['resource_guid'] === false) {
      $data['resource_item_set'][$key]['resource_guid'] = $id_to_guid['item_set'][$data['resource_item_set'][$key]['id']];
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource', `related_varchar` = 'item_set' ");
      $query->bindValue(':guid', $data['resource_item_set'][$key]['resource_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_item_set'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_item_set'][$data['resource_item_set'][$key]['resource_guid']] = $data['resource_item_set'][$key]['id'];
    $id_to_guid['resource_item_set'][$data['resource_item_set'][$key]['id']] = $data['resource_item_set'][$key]['resource_guid'];
  }


  foreach ($data['resource_media'] as $key => $value) {
    $data['resource_media'][$key]['resource_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource' AND `related_varchar` = 'media' ");
    $query->bindValue(':related_id', $data['resource_media'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_media'][$key]['resource_guid'] = $row['guid'];
      }
    }
    if ($data['resource_media'][$key]['resource_guid'] === false) {
      $data['resource_media'][$key]['resource_guid'] = $id_to_guid['media'][$data['resource_media'][$key]['id']];
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource', `related_varchar` = 'media' ");
      $query->bindValue(':guid', $data['resource_media'][$key]['resource_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_media'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_media'][$data['resource_media'][$key]['resource_guid']] = $data['resource_media'][$key]['id'];
    $id_to_guid['resource_media'][$data['resource_media'][$key]['id']] = $data['resource_media'][$key]['resource_guid'];
  }




 


  // resource_template
  $data['resource_template'] = array();
  if (count($resource_template_id) > 0) {
    $query = $db->prepare("SELECT * FROM `resource_template` WHERE `id` IN (".implode(',', $resource_template_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
       if ($row['resource_class_id'] != NULL) {
          $resource_class_id[$row['resource_class_id']] = $row['resource_class_id'];
        }
        $resource_template_id[$row['id']] = $row['id'];
        $data['resource_template'][$row['id']] = $row;
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  // insert resource_template into uvaimexguid table
  foreach ($data['resource_template'] as $key => $value) {
    $data['resource_template'][$key]['resource_template_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource_template' ");
    $query->bindValue(':related_id', $data['resource_template'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_template'][$key]['resource_template_guid'] = $row['guid'];
      }
    }
    if ($data['resource_template'][$key]['resource_template_guid'] === false) {
      $data['resource_template'][$key]['resource_template_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource_template' ");
      $query->bindValue(':guid', $data['resource_template'][$key]['resource_template_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_template'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_template'][$data['resource_template'][$key]['resource_template_guid']] = $data['resource_template'][$key]['id'];
    $id_to_guid['resource_template'][$data['resource_template'][$key]['id']] = $data['resource_template'][$key]['resource_template_guid'];
  }



  // resource_class
  $data['resource_class'] = array();
  if (count($resource_class_id) > 0) {
    $query = $db->prepare("SELECT * FROM `resource_class` WHERE `id` IN (".implode(',', $resource_class_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $resource_class_id[$row['id']] = $row['id'];
        $data['resource_class'][$row['id']] = $row;
        $vocabulary_id[$row['vocabulary_id']] = $row['vocabulary_id'];
      }
    }
  }
  // insert resource_class into uvaimexguid table
  foreach ($data['resource_class'] as $key => $value) {
    $data['resource_class'][$key]['resource_class_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource_class' ");
    $query->bindValue(':related_id', $data['resource_class'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_class'][$key]['resource_class_guid'] = $row['guid'];
      }
    }
    if ($data['resource_class'][$key]['resource_class_guid'] === false) {
      $data['resource_class'][$key]['resource_class_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource_class' ");
      $query->bindValue(':guid', $data['resource_class'][$key]['resource_class_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_class'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_class'][$data['resource_class'][$key]['resource_class_guid']] = $data['resource_class'][$key]['id'];
    $id_to_guid['resource_class'][$data['resource_class'][$key]['id']] = $data['resource_class'][$key]['resource_class_guid'];
  }





  // resource_template_property
  $data['resource_template_property'] = array();
  if (count($resource_template_id) > 0) {
    $query = $db->prepare("SELECT * FROM `resource_template_property` WHERE `resource_template_id` IN (".implode(',', $resource_template_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $resource_template_property_id[$row['id']] = $row['id'];
        $data['resource_template_property'][$row['id']] = $row;
        $property_id[$row['property_id']] = $row['property_id'];
      }
    }
  }
  // insert resource_template_property into uvaimexguid table
  foreach ($data['resource_template_property'] as $key => $value) {
    $data['resource_template_property'][$key]['resource_template_property_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'resource_template_property' ");
    $query->bindValue(':related_id', $data['resource_template_property'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['resource_template_property'][$key]['resource_template_property_guid'] = $row['guid'];
      }
    }
    if ($data['resource_template_property'][$key]['resource_template_property_guid'] === false) {
      $data['resource_template_property'][$key]['resource_template_property_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'resource_template_property' ");
      $query->bindValue(':guid', $data['resource_template_property'][$key]['resource_template_property_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['resource_template_property'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['resource_template_property'][$data['resource_template_property'][$key]['resource_template_property_guid']] = $data['resource_template_property'][$key]['id'];
    $id_to_guid['resource_template_property'][$data['resource_template_property'][$key]['id']] = $data['resource_template_property'][$key]['resource_template_property_guid'];
  }






  // property
  $data['property'] = array();
  if (count($property_id) > 0) {
    $query = $db->prepare("SELECT * FROM `property` WHERE `id` IN (".implode(',', $property_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $property_id[$row['id']] = $row['id'];
        $data['property'][$row['id']] = $row;
        $vocabulary_id[$row['vocabulary_id']] = $row['vocabulary_id'];
      }
    }
  }
  // insert property into uvaimexguid table
  foreach ($data['property'] as $key => $value) {
    $data['property'][$key]['property_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'property' ");
    $query->bindValue(':related_id', $data['property'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['property'][$key]['property_guid'] = $row['guid'];
      }
    }
    if ($data['property'][$key]['property_guid'] === false) {
      $data['property'][$key]['property_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'property' ");
      $query->bindValue(':guid', $data['property'][$key]['property_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['property'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['property'][$data['property'][$key]['property_guid']] = $data['property'][$key]['id'];
    $id_to_guid['property'][$data['property'][$key]['id']] = $data['property'][$key]['property_guid'];
  }





  // vocabulary
  $data['vocabulary'] = array();
  if (count($vocabulary_id) > 0) {
    $query = $db->prepare("SELECT * FROM `vocabulary` WHERE `id` IN (".implode(',', $vocabulary_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $vocabulary_id[$row['id']] = $row['id'];
        $data['vocabulary'][$row['id']] = $row;
        if ($row['owner_id'] != NULL) {
          $user_id[$row['owner_id']] = $row['owner_id'];
        }
      }
    }
  }
  // insert vocabulary into uvaimexguid table
  foreach ($data['vocabulary'] as $key => $value) {
    $data['vocabulary'][$key]['vocabulary_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'vocabulary' ");
    $query->bindValue(':related_id', $data['vocabulary'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['vocabulary'][$key]['vocabulary_guid'] = $row['guid'];
      }
    }
    if ($data['vocabulary'][$key]['vocabulary_guid'] === false) {
      $data['vocabulary'][$key]['vocabulary_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'vocabulary' ");
      $query->bindValue(':guid', $data['vocabulary'][$key]['vocabulary_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['vocabulary'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['vocabulary'][$data['vocabulary'][$key]['vocabulary_guid']] = $data['vocabulary'][$key]['id'];
    $id_to_guid['vocabulary'][$data['vocabulary'][$key]['id']] = $data['vocabulary'][$key]['vocabulary_guid'];
  }








  // asset
  $data['asset'] = array();
  if (count($asset_id) > 0) {
    $query = $db->prepare("SELECT * FROM `asset` WHERE `id` IN (".implode(',', $asset_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['asset'][$row['id']] = $row;
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  

  // insert media into uvaimexguid table
  foreach ($data['asset'] as $key => $value) {
    $data['asset'][$key]['asset_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'asset' ");
    $query->bindValue(':related_id', $data['asset'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['asset'][$key]['asset_guid'] = $row['guid'];
      }
    }
    if ($data['asset'][$key]['asset_guid'] === false) {
      $data['asset'][$key]['asset_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'asset' ");
      $query->bindValue(':guid', $data['asset'][$key]['asset_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['asset'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['asset'][$data['asset'][$key]['asset_guid']] = $data['asset'][$key]['id'];
    $id_to_guid['asset'][$data['asset'][$key]['id']] = $data['asset'][$key]['asset_guid'];
  }



  // user
  $data['user'] = array();
  if (count($user_id) > 0) {
    $query = $db->prepare("SELECT * FROM `user` WHERE `id` IN (".implode(',', $user_id).") ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['user'][$row['id']] = $row;
      }
    }
  }
  // insert media into uvaimexguid table
  foreach ($data['user'] as $key => $value) {
    $data['user'][$key]['user_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'user' ");
    $query->bindValue(':related_id', $data['user'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['user'][$key]['user_guid'] = $row['guid'];
      }
    }
    if ($data['user'][$key]['user_guid'] === false) {
      $data['user'][$key]['user_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'user' ");
      $query->bindValue(':guid', $data['user'][$key]['user_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['user'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['user'][$data['user'][$key]['user_guid']] = $data['user'][$key]['id'];
    $id_to_guid['user'][$data['user'][$key]['id']] = $data['user'][$key]['user_guid'];
  }






  // user_setting
  $data['user_setting'] = array();
  $query = $db->prepare("SELECT * FROM `user_setting` WHERE `user_id` IN (".implode(',', $user_id).")");
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      $data['user_setting'][$row['id']] = $row;
    }
  }
  // insert user_setting into uvaimexguid table
  foreach ($data['user_setting'] as $key => $value) {
    $data['user_setting'][$key]['user_setting_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `related_varchar` = :related_varchar AND `related_table` = 'user_setting' AND `relatedID` = :relatedID ");
    $query->bindValue(':related_varchar', $data['user_setting'][$key]['id'], PDO::PARAM_STR);
    $query->bindValue(':relatedID', $data['user_setting'][$key]['user_id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['user_setting'][$key]['user_setting_guid'] = $row['guid'];
      }
    }
    if ($data['user_setting'][$key]['user_setting_guid'] === false) {
      $data['user_setting'][$key]['user_setting_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `related_varchar` = :related_varchar, `relatedID` = :relatedID, `related_table` = 'user_setting' ");
      $query->bindValue(':guid', $data['user_setting'][$key]['user_setting_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_varchar', $data['user_setting'][$key]['id'], PDO::PARAM_STR);
      $query->bindValue(':relatedID', $data['user_setting'][$key]['user_id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['user_setting'][$data['user_setting'][$key]['user_setting_guid']] = $data['user_setting'][$key]['id'];
    $id_to_guid['user_setting'][$data['user_setting'][$key]['id']] = $data['user_setting'][$key]['user_setting_guid'];
  }




  // fulltext_search
  $data['fulltext_search'] = array();
  if (count($site_page_id) > 0) {
    $query = $db->prepare("SELECT * FROM `fulltext_search` WHERE `id` IN (".implode(',', $site_page_id).") AND `resource` = 'site_pages' ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['fulltext_search'][$row['id']] = $row;
        $data['fulltext_search'][$row['id']]['site_page_guid'] = $id_to_guid['site_page'][$row['id']];
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  if (count($item_id) > 0) {
    $query = $db->prepare("SELECT * FROM `fulltext_search` WHERE `id` IN (".implode(',', $item_id).") AND `resource` = 'items' ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['fulltext_search'][$row['id']] = $row;
        $data['fulltext_search'][$row['id']]['item_guid'] = $id_to_guid['item'][$row['id']];
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  if (count($media_id) > 0) {
    $query = $db->prepare("SELECT * FROM `fulltext_search` WHERE `id` IN (".implode(',', $media_id).") AND `resource` = 'media' ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['fulltext_search'][$row['id']] = $row;
        $data['fulltext_search'][$row['id']]['media_guid'] = $id_to_guid['media'][$row['id']];
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  if (count($item_set_id) > 0) {
    $query = $db->prepare("SELECT * FROM `fulltext_search` WHERE `id` IN (".implode(',', $item_set_id).") AND `resource` = 'item_sets' ");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['fulltext_search'][$row['id']] = $row;
        $data['fulltext_search'][$row['id']]['item_set_guid'] = $id_to_guid['item_set'][$row['id']];
        $user_id[$row['owner_id']] = $row['owner_id'];
      }
    }
  }
  

  // insert fulltext_search into uvaimexguid table
  foreach ($data['fulltext_search'] as $key => $value) {
    $data['fulltext_search'][$key]['fulltext_search_guid'] = false;
    $query = $db->prepare("SELECT `guid` FROM `uvaimexguid` WHERE `relatedID` = :related_id AND `related_table` = 'fulltext_search' ");
    $query->bindValue(':related_id', $data['fulltext_search'][$key]['id'], PDO::PARAM_INT);
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $data['fulltext_search'][$key]['fulltext_search_guid'] = $row['guid'];
      }
    }
    if ($data['fulltext_search'][$key]['fulltext_search_guid'] === false) {
      $data['fulltext_search'][$key]['fulltext_search_guid'] = $guid.'-'._generate_guid();
      $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :related_id, `related_table` = 'fulltext_search' ");
      $query->bindValue(':guid', $data['fulltext_search'][$key]['fulltext_search_guid'], PDO::PARAM_STR);
      $query->bindValue(':related_id', $data['fulltext_search'][$key]['id'], PDO::PARAM_INT);
      $query->execute();
    }
    $guid_to_id['fulltext_search'][$data['fulltext_search'][$key]['fulltext_search_guid']] = $data['fulltext_search'][$key]['id'];
    $id_to_guid['fulltext_search'][$data['fulltext_search'][$key]['id']] = $data['fulltext_search'][$key]['fulltext_search_guid'];
  }





  // remove all id's and replace them with guid
  foreach ($data['site'] as $key => $value) {
    $data['site'][$id_to_guid['site'][$key]] = $value;
    $data['site'][$id_to_guid['site'][$key]]['site_guid'] = $id_to_guid['site'][$value['id']];
    $data['site'][$id_to_guid['site'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    if ($value['thumbnail_id'] != NULL) {
      $data['site'][$id_to_guid['site'][$key]]['media_guid'] = $id_to_guid['media'][$value['thumbnail_id']];
    } else {
      $data['site'][$id_to_guid['site'][$key]]['media_guid'] = false;
    }
    if ($value['homepage_id'] != NULL) {
      $data['site'][$id_to_guid['site'][$key]]['site_page_guid'] = $id_to_guid['site_page'][$value['homepage_id']];
    } else {
      $data['site'][$id_to_guid['site'][$key]]['site_page_guid'] = false;
    }


   // adjust navigation's id references
   // we only allow for two levels of naviations
   if (isset($data['site'][$id_to_guid['site'][$key]]['navigation']) && $data['site'][$id_to_guid['site'][$key]]['navigation'] != '') {
      $navigation = json_decode($data['site'][$id_to_guid['site'][$key]]['navigation'],true);
      foreach ($navigation as $ky => $nav) {
        if ($nav['type'] == 'page') {
          $navigation[$ky]['data']['id'] = $id_to_guid['site_page'][$navigation[$ky]['data']['id']];
        } else if ($nav['type'] == 'browse') {
          if (preg_match('/^item_set_id%5B%5D=(\d+)/', $navigation[$ky]['data']['query'], $match)) {
            $navigation[$ky]['data']['query'] = str_replace('item_set_id%5B%5D='.$match[1], 'item_set_id%5B%5D=ITEM_SET_ID', $navigation[$ky]['data']['query']);
            $navigation[$ky]['data']['item_set_guid'] = $id_to_guid['item_set'][$match[1]];
          }
        }
        
        if (isset($navigation[$ky]['links']) && is_array($navigation[$ky]['links']) && count($navigation[$ky]['links'])) {
          foreach ($navigation[$ky]['links'] as $k => $n) {
            if ($n['type'] == 'page') {
              $navigation[$ky]['links'][$k]['data']['id'] = $id_to_guid['site_page'][$navigation[$ky]['links'][$k]['data']['id']];
            }
          }
        }
      }
      $data['site'][$id_to_guid['site'][$key]]['navigation'] = json_encode($navigation);
      unset($navigation);
    }



    // homepage_id and thumbnail_id are removed even if we might have not created the corresponding media_guid and page_guid, 
    // this is the case in which they are NULL
    unset($data['site'][$id_to_guid['site'][$key]]['homepage_id']);
    unset($data['site'][$id_to_guid['site'][$key]]['thumbnail_id']);
    unset($data['site'][$id_to_guid['site'][$key]]['owner_id']);
    unset($data['site'][$id_to_guid['site'][$key]]['id']);
    unset($data['site'][$key]);
  }

  foreach ($data['site_item_set'] as $key => $value) {
    $data['site_item_set'][$id_to_guid['site_item_set'][$key]] = $value;
    $data['site_item_set'][$id_to_guid['site_item_set'][$key]]['site_item_set_guid'] = $id_to_guid['site_item_set'][$value['id']];
    $data['site_item_set'][$id_to_guid['site_item_set'][$key]]['site_guid'] = $id_to_guid['site'][$value['site_id']];
    $data['site_item_set'][$id_to_guid['site_item_set'][$key]]['item_set_guid'] = $id_to_guid['item_set'][$value['item_set_id']];
    unset($data['site_item_set'][$id_to_guid['site_item_set'][$key]]['id']);
    unset($data['site_item_set'][$id_to_guid['site_item_set'][$key]]['site_id']);
    unset($data['site_item_set'][$id_to_guid['site_item_set'][$key]]['item_set_id']); 
    unset($data['site'][$key]);
  }

  foreach ($data['site_page'] as $key => $value) {
    $data['site_page'][$id_to_guid['site_page'][$key]] = $value;
    $data['site_page'][$id_to_guid['site_page'][$key]]['site_page_guid'] = $id_to_guid['site_page'][$value['id']];
    $data['site_page'][$id_to_guid['site_page'][$key]]['site_guid'] = $id_to_guid['site'][$value['site_id']];
    unset($data['site_page'][$id_to_guid['site_page'][$key]]['id']);
    unset($data['site_page'][$id_to_guid['site_page'][$key]]['site_id']);
    unset($data['site_page'][$key]);
  }

  foreach ($data['site_page_block'] as $key => $value) {
    $data['site_page_block'][$id_to_guid['site_page_block'][$key]] = $value;
    $data['site_page_block'][$id_to_guid['site_page_block'][$key]]['site_page_block_guid'] = $id_to_guid['site_page_block'][$value['id']];
    $data['site_page_block'][$id_to_guid['site_page_block'][$key]]['site_page_guid'] = $id_to_guid['site_page'][$value['page_id']];

    // fix json data with related id's
    if ($data['site_page_block'][$id_to_guid['site_page_block'][$key]]['layout'] == 'asset') {

      $asset = json_decode($data['site_page_block'][$id_to_guid['site_page_block'][$key]]['data'], true);
      foreach ($asset as $k => $a) {
        if (is_numeric($a['id'])) {
          $asset[$k]['id'] = $id_to_guid['asset'][$a['id']];
        }
      }
      $data['site_page_block'][$id_to_guid['site_page_block'][$key]]['data'] = json_encode($asset);
    }  

    
    
        
    unset($data['site_page_block'][$id_to_guid['site_page_block'][$key]]['id']);
    unset($data['site_page_block'][$id_to_guid['site_page_block'][$key]]['page_id']);
    unset($data['site_page_block'][$key]);
  }

  foreach ($data['site_block_attachment'] as $key => $value) {
    $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]] = $value;
    $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['site_block_attachment_guid'] = $id_to_guid['site_block_attachment'][$value['id']];
    $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['site_page_block_guid'] = $id_to_guid['site_page_block'][$value['block_id']];
    if ($value['item_id'] == null) {
      $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['item_guid'] = false;
    } else {
      $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['item_guid'] = $id_to_guid['item'][$value['item_id']];
    }
    if ($value['media_id'] == null) {
      $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['media_guid'] = false;
    } else {
      $data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['media_guid'] = $id_to_guid['media'][$value['media_id']];
    }
    unset($data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['id']);
    unset($data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['block_id']);
    unset($data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['item_id']);
    unset($data['site_block_attachment'][$id_to_guid['site_block_attachment'][$key]]['media_id']);
    unset($data['site_block_attachment'][$key]);
  }

  foreach ($data['site_setting'] as $key => $value) {
    $data['site_setting'][$id_to_guid['site_setting'][$key]] = $value;
    $data['site_setting'][$id_to_guid['site_setting'][$key]]['site_setting_guid'] = $id_to_guid['site_setting'][$value['id']];
    $data['site_setting'][$id_to_guid['site_setting'][$key]]['site_guid'] = $id_to_guid['site'][$value['site_id']];

    if ($value['id'] == 'theme_settings_uva') {
      $theme_settings = json_decode($value['value'], true);
      if (isset($theme_settings['banner']) && is_numeric($theme_settings['banner'])) {
        $theme_settings['banner_guid'] = $id_to_guid['asset'][$theme_settings['banner']];
        unset($theme_settings['banner']);
      }
      $data['site_setting'][$id_to_guid['site_setting'][$key]]['value'] = json_encode($theme_settings);
    }
    // site setting id is maitained
    // unset($data['site_setting'][$id_to_guid['site_setting'][$key]]['id']);
    unset($data['site_setting'][$id_to_guid['site_setting'][$key]]['site_id']);
    unset($data['site_setting'][$key]);
  }

  foreach ($data['item_set'] as $key => $value) {
    $data['item_set'][$id_to_guid['item_set'][$key]] = $value;
    $data['item_set'][$id_to_guid['item_set'][$key]]['item_set_guid'] = $id_to_guid['item_set'][$value['id']];
    unset($data['item_set'][$id_to_guid['item_set'][$key]]['id']);
    unset($data['item_set'][$key]);
  }

  // special case item_item_set does not have an id, only creating a many to many relation item_id <-> item_set_id
  foreach ($data['item_item_set'] as $key => $value) {
    $data['item_item_set'][$key]['item_guid'] = $id_to_guid['item'][$value['item_id']];
    $data['item_item_set'][$key]['item_set_guid'] = $id_to_guid['item_set'][$value['item_set_id']];
    unset($data['item_item_set'][$key]['item_id']);
    unset($data['item_item_set'][$key]['item_set_id']);
  }

  // special case item_site does not have an id, only creating a many to many relation item_id <-> site_id
  foreach ($data['item_site'] as $key => $value) {
    $data['item_site'][$key]['item_guid'] = $id_to_guid['item'][$value['item_id']];
    $data['item_site'][$key]['site_guid'] = $id_to_guid['site'][$value['site_id']];
    unset($data['item_site'][$key]['item_id']);
    unset($data['item_site'][$key]['site_id']);
  }

  foreach ($data['item'] as $key => $value) {
    $data['item'][$id_to_guid['item'][$key]] = $value;
    $data['item'][$id_to_guid['item'][$key]]['item_guid'] = $id_to_guid['item'][$value['id']];
    if ($value['primary_media_id'] != NULL) {
      $data['item'][$id_to_guid['item'][$key]]['media_guid'] = $id_to_guid['media'][$value['primary_media_id']];
    } else {
      $data['item'][$id_to_guid['item'][$key]]['media_guid'] = false;
    }

    unset($data['item'][$id_to_guid['item'][$key]]['primary_media_id']);
    unset($data['item'][$id_to_guid['item'][$key]]['id']);
    unset($data['item'][$key]);
  }

  foreach ($data['media'] as $key => $value) {
    $data['media'][$id_to_guid['media'][$key]] = $value;
    $data['media'][$id_to_guid['media'][$key]]['media_guid'] = $id_to_guid['media'][$value['id']];
    $data['media'][$id_to_guid['media'][$key]]['item_guid'] = $id_to_guid['item'][$value['item_id']];
    unset($data['media'][$id_to_guid['media'][$key]]['id']);
    unset($data['media'][$id_to_guid['media'][$key]]['item_id']);
    unset($data['media'][$key]);
  }


  foreach ($data['property'] as $key => $value) {
    $data['property'][$id_to_guid['property'][$key]] = $value;
    $data['property'][$id_to_guid['property'][$key]]['property_guid'] = $id_to_guid['property'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['property'][$id_to_guid['property'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['property'][$id_to_guid['property'][$key]]['user_guid'] = false;
    }
    $data['property'][$id_to_guid['property'][$key]]['vocabulary_guid'] = $id_to_guid['vocabulary'][$value['vocabulary_id']];
    unset($data['property'][$id_to_guid['property'][$key]]['id']);
    unset($data['property'][$id_to_guid['property'][$key]]['owner_id']);
    unset($data['property'][$id_to_guid['property'][$key]]['vocabulary_id']);
    unset($data['property'][$key]);
  }


  foreach ($data['vocabulary'] as $key => $value) {
    $data['vocabulary'][$id_to_guid['vocabulary'][$key]] = $value;
    $data['vocabulary'][$id_to_guid['vocabulary'][$key]]['vocabulary_guid'] = $id_to_guid['vocabulary'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['vocabulary'][$id_to_guid['vocabulary'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['vocabulary'][$id_to_guid['vocabulary'][$key]]['user_guid'] = false;
    }
    unset($data['vocabulary'][$id_to_guid['vocabulary'][$key]]['id']);
    unset($data['vocabulary'][$id_to_guid['vocabulary'][$key]]['owner_id']);
    unset($data['vocabulary'][$key]);
  }


  foreach ($data['resource_template_property'] as $key => $value) {
    $data['resource_template_property'][$id_to_guid['resource_template_property'][$key]] = $value;
    $data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['resource_template_property_guid'] = $id_to_guid['resource_template_property'][$value['id']];
    $data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['resource_template_guid'] = $id_to_guid['resource_template'][$value['resource_template_id']];
    $data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['property_guid'] = $id_to_guid['property'][$value['property_id']];
    unset($data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['id']);
    unset($data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['resource_template_id']);
    unset($data['resource_template_property'][$id_to_guid['resource_template_property'][$key]]['property_id']);
    unset($data['resource_template_property'][$key]);
  }


  foreach ($data['resource_template'] as $key => $value) {
    $data['resource_template'][$id_to_guid['resource_template'][$key]] = $value;
    $data['resource_template'][$id_to_guid['resource_template'][$key]]['resource_template_guid'] = $id_to_guid['resource_template'][$value['id']];
    if ($value['resource_class_id'] != NULL) {
      $data['resource_template'][$id_to_guid['resource_template'][$key]]['resource_class_guid'] = $id_to_guid['resource_class'][$value['resource_class_id']];
    } else {
      $data['resource_template'][$id_to_guid['resource_template'][$key]]['resource_class_guid'] = false;
    }
    if ($value['owner_id'] != NULL) {
      $data['resource_template'][$id_to_guid['resource_template'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['resource_template'][$id_to_guid['resource_template'][$key]]['user_guid'] = false;
    }
    unset($data['resource_template'][$id_to_guid['resource_template'][$key]]['id']);
    unset($data['resource_template'][$id_to_guid['resource_template'][$key]]['resource_class_id']);
    unset($data['resource_template'][$id_to_guid['resource_template'][$key]]['owner_id']);
    unset($data['resource_template'][$key]);
    
    // these 2 are always null and their relation to anything is not documented
    unset($data['resource_template'][$id_to_guid['resource_template'][$key]]['title_property_id']);
    unset($data['resource_template'][$id_to_guid['resource_template'][$key]]['description_property_id']);

  }

  foreach ($data['resource_class'] as $key => $value) {
    $data['resource_class'][$id_to_guid['resource_class'][$key]] = $value;
    $data['resource_class'][$id_to_guid['resource_class'][$key]]['resource_class_guid'] = $id_to_guid['resource_class'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['resource_class'][$id_to_guid['resource_class'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['resource_class'][$id_to_guid['resource_class'][$key]]['user_guid'] = false;
    }
    if ($value['vocabulary_id'] != NULL) {
      $data['resource_class'][$id_to_guid['resource_class'][$key]]['vocabulary_guid'] = $id_to_guid['vocabulary'][$value['vocabulary_id']];
    } else {
      $data['resource_class'][$id_to_guid['resource_class'][$key]]['vocabulary_guid'] = false;
    }
    unset($data['resource_class'][$id_to_guid['resource_class'][$key]]['id']);
    unset($data['resource_class'][$id_to_guid['resource_class'][$key]]['owner_id']);
    unset($data['resource_class'][$id_to_guid['resource_class'][$key]]['vocabulary_id']);
    unset($data['resource_class'][$key]);
  }







  foreach ($data['resource_item'] as $key => $value) {
    $data['resource_item'][$id_to_guid['resource_item'][$key]] = $value;
    $data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_guid'] = $id_to_guid['resource_item'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['user_guid'] = false;
    }
    if ($value['resource_class_id'] != NULL) {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_class_guid'] = $id_to_guid['resource_class'][$value['resource_class_id']];
    } else {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_class_guid'] = false;
    }
    if ($value['resource_template_id'] != NULL) {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_template_guid'] = $id_to_guid['resource_template'][$value['resource_template_id']];
    } else {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_template_guid'] = false;
    }
    if ($value['thumbnail_id'] != NULL) {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['asset_guid'] = $id_to_guid['asset'][$value['thumbnail_id']];
    } else {
      $data['resource_item'][$id_to_guid['resource_item'][$key]]['asset_guid'] = false;
    }
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['id']);
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['owner_id']);
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_class_id']);
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['resource_template_id']);
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['thumbnail_id']);
    unset($data['resource_item'][$id_to_guid['resource_item'][$key]]['owner_id']);
    unset($data['resource_item'][$key]);
  }


  foreach ($data['resource_item_set'] as $key => $value) {
    $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]] = $value;
    $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_guid'] = $id_to_guid['resource_item_set'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['user_guid'] = false;
    }
    if ($value['resource_class_id'] != NULL) {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_class_guid'] = $id_to_guid['resource_class'][$value['resource_class_id']];
    } else {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_class_guid'] = false;
    }
    if ($value['resource_template_id'] != NULL) {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_template_guid'] = $id_to_guid['resource_template'][$value['resource_template_id']];
    } else {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_template_guid'] = false;
    }
    if ($value['thumbnail_id'] != NULL) {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['asset_guid'] = $id_to_guid['asset'][$value['thumbnail_id']];
    } else {
      $data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['asset_guid'] = false;
    }
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['id']);
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['owner_id']);
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_class_id']);
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['resource_template_id']);
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['thumbnail_id']);
    unset($data['resource_item_set'][$id_to_guid['resource_item_set'][$key]]['owner_id']);
    unset($data['resource_item_set'][$key]);
  }


  foreach ($data['resource_media'] as $key => $value) {
    $data['resource_media'][$id_to_guid['resource_media'][$key]] = $value;
    $data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_guid'] = $id_to_guid['resource_media'][$value['id']];
    if ($value['owner_id'] != NULL) {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    } else {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['user_guid'] = false;
    }
    if ($value['resource_class_id'] != NULL) {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_class_guid'] = $id_to_guid['resource_class'][$value['resource_class_id']];
    } else {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_class_guid'] = false;
    }
    if ($value['resource_template_id'] != NULL) {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_template_guid'] = $id_to_guid['resource_template'][$value['resource_template_id']];
    } else {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_template_guid'] = false;
    }
    if ($value['thumbnail_id'] != NULL) {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['asset_guid'] = $id_to_guid['asset'][$value['thumbnail_id']];
    } else {
      $data['resource_media'][$id_to_guid['resource_media'][$key]]['asset_guid'] = false;
    }
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['id']);
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['owner_id']);
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_class_id']);
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['resource_template_id']);
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['thumbnail_id']);
    unset($data['resource_media'][$id_to_guid['resource_media'][$key]]['owner_id']);
    unset($data['resource_media'][$key]);
  }


  foreach ($data['asset'] as $key => $value) {
    $data['asset'][$id_to_guid['asset'][$key]] = $value;
    $data['asset'][$id_to_guid['asset'][$key]]['asset_guid'] = $id_to_guid['asset'][$value['id']];
    $data['asset'][$id_to_guid['asset'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    unset($data['asset'][$id_to_guid['asset'][$key]]['id']);
    unset($data['asset'][$id_to_guid['asset'][$key]]['owner_id']);
    unset($data['asset'][$key]);
  }


  foreach ($data['fulltext_search'] as $key => $value) {
    $data['fulltext_search'][$id_to_guid['fulltext_search'][$key]] = $value;
    $data['fulltext_search'][$id_to_guid['fulltext_search'][$key]]['fulltext_search_guid'] = $id_to_guid['fulltext_search'][$value['id']];
    $data['fulltext_search'][$id_to_guid['fulltext_search'][$key]]['user_guid'] = $id_to_guid['user'][$value['owner_id']];
    unset($data['fulltext_search'][$id_to_guid['fulltext_search'][$key]]['id']);
    unset($data['fulltext_search'][$id_to_guid['fulltext_search'][$key]]['owner_id']);
    unset($data['fulltext_search'][$key]);
  }



  foreach ($data['user'] as $key => $value) {
    $data['user'][$id_to_guid['user'][$key]] = $value;
    $data['user'][$id_to_guid['user'][$key]]['user_guid'] = $id_to_guid['user'][$value['id']];
    unset($data['user'][$id_to_guid['user'][$key]]['id']);
    unset($data['user'][$key]);
  }


  foreach ($data['user_setting'] as $key => $value) {
    $data['user_setting'][$id_to_guid['user_setting'][$key]] = $value;
    $data['user_setting'][$id_to_guid['user_setting'][$key]]['user_setting_guid'] = $id_to_guid['user_setting'][$value['id']];
    $data['user_setting'][$id_to_guid['user_setting'][$key]]['user_guid'] = $id_to_guid['user'][$value['user_id']];
    // site setting id is maitained
    // unset($data['user_setting'][$id_to_guid['user_setting'][$key]]['id']);
    unset($data['user_setting'][$id_to_guid['user_setting'][$key]]['user_id']);
    unset($data['user_setting'][$key]);
  }





  // load files
  $files_dir = dirname(dirname($options['config'])).'/files/';
  $data['storage'] = array();
  if (is_array($data['asset']) && count($data['asset']) > 0) {
    foreach ($data['asset'] as $key => $value) {
      if (isset($value['storage_id']) && isset($value['extension'])) {
        if ($file = file_get_contents($files_dir.'asset/'.$value['storage_id'].'.'.$value['extension'])) {
          $data['storage']['asset'][$value['storage_id']]['file'] = base64_encode($file);
          $data['storage']['asset'][$value['storage_id']]['name'] = $value['storage_id'].'.'.$value['extension'];
        }
      }
    }
  } 
  if (is_array($data['media']) && count($data['media']) > 0) {
    foreach ($data['media'] as $key => $value) {
      if (isset($value['storage_id']) && isset($value['extension']) && isset($value['renderer']) && $value['renderer'] == 'file') {
        if ($file = file_get_contents($files_dir.'original/'.$value['storage_id'].'.'.$value['extension'])) {
          $data['storage']['original'][$value['storage_id']]['file'] = base64_encode($file);
          $data['storage']['original'][$value['storage_id']]['name'] = $value['storage_id'].'.'.$value['extension'];
        }
        if ($file = file_get_contents($files_dir.'large/'.$value['storage_id'].'.jpg')) {  // .$value['extension'] large, medium and square files are stored as jpg
          $data['storage']['large'][$value['storage_id']]['file'] = base64_encode($file);
          $data['storage']['large'][$value['storage_id']]['name'] = $value['storage_id'].'.jpg';
        }
        if ($file = file_get_contents($files_dir.'medium/'.$value['storage_id'].'.jpg')) {
          $data['storage']['medium'][$value['storage_id']]['file'] = base64_encode($file);
          $data['storage']['medium'][$value['storage_id']]['name'] = $value['storage_id'].'.jpg';
        }
        if ($file = file_get_contents($files_dir.'square/'.$value['storage_id'].'.jpg')) {
          $data['storage']['square'][$value['storage_id']]['file'] = base64_encode($file);
          $data['storage']['square'][$value['storage_id']]['name'] = $value['storage_id'].'.jpg';
        }
      }
    }
  }
  
  

  
  return $data;


}




















function uvaimex_import($options,$db,$preferences) {

  // create (if needed) the uvaimexguid table
  $guid = _create_uvaimexguid_table($db,$preferences);

  $json_file = file_get_contents($options['file']);
  $data = json_decode($json_file, true);

  $guid_to_id = array();
  $id_to_guid = array();






  // add users
  if (isset($data['user']) && count($data['user']) > 0) {
    foreach ($data['user'] as $key => $record) {
      // check if site has already been created
      $data['user'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'user' ");
      $query->bindValue(':guid', $record['user_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['user'][$key]['id'] = $row['relatedID'];
          $data['user'][$key]['user_email_already_existed'] = true;
        }
      }
      if ($data['user'][$key]['id'] === false) {
        // first we want to check if a user with the same email is already in:
        $query = $db->prepare('SELECT `id` FROM `user` WHERE `email` = :email');
        $query->bindValue(':email', $data['user'][$key]['email'], PDO::PARAM_STR);
        if ($query->execute()) {
          while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $data['user'][$key]['id'] = $row['id'];
            $data['user'][$key]['user_email_already_existed'] = true;
          }
        }
   
        if ($data['user'][$key]['id'] !== false) {
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'user' ");
          $query->bindValue(':guid', $data['user'][$key]['user_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['user'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        } else { // we did not find a user with the same email
          $sql = 'INSERT INTO `user` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          if ($query->execute()) {
            $data['user'][$key]['id'] = $db->lastInsertId();
          }
          // insert record into uvaimexguid
         $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'user' ");
          $query->bindValue(':guid', $data['user'][$key]['user_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['user'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        }
      } else {
        $sql = 'UPDATE `user` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_guid':
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['user'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['user'][$data['user'][$key]['user_guid']] = $data['user'][$key]['id'];
      $id_to_guid['user'][$data['user'][$key]['id']] = $data['user'][$key]['user_guid'];
      
    }
  }











  // create user_setting
  if (isset($data['user_setting']) && count($data['user_setting']) > 0) {
    foreach ($data['user_setting'] as $key => $record) {
      if (isset($data['user'][$data['user_setting'][$key]['user_guid']]['user_email_already_existed']) && $data['user'][$data['user_setting'][$key]['user_guid']]['user_email_already_existed'] === true) {
        continue; // we'll skip updating the setting of this user
      }
    
      // check if user_setting has already been created
      $data['user_setting'][$key]['user_setting_id'] = false; // this is only used to check if record was inserted

      $query = $db->prepare("SELECT `related_varchar`, `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'user_setting' ");
      $query->bindValue(':guid', $record['user_setting_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['user_setting'][$key]['user_setting_id'] = $row['related_varchar'];
          $data['user_setting'][$key]['user_id'] = $row['relatedID'];
        }
      }
      if ($data['user_setting'][$key]['user_setting_id'] === false) {
        $sql = 'INSERT INTO `user_setting` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_setting_guid':
            case 'user_setting_id':
             break;
            case 'user_guid':
              $sql .= " `user_id` = :user_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_setting_guid':
            case 'user_setting_id':
               break;
            case 'user_guid':
              $query->bindValue(':user_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->execute();
        
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `related_varchar` = :related_varchar, `related_table` = 'user_setting' ");
        $query->bindValue(':guid', $data['user_setting'][$key]['user_setting_guid'], PDO::PARAM_STR);
        $query->bindValue(':related_varchar', $data['user_setting'][$key]['id'], PDO::PARAM_STR);
        if ($query->execute()) {
          //echo("record inserted into uvaimexguid \n");
        } else {
          //echo("record NOT inserted into uvaimexguid \n");
        }
      } else {
        $sql = 'UPDATE `user_setting` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_setting_guid':
            case 'user_setting_id':
            case 'user_guid':
            case 'id':
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id AND `user_id` = :user_id';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'user_setting_guid':
            case 'user_setting_id':
            case 'id':
            case 'user_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['user_setting'][$key]['id'], PDO::PARAM_STR); 
        $query->bindValue(':user_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
        if ($query->execute()) {
          // success
        }
      }
      
      $guid_to_id['user_setting'][$data['user_setting'][$key]['user_setting_guid']] = $data['user_setting'][$key]['id'];
      $id_to_guid['user_setting'][$data['user_setting'][$key]['id']] = $data['user_setting'][$key]['user_setting_guid'];
      
    }
  }















  // create site
  if (isset($data['site']) && count($data['site']) > 0) {
    foreach ($data['site'] as $key => $record) {
      // check if site has already been created
      $data['site'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'site' ");
      $query->bindValue(':guid', $record['site_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['site'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['site'][$key]['id'] === false) {
        $sql = 'INSERT INTO `site` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_guid':
            case 'site_page_guid':
            case 'media_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_guid':
            case 'site_page_guid':
            case 'media_guid':
              break;
            case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['site'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'site' ");
        $query->bindValue(':guid', $data['site'][$key]['site_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['site'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `site` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_guid':
            case 'site_page_guid':
            case 'media_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_guid':
            case 'site_page_guid':
            case 'media_guid':
              break;
            case 'user_guid': 
               $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
             break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['site'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['site'][$data['site'][$key]['site_guid']] = $data['site'][$key]['id'];
      $id_to_guid['site'][$data['site'][$key]['id']] = $data['site'][$key]['site_guid'];
      
      // needed for import/export data verification (records no longer utilized)
      $options['siteslug'] = $data['site'][$key]['slug'];
    }
  } else {
    trigger_error('No site data available in source file', E_USER_ERROR);
    die;
  }



























  // create site_page
  if (isset($data['site_page']) && count($data['site_page']) > 0) {
    foreach ($data['site_page'] as $key => $record) {
    
      // check if site_page has already been created
      $data['site_page'][$key]['id'] = false;
      //print_r($record);
      //echo("\n");
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'site_page' ");
      $query->bindValue(':guid', $record['site_page_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['site_page'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['site_page'][$key]['id'] === false) {
        //echo("inserting new record into site_page \n");
        $sql = 'INSERT INTO `site_page` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_page_guid':
              break;
            case 'site_guid':
              $sql .= " `site_id` = :site_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_page_guid':
              break;
            case 'site_guid':
              $query->bindValue(':site_id',  $guid_to_id['site'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          //echo("record inserted \n");
          $data['site_page'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'site_page' ");
        $query->bindValue(':guid', $data['site_page'][$key]['site_page_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['site_page'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        //echo("updating new record into site_page \n");
        $sql = 'UPDATE `site_page` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_page_guid':
              break;
            case 'site_guid':
              $sql .= " `site_id` = :site_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_page_guid':
              break;
            case 'site_guid':
              $query->bindValue(':site_id',  $guid_to_id['site'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['site_page'][$key]['id'], PDO::PARAM_STR);      
        if ($query->execute()) {
          //echo("record updated \n");
        }
      }
      
      $guid_to_id['site_page'][$data['site_page'][$key]['site_page_guid']] = $data['site_page'][$key]['id'];
      $id_to_guid['site_page'][$data['site_page'][$key]['id']] = $data['site_page'][$key]['site_page_guid'];
      
    }
  }























  // create site_page_block
  if (isset($data['site_page_block']) && count($data['site_page_block']) > 0) {
    foreach ($data['site_page_block'] as $key => $record) {
      // check if site has already been created
      $data['site_page_block'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'site_page_block' ");
      $query->bindValue(':guid', $record['site_page_block_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['site_page_block'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['site_page_block'][$key]['id'] === false) {
        $sql = 'INSERT INTO `site_page_block` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_page_block_guid':
              break;
           case 'site_page_guid':
              $sql .= " `page_id` = :page_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_page_block_guid':
              break;
            case 'site_page_guid':
              $query->bindValue(':page_id',  $guid_to_id['site_page'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['site_page_block'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'site_page_block' ");
        $query->bindValue(':guid', $data['site_page_block'][$key]['site_page_block_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['site_page_block'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `site_page_block` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_page_block_guid':
              break;
            case 'site_page_guid':
              $sql .= " `page_id` = :page_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_page_block_guid':
              break;
            case 'site_page_guid':
              $query->bindValue(':page_id',  $guid_to_id['site_page'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['site_page_block'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['site_page_block'][$data['site_page_block'][$key]['site_page_block_guid']] = $data['site_page_block'][$key]['id'];
      $id_to_guid['site_page_block'][$data['site_page_block'][$key]['id']] = $data['site_page_block'][$key]['site_page_block_guid'];
      
    }
  }
















  // create asset
  if (isset($data['asset']) && count($data['asset']) > 0) {
    foreach ($data['asset'] as $key => $record) {
    
      // check if site_page has already been created
      $data['asset'][$key]['id'] = false;
      //print_r($record);
      //echo("\n");
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'asset' ");
      $query->bindValue(':guid', $record['asset_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['asset'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['asset'][$key]['id'] === false) {
        //echo("inserting new record into site_page \n");
        $sql = 'INSERT INTO `asset` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'asset_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'asset_guid':
              break;
            case 'user_guid':
              $query->bindValue(':owner_id',  $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          //echo("record inserted \n");
          $data['asset'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'asset' ");
        $query->bindValue(':guid', $data['asset'][$key]['asset_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['asset'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        //echo("updating new record into site_page \n");
        $sql = 'UPDATE `asset` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'asset_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'asset_guid':
              break;
            case 'user_guid':
              $query->bindValue(':owner_id',  $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['asset'][$key]['id'], PDO::PARAM_STR);      
        if ($query->execute()) {
          //echo("record updated \n");
        }
      }
      
      $guid_to_id['asset'][$data['asset'][$key]['asset_guid']] = $data['asset'][$key]['id'];
      $id_to_guid['asset'][$data['asset'][$key]['id']] = $data['asset'][$key]['asset_guid'];
      
    }
  }














  // create site_setting
  if (isset($data['site_setting']) && count($data['site_setting']) > 0) {
    foreach ($data['site_setting'] as $key => $record) {
      if ($data['site_setting'][$key]['id'] == 'theme_settings_uva') {
        $theme_settings = json_decode($data['site_setting'][$key]['value'], true);
        if (isset($theme_settings['banner_guid']) && $theme_settings['banner_guid'] != '') {
          $theme_settings['banner'] = $guid_to_id['asset'][$theme_settings['banner_guid']];
          unset($theme_settings['banner_guid']);
          $data['site_setting'][$key]['value'] = json_encode($theme_settings);
          $record['value'] = $data['site_setting'][$key]['value'];
        }
        unset($theme_settings);
      }
      // check if site has already been created
      $data['site_setting'][$key]['site_setting_id'] = false; // this is only used to check if record was inserted

      $query = $db->prepare("SELECT `related_varchar`  FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'site_setting' ");
      $query->bindValue(':guid', $record['site_setting_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['site_setting'][$key]['id'] = $row['related_varchar'];
          if ($data['site_setting'][$key]['id'] != '') {
            $data['site_setting'][$key]['site_setting_id'] = true;
          }
        }
      }
      if ($data['site_setting'][$key]['site_setting_id'] === false) {
        //echo("site_setting_id is still false \n");
        $sql = 'INSERT INTO `site_setting` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_setting_guid':
            case 'site_setting_id':
             break;
            case 'site_guid':
              $sql .= " `site_id` = :site_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_setting_guid':
            case 'site_setting_id':
               break;
            case 'site_guid':
              $query->bindValue(':site_id', $guid_to_id['site'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->execute();
        
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `related_varchar` = :related_varchar, `related_table` = 'site_setting', `relatedID` = :relatedID ");
        $query->bindValue(':guid', $data['site_setting'][$key]['site_setting_guid'], PDO::PARAM_STR);
        $query->bindValue(':related_varchar', $data['site_setting'][$key]['id'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $guid_to_id['site'][$data['site_setting'][$key]['site_guid']], PDO::PARAM_INT);
        if ($query->execute()) {
          //echo("record inserted into uvaimexguid \n");
        } else {
          //echo("record NOT inserted into uvaimexguid \n");
        }
      } else {
        $sql = 'UPDATE `site_setting` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_setting_guid':
            case 'site_setting_id':
            case 'id':
            case 'site_guid':
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id AND `site_id` = :site_id';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'site_setting_guid':
            case 'site_setting_id':
            case 'id':
              break;
            case 'site_guid':
              $query->bindValue(':site_id', $guid_to_id['site'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['site_setting'][$key]['id'], PDO::PARAM_STR); 
        if ($query->execute()) {
          //echo("record updated into site_setting \n");
        } else {
          //echo("record NOT updated into site_setting \n");
        }
      }
      
      $guid_to_id['site_setting'][$data['site_setting'][$key]['site_setting_guid']] = $data['site_setting'][$key]['id'];
      $id_to_guid['site_setting'][$data['site_setting'][$key]['id']] = $data['site_setting'][$key]['site_setting_guid'];
      
    }
  }








  // adjust site navigation
  if (isset($data['site']) && count($data['site']) > 0) {
    foreach ($data['site'] as $key => $record) {
      if (isset($record['navigation']) && $record['navigation'] != '') {
        $navigation = json_decode($record['navigation'],true);
        foreach ($navigation as $ky => $nav) {
          if ($nav['type'] == 'page') {
            $navigation[$ky]['data']['id'] = $guid_to_id['site_page'][$navigation[$ky]['data']['id']];
          } else if ($nav['type'] == 'browse') {
            $navigation[$ky]['data']['query'] = str_replace('ITEM_SET_ID',  $guid_to_id['item_set'][$navigation[$ky]['data']['item_set_guid']],$navigation[$ky]['data']['query']);      
          }
          if (isset($navigation[$ky]['links']) && is_array($navigation[$ky]['links']) && count($navigation[$ky]['links'])) {
            foreach ($navigation[$ky]['links'] as $k => $n) {
              if ($n['type'] == 'page') {
                $navigation[$ky]['links'][$k]['data']['id'] = $guid_to_id['site_page'][$navigation[$ky]['links'][$k]['data']['id']];
              }
            }
          }
        }
        $data['site'][$key]['navigation'] = json_encode($navigation);
        unset($navigation);
        // print_r($data['site'][$key]['navigation']);
        $query = $db->prepare("UPDATE `site` SET `navigation` = :navigation WHERE `id` = :id ");
        $query->bindValue(':navigation', $data['site'][$key]['navigation'], PDO::PARAM_STR);
        $query->bindValue(':id', $data['site'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      }
    }
  }
  // adjust site_page_block to asset
  if (isset($data['site_page_block']) && count($data['site_page_block']) > 0) {
    foreach ($data['site_page_block'] as $key => $record) {
      if ($record['layout'] == 'asset') {
        $asset = json_decode($record['data'], true);
        foreach ($asset as $k => $a) {
          $asset[$k]['id'] = $guid_to_id['asset'][$asset[$k]['id']];
        }
        $data['site_page_block'][$key]['data'] = json_encode($asset);
        $query = $db->prepare("UPDATE `site_page_block` SET `data` = :data WHERE `id` = :id ");
        $query->bindValue(':data', $data['site_page_block'][$key]['data'], PDO::PARAM_STR);
        $query->bindValue(':id', $data['site_page_block'][$key]['id'], PDO::PARAM_STR);
        $query->execute();

      }
    }
  }
  // update site with home page reference
  if (isset($data['site']) && count($data['site']) > 0) {
    foreach ($data['site'] as $key => $record) {
      if (isset($record['page_guid']) && $record['page_guid'] != '') {
        $query = $db->prepare("UPDATE `site` SET `homepage_id` = :homepage_id WHERE `id` = :id ");
        $query->bindValue(':homepage_id', $guid_to_id['site_page'][$record['page_guid']], PDO::PARAM_INT);
        $query->bindValue(':id', $data['site'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      }
    }
  }


   








  // create vocabulary
  if (isset($data['vocabulary']) && count($data['vocabulary']) > 0) {
    foreach ($data['vocabulary'] as $key => $record) {
      // check if vocabulary has already been created
      $data['vocabulary'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'vocabulary' ");
      $query->bindValue(':guid', $record['vocabulary_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['vocabulary'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['vocabulary'][$key]['id'] === false) {
        // first we want to check if a vocabulary with the same info is already in, vocabularies with owner_id false are typically preinstalled by omeka
        
        $query = $db->prepare('SELECT `id` FROM `vocabulary` WHERE `namespace_uri` = :namespace_uri');
        $query->bindValue(':namespace_uri', $data['vocabulary'][$key]['namespace_uri'], PDO::PARAM_STR);
        if ($query->execute()) {
          while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $data['vocabulary'][$key]['id'] = $row['id'];
            $data['vocabulary'][$key]['vocabulary_already_existed'] = true;
          }
        }

        if ($data['vocabulary'][$key]['id'] !== false) {
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'vocabulary' ");
          $query->bindValue(':guid', $data['vocabulary'][$key]['vocabulary_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['vocabulary'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        } else { // we did not find a user with the same email
          $sql = 'INSERT INTO `vocabulary` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          if ($query->execute()) {
            $data['vocabulary'][$key]['id'] = $db->lastInsertId();
          }
          // insert record into uvaimexguid
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'vocabulary' ");
          $query->bindValue(':guid', $data['vocabulary'][$key]['vocabulary_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['vocabulary'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        }
      } else {
        if (isset($data['vocabulary'][$key]['user_guid']) && $data['vocabulary'][$key]['user_guid'] != false) {
         // if there is no user_guid we should leave the vocabulary alone, just only need to know the id
          $sql = 'UPDATE `user` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $sql .= ' WHERE `id` = :id ';
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          $query->bindValue(':id',$data['vocabulary'][$key]['id'], PDO::PARAM_STR);      
          $query->execute();
        }

      }
      
      $guid_to_id['vocabulary'][$data['vocabulary'][$key]['vocabulary_guid']] = $data['vocabulary'][$key]['id'];
      $id_to_guid['vocabulary'][$data['vocabulary'][$key]['id']] = $data['vocabulary'][$key]['vocabulary_guid'];
      
    }
  }





















  // create property
  if (isset($data['property']) && count($data['property']) > 0) {
    foreach ($data['property'] as $key => $record) {
      // check if vocabulary has already been created
      $data['property'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'property' ");
      $query->bindValue(':guid', $record['property_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['property'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['property'][$key]['id'] === false) {
        // first we want to check if a vocabulary with the same email is already in, vocabularies with owner_id false are typically preinstalled by omeka
        
        $query = $db->prepare('SELECT `id` FROM `property` WHERE `vocabulary_id` = :vocabulary_id AND `local_name` = :local_name ');
        $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$data['property'][$key]['vocabulary_guid']], PDO::PARAM_INT);
        $query->bindValue(':local_name', $data['property'][$key]['local_name'], PDO::PARAM_STR);
        if ($query->execute()) {
          while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $data['property'][$key]['id'] = $row['id'];
            $data['property'][$key]['property_already_existed'] = true;
          }
        }

        if ($data['property'][$key]['id'] !== false) {
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'property' ");
          $query->bindValue(':guid', $data['property'][$key]['user_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['property'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        } else { // we did not find a user with the same email
          $sql = 'INSERT INTO `property` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'property_guid':
                break;
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              case 'vocabulary_guid':
                $sql .= " `vocabulary_id` = :vocabulary_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'property_guid':
                break;
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              case 'vocabulary_guid':
                $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          if ($query->execute()) {
            $data['property'][$key]['id'] = $db->lastInsertId();
          }
          // insert record into uvaimexguid
         $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'property' ");
          $query->bindValue(':guid', $data['property'][$key]['property_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['property'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        }
      } else {
        if (isset($data['property'][$key]['user_guid']) && $data['property'][$key]['user_guid'] != false) {
         // if there is no user_guid we should leave the property alone, just only need to know the id
          $sql = 'UPDATE `property` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'property_guid':
                break;
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              case 'vocabulary_guid':
                $sql .= " `vocabulary_id` = :vocabulary_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $sql .= ' WHERE `id` = :id ';
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'property_guid':
                break;
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              case 'vocabulary_guid':
                $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          $query->bindValue(':id',$data['property'][$key]['id'], PDO::PARAM_STR);      
          $query->execute();
        }

      }
      
      $guid_to_id['property'][$data['property'][$key]['property_guid']] = $data['property'][$key]['id'];
      $id_to_guid['property'][$data['property'][$key]['id']] = $data['property'][$key]['property_guid'];
      
    }
  }

















  // create resource_class
  if (isset($data['resource_class']) && count($data['resource_class']) > 0) {
    foreach ($data['resource_class'] as $key => $record) {
      // check if vocabulary has already been created
      $data['resource_class'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'resource_class' ");
      $query->bindValue(':guid', $record['resource_class_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['resource_class'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['resource_class'][$key]['id'] === false) {
      
        $query = $db->prepare('SELECT `id` FROM `resource_class` WHERE `local_name` = :local_name AND `label` = :label LIMIT 1');
        $query->bindValue(':local_name', $data['resource_class'][$key]['local_name'], PDO::PARAM_STR);
        $query->bindValue(':label', $data['resource_class'][$key]['label'], PDO::PARAM_STR);
        if ($query->execute()) {
          while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $data['resource_class'][$key]['id'] = $row['id'];
            $data['resource_class'][$key]['resource_class_already_existed'] = true;
          }
        }

        if ($data['resource_class'][$key]['id'] !== false) {
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource_class' ");
          $query->bindValue(':guid', $data['resource_class'][$key]['resource_class_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['resource_class'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        } else { // we did not find a resource_class 
          $sql = 'INSERT INTO `resource_class` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_class_guid':
                break;
              case 'vocabulary_guid':
                $sql .= " `vocabulary_id` = :vocabulary_id,";
                break;
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_class_guid':
                break;
              case 'vocabulary_guid':
                $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
                break;
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          if ($query->execute()) {
            $data['resource_class'][$key]['id'] = $db->lastInsertId();
          }
          // insert record into uvaimexguid
         $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource_class' ");
          $query->bindValue(':guid', $data['resource_class'][$key]['resource_class_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['resource_class'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        }
      } else {
       // if there is no user_guid we should leave the resource_class alone, just only need to know the id
        if (!isset($data['resource_class'][$key]['user_guid']) || $data['resource_class'][$key]['user_guid'] != false) {

          $sql = 'UPDATE `user` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_class_guid':
                break;
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              case 'vocabulary_guid':
                $sql .= " `vocabulary_id` = :vocabulary_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $sql .= ' WHERE `id` = :id ';
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_class_guid':
                break;
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              case 'vocabulary_guid':
               $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          $query->bindValue(':id',$data['resource_class'][$key]['id'], PDO::PARAM_STR);      
          $query->execute();
        }
      }

      
      
      $guid_to_id['resource_class'][$data['resource_class'][$key]['resource_class_guid']] = $data['resource_class'][$key]['id'];
      $id_to_guid['resource_class'][$data['resource_class'][$key]['id']] = $data['resource_class'][$key]['resource_class_guid'];
      
    }
  }
























  // create resource_template
  if (isset($data['resource_template']) && count($data['resource_template']) > 0) {
    foreach ($data['resource_template'] as $key => $record) {
      // check if vocabulary has already been created
      $data['resource_template'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'resource_template' ");
      $query->bindValue(':guid', $record['resource_template_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['resource_template'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['resource_template'][$key]['id'] === false) {
      
        $query = $db->prepare('SELECT `id` FROM `resource_template` WHERE `label` = :label ');
        $query->bindValue(':label', $data['resource_template'][$key]['label'], PDO::PARAM_STR);
        if ($query->execute()) {
          while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $data['resource_template'][$key]['id'] = $row['id'];
            $data['resource_template'][$key]['resource_template_already_existed'] = true;
          }
        }

        if ($data['resource_template'][$key]['id'] !== false) {
          $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource_template' ");
          $query->bindValue(':guid', $data['resource_template'][$key]['resource_template_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['resource_template'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        } else { // we did not find a resource_class 
          $sql = 'INSERT INTO `resource_template` SET';
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_template_guid':
                break;
              case 'vocabulary_guid':
                $sql .= " `vocabulary_id` = :vocabulary_id,";
                break;
              case 'resource_class_guid':
                $sql .= " `resource_class_id` = :resource_class_id,";
                break;
              case 'user_guid':
                $sql .= " `owner_id` = :owner_id,";
                break;
              default:
                $sql .= " `{$field}` = :{$field},";
                break;
            }
          }
          $sql = rtrim($sql, ', ');
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
              case 'resource_template_guid':
                break;
              case 'vocabulary_guid':
                $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
                break;
              case 'resource_class_guid':
                if ($value != false) {
                  $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_INT);
                } else {
                  $query->bindValue(':resource_class_id', '', PDO::PARAM_STR);
                }
                break;
              case 'user_guid':
                $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          if ($query->execute()) {
            $data['resource_template'][$key]['id'] = $db->lastInsertId();
          }
          // insert record into uvaimexguid
         $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource_template' ");
          $query->bindValue(':guid', $data['resource_template'][$key]['resource_template_guid'], PDO::PARAM_STR);
          $query->bindValue(':relatedID', $data['resource_template'][$key]['id'], PDO::PARAM_INT);
          $query->execute();
        }
      } else {
       // if there is no user_guid we should leave the vocabulary alone, just only need to know the id
        $sql = 'UPDATE `resource_template` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'resource_template_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
             case 'resource_class_guid':
                $sql .= " `resource_class_id` = :resource_class_id,";
                break;
            case 'vocabulary_guid':
              $sql .= " `vocabulary_id` = :vocabulary_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'resource_template_guid':
              break;
            case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
              case 'resource_class_guid':
                if ($value != false) {
                  $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_INT);
                } else {
                  $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
                }
                break;
            case 'vocabulary_guid':
              $query->bindValue(':vocabulary_id', $guid_to_id['vocabulary'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['resource_template'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }

      
      
      $guid_to_id['resource_template'][$data['resource_template'][$key]['resource_template_guid']] = $data['resource_template'][$key]['id'];
      $id_to_guid['resource_template'][$data['resource_template'][$key]['id']] = $data['resource_template'][$key]['resource_template_guid'];
      
    }
  }













  // create resource_item
  if (isset($data['resource_item']) && count($data['resource_item']) > 0) {
    foreach ($data['resource_item'] as $key => $record) {
      //echo('resource_item => '.$key."\n");
      // check if resource_item has already been created
      $data['resource_item'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'resource' AND `related_varchar` = 'item' ");
      $query->bindValue(':guid', $record['resource_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['resource_item'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['resource_item'][$key]['id'] === false) {
        $sql = 'INSERT INTO `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              if ($value != false) {
                $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
              }
               break;
           case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
           case 'asset_guid':
              if ($value == false) {
                $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
              } else {
                $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
              }
              break;
            case 'resource_template_guid':
              if ($value != false) {
                $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_template_id', null, PDO::PARAM_NULL);
              }
              break;
           default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['resource_item'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource', `related_varchar` = 'item' ");
        $query->bindValue(':guid', $data['resource_item'][$key]['resource_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['resource_item'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              if ($value != false) {
                $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
              } else{
                $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
              }
              break;
           case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
           case 'asset_guid':
              if ($value != false) {
                $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
              }
              break;
            case 'resource_template_guid':
              if ($value != false) {
                $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_template_id', null, PDO::PARAM_NULL);
              }
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['resource_item'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['resource_item'][$data['resource_item'][$key]['resource_guid']] = $data['resource_item'][$key]['id'];
      $id_to_guid['resource_item'][$data['resource_item'][$key]['id']] = $data['resource_item'][$key]['resource_guid'];
      
    }
  }







  // create resource_item_set
  if (isset($data['resource_item_set']) && count($data['resource_item_set']) > 0) {
    foreach ($data['resource_item_set'] as $key => $record) {
      // check if resource_item_set has already been created
      $data['resource_item_set'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'resource' AND `related_varchar` = 'item_set' ");
      $query->bindValue(':guid', $record['resource_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['resource_item_set'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['resource_item_set'][$key]['id'] === false) {
        $sql = 'INSERT INTO `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              if ($value != false) {
                $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
              } else{
                $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
              }
              break;
           case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
           case 'asset_guid':
             if ($value != false) {
               $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
             } else {
               $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
             }
              break;
            case 'resource_template_guid':
              $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              break;
           default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['resource_item_set'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource', `related_varchar` = 'item_set' ");
        $query->bindValue(':guid', $data['resource_item_set'][$key]['resource_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['resource_item_set'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
             if ($value != false) {
               $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
             } else{
               $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
             }
             break;
           case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
           case 'asset_guid':
             if ($value != false) {
               $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
             } else {
               $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
             }
              break;
            case 'resource_template_guid':
              $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['resource_item_set'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['resource_item_set'][$data['resource_item_set'][$key]['resource_guid']] = $data['resource_item_set'][$key]['id'];
      $id_to_guid['resource_item_set'][$data['resource_item_set'][$key]['id']] = $data['resource_item_set'][$key]['resource_guid'];
      
    }
  }







  // create resource_media
  if (isset($data['resource_media']) && count($data['resource_media']) > 0) {
    foreach ($data['resource_media'] as $key => $record) {
      // check if resource_media has already been created
      $data['resource_media'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'resource' AND `related_varchar` = 'media' ");
      $query->bindValue(':guid', $record['resource_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['resource_media'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['resource_media'][$key]['id'] === false) {
        $sql = 'INSERT INTO `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              if ($value != false) {
                $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
              } else{
                $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
              }
              break;
           case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
           case 'asset_guid':
             if ($value != false) {
               $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
             } else {
               $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
             }
              break;
            case 'resource_template_guid':
              if ($value != false) {
                $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_template_id', null, PDO::PARAM_NULL);
              }
              break;
           default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['resource_media'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'resource', `related_varchar` = 'media' ");
        $query->bindValue(':guid', $data['resource_media'][$key]['resource_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['resource_media'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `resource` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'resource_guid':
              break;
           case 'resource_class_guid':
              $sql .= " `resource_class_id` = :resource_class_id,";
              break;
           case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
           case 'asset_guid':
              $sql .= " `thumbnail_id` = :thumbnail_id,";
              break;
           case 'resource_template_guid':
              $sql .= " `resource_template_id` = :resource_template_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'resource_guid':
              break;
            case 'resource_class_guid':
              if ($value != false) {
                $query->bindValue(':resource_class_id', $guid_to_id['resource_class'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_class_id', null, PDO::PARAM_NULL);
              }
              break;
            case 'user_guid':
              $query->bindValue(':owner_id', $guid_to_id['user'][$value], PDO::PARAM_STR);
              break;
            case 'asset_guid':
              if ($value != false) {
                $query->bindValue(':thumbnail_id', $guid_to_id['asset'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':thumbnail_id', null, PDO::PARAM_NULL);
              }
              break;
            case 'resource_template_guid':
              if ($value != false) {
                $query->bindValue(':resource_template_id', $guid_to_id['resource_template'][$value], PDO::PARAM_STR);
              } else {
                $query->bindValue(':resource_template_id', null, PDO::PARAM_NULL);
              }
              break;
             default:
               $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
               break;
          }
        }
        $query->bindValue(':id',$data['resource_media'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['resource_media'][$data['resource_media'][$key]['resource_guid']] = $data['resource_media'][$key]['id'];
      $id_to_guid['resource_media'][$data['resource_media'][$key]['id']] = $data['resource_media'][$key]['resource_guid'];
      
    }
  }







  // create item_set
  if (isset($data['item_set']) && count($data['item_set']) > 0) {
    foreach ($data['item_set'] as $key => $record) {
      // check if item_set has already been created
      $data['item_set'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'item_set' ");
      $query->bindValue(':guid', $record['item_set_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['item_set'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['item_set'][$key]['id'] === false) {
        $data['item_set'][$key]['id'] = $guid_to_id['resource_item_set'][$data['item_set'][$key]['item_set_guid']];
        $sql = 'INSERT INTO `item_set` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_set_guid':
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_set_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          // the id is set by resource
          // $data['item_set'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'item_set' ");
        $query->bindValue(':guid', $data['item_set'][$key]['item_set_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['item_set'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `item_set` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_set_guid':
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_set_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->execute();
      }
      
      $guid_to_id['item_set'][$data['item_set'][$key]['item_set_guid']] = $data['item_set'][$key]['id'];
      $id_to_guid['item_set'][$data['item_set'][$key]['id']] = $data['item_set'][$key]['item_set_guid'];
      
    }
  }






  // create item
  if (isset($data['item']) && count($data['item']) > 0) {
    foreach ($data['item'] as $key => $record) {
      // check if item has already been created
      $data['item'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'item' ");
      $query->bindValue(':guid', $record['item_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['item'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['item'][$key]['id'] === false) {
        $data['item'][$key]['id'] = $guid_to_id['resource_item'][$data['item'][$key]['item_guid']];
        $record['id'] = $data['item'][$key]['id'];
        $sql = 'INSERT INTO `item` SET ';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_guid':
              break;
           case 'media_guid':
              $sql .= " `primary_media_id` = NULL,"; // we'll deal with primary_media_id once we created it
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        //print_r($data['item'][$key]);
        //echo($sql);
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_guid':
           case 'media_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          // $data['item'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'item' ");
        $query->bindValue(':guid', $data['item'][$key]['item_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['item'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `item` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'id':
            case 'item_guid':
            case 'media_guid':
             break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        if ($sql != 'UPDATE `item` SET') {
          // normally the item set only have two fields so no update can be made yet, but just in case some plugin addded additional fields (media id will be updated later)
          $sql = rtrim($sql, ', ');
          $sql .= ' WHERE `id` = :id ';
          $query = $db->prepare($sql);
          foreach ($record as $field => $value) {
            switch ($field) {
             case 'item_guid':
                break;
              default:
                $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
                break;
            }
          }
          $query->bindValue(':id',$data['item'][$key]['id'], PDO::PARAM_STR);      
          $query->execute();
        }
      }
      
      $guid_to_id['item'][$data['item'][$key]['item_guid']] = $data['item'][$key]['id'];
      $id_to_guid['item'][$data['item'][$key]['id']] = $data['item'][$key]['item_guid'];
      
    }
  }






  // create media
  if (isset($data['media']) && count($data['media']) > 0) {
    foreach ($data['media'] as $key => $record) {
      // check if site has already been created
      $data['media'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'media' ");
      $query->bindValue(':guid', $record['media_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['media'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['media'][$key]['id'] === false) {
        $data['media'][$key]['id'] = $guid_to_id['resource_media'][$data['media'][$key]['media_guid']];
        $record['id'] = $data['media'][$key]['id'];
        $sql = 'INSERT INTO `media` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'media_guid':
              break;
           case 'item_guid':
              $sql .= " `item_id` = :item_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
//        echo($sql);
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'item_guid':
             if ($value != false) {
               $query->bindValue(':item_id', $guid_to_id['item'][$value], PDO::PARAM_INT);
             } else {
               $query->bindValue(':item_id', null, PDO::PARAM_NULL);
             }
             break;
           case 'media_guid':
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          //$data['media'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'media' ");
        $query->bindValue(':guid', $data['media'][$key]['media_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['media'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `media` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'media_guid':
           case 'id':
               break;
          case 'item_guid':
              $sql .= " `item_id` = :item_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        //echo($sql);
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'media_guid':
           case 'id':
              break;
           case 'item_guid':
              $query->bindValue(':item_id', $guid_to_id['item'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['media'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['media'][$data['media'][$key]['media_guid']] = $data['media'][$key]['id'];
      $id_to_guid['media'][$data['media'][$key]['id']] = $data['media'][$key]['media_guid'];
      
    }
  }





  // update item with primary_media_id
  if (isset($data['item']) && count($data['item']) > 0) {
    foreach ($data['item'] as $key => $record) {
     if ($record['media_guid'] != false) {
       $query = $db->prepare("UPDATE `item` SET `primary_media_id` = :primary_media_id WHERE `id` = :id ");
       $query->bindValue(':primary_media_id', $guid_to_id['media'][$record['media_guid']], PDO::PARAM_INT);
       $query->bindValue(':id', $record['id'], PDO::PARAM_INT);
        if ($query->execute()) {
          // success
        }
      }
    }
  }






  // create fulltext_search
  if (isset($data['fulltext_search']) && count($data['fulltext_search']) > 0) {
    foreach ($data['fulltext_search'] as $key => $record) {
    
      // check if site_page has already been created
      $data['fulltext_search'][$key]['id'] = false;
      //print_r($record);
      //echo("\n");
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'fulltext_search' ");
      $query->bindValue(':guid', $record['fulltext_search_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['fulltext_search'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['fulltext_search'][$key]['id'] === false) {
        //echo("inserting new record into site_page \n");
        $sql = 'INSERT INTO `fulltext_search` SET `id` = :id, ';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'fulltext_search_guid':
            case 'item_set_guid':
            case 'item_guid':
            case 'site_page_guid':
            case 'media_guid':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'fulltext_search_guid':
              break;
            case 'item_set_guid':
              $query->bindValue(':id',  $guid_to_id['item_set'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['item_set'][$value];
              break;
            case 'item_guid':
              $query->bindValue(':id',  $guid_to_id['item'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['item'][$value];
              break;
            case 'site_page_guid':
              $query->bindValue(':id',  $guid_to_id['site_page'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['site_page'][$value];
              break;
            case 'media_guid':
              $query->bindValue(':id',  $guid_to_id['media'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['media'][$value];
              break;
            case 'user_guid':
              $query->bindValue(':owner_id',  $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          //echo("record inserted \n");
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_varchar` = :related_varchar, `related_table` = 'fulltext_search' ");
        $query->bindValue(':guid', $data['fulltext_search'][$key]['fulltext_search_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['fulltext_search'][$key]['id'], PDO::PARAM_INT);
        $query->bindValue(':related_varchar', $data['fulltext_search'][$key]['resource'], PDO::PARAM_STR);
        $query->execute();
      } else {
        //echo("updating new record into site_page \n");
        $sql = 'UPDATE `fulltext_search` SET ';
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'fulltext_search_guid':
            case 'item_set_guid':
            case 'item_guid':
            case 'site_page_guid':
            case 'media_guid':
            case 'resource':
            case 'id':
              break;
            case 'user_guid':
              $sql .= " `owner_id` = :owner_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id AND `resource` = :resource ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
            case 'fulltext_search_guid':
            case 'resource':
            case 'id':
              break;
            case 'item_set_guid':
              $query->bindValue(':id',  $guid_to_id['item_set'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['item_set'][$value];
              break;
            case 'item_guid':
              $query->bindValue(':id',  $guid_to_id['item'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['item'][$value];
              break;
            case 'site_page_guid':
              $query->bindValue(':id',  $guid_to_id['site_page'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['site_page'][$value];
              break;
            case 'media_guid':
              $query->bindValue(':id',  $guid_to_id['media'][$value], PDO::PARAM_INT);
              $data['fulltext_search'][$key]['id'] = $guid_to_id['media'][$value];
              break;
            case 'user_guid':
              $query->bindValue(':owner_id',  $guid_to_id['user'][$value], PDO::PARAM_INT);
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['fulltext_search'][$key]['id'], PDO::PARAM_STR);      
        $query->bindValue(':resource',$data['fulltext_search'][$key]['resource'], PDO::PARAM_STR);      
        if ($query->execute()) {
          //echo("record updated \n");
        }
      }
      
      $guid_to_id['fulltext_search'][$data['fulltext_search'][$key]['fulltext_search_guid']] = $data['fulltext_search'][$key]['id'];
      $id_to_guid['fulltext_search'][$data['fulltext_search'][$key]['id']] = $data['fulltext_search'][$key]['fulltext_search_guid'];
      
    }
  }








  // create site_block_attachment
  if (isset($data['site_block_attachment']) && count($data['site_block_attachment']) > 0) {
    foreach ($data['site_block_attachment'] as $key => $record) {
      // check if site has already been created
      $data['site_block_attachment'][$key]['id'] = false;
      $query = $db->prepare("SELECT `relatedID` FROM `uvaimexguid` WHERE `guid` = :guid AND `related_table` = 'site_block_attachment' ");
      $query->bindValue(':guid', $record['site_block_attachment_guid'], PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $data['site_block_attachment'][$key]['id'] = $row['relatedID'];
        }
      }
      if ($data['site_block_attachment'][$key]['id'] === false) {
        $sql = 'INSERT INTO `site_block_attachment` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_block_attachment_guid':
              break;
           case 'site_page_block_guid':
              $sql .= " `block_id` = :block_id,";
              break;
           case 'item_guid':
              $sql .= " `item_id` = :item_id,";
              break;
           case 'media_guid':
              $sql .= " `media_id` = :media_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_block_attachment_guid':
              break;
            case 'site_page_block_guid':
              if ($value != false) {
                $query->bindValue(':block_id',  $guid_to_id['site_page_block'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':block_id',  null, PDO::PARAM_NULL);
              }
              break;
            case 'item_guid':
              if ($value != false) {
                $query->bindValue(':item_id',  $guid_to_id['item'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':item_id',  null, PDO::PARAM_NULL);
              }
              break;
            case 'media_guid':
              if ($value != false) {
                $query->bindValue(':media_id',  $guid_to_id['media'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':media_id',  null, PDO::PARAM_NULL);
              }
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        if ($query->execute()) {
          $data['site_block_attachment'][$key]['id'] = $db->lastInsertId();
        }
        // insert record into uvaimexguid
        $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `relatedID` = :relatedID, `related_table` = 'site_block_attachment' ");
        $query->bindValue(':guid', $data['site_block_attachment'][$key]['site_block_attachment_guid'], PDO::PARAM_STR);
        $query->bindValue(':relatedID', $data['site_block_attachment'][$key]['id'], PDO::PARAM_INT);
        $query->execute();
      } else {
        $sql = 'UPDATE `site_block_attachment` SET';
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_block_attachment_guid':
              break;
           case 'site_page_block_guid':
              $sql .= " `block_id` = :block_id,";
              break;
           case 'item_guid':
              $sql .= " `item_id` = :item_id,";
              break;
           case 'media_guid':
              $sql .= " `media_id` = :media_id,";
              break;
            default:
              $sql .= " `{$field}` = :{$field},";
              break;
          }
        }
        $sql = rtrim($sql, ', ');
        $sql .= ' WHERE `id` = :id ';
        $query = $db->prepare($sql);
        foreach ($record as $field => $value) {
          switch ($field) {
           case 'site_block_attachment_guid':
              break;
           case 'site_page_block_guid':
              if ($value != false) {
                $query->bindValue(':block_id',  $guid_to_id['site_page_block'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':block_id',  null, PDO::PARAM_NULL);
              }
              break;
            case 'item_guid':
              if ($value != false) {
                $query->bindValue(':item_id',  $guid_to_id['item'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':item_id',  null, PDO::PARAM_NULL);
              }
              break;
            case 'media_guid':
              if ($value != false) {
                $query->bindValue(':media_id',  $guid_to_id['media'][$value], PDO::PARAM_INT);
              } else {
                $query->bindValue(':media_id',  null, PDO::PARAM_NULL);
              }
              break;
            default:
              $query->bindValue(':'.$field, $value, PDO::PARAM_STR);
              break;
          }
        }
        $query->bindValue(':id',$data['site_block_attachment'][$key]['id'], PDO::PARAM_STR);      
        $query->execute();
      }
      
      $guid_to_id['site_block_attachment'][$data['site_block_attachment'][$key]['site_block_attachment_guid']] = $data['site_block_attachment'][$key]['id'];
      $id_to_guid['site_block_attachment'][$data['site_block_attachment'][$key]['id']] = $data['site_block_attachment'][$key]['site_block_attachment_guid'];
      
    }
  }




  // store files
  if (isset($options['writefiles']) && $options['writefiles'] != '') {
    $files_dir = $options['writefiles'];
  } else {
    $files_dir = dirname(dirname($options['config'])).'/files/';
  }
  if (isset($data['storage'])) {
    foreach ($data['storage'] as $location => $storage) {
      if (isset($data['storage'][$location]) && count($data['storage'][$location]) > 0) {
        if (!is_dir($files_dir.$location)) {
          mkdir($files_dir.$location);         
        }
        foreach ($data['storage'][$location] as $storage_id => $file) {
          if (!file_put_contents($files_dir.$location.'/'.$file['name'], base64_decode($file['file']))) {
            trigger_error('Could not write file '.$files_dir.$location.'/'.$file['name'], E_USER_ERROR);
          }
        }
      }
    }
  }
  
  
  
  
  
  // we are done updating but we need to make sure we don't have anything left over from a previous import
  $current_data = uvaimex_export($options,$db,$preferences);
  // let's remove some record that might be different but we would not delete:
  foreach (array('user_setting','module') as $rem) {
    if (isset($current_data[$rem])) {
      unset($current_data[$rem]);
    }
  }
  foreach ($current_data as $table => $content) {
    if (!isset($data[$table])) {
      trigger_error('There is a discrepancy between the source data and the data in this installation, '.$table.' is not present in source.', E_USER_NOTICE);
    } else {
      foreach ($content as $content_uid => $content_data) {
        if (isset($data[$table][$content_uid])) {
          unset($current_data[$table][$content_uid]);
        }
      }
      if (count($current_data[$table]) == 0) {
        unset($current_data[$table]);
      }
    }
  }

  $to_be_removed = array();
  foreach ($current_data as $table => $content) {
    foreach ($content as $content_uid => $content_data) {
      $query = $db->prepare("SELECT * FROM `uvaimexguid` WHERE `guid` = :guid ");
      $query->bindValue(':guid', $content_uid, PDO::PARAM_STR);
      if ($query->execute()) {
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $to_be_removed[$row['id']] = $row;
        }
      }
    }
  }

  foreach ($to_be_removed as $id => $rem) {
    switch ($rem['related_table']) {
      case 'user': 
        // we don't want to remove any user
        break;
      case 'item':
      case 'asset':
      case 'media':
      case 'property':
      case 'resource_class':
      case 'resource_template':
      case 'site_block_attachment':
      case 'site_page':
      case 'site_page_block':
      case 'vocabulary':
        $query = $db->prepare("DELETE FROM `uvaimexguid` WHERE `guid` = :guid ");
        $query->bindValue(':guid', $rem['guid'], PDO::PARAM_STR);
        $query->execute();
        $query = $db->prepare("DELETE FROM `{$rem['related_table']}` WHERE `id` = :id ");
        $query->bindValue(':id', $rem['relatedID'], PDO::PARAM_INT);
        $query->execute();
        break;
      case 'site_setting':
        $query = $db->prepare("DELETE FROM `uvaimexguid` WHERE `guid` = :guid ");
        $query->bindValue(':guid', $rem['guid'], PDO::PARAM_STR);
        $query->execute();
        $query = $db->prepare("DELETE FROM `{$rem['related_table']}` WHERE `id` = :id AND `site_id` = :site_id ");
        $query->bindValue(':id', $rem['related_varchar'], PDO::PARAM_INT);
        $query->bindValue(':site_id', $rem['relatedID'], PDO::PARAM_STR);
        $query->execute();      
        break;
      case 'user_setting':
        $query = $db->prepare("DELETE FROM `uvaimexguid` WHERE `guid` = :guid ");
        $query->bindValue(':guid', $rem['guid'], PDO::PARAM_STR);
        $query->execute();
        $query = $db->prepare("DELETE FROM `{$rem['related_table']}` WHERE `id` = :id AND `user_id` = :user_id ");
        $query->bindValue(':id', $rem['related_varchar'], PDO::PARAM_INT);
        $query->bindValue(':user_id', $rem['relatedID'], PDO::PARAM_STR);
        $query->execute();      
        break;
      case 'resource':
        $query = $db->prepare("DELETE FROM `uvaimexguid` WHERE `guid` = :guid ");
        $query->bindValue(':guid', $rem['guid'], PDO::PARAM_STR);
        $query->execute();
        $query = $db->prepare("DELETE FROM `{$rem['related_table']}` WHERE `id` = :id AND `resource_type` LIKE :resource_type ");
        $query->bindValue(':id', $rem['relatedID'], PDO::PARAM_INT);
        $query->bindValue(':resource_type', 'Omeka\\Entity\\'.ucwords($rem['related_varchar']), PDO::PARAM_STR);
        $query->execute();      
        break;
    }
  }




}




/* 
 * @_get_theme_info function
 * This function extrabcts the theme information form the theme.ini file
 * implies that an $options['config'] is set and poiting to a valid Omeka-s config file 
 * The location of the Omeka-s config file is used to deduct the location of the theme config.
 */

function _get_theme_info($slug) {
  global $options;
  $theme = array();
  $themeini = _read_pref(dirname(dirname($options['config'])).'/themes/'.$slug.'/config/theme.ini');
  $theme = $themeini['info'];
  $theme['themeslug'] = $slug;
  return $theme;
}




function _create_uvaimexguid_table($db,$preferences) {

  $uvaimex_table = false;
  $query = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_TYPE LIKE 'BASE TABLE' AND TABLE_NAME = 'uvaimexguid' AND `TABLE_SCHEMA` =  :dbname ");
  $query->bindValue(':dbname', $preferences['dbname'], PDO::PARAM_STR);
  if ($query->execute()) {
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      if ($row['TABLE_NAME'] == 'uvaimexguid') {
        $uvaimex_table = true;
      }
    }
  }

  // guid
  $guid = false;
  if ($uvaimex_table === false) {
    $guid = _generate_guid();
    // we need to create the table
    $query = $db->prepare("CREATE TABLE `uvaimexguid` (`id` int NOT NULL, `guid` varchar(255) COLLATE utf8mb4_bin NOT NULL DEFAULT '', `relatedID` INT NULL DEFAULT NULL, `related_varchar` VARCHAR(255) NULL DEFAULT NULL, `related_table` varchar(128) COLLATE utf8mb4_bin NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    $query->execute();
    $query = $db->prepare("ALTER TABLE `uvaimexguid` ADD PRIMARY KEY (`id`)");
    $query->execute();
    $query = $db->prepare("ALTER TABLE `uvaimexguid` MODIFY `id` int NOT NULL AUTO_INCREMENT");
    $query->execute();
    $query = $db->prepare("INSERT INTO `uvaimexguid` SET `guid` = :guid, `related_table` = 'installation'");
    $query->bindValue(':guid', $guid, PDO::PARAM_STR);
    $query->execute();
  } else {
    $query = $db->prepare("SELECT `guid` FROM  `uvaimexguid` WHERE `related_table` = 'installation'");
    if ($query->execute()) {
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $guid = $row['guid'];
      }
    }
  }

  if ($guid === false) {
    trigger_error('An unexpected issue happened with guid retrieval or creation', E_USER_ERROR);
    die;
  }

  return $guid;

}




/* 
 * @_process_account_email function
 * This function analizes the email from a specific account
 * bool trigger_error ( string $error_msg [, int $error_type = E_USER_ERROR ] )
 */
function error_handler($level, $message, $file, $line, $context=false) {
  if($level === E_USER_WARNING) {
    print_msg('Warning:'.$message);
    return(true); // Prevent the PHP error handler from continuing
  } else if ($level === E_USER_NOTICE) {
    print_msg('Notice: '.$message);
    return(true); // Prevent the PHP error handler from continuing
  } else if($level === E_USER_ERROR) {
    print_msg('Error:'.$message);
    return(true); // Prevent the PHP error handler from continuing
  }
  return(false); // Continue to PHP's error handler
}







/* 
 * @print_msg function
 * This function prints debugging messages when the script is called with --test
 */
function print_msg($msg) {
  global $options;
  if (is_array($msg) || is_object($msg)) {
    print_r($msg);
  } else {
    echo($msg."\n");
  }
}



