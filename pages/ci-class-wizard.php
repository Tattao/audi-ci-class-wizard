<?php

require_once(dirname(__FILE__, 4).DIRECTORY_SEPARATOR.'approot.inc.php');
require_once(APPROOT.'application/startup.inc.php');
require_once(APPROOT.'application/loginwebpage.class.inc.php');
require_once(APPROOT.'application/webpage.class.inc.php');

LoginWebPage::DoLogin();

require_once(APPROOT.'extensions/audi-ci-class-wizard/lib/functions.inc.php');

use Combodo\iTop\Application\UI\Base\Layout\PageContent\PageContentFactory;
use Combodo\iTop\Application\UI\Base\Layout\MultiColumn\MultiColumnUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Layout\MultiColumn\Column\ColumnUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Panel\PanelUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Panel\Panel;
use Combodo\iTop\Application\UI\Base\Component\Form\FormUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Html\Html;
use Combodo\iTop\Application\UI\Base\Component\Button\ButtonUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Input\InputUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Input\TextArea;
use Combodo\iTop\Application\UI\Base\Component\Field\FieldUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Field\Field;

$sTitle = Dict::S('Menu:AudiCiClassWizard');
$oP = new iTopWebPage($sTitle.' - '.Dict::S('UI:AudiCiClassWizard:PageTitle'));

$oP->add_style(
    // CSS fix for "thin and long" inputs and alignment
    '#fields_table.ibo-table th,#fields_table.ibo-table td{padding:6px 8px;vertical-align:middle;}'
    .'#parent_attr_table.ibo-table th,#parent_attr_table.ibo-table td{padding:6px 8px;vertical-align:middle;}'
    .'#fields_table input[type="text"],#fields_table select{min-height:30px;}'
    .'.wizard-toolbar{display:flex;gap:10px;align-items:center;margin-top:8px;margin-bottom:10px;}'
    .'.ibo-input-error{color:#d0021b; font-size:0.9em; margin-top:4px; font-weight:bold;}'
    // Restrict width of inputs in the wizard form to avoid "long and thin" look
    .'.ibo-field .ibo-input { max-width: 400px; width: 100%; }' 
    .'.ibo-field select { max-width: 400px; width: 100%; }'
    .'.ibo-field textarea { max-width: 100%; }'
    // Ensure labels do not wrap and question mark stays with the label
    .'.ibo-field--label { white-space: nowrap !important; display: inline-flex; align-items: center; }'
);

// Build Parent Class choices (restrict to FunctionalCI hierarchy + Location)
$aParentClasses = array();
try {
    $aParentClasses = array('cmdbAbstractObject', 'FunctionalCI', 'Location');
    if (method_exists('MetaModel', 'GetSubclasses')) {
        $subs = MetaModel::GetSubclasses('FunctionalCI');
        if (is_array($subs)) {
            $aParentClasses = array_merge($aParentClasses, $subs);
        }
        // Also include Location subclasses
        $subsLoc = MetaModel::GetSubclasses('Location');
        if (is_array($subsLoc)) {
            $aParentClasses = array_merge($aParentClasses, $subsLoc);
        }
    }
} catch (Exception $e) {
    $aParentClasses = array('cmdbAbstractObject', 'FunctionalCI', 'Location');
}
$aParentClasses = array_values(array_unique($aParentClasses));

$aParentZlists = array();
$aParentMeta   = array();  // 新增：父类属性元数据

foreach ($aParentClasses as $pc) {
    try {
        // 1. 全部属性：兼容不同版本（有的是 code=>def，有的是 0=>'name'）
        $aAllAttr  = MetaModel::GetAttributesList($pc);
        $aAllCodes = array();

        if (is_array($aAllAttr)) {
            $firstKey = array_key_first($aAllAttr);
            if (is_int($firstKey)) {
                // 形式：0=>'name',1=>'org_id'...
                foreach ($aAllAttr as $val) {
                    $code = (string) $val;
                    $aAllCodes[] = $code;
                    $oDef = MetaModel::GetAttributeDef($pc, $code);
                    $aParentMeta[$pc][$code] = array(
                        'code'  => $code,
                        'label' => $oDef->GetLabel(),
                        'type'  => get_class($oDef),
                    );
                }
            } else {
                // 形式：code => def
                foreach ($aAllAttr as $code => $oDef) {
                    $code = (string) $code;
                    $aAllCodes[] = $code;
                    $aParentMeta[$pc][$code] = array(
                        'code'  => $code,
                        'label' => (string) ($oDef instanceof AttributeDefinition ? $oDef->GetLabel() : ''),
                        'type'  => (is_object($oDef) ? get_class($oDef) : 'unknown'),
                    );
                }
            }
        }

        // 2. ZList：details / search / list 是哪些字段
        $aDetailsItems = MetaModel::GetZListItems($pc, 'details');
        $aSearchItems  = MetaModel::GetZListItems($pc, 'search');
        $aListItems    = MetaModel::GetZListItems($pc, 'list');

        // 注意：这里 value 才是字段名，所以用 array_values()
        $details = is_array($aDetailsItems) ? array_values($aDetailsItems) : array();
        $search  = is_array($aSearchItems)  ? array_values($aSearchItems)  : array();
        $list    = is_array($aListItems)    ? array_values($aListItems)    : array();

        // 3. 兜底：ZList 为空时，从全部属性列表截一段，避免空白
        if (empty($details)) { $details = array_slice($aAllCodes, 0, 8); }
        if (empty($search))  { $search  = array_slice($aAllCodes, 0, 8); }
        if (empty($list))    { $list    = array_slice($aAllCodes, 0, 5); }

        $aParentZlists[$pc] = array(
            'details' => $details,
            'search'  => $search,
            'list'    => $list,
            'all'     => $aAllCodes,
        );
    } catch (Exception $e) {
        $aParentZlists[$pc] = array(
            'details' => array('name'),
            'search'  => array('name'),
            'list'    => array('name'),
            'all'     => array('name'),
        );
    }
}


$op = utils::ReadParam('operation', '');

if ($op === 'generate')
{
    $module     = utils::ReadPostedParam('module', '', 'raw_data');
    $module_lbl = utils::ReadPostedParam('module_label', 'raw_data');
    $classId    = utils::ReadPostedParam('class_id', '', 'raw_data');
    $class_lbl  = utils::ReadPostedParam('class_label', '', 'raw_data'); // New field
    $parent     = utils::ReadPostedParam('parent_class', 'PhysicalDevice', 'raw_data');
    $dbtable    = utils::ReadPostedParam('db_table', '', 'raw_data');
    $icon       = utils::ReadPostedParam('icon', '', 'raw_data');
    $deps       = utils::ReadPostedParam('dependencies', '', 'raw_data');

    // Fallback for class label
    if (trim($class_lbl) === '') {
        $class_lbl = $classId;
    }

    // 处理字段
    $aFields = [];
    $field_ids   = utils::ReadPostedParam('field_id', [], 'raw_data');
    $field_type  = utils::ReadPostedParam('field_type', [], 'raw_data');
    $field_sql   = utils::ReadPostedParam('field_sql', [], 'raw_data');
    $field_enum  = utils::ReadPostedParam('field_enum', [], 'raw_data');

    for ($i=0; $i<count($field_ids); $i++) {
        $id = trim($field_ids[$i]);
        if ($id === '') continue;

        // Normalize id: allow a-z, 0-9, underscore. Replace spaces.
        $idNorm = preg_replace('/[^A-Za-z0-9_]/', '_', $id);
        if ($idNorm !== $id) { $id = $idNorm; }

        $type = $field_type[$i] ?? 'AttributeString';
        $sql  = trim($field_sql[$i] ?? '');
        if ($sql === '') { $sql = $id; }

        $a = [
            'id' => $id,
            'type' => $type,
            'sql'  => $sql,
            'is_null_allowed' => true,
            'default_value' => '',
        ];

        if ($field_type[$i] === 'AttributeEnum') {
            $vals = array_filter(array_map('trim', explode(',', $field_enum[$i])));
            $a['values'] = $vals;
        }
        $aFields[] = $a;
    }

    $details_inherit = utils::ReadPostedParam('details_inherit', [], 'raw_data');
    $search_inherit  = utils::ReadPostedParam('search_inherit',  [], 'raw_data');
    $list_inherit    = utils::ReadPostedParam('list_inherit',    [], 'raw_data');

    $candidates = array(
        rtrim(APPROOT, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR,
        rtrim(dirname(APPROOT), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR,
    );
    $extRoot = null;
    foreach ($candidates as $c) {
        if (is_dir($c) && is_writable($c)) { $extRoot = $c; break; }
    }
    if ($extRoot === null) {
        $extRoot = $candidates[0];
        if (!is_dir($extRoot) || !is_writable($extRoot)) {
            $oP->add('<h2>写入失败</h2>');
            $oP->add('<div>扩展根目录不可写：'.utils::HtmlEntities($extRoot).'</div>');
            $oP->output();
            exit;
        }
    }
    
    // --- Uniqueness Validation ---
    $aErrors = array();
    $aFieldErrors = array();

    // 1. Module Name (Directory)
    $sDir = $extRoot.$module.DIRECTORY_SEPARATOR;
    if (is_dir($sDir)) {
         $msg = "Module Name (Directory) '$module' already exists: $sDir";
         $aErrors[] = $msg;
         $aFieldErrors['module'] = $msg;
    }

    // 2. Module Label (Scan all extensions)
    // Scan all module.xxx.php files in extensions/ to check if label is used
    $aExtFiles = glob($extRoot . '*/module.*.php');
    if ($aExtFiles) {
        foreach ($aExtFiles as $sExtFile) {
            $sContent = file_get_contents($sExtFile);
            if (preg_match("/'label'\s*=>\s*'(.*?)'/", $sContent, $m)) {
                if (trim($m[1]) === trim($module_lbl)) {
                    $msg = "Module Label '$module_lbl' 已被其他模块使用 (File: ".basename($sExtFile).")";
                    $aErrors[] = $msg;
                    $aFieldErrors['module_label'] = $msg;
                    break; 
                }
            }
        }
    }

    // 3. Class ID
    // Check if class exists in current environment
    if (class_exists($classId) || (method_exists('MetaModel', 'IsValidClass') && MetaModel::IsValidClass($classId))) {
        $msg = "Class ID '$classId' 已存在 (Internal Class Name)";
        $aErrors[] = $msg;
        $aFieldErrors['class_id'] = $msg;
    }

    // 4. Class Label & 5. DB Table
    // Iterate all known classes
    if (method_exists('MetaModel', 'GetClasses')) {
        foreach (MetaModel::GetClasses() as $cls) {
            // Check Class Label (Translated Name)
            // Note: This checks against the current user's language translation.
            $existingLabel = MetaModel::GetName($cls);
            if ($existingLabel === $class_lbl) {
                $msg = "Class Label '$class_lbl' 已被类 '$cls' 使用";
                $aErrors[] = $msg;
                $aFieldErrors['class_label'] = $msg;
            }
            
            // Check DB Table
            $existingTable = MetaModel::DBGetTable($cls);
            if ($existingTable === $dbtable) {
                $msg = "DB Table '$dbtable' 已被类 '$cls' 使用";
                $aErrors[] = $msg;
                $aFieldErrors['db_table'] = $msg;
            }
        }
    }

    if (empty($aErrors)) {
        // --- End Validation ---
    
        if (@mkdir($sDir, 0775, true) === false) {
            $oP->add('<h2>创建目录失败</h2>');
            $oP->add('<div>无法创建：'.utils::HtmlEntities($sDir).'</div>');
            $oP->output();
            exit;
        }

        // 处理图标上传
        if (isset($_FILES['icon_file']) && ($_FILES['icon_file']['error'] === UPLOAD_ERR_OK)) {
            $sName = basename($_FILES['icon_file']['name']);
            $sExt = strtolower(pathinfo($sName, PATHINFO_EXTENSION));
            $aAllowed = array('png','svg','jpg','jpeg','gif','ico');
            if (in_array($sExt, $aAllowed)) {
                $sImagesDir = $sDir.'images/';
                if (!is_dir($sImagesDir)) {@mkdir($sImagesDir, 0775, true);}    
                $sTarget = $sImagesDir.$classId.'.'.$sExt;
                if (move_uploaded_file($_FILES['icon_file']['tmp_name'], $sTarget)) {
                    $icon = 'images/'.$classId.'.'.$sExt;
                }
            }
        }
        // 设置图标的安全默认值（可为空则使用通用图标）
        if (trim($icon) === '') {
            $icon = '../../images/icons/icons8-server.svg';
        }

        // 写入 datamodel
        // Normalize classId and dbtable
        $classId = preg_replace('/[^A-Za-z0-9_]/', '_', $classId);
        if ($dbtable === '') { $dbtable = strtolower($classId); }
        $dbtable = preg_replace('/[^a-z0-9_]/', '_', strtolower($dbtable));
        $sXml = GenerateDataModelXml($classId, $parent, $dbtable, $icon, $aFields, (array)$details_inherit, (array)$search_inherit, (array)$list_inherit);
        $r1 = file_put_contents($sDir.'datamodel.'.$module.'.xml', $sXml);
        if ($r1 === false) {
            $oP->add('<h2>写入失败</h2>');
            $oP->add('<div>无法写入 datamodel.'.utils::HtmlEntities($module).'.xml</div>');
            $oP->output();
            exit;
        }

        // 写入 module.php
        $sPhp = GenerateModulePhp($module, $module_lbl, '1.0.0', 'business', $deps);
        $r2 = file_put_contents($sDir.'module.'.$module.'.php', $sPhp);
        if ($r2 === false) {
            $oP->add('<h2>写入失败</h2>');
            $oP->add('<div>无法写入 module.'.utils::HtmlEntities($module).'.php</div>');
            $oP->output();
            exit;
        }

        $sDict = GenerateDictPhp($module, $classId, $class_lbl, $aFields);
        $r3 = file_put_contents($sDir.'en.dict.'.$module.'.php', $sDict);
        if ($r3 === false) {
            $oP->add('<h2>写入失败</h2>');
            $oP->add('<div>无法写入 en.dict.'.utils::HtmlEntities($module).'.php</div>');
            $oP->output();
            exit;
        }

        $oP->add("<h1>模块创建成功</h1>");
        $oP->add('<p>目录：'.utils::HtmlEntities($sDir).'</p>');
        $oP->add('<p><a href="'.utils::GetAbsoluteUrlAppRoot().'setup/">点击这里执行 iTop Setup（Update existing instance）</a></p>');

        $oP->output();
        exit;
    }
}

$op2 = utils::ReadParam('operation_add_attr', '');
if ($op2 === 'add_attr') {
    $classId = utils::ReadPostedParam('aa_class_id', '', 'raw_data');
    $fid     = utils::ReadPostedParam('aa_field_id', '', 'raw_data');
    $ftype   = utils::ReadPostedParam('aa_field_type', 'AttributeString', 'raw_data');
    $fsql    = utils::ReadPostedParam('aa_field_sql', '', 'raw_data');
    $fvals   = utils::ReadPostedParam('aa_field_enum', '', 'raw_data');
    $inDetails = (bool) utils::ReadPostedParam('aa_in_details', '1', 'raw_data');
    $inSearch  = (bool) utils::ReadPostedParam('aa_in_search',  '1', 'raw_data');
    $inList    = (bool) utils::ReadPostedParam('aa_in_list',    '0', 'raw_data');

    $fid = preg_replace('/[^A-Za-z0-9_]/', '_', trim($fid));
    $fsql = trim($fsql) === '' ? $fid : preg_replace('/[^A-Za-z0-9_]/', '_', trim($fsql));
    $aField = array(
        'id' => $fid,
        'type' => $ftype,
        'sql' => $fsql,
        'is_null_allowed' => true,
        'default_value' => '',
    );
    if ($ftype === 'AttributeEnum') {
        $aField['values'] = array_filter(array_map('trim', explode(',', (string)$fvals)));
    }

    $sDeltaXml = GenerateAddAttributeDeltaXml($classId, $aField, $inDetails, $inSearch, $inList);

    $module = utils::ReadPostedParam('aa_module', 'audi-ci-class-delta', 'raw_data');
    $extRoot = rtrim(APPROOT, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR;
    $sDir = $extRoot.$module.DIRECTORY_SEPARATOR;
    if (!is_dir($sDir)) { @mkdir($sDir, 0775, true); }

    $r1 = file_put_contents($sDir.'datamodel.'.$module.'.xml', $sDeltaXml);
    $r2 = file_put_contents($sDir.'module.'.$module.'.php', GenerateModulePhp($module, 'Add Attribute delta', '1.0.0', 'business', ''));
    $r3 = file_put_contents($sDir.'en.dict.'.$module.'.php', GenerateDictPhp($module, $classId, $classId, array(array('id'=>$fid))));

    if ($r1 && $r2 && $r3) {
        $oP->add('<div class="ibo-alert ibo-alert--success"><h2>增量XML已生成</h2><p>目录：'.utils::HtmlEntities($sDir).'</p><p><a href="'.utils::GetAbsoluteUrlAppRoot().'setup/">点击执行 iTop Setup 使更改生效</a></p></div>');
    } else {
        $oP->add('<div class="ibo-alert ibo-alert--error"><h2>生成失败</h2><p>请检查写入权限：'.utils::HtmlEntities($sDir).'</p></div>');
    }
}

$sParentOptions = '';
foreach ($aParentClasses as $pc) {
    $bAbstract = false;
    try { $bAbstract = MetaModel::IsAbstract($pc); } catch (Exception $e) { $bAbstract = false; }
    $label = $pc.($bAbstract ? ' (abstract)' : '');
    $sParentOptions .= '<option value="'.utils::HtmlEntities($pc).'">'.utils::HtmlEntities($label).'</option>';
}

// Prepare Icon List
$sIconDir = APPROOT.'images/icons/';
$sIconHtml = '';
if (is_dir($sIconDir)) {
    $aFiles = scandir($sIconDir);
    natsort($aFiles); 
    foreach ($aFiles as $sFile) {
        if ($sFile === '.' || $sFile === '..') continue;
        if (preg_match('/\.(svg|png)$/i', $sFile)) {
             $url = utils::GetAbsoluteUrlAppRoot() . 'images/icons/' . $sFile;
             $val = '../../images/icons/' . $sFile;
             $sIconHtml .= '<img src="'.$url.'" title="'.$sFile.'" data-val="'.$val.'" class="icon-picker-item" onclick="selectIcon(this)" style="width:32px; height:32px; cursor:pointer; border:2px solid transparent; padding:2px; margin:2px;">';
        }
    }
}

$sErrorBlock = '';
// Only show generic top-level error if needed, but user requested inline. 
// We can leave sErrorBlock empty or use it for non-field errors if any.

// Prepare error messages and input values for HTML
$sErrModule      = isset($aFieldErrors['module'])       ? '<div class="ibo-input-error">'.utils::HtmlEntities($aFieldErrors['module']).'</div>'       : '';
$sErrModuleLabel = isset($aFieldErrors['module_label']) ? '<div class="ibo-input-error">'.utils::HtmlEntities($aFieldErrors['module_label']).'</div>' : '';
$sErrClassId     = isset($aFieldErrors['class_id'])     ? '<div class="ibo-input-error">'.utils::HtmlEntities($aFieldErrors['class_id']).'</div>'     : '';
$sErrClassLabel  = isset($aFieldErrors['class_label'])  ? '<div class="ibo-input-error">'.utils::HtmlEntities($aFieldErrors['class_label']).'</div>'  : '';
$sErrDbTable     = isset($aFieldErrors['db_table'])     ? '<div class="ibo-input-error">'.utils::HtmlEntities($aFieldErrors['db_table']).'</div>'     : '';

// Default values (if not submitted, use defaults or empty)
// Note: ReadPostedParam returns raw data, so we must escape it for HTML value attribute.
$valModule      = isset($module) ? utils::HtmlEntities($module) : '';
$valModuleLabel = isset($module_lbl) ? utils::HtmlEntities($module_lbl) : '';
$valDeps        = isset($deps) ? utils::HtmlEntities($deps) : 'itop-config-mgmt/3.2.1';
$valClassId     = isset($classId) ? utils::HtmlEntities($classId) : '';
$valClassLabel  = isset($class_lbl) ? utils::HtmlEntities($class_lbl) : '';
$valDbTable     = isset($dbtable) ? utils::HtmlEntities($dbtable) : '';

// --- UI Construction ---

$oForm = FormUIBlockFactory::MakeStandard('generate_form');
$oForm->AddSubBlock(InputUIBlockFactory::MakeForHidden('operation', 'generate'));

// --- Panel 1: Module Info ---
$oPanelModule = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('Module Info 模块信息');
$oForm->AddSubBlock($oPanelModule);

$oModuleCols = MultiColumnUIBlockFactory::MakeStandard();

// Module Name
$oInputModule = InputUIBlockFactory::MakeStandard('text', 'module', $valModule);
$oInputModule->SetPlaceholder('audi-av-ci');
$oFieldModule = FieldUIBlockFactory::MakeFromObject('Module name 模块名', $oInputModule, null);
$oFieldModule->SetDescription('对应 extensions/ 下的目录名。仅限英文小写、数字、连字符，无空格 (e.g., audi-av-ci)');

$oColMod1 = ColumnUIBlockFactory::MakeStandard();
$oColMod1->AddSubBlock($oFieldModule);
if (!empty($sErrModule)) { $oColMod1->AddSubBlock(new Html($sErrModule)); }
$oModuleCols->AddSubBlock($oColMod1);

// Module Label
$oInputModuleLabel = InputUIBlockFactory::MakeStandard('text', 'module_label', $valModuleLabel);
$oInputModuleLabel->SetPlaceholder('New CI Class Module');
$oFieldModuleLabel = FieldUIBlockFactory::MakeFromObject('Module label 模块标签', $oInputModuleLabel, null);
$oFieldModuleLabel->SetDescription('显示在 iTop Setup Wizard 列表中的名称。支持空格 (e.g., Audio/Video CI Extension)');

$oColMod2 = ColumnUIBlockFactory::MakeStandard();
$oColMod2->AddSubBlock($oFieldModuleLabel);
if (!empty($sErrModuleLabel)) { $oColMod2->AddSubBlock(new Html($sErrModuleLabel)); }
$oModuleCols->AddSubBlock($oColMod2);

$oPanelModule->AddSubBlock($oModuleCols);

// Dependencies
$oInputDeps = new TextArea('dependencies', $valDeps);
$oInputDeps->SetPlaceholder('itop-config-mgmt/3.2.1');
$oFieldDeps = FieldUIBlockFactory::MakeFromObject('Dependencies 依赖（逗号分隔）', $oInputDeps, Field::ENUM_FIELD_LAYOUT_LARGE);
$oFieldDeps->SetDescription('示例 Example: itop-config-mgmt/3.2.1,itop-endusers-devices/3.2.1');
$oPanelModule->AddSubBlock($oFieldDeps);


// --- Panel 2: CI Class Info ---
$oPanelClass = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('CI Class Info CI类信息');
$oForm->AddSubBlock($oPanelClass);

$oClassCols = MultiColumnUIBlockFactory::MakeStandard();

// Class ID
$oInputClassId = InputUIBlockFactory::MakeStandard('text', 'class_id', $valClassId);
$oInputClassId->SetPlaceholder('TvDevice');
$oFieldClassId = FieldUIBlockFactory::MakeFromObject('Class ID 类ID', $oInputClassId, null);
$oFieldClassId->SetDescription('内部使用的 PHP 类名。必须英文开头，PascalCase 风格 (e.g., TvDevice, ServerNode)');

$oColClass1 = ColumnUIBlockFactory::MakeStandard();
$oColClass1->AddSubBlock($oFieldClassId);
if (!empty($sErrClassId)) { $oColClass1->AddSubBlock(new Html($sErrClassId)); }
$oClassCols->AddSubBlock($oColClass1);

// Class Label
$oInputClassLabel = InputUIBlockFactory::MakeStandard('text', 'class_label', $valClassLabel);
$oInputClassLabel->SetPlaceholder('TV Device');
$oFieldClassLabel = FieldUIBlockFactory::MakeFromObject('Class Label 类显示名', $oInputClassLabel, null);
$oFieldClassLabel->SetDescription('显示在 iTop 菜单、详情页的名称。支持中文/英文 (e.g., TV Device, 电视设备)');

$oColClass2 = ColumnUIBlockFactory::MakeStandard();
$oColClass2->AddSubBlock($oFieldClassLabel);
if (!empty($sErrClassLabel)) { $oColClass2->AddSubBlock(new Html($sErrClassLabel)); }
$oClassCols->AddSubBlock($oColClass2);

$oPanelClass->AddSubBlock($oClassCols);

$oClassCols2 = MultiColumnUIBlockFactory::MakeStandard();

// Add required assets for Treeview and Selectize
$oP->LinkStylesheetFromAppRoot('css/jquery.treeview.css');
$oP->LinkScriptFromAppRoot('js/jquery.treeview.js');
$oP->LinkStylesheetFromAppRoot('css/selectize.default.css');
$oP->LinkScriptFromAppRoot('js/selectize.js');

// Parent Class Tree Builder
function getSubclassTreeHtml($sClass, $aAllowedClasses, $level = 0) {
    $sHtml = '';
    
    $aDirectChildren = array();
    foreach ($aAllowedClasses as $candidate) {
        if (strcasecmp($candidate, $sClass) === 0) continue;

        $parent = '';
        try {
            if (method_exists('MetaModel', 'GetParentClass') && MetaModel::IsValidClass($candidate)) {
                $parent = MetaModel::GetParentClass($candidate);
            }
        } catch (Exception $e) {
            // Ignore exceptions
        } catch (Error $e) {
            // Ignore fatal errors (PHP 7+) like TypeErrors from count(null)
        }
        
        // Special case: FunctionalCI's parent might return empty string or null if it's considered a root in some contexts,
        // OR if sClass is cmdbAbstractObject, we should manually include FunctionalCI if it claims no parent or its parent is cmdbAbstractObject.
        // Based on debug: Parent of FunctionalCI is empty string!
        
        if ($sClass === 'cmdbAbstractObject' && $candidate === 'FunctionalCI') {
             // Force FunctionalCI to be a child of cmdbAbstractObject
             $aDirectChildren[] = $candidate;
             continue;
        }

        if (strcasecmp($parent, $sClass) === 0) {
            $aDirectChildren[] = $candidate;
        }
    }
    
    sort($aDirectChildren);

    if (!empty($aDirectChildren)) {
        $sHtml .= '<ul>';
        foreach ($aDirectChildren as $child) {
            $label = $child;
            try {
               $label = MetaModel::GetName($child);
            } catch(Exception $e){}
            
            $sHtml .= '<li class="closed">';
            // Add a clickable span that acts as selector
            $sHtml .= '<span class="folder tree-node-selector" data-class="'.$child.'" style="cursor:pointer;">'.utils::HtmlEntities($label).' ('.$child.')</span>';
            
            // Recursively add children
            $sHtml .= getSubclassTreeHtml($child, $aAllowedClasses, $level + 1);
            $sHtml .= '</li>';
        }
        $sHtml .= '</ul>';
    }
    return $sHtml;
}

// Build Search Options
$sSearchOptions = '<option value="">Select a class...</option>';
foreach ($aParentClasses as $pc) {
    $label = $pc;
    try { $label = MetaModel::GetName($pc); } catch (Exception $e) {}
    $sSearchOptions .= '<option value="'.$pc.'">'.$label.' ('.$pc.')</option>';
}

// Build Tree HTML
$sTreeHtml = '<ul id="parent_class_tree" class="treeview filetree">';

$root = 'cmdbAbstractObject';
if (in_array($root, $aParentClasses)) {
     $label = $root; 
     try { $label = MetaModel::GetName($root); } catch(Exception $e){}
     $sTreeHtml .= '<li class="open"><span class="folder tree-node-selector" data-class="'.$root.'" style="cursor:pointer; font-weight:bold;">'.utils::HtmlEntities($label).' ('.$root.')</span>';
     $sTreeHtml .= getSubclassTreeHtml($root, $aParentClasses);
     $sTreeHtml .= '</li>';
} else {
    $root = 'FunctionalCI';
    if (in_array($root, $aParentClasses)) {
         $label = $root; 
         try { $label = MetaModel::GetName($root); } catch(Exception $e){}
         $sTreeHtml .= '<li class="open"><span class="folder tree-node-selector" data-class="'.$root.'" style="cursor:pointer; font-weight:bold;">'.utils::HtmlEntities($label).' ('.$root.')</span>';
         $sTreeHtml .= getSubclassTreeHtml($root, $aParentClasses);
         $sTreeHtml .= '</li>';
    }
}
$sTreeHtml .= '</ul>';

$sParentSelectorHtml = <<<HTML
<div id="parent_class_selector_container">
    <input type="hidden" name="parent_class" id="parent_class_input" value="">
    <div style="margin-bottom: 5px;">
        <select id="parent_class_search" style="width:100%;">
            {$sSearchOptions}
        </select>
    </div>
    <div style="border: 1px solid #ccc; min-height: 300px; max-height: 600px; overflow-y: auto; padding: 10px; resize: vertical; background: #fff; border-radius: 4px;">
        {$sTreeHtml}
    </div>
</div>
HTML;

$oFieldParent = FieldUIBlockFactory::MakeFromObject('Parent Class 父类', new Html($sParentSelectorHtml), null);
$oFieldParent->SetDescription('可选择顶层 cmdbAbstractObject 或 FunctionalCI；继承抽象类是允许的');
$oClassCols2->AddSubBlock(ColumnUIBlockFactory::MakeForBlock($oFieldParent));

// Add JS for Selectize and Treeview interaction
$oP->add_ready_script(<<<JS
    // Initialize Treeview
    $('#parent_class_tree').treeview({
        collapsed: true,
        animated: "fast",
        unique: false
    });

    // Initialize Selectize
    var \$select = $('#parent_class_search').selectize({
        create: false,
        sortField: 'text',
        onChange: function(value) {
            if (value) {
                selectClassInTree(value);
            }
        }
    });
    var selectize = \$select[0].selectize;

    // Function to handle selection
    function selectClassInTree(classId) {
        // Update Hidden Input
        $('#parent_class_input').val(classId).trigger('change');
        
        // Visual Feedback in Tree
        $('.tree-node-selector').css('font-weight', 'normal').css('color', 'black');
        var target = $('.tree-node-selector[data-class="' + classId + '"]');
        if (target.length) {
            target.css('font-weight', 'bold').css('color', '#d0021b');
            
            // Expand parents
            target.parents('li.closed').each(function(){
                $(this).removeClass('closed').addClass('open');
                $(this).children('ul').show();
                $(this).children('.hitarea').removeClass('expandable-hitarea').addClass('collapsable-hitarea');
            });
            
            // Scroll to view
             var container = target.closest('div');
             if (container.length) {
                 container.scrollTop(target.offset().top - container.offset().top + container.scrollTop() - 50);
             }
        }
        
        // Sync Selectize if triggered from Tree
        if (selectize.getValue() !== classId) {
            selectize.setValue(classId, true); // true = silent
        }
        
        // Trigger external change handler (for rendering attributes table)
        // Check if function exists (it is defined later in the file)
        if (typeof renderParentAttrTable === 'function') {
            renderParentAttrTable(classId);
        }
    }

    // Click handler for tree nodes
    $('.tree-node-selector').on('click', function() {
        var cls = $(this).data('class');
        selectClassInTree(cls);
    });
    
    // Initial selection logic moved to end of file or here
    // We need to wait for renderParentAttrTable to be defined?
    // Actually renderParentAttrTable is defined in a later script block.
    // So we should trigger it there or ensure this script runs after.
    // add_ready_script executes in order.
    // The other script block is added later in the PHP file, so it will be output later.
    // But we need to call it.
    // Let's rely on the change event of the hidden input?
    // renderParentAttrTable listens to select[name="parent_class"].
    // But we changed name="parent_class" to be the hidden input.
    // Hidden inputs don't always trigger 'change' automatically when set via JS, but we did .trigger('change').
    
    // Let's make sure the listener is attached to the hidden input.
    // The original code: var sel = $('select[name="parent_class"]');
    // Now it is input[type="hidden"][name="parent_class"].
    // We need to update the listener in the other script block too.
JS
);


// Inject initial value logic
$sInitialClass = isset($parent) ? $parent : 'PhysicalDevice';
$oP->add_ready_script("selectClassInTree('$sInitialClass');");


// DB Table
$oInputDbTable = InputUIBlockFactory::MakeStandard('text', 'db_table', $valDbTable);
$oInputDbTable->SetPlaceholder('tv_device');
$oFieldDbTable = FieldUIBlockFactory::MakeFromObject('DB Table 数据表名', $oInputDbTable, null);
$oFieldDbTable->SetDescription('数据库表名。英文小写 snake_case (e.g., tv_device)');

$oColClass3 = ColumnUIBlockFactory::MakeStandard();
$oColClass3->AddSubBlock($oFieldDbTable);
if (!empty($sErrDbTable)) { $oColClass3->AddSubBlock(new Html($sErrDbTable)); }
$oClassCols2->AddSubBlock($oColClass3);

$oPanelClass->AddSubBlock($oClassCols2);

// Parent Attributes Table
$sParentTableHtml = <<<HTML
<table id="parent_attr_table" class="ibo-table" cellpadding="3">
  <thead>
    <tr>
      <th>属性编码</th>
      <th>名称</th>
      <th>类型</th>
      <th>details 默认</th>
      <th>继承到 details</th>
      <th>search 默认</th>
      <th>继承到 search</th>
      <th>list 默认</th>
      <th>继承到 list</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
HTML;
$oFieldParentAttr = FieldUIBlockFactory::MakeLarge('父类属性继承（默认勾选父类的 details/search/list）', $sParentTableHtml);
$oFieldParentAttr->SetDescription('默认列表示父类当前 ZList，下面的勾选框为本次新建类要继承的字段，可自行增减。');
$oPanelClass->AddSubBlock($oFieldParentAttr);

// Icon
$sIconPickerHtml = <<<HTML
<div style="display:flex; gap: 15px; align-items: flex-start;">
    <div style="flex:1;">
        <label style="font-weight:normal; display:block; margin-bottom:5px;">方式一：选择现有图标 (Select Existing)</label>
        <div style="border:1px solid #ccc; padding:5px; height: 120px; overflow-y: auto; background: #fff; border-radius: 4px;">
            <div style="display: flex; flex-wrap: wrap;">
                {$sIconHtml}
            </div>
        </div>
        <input type="text" id="icon_path_input" name="icon" placeholder="selected icon path..." style="width:100%; margin-top:5px; background:#f9f9f9;" readonly>
    </div>
    <div style="flex:1; padding-left:15px; border-left:1px solid #eee;">
        <label style="font-weight:normal; display:block; margin-bottom:5px;">方式二：上传新图标 (Upload New)</label>
        <input type="file" name="icon_file" accept=".png,.svg,.jpg,.jpeg,.gif,.ico" onchange="clearIconSelection()">
        <br><small>支持 PNG/SVG/JPG/ICO。上传后将自动保存到 images/ 目录。</small>
    </div>
</div>
HTML;
$oFieldIcon = FieldUIBlockFactory::MakeLarge('Icon 图标', $sIconPickerHtml);
$oPanelClass->AddSubBlock($oFieldIcon);


// --- Panel 3: Fields ---
$oPanelFields = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('Fields 字段');
$oForm->AddSubBlock($oPanelFields);

$sFieldsTableHtml = <<<HTML
<table id="fields_table" class="ibo-table" cellpadding="5">
<thead>
  <tr><th>ID</th><th>Type</th><th>SQL</th><th>Enum Values (comma)</th><th>操作</th></tr>
</thead>
<tbody>
  <tr>
    <td><input type="text" name="field_id[]" placeholder="robot_type"></td>
    <td>
       <select name="field_type[]">
         <option value="AttributeString">String</option>
         <option value="AttributeEnum">Enum</option>
         <option value="AttributeInteger">Integer</option>
         <option value="AttributeDate">Date</option>
       </select>
    </td>
    <td><input type="text" name="field_sql[]" placeholder="robot_type"></td>
    <td><input type="text" name="field_enum[]" placeholder="industrial,service"></td>
    <td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger" onclick="removeFieldRow(this)">删除</button></td>
  </tr>
</tbody>
</table>
<div class="wizard-toolbar" style="margin-top:10px;">
<button type="button" class="ibo-button ibo-is-regular ibo-is-primary" id="add_field_btn">新增字段</button>
</div>
<small>SQL 留空则与 ID 相同；Enum 用逗号分隔</small>
HTML;

$oPanelFields->AddSubBlock(new Html($sFieldsTableHtml));


// --- Submit Button ---
$oForm->AddSubBlock(new Html('<div class="wizard-toolbar" style="margin-top:20px;">'));
$oBtnSubmit = ButtonUIBlockFactory::MakeForPrimaryAction('生成模块 Generate', 'submit', null, true);
$oForm->AddSubBlock($oBtnSubmit);
$oForm->AddSubBlock(new Html('</div>'));

$oP->AddUiBlock($oForm);
$oP->add_ready_script(
<<<'JS'
// Removed old definition to avoid conflict/confusion, implemented in next block
$('#add_field_btn').on('click', function(){
  var row = '<tr>'+
    '<td><input type="text" name="field_id[]" placeholder="attribute_id"></td>'+
    '<td><select name="field_type[]">'+
      '<option value="AttributeString">String</option>'+
      '<option value="AttributeEnum">Enum</option>'+
      '<option value="AttributeInteger">Integer</option>'+
      '<option value="AttributeDate">Date</option>'+
    '</select></td>'+
    '<td><input type="text" name="field_sql[]" placeholder="attribute_id"></td>'+
    '<td><input type="text" name="field_enum[]" placeholder="v1,v2"></td>'+
    '<td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger" onclick="removeFieldRow(this)">删除</button></td>'+
  '</tr>';
  $('#fields_table tbody').append(row);
});
// Attach event listener for existing buttons (if any are static, though currently all are dynamic or initial)
// But since the function is global, inline onclick works.
// However, let's make sure the function is available in global scope properly or attach via delegation
$(document).on('click', '.ibo-is-danger', function() {
    // If using class delegation, we can remove inline onclick
});
// Actually, the inline onclick="removeFieldRow(this)" expects removeFieldRow to be global.
// Let's expose it to window or just keep it simple.
window.removeFieldRow = function(btn){
  var tr = $(btn).closest('tr');
  var tbody = tr.parent(); 
  if(tbody.children('tr').length > 1){ tr.remove();} 
  else { alert('至少保留一个字段行'); }
};

window.selectIcon = function(img) {
  var val = $(img).data('val');
  $('#icon_path_input').val(val);
  $('.icon-picker-item').css({'border-color':'transparent','background':'transparent'});
  $(img).css({'border-color':'#007bff','background':'#eef'});
  // Clear file input
  $('input[name="icon_file"]').val('');
};

window.clearIconSelection = function() {
  // Optionally clear the text input if file is chosen
  // But maybe user wants to see what they chose? 
  // If file is selected, it takes precedence anyway.
  // But let's clear the text to avoid confusion.
  $('#icon_path_input').val('');
  $('.icon-picker-item').css({'border-color':'transparent','background':'transparent'});
};
JS
);

$jsonParentZ    = json_encode($aParentZlists, JSON_UNESCAPED_UNICODE);
$jsonParentMeta = json_encode($aParentMeta,   JSON_UNESCAPED_UNICODE);

$oP->add_ready_script(<<<JS
var parentZ    = $jsonParentZ;
var parentMeta = $jsonParentMeta;

/**
 * 根据当前父类渲染属性表，表中有：
 * - 默认列：只显示 ✓，表示这个字段在父类对应 ZList 中
 * - 继承列：checkbox，可调整本次新建类的 details/search/list
 */
window.renderParentAttrTable = function(cls){
  var z = parentZ[cls] || {details:[], search:[], list:[], all:[]};
  var metaAll = parentMeta[cls] || {};
  var tbody = $('#parent_attr_table tbody');
  tbody.empty();

  var allCodes = Array.isArray(z.all) ? z.all : [];
  allCodes.forEach(function(code){
    var m = metaAll[code] || {code: code, label: code, type: ''};

    var inDetails = Array.isArray(z.details) && z.details.indexOf(code) !== -1;
    var inSearch  = Array.isArray(z.search)  && z.search.indexOf(code)  !== -1;
    var inList    = Array.isArray(z.list)    && z.list.indexOf(code)    !== -1;

    var tr = $('<tr/>');
    tr.append($('<td/>').text(m.code));
    tr.append($('<td/>').text(m.label));
    tr.append($('<td/>').text(m.type));

    tr.append($('<td/>').text(inDetails ? '✓' : ''));
    tr.append($('<td/>').append(
      $('<input type="checkbox" name="details_inherit[]">')
        .val(m.code)
        .prop('checked', inDetails)
    ));

    tr.append($('<td/>').text(inSearch ? '✓' : ''));
    tr.append($('<td/>').append(
      $('<input type="checkbox" name="search_inherit[]">')
        .val(m.code)
        .prop('checked', inSearch)
    ));

    tr.append($('<td/>').text(inList ? '✓' : ''));
    tr.append($('<td/>').append(
      $('<input type="checkbox" name="list_inherit[]">')
        .val(m.code)
        .prop('checked', inList)
    ));

    tbody.append(tr);
  });
}


$(function(){
  var sel = $('#parent_class_input');
  if (sel.length){
    sel.on('change', function(){ window.renderParentAttrTable(this.value); });
    // Initial render if value is set
    if(sel.val()) window.renderParentAttrTable(sel.val());
  }
});

JS
);


$oP->output();
