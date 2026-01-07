<?php

/**
 * Quick Add Attribute - 为已有 CI 类批量新增属性并生成增量 XML 扩展
 */

require_once(dirname(__FILE__, 4).DIRECTORY_SEPARATOR.'approot.inc.php');
require_once(APPROOT.'application/startup.inc.php');
require_once(APPROOT.'application/loginwebpage.class.inc.php');
require_once(APPROOT.'application/webpage.class.inc.php');

LoginWebPage::DoLogin();

function get_available_linkset_attributes() {
    $aLinkSetAttributes = [];
    $aProcessedIds = [];

    // 核心数据模型和扩展中的数据模型文件
    $aDataModelPaths = array_merge(
        glob(APPROOT . 'datamodels/2.x/*/*.xml'),
        glob(APPROOT . 'extensions/*/*.xml')
    );

    foreach ($aDataModelPaths as $sFilePath) {
        if (!is_readable($sFilePath)) {
            continue;
        }

        $oXml = @simplexml_load_file($sFilePath);
        if ($oXml === false) {
            continue;
        }

        $oXml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $aNodes = $oXml->xpath('//field[@xsi:type="AttributeLinkedSet" or @xsi:type="AttributeLinkedSetIndirect"]');

        foreach ($aNodes as $oNode) {
            $sId = (string)$oNode['id'];
            if ($sId && !isset($aProcessedIds[$sId])) {
                $aLinkSetAttributes[] = [
                    'id' => $sId,
                    'xml' => $oNode->asXML(),
                ];
                $aProcessedIds[$sId] = true;
            }
        }
    }

    // 按ID排序以便于查找
    usort($aLinkSetAttributes, function($a, $b) {
        return strcmp($a['id'], $b['id']);
    });
    return $aLinkSetAttributes;
}


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
$oP = new iTopWebPage($sTitle.' - Add Attribute');

$oP->add_style(
    '#aa_fields_table.ibo-table th,#aa_fields_table.ibo-table td{padding:6px 8px;vertical-align:middle;}'
    .'#aa_linksets_table.ibo-table th,#aa_linksets_table.ibo-table td{padding:6px 8px;vertical-align:middle;}'
    .'#existing_attr_table.ibo-table th,#existing_attr_table.ibo-table td{padding:6px 8px;vertical-align:middle;}'
    .'#aa_fields_table input[type="text"],#aa_fields_table select{min-height:30px;}'
    .'.wizard-toolbar{display:flex;gap:10px;align-items:center;margin-top:8px;margin-bottom:10px;}'
    .'.ibo-input-error{color:#d0021b; font-size:0.9em; margin-top:4px; font-weight:bold;}'
    // Restrict width of inputs in the wizard form to avoid "long and thin" look
    .'.ibo-field .ibo-input { max-width: 400px; width: 100%; }' 
    .'.ibo-field select { max-width: 400px; width: 100%; }'
    .'.ibo-field textarea { max-width: 100%; }'
    // Ensure labels do not wrap and question mark stays with the label
    .'.ibo-field--label { white-space: nowrap !important; display: inline-flex; align-items: center; }'
);

// 收集可用类
$aClasses = array();
try {
    $aClasses = MetaModel::GetClasses('bizmodel');
} catch (Exception $e) {
    $aClasses = array();
}
if (method_exists('MetaModel', 'IsValidClass')) {
    if (MetaModel::IsValidClass('FunctionalCI')) { array_unshift($aClasses, 'FunctionalCI'); }
    if (MetaModel::IsValidClass('cmdbAbstractObject')) { array_unshift($aClasses, 'cmdbAbstractObject'); }
} else {
    array_unshift($aClasses, 'FunctionalCI');
    array_unshift($aClasses, 'cmdbAbstractObject');
}
$aClasses = array_values(array_unique($aClasses));

// 预构建属性元数据、Zlists
$aZ = array();
$aMeta = array();
foreach ($aClasses as $c) {
    try {
        $aAll = MetaModel::GetAttributesList($c);
        $codes = array();
        if (is_array($aAll)) {
            $keys = array_keys($aAll);
            $firstKey = (count($keys) > 0) ? $keys[0] : null;
            // iTop 3.2 之后 GetAttributesList 返回 ['attcode' => AttributeDef]
            if ($firstKey !== null && is_int($firstKey)) {
                // 兼容旧格式
                foreach ($aAll as $val) {
                    $code = (string)$val;
                    $codes[] = $code;
                    $oDef = MetaModel::GetAttributeDef($c, $code);
                    $aMeta[$c][$code] = array(
                        'code'  => $code,
                        'label' => $oDef->GetLabel(),
                        'type'  => get_class($oDef),
                    );
                }
            } else {
                foreach ($aAll as $code => $oDef) {
                    $code = (string)$code;
                    $codes[] = $code;
                    // Ensure $oDef is an object (handle static analysis warnings and potential edge cases)
                    if (!is_object($oDef)) {
                        $oDef = MetaModel::GetAttributeDef($c, $code);
                    }
                    $aMeta[$c][$code] = array(
                        'code'  => $code,
                        'label' => $oDef->GetLabel(),
                        'type'  => get_class($oDef),
                    );
                }
            }
        }
        $details = array_values((array) MetaModel::GetZListItems($c, 'details'));
        $search  = array_values((array) MetaModel::GetZListItems($c, 'search'));
        $list    = array_values((array) MetaModel::GetZListItems($c, 'list'));
        if (empty($details)) { $details = array_slice($codes, 0, 8); }
        if (empty($search))  { $search  = array_slice($codes, 0, 8); }
        if (empty($list))    { $list    = array_slice($codes, 0, 5); }
        $aZ[$c] = array('details' => $details, 'search' => $search, 'list' => $list, 'all' => $codes);
    } catch (Exception $e) {
        $aZ[$c] = array(
            'details' => array('name'),
            'search'  => array('name'),
            'list'    => array('name'),
            'all'     => array('name'),
        );
    }
}

// 处理表单提交：生成增量 XML
$op = utils::ReadParam('operation_add_attr', '');
if ($op === 'add_attr') {
    $classId  = utils::ReadPostedParam('aa_class_id', '', 'raw_data');
    $ids      = utils::ReadPostedParam('aa_field_id', array(), 'raw_data');
    $types    = utils::ReadPostedParam('aa_field_type', array(), 'raw_data');
    $sqls     = utils::ReadPostedParam('aa_field_sql', array(), 'raw_data');
    $enums    = utils::ReadPostedParam('aa_field_enum', array(), 'raw_data');
    $inDetails = (bool) utils::ReadPostedParam('aa_in_details', '1', 'raw_data');
    $inSearch  = (bool) utils::ReadPostedParam('aa_in_search',  '1', 'raw_data');
    $inList    = (bool) utils::ReadPostedParam('aa_in_list',    '0', 'raw_data');
    $addType   = utils::ReadPostedParam('aa_add_type', 'field', 'raw_data');

    // 组装字段定义
    $aFields = array();

    if ($addType === 'field') {
        for ($i = 0; $i < count($ids); $i++) {
            $fid = preg_replace('/[^A-Za-z0-9_]/', '_', trim((string) ($ids[$i] ?? '')));
            if ($fid !== '' && !preg_match('/^[A-Za-z_]/', $fid)) {
                $fid = 'a_'.$fid; // 保证以字母或下划线开头，避免MetaModel警告
            }
            if ($fid === '') {
                continue;
            }
            $ftype = (string) ($types[$i] ?? 'AttributeString');
            $fsql  = trim((string) ($sqls[$i] ?? ''));
            $fsql  = $fsql === '' ? $fid : preg_replace('/[^A-Za-z0-9_]/', '_', $fsql);

            $field = array(
                'id'              => $fid,
                'type'            => $ftype,
                'sql'             => $fsql,
                'is_null_allowed' => true,
                'default_value'   => '',
            );

            if ($ftype === 'AttributeEnum') {
                $vals = array_filter(array_map('trim', explode(',', (string) ($enums[$i] ?? ''))));
                $field['values'] = $vals;
            }
            $aFields[] = $field;
        }
    } else {
        // 新增 LinkSet（动态，多选且去重）
        $idsList = utils::ReadPostedParam('aa_linkset_type', array(), 'raw_data'); // name="aa_linkset_type[]"
        if (!is_array($idsList)) { $idsList = array(); }
        $idsList = array_values(array_unique(array_filter(array_map('strval', $idsList))));

        $aLinkSetAttributes = get_available_linkset_attributes();
        $byId = array();
        foreach ($aLinkSetAttributes as $attr) { $byId[$attr['id']] = $attr; }

        foreach ($idsList as $linksetTypeId) {
            if (!isset($byId[$linksetTypeId])) { continue; }
            $selectedAttr = $byId[$linksetTypeId];
            $xml = (string) $selectedAttr['xml'];
            $fid = '';
            if ($xml !== '') {
                $ox = @simplexml_load_string($xml);
                if ($ox !== false) {
                    $fid = (string) ($ox['id'] ?? '');
                    if ($fid !== '' && !preg_match('/^[A-Za-z_]/', $fid)) { $fid = 'a_'.$fid; }
                }
            }
            $aFields[] = array('xml' => $xml, 'id' => $fid);
        }
    }

    // 构建 <fields> 片段
    $sFieldXml = '';
    foreach ($aFields as $f) {
        if (isset($f['xml'])) {
            // 直接使用 LinkSet 的完整 XML
            $sFieldXml .= preg_replace('/<field(.*?)>/i', '<field$1 _delta="define">', $f['xml']);
        } else {
            // 为常规字段构建 XML
            $fid   = htmlspecialchars($f['id'], ENT_QUOTES, 'UTF-8');
            $ftype = htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8');
            $fsql  = htmlspecialchars($f['sql'], ENT_QUOTES, 'UTF-8');

            $sFieldXml .= "          <field id=\"{$fid}\" xsi:type=\"{$ftype}\" _delta=\"define\">\n".
                          "            <sql>{$fsql}</sql>\n".
                          "            <default_value></default_value>\n".
                          "            <is_null_allowed>true</is_null_allowed>\n";

            if ($ftype === 'AttributeEnum' && !empty($f['values'])) {
                $sFieldXml .= "            <values>\n";
                foreach ($f['values'] as $v) {
                    $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                    $sFieldXml .= "              <value>{$v}</value>\n";
                }
                $sFieldXml .= "            </values>\n";
            }
            $sFieldXml .= "          </field>\n";
        }
    }

    // 构建 presentation
    $buildPresItems = function (array $fields) {
        $s = '';
        $rank = 500;
        foreach ($fields as $f) {
            $fid = htmlspecialchars($f['id'], ENT_QUOTES, 'UTF-8');
            $s .= "            <item id=\"{$fid}\" _delta=\"define\"><rank>{$rank}</rank></item>\n";
            $rank += 10;
        }
        return $s;
    };
    $sPres = '';
    if ($inDetails) {
        $sPres .= "        <details>\n          <items>\n".$buildPresItems($aFields)."          </items>\n        </details>\n";
    }
    if ($inSearch) {
        $sPres .= "        <search>\n          <items>\n".$buildPresItems($aFields)."          </items>\n        </search>\n";
    }
    if ($inList) {
        $sPres .= "        <list>\n          <items>\n".$buildPresItems($aFields)."          </items>\n        </list>\n";
    }

    // 完整增量 XML
    $sDeltaXml =
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
        "<itop_design xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" version=\"1.6\">\n".
        "  <classes>\n".
        "    <class id=\"".htmlspecialchars($classId, ENT_QUOTES, 'UTF-8')."\" _delta=\"must_exist\">\n".
        "      <fields>\n".$sFieldXml."      </fields>\n".
        "      <methods/>\n".
        "      <presentation>\n".$sPres."      </presentation>\n".
        "    </class>\n".
        "  </classes>\n".
        "</itop_design>\n";

    // 保存到 extensions 下的新模块
    $module = utils::ReadPostedParam('aa_module', 'audi-ci-class-delta', 'raw_data');
    $extRoot = rtrim(APPROOT, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR;
    $sDir = $extRoot.$module.DIRECTORY_SEPARATOR;
    if (!is_dir($sDir)) {
        @mkdir($sDir, 0775, true);
    }

    $r1 = file_put_contents($sDir.'datamodel.'.$module.'.xml', $sDeltaXml);

    /**
     * ========= 依赖模块自动探测（避免依赖自己） =========
     *
     * 情况 1：FunctionalCI、Server 等核心 CI 类
     *   → 默认依赖 itop-config-mgmt
     *
     * 情况 2：某个自定义扩展里定义的新 CI 类
     *   → 在 extensions/*/
     //datamodel.*.xml 中找到 _delta="define" 那个 class
     /*      然后依赖那个扩展的 module
     */

    // 默认依赖：配置管理模块（适用于大部分标准 CI，如 FunctionalCI 等）
    $dep = 'itop-config-mgmt/2.0.0';

    // 自动探测：如果 class 属于某个扩展模块，则依赖那个模块
    $extScan = $extRoot;
    $dirs = @scandir($extScan);

    if (is_array($dirs)) {
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            $dir = $extScan.$d.DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                continue;
            }

            $xmls = glob($dir.'datamodel.*.xml');
            $found = false;
            foreach ((array) $xmls as $xf) {
                $cnt = @file_get_contents($xf);
                // 只将“真正定义该类”的模块作为依赖：必须有 _delta="define"
                if ($cnt !== false
                    && strpos($cnt, '<class id="'.$classId.'"') !== false
                    && strpos($cnt, '_delta="define"') !== false
                ) {
                    $mods = glob($dir.'module.*.php');
                    if (!empty($mods)) {
                        $mpc = @file_get_contents($mods[0]);
                        if ($mpc !== false && preg_match("/AddModule\(\s*__FILE__,\s*'([^']+)'/", $mpc, $m)) {
                            $dep = $m[1]; // 发现就覆盖默认依赖
                        }
                    }
                    $found = true;
                    break;
                }
            }
            if ($found) {
                break;
            }
        }
    }

    // module.php & 字典
    $r2 = file_put_contents(
        $sDir.'module.'.$module.'.php',
        GenerateModulePhp($module, 'Add Attribute delta', '1.0.0', 'business', $dep)
    );

    $dictFields = array();
    foreach ($aFields as $f) {
        $dictFields[] = array('id' => $f['id']);
    }
    $r3 = file_put_contents(
        $sDir.'en.dict.'.$module.'.php',
        GenerateDictPhp($module, $classId, $classId, $dictFields)
    );

    if ($r1 && $r2 && $r3) {
        $oP->add(
            '<div class="ibo-alert ibo-alert--success"><h2>增量XML已生成</h2>'.
            '<p>目录：'.utils::HtmlEntities($sDir).'</p>'.
            '<p><a href="'.utils::GetAbsoluteUrlAppRoot().'setup/">点击执行 iTop Setup 使更改生效</a></p>'.
            '</div>'
        );
    } else {
        $oP->add(
            '<div class="ibo-alert ibo-alert--error"><h2>生成失败</h2>'.
            '<p>请检查写入权限：'.utils::HtmlEntities($sDir).'</p>'.
            '</div>'
        );
    }
}

// 下拉选项
$sClassOptions = '';
foreach ($aClasses as $pc) {
    $bAbstract = false;
    try {
        $bAbstract = MetaModel::IsAbstract($pc);
    } catch (Exception $e) {
        $bAbstract = false;
    }
    $label = $pc.($bAbstract ? ' (abstract)' : '');
    $sClassOptions .= '<option value="'.utils::HtmlEntities($pc).'">'.utils::HtmlEntities($label).'</option>';
}

// JS 需要用到的 JSON
$jsonZ    = json_encode($aZ, JSON_UNESCAPED_UNICODE);
$jsonMeta = json_encode($aMeta, JSON_UNESCAPED_UNICODE);
$aLinkSetAttributes = get_available_linkset_attributes();
// 为避免在 <script> 中嵌入原始 XML 导致解析问题，前端改用 Base64 承载 XML
$aLinkSetAttrForJs = array();
foreach ($aLinkSetAttributes as $attr) {
    $aLinkSetAttrForJs[] = array(
        'id' => $attr['id'],
        'xml_b64' => base64_encode($attr['xml'] ?? ''),
    );
}
$jsonLinksets = json_encode($aLinkSetAttrForJs, JSON_UNESCAPED_UNICODE);

// --- UI Construction ---

$oForm = FormUIBlockFactory::MakeStandard('add_attr_form');
$oForm->AddSubBlock(InputUIBlockFactory::MakeForHidden('operation_add_attr', 'add_attr'));

// --- Panel 1: Target Class & Module ---
$oPanelInfo = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('Target Info 目标信息');
$oForm->AddSubBlock($oPanelInfo);

$oInfoCols = MultiColumnUIBlockFactory::MakeStandard();

// Module Name
$oInputModule = InputUIBlockFactory::MakeStandard('text', 'aa_module', 'audi-ci-class-delta');
$oFieldModule = FieldUIBlockFactory::MakeFromObject('Module name（保存到哪个扩展）', $oInputModule, null);
$oInfoCols->AddSubBlock(ColumnUIBlockFactory::MakeForBlock($oFieldModule));

// Class ID
// Reusing the generated $sClassOptions
$sClassSelectHtml = '<select name="aa_class_id" required>'.$sClassOptions.'</select>';
$oFieldClass = FieldUIBlockFactory::MakeFromObject('Class ID（现有CI类）', new Html($sClassSelectHtml), null);
$oInfoCols->AddSubBlock(ColumnUIBlockFactory::MakeForBlock($oFieldClass));

$oPanelInfo->AddSubBlock($oInfoCols);


// --- Panel 2: Existing Attributes ---
$oPanelExisting = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('Existing Attributes 现有属性');
$oForm->AddSubBlock($oPanelExisting);

$sExistingHtml = <<<HTML
<table id="existing_attr_table" class="ibo-table" cellpadding="3">
  <thead>
    <tr>
      <th>属性编码</th>
      <th>名称</th>
      <th>类型</th>
      <th>details</th>
      <th>search</th>
      <th>list</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
<div class="ibo-field" style="margin-top:8px">
  <input type="text" id="existing_attr_filter" placeholder="筛选属性名称或编码" class="ibo-input">
  <small>输入关键字过滤展示的属性</small>
</div>
HTML;
$oPanelExisting->AddSubBlock(new Html($sExistingHtml));


// --- Panel 3: Add New Attributes ---
$oPanelNew = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('New Attributes 新增属性');
$oForm->AddSubBlock($oPanelNew);

// Type Toggle
$sToggleHtml = <<<HTML
<div class="ibo-field">
  <label><input type="radio" name="aa_add_type" value="field" checked> 新增字段</label>
  <label style="margin-left: 15px;"><input type="radio" name="aa_add_type" value="linkset"> 新增属性 (LinkSet)</label>
</div>
HTML;
$oPanelNew->AddSubBlock(new Html($sToggleHtml));

// Fields Table Section
$sFieldsSectionHtml = <<<HTML
<fieldset class="ibo-fieldset" id="fieldset_add_field" style="border:none; padding:0; margin:0;">
  <legend style="font-size:1.1em; font-weight:bold; margin-bottom:10px;">新增字段</legend>
  <table id="aa_fields_table" class="ibo-table" cellpadding="5">
    <thead>
      <tr><th>ID</th><th>Type</th><th>SQL</th><th>Enum Values (comma)</th><th>操作</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><input type="text" name="aa_field_id[]" placeholder="new_attr" required></td>
        <td>
          <select name="aa_field_type[]">
            <option value="AttributeString">AttributeString</option>
            <option value="AttributeEnum">AttributeEnum</option>
            <option value="AttributeInteger">AttributeInteger</option>
            <option value="AttributeDate">AttributeDate</option>
          </select>
        </td>
        <td><input type="text" name="aa_field_sql[]" placeholder="new_attr"></td>
        <td><input type="text" name="aa_field_enum[]" placeholder="v1,v2"></td>
        <td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger" onclick="removeFieldRow(this)">删除</button></td>
      </tr>
    </tbody>
  </table>
  <span class="ibo-button-group wizard-toolbar">
    <button type="button" class="ibo-button ibo-is-regular ibo-is-primary" id="aa_add_field_btn">新增字段</button>
  </span>
  <small>SQL 留空则与 ID 相同；Enum 用逗号分隔</small>
</fieldset>
HTML;
$oPanelNew->AddSubBlock(new Html($sFieldsSectionHtml));

// Linksets Section
$sLinksetsSectionHtml = <<<HTML
<fieldset class="ibo-fieldset" id="fieldset_add_linkset" style="display:none; border:none; padding:0; margin:0;">
  <legend style="font-size:1.1em; font-weight:bold; margin-bottom:10px;">新增属性 (LinkSet)</legend>
  <table id="aa_linksets_table" class="ibo-table" cellpadding="5">
    <thead>
      <tr><th>属性类型</th><th>操作</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <select name="aa_linkset_type[]"></select>
        </td>
        <td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger aa-remove-linkset">删除</button></td>
      </tr>
    </tbody>
  </table>
  <span class="ibo-button-group wizard-toolbar">
    <button type="button" class="ibo-button ibo-is-regular ibo-is-primary" id="aa_add_linkset_btn">新增属性</button>
  </span>
  <small>可新增多个属性，系统自动过滤重复和已存在项</small>
</fieldset>
HTML;
$oPanelNew->AddSubBlock(new Html($sLinksetsSectionHtml));


// --- Panel 4: Display Options & Actions ---
$oPanelActions = PanelUIBlockFactory::MakeWithBrandingPrimaryColor('Options & Generate 选项与生成');
$oForm->AddSubBlock($oPanelActions);

$sOptionsHtml = <<<HTML
<div class="ibo-field">展示列表：<br>
  <label><input type="checkbox" name="aa_in_details" value="1" checked> details</label>
  <label><input type="checkbox" name="aa_in_search" value="1" checked> search</label>
  <label><input type="checkbox" name="aa_in_list" value="1"> list</label>
</div>
HTML;
$oPanelActions->AddSubBlock(new Html($sOptionsHtml));

$sPreviewHtml = <<<HTML
<div class="ibo-field" style="margin-bottom: 20px;">
  <span class="ibo-button-group">
    <button type="button" class="ibo-button ibo-is-regular ibo-is-primary" id="preview_delta_btn">预览增量 XML</button>
    <button type="button" class="ibo-button ibo-is-regular ibo-is-neutral" id="copy_delta_btn">复制到剪贴板</button>
  </span>
  <pre id="preview_delta_box" style="display:none; max-height:280px; overflow:auto; background:#f5f5f5; padding:8px; margin-top: 10px;"></pre>
</div>
HTML;
$oPanelActions->AddSubBlock(new Html($sPreviewHtml));

$oBtnSubmit = ButtonUIBlockFactory::MakeForPrimaryAction('生成增量 XML', 'submit', null, true);
$oPanelActions->AddSubBlock($oBtnSubmit);

$oP->AddUiBlock($oForm);

// JS：不要再包 DOMContentLoaded，iTop 会在 DOM ready 时执行本段
$oP->add_ready_script(
<<<JS
var zlists = $jsonZ || {};
var meta   = $jsonMeta || {};
var availableLinksets = $jsonLinksets || [];

function updateLinksetDropdown(selectedClass) {
    var selects = document.querySelectorAll('select[name="aa_linkset_type[]"]');
    if (selects.length === 0) return;

    var existingAttrs = meta[selectedClass] ? Object.keys(meta[selectedClass]) : [];
    var source = Array.isArray(availableLinksets) ? availableLinksets : [];
    var filteredLinksets = source.filter(function(attr) {
        return attr && attr.id && existingAttrs.indexOf(attr.id) === -1;
    });

    // 当前已选的值集合，避免重复
    var chosen = Array.prototype.map.call(selects, function(s){ return s.value; })
      .filter(function(v){ return !!v; });

    selects.forEach(function(linksetSelect){
        var current = linksetSelect.value;
        linksetSelect.innerHTML = '';
        var list = filteredLinksets.filter(function(attr){
            return chosen.indexOf(attr.id) === -1 || attr.id === current; // 保留当前选择
        });
        if (list.length === 0) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '无可添加的属性';
            linksetSelect.appendChild(opt);
            return;
        }
        list.forEach(function(attr){
            var option = document.createElement('option');
            option.value = attr.id;
            option.textContent = attr.id;
            linksetSelect.appendChild(option);
        });
        if (current) linksetSelect.value = current; // 恢复选中
    });
}

function renderExistingAttrTable(cls){
  var z = zlists[cls] || {details:[], search:[], list:[], all:[]};
  var metaAll = meta[cls] || {};
  var tbody = document.querySelector('#existing_attr_table tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  var allCodes = Array.isArray(z.all) ? z.all : [];
  if (allCodes.length === 0) { allCodes = Object.keys(metaAll); }
  allCodes.forEach(function(code){
    var m = metaAll[code] || {code: code, label: code, type: ''};
    var inDetails = Array.isArray(z.details) && z.details.indexOf(code) !== -1;
    var inSearch  = Array.isArray(z.search)  && z.search.indexOf(code)  !== -1;
    var inList    = Array.isArray(z.list)    && z.list.indexOf(code)    !== -1;
    var tr = document.createElement('tr');
    var td1 = document.createElement('td'); td1.textContent = m.code;
    var td2 = document.createElement('td'); td2.textContent = m.label;
    var td3 = document.createElement('td'); td3.textContent = m.type;
    var td4 = document.createElement('td'); td4.textContent = inDetails ? '✓' : '';
    var td5 = document.createElement('td'); td5.textContent = inSearch ? '✓' : '';
    var td6 = document.createElement('td'); td6.textContent = inList ? '✓' : '';
    tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
    tr.appendChild(td4); tr.appendChild(td5); tr.appendChild(td6);
    tbody.appendChild(tr);
  });
}

function removeFieldRow(btn){
  var tr = btn.closest('tr');
  if (tr) {
    var tbody = tr.parentElement;
    if (tbody && tbody.children.length > 1) {
      tbody.removeChild(tr);
    }
  }
}
window.removeFieldRow = removeFieldRow;

function removeLinksetRow(btn){
  var tr = btn.closest('tr');
  var tbody = tr && tr.parentElement;
  if (tbody && tbody.children.length > 1){
    tbody.removeChild(tr);
    var clsEl = document.querySelector('select[name="aa_class_id"]');
    var cls   = clsEl ? clsEl.value : '';
    updateLinksetDropdown(cls);
  }
}
window.removeLinksetRow = removeLinksetRow;

// Add type radio toggle
document.querySelectorAll('input[name="aa_add_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var fieldsetField = document.getElementById('fieldset_add_field');
        var fieldsetLinkset = document.getElementById('fieldset_add_linkset');
        var setEnabled = function(root, enabled){
            if (!root) return;
            Array.prototype.forEach.call(root.querySelectorAll('input, select, textarea, button'), function(el){
                el.disabled = !enabled;
            });
        };
        if (this.value === 'field') {
            if (fieldsetField) fieldsetField.style.display = 'block';
            if (fieldsetLinkset) fieldsetLinkset.style.display = 'none';
            setEnabled(fieldsetField, true);
            setEnabled(fieldsetLinkset, false);
        } else {
            if (fieldsetField) fieldsetField.style.display = 'none';
            if (fieldsetLinkset) fieldsetLinkset.style.display = 'block';
            setEnabled(fieldsetField, false);
            setEnabled(fieldsetLinkset, true);
        }
    });
});

// ===== 初始化、事件绑定 =====

var sel = document.querySelector('select[name="aa_class_id"]');
if (sel){
  sel.addEventListener('change', function(){
    renderExistingAttrTable(this.value);
    updateLinksetDropdown(this.value);
  });
  // Initial call
  renderExistingAttrTable(sel.value);
  updateLinksetDropdown(sel.value);
}

// 初始化：根据当前选择禁用隐藏区域，避免隐藏区域的 required 阻止提交；并绑定初始删除按钮
(function(){
  var cur = document.querySelector('input[name="aa_add_type"]:checked');
  var fieldsetField = document.getElementById('fieldset_add_field');
  var fieldsetLinkset = document.getElementById('fieldset_add_linkset');
  var setEnabled = function(root, enabled){
    if (!root) return;
    Array.prototype.forEach.call(root.querySelectorAll('input, select, textarea, button'), function(el){
      el.disabled = !enabled;
    });
  };
  if (cur){
    if (cur.value === 'field'){
      setEnabled(fieldsetField, true);
      setEnabled(fieldsetLinkset, false);
    } else {
      setEnabled(fieldsetField, false);
      setEnabled(fieldsetLinkset, true);
    }
  }
  var tbody = document.querySelector('#aa_linksets_table tbody');
  if (tbody){
    tbody.querySelectorAll('.aa-remove-linkset').forEach(function(btn){
      btn.addEventListener('click', function(){ removeLinksetRow(this); });
    });
  }
})();

var addBtn = document.getElementById('aa_add_field_btn');
if (addBtn){
  addBtn.addEventListener('click', function(){
    var row = '<tr>'+
      '<td><input type="text" name="aa_field_id[]" placeholder="new_attr" required></td>'+
      '<td><select name="aa_field_type[]">'+
        '<option value="AttributeString">AttributeString</option>'+
        '<option value="AttributeEnum">AttributeEnum</option>'+
        '<option value="AttributeInteger">AttributeInteger</option>'+
        '<option value="AttributeDate">AttributeDate</option>'+
      '</select></td>'+
      '<td><input type="text" name="aa_field_sql[]" placeholder="new_attr"></td>'+
      '<td><input type="text" name="aa_field_enum[]" placeholder="v1,v2"></td>'+
      '<td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger" onclick="removeFieldRow(this)">删除</button></td>'+
    '</tr>';
    var tbody = document.querySelector('#aa_fields_table tbody');
    if (tbody){ tbody.insertAdjacentHTML('beforeend', row); }
  });
}

var filter = document.getElementById('existing_attr_filter');
if (filter){
  filter.addEventListener('input', function(){
    var kw = (this.value || '').toLowerCase();
    var rows = document.querySelectorAll('#existing_attr_table tbody tr');
    rows.forEach(function(tr){
      var txt = tr.textContent.toLowerCase();
      tr.style.display = txt.indexOf(kw) !== -1 ? '' : 'none';
    });
  });
}

var preview = document.getElementById('preview_delta_btn');
if (preview){
  preview.addEventListener('click', function(){
    var clsEl = document.querySelector('select[name="aa_class_id"]');
    var cls   = clsEl ? clsEl.value : '';
    var bDet  = !!document.querySelector('input[name="aa_in_details"]:checked');
    var bSea  = !!document.querySelector('input[name="aa_in_search"]:checked');
    var bList = !!document.querySelector('input[name="aa_in_list"]:checked');

    var sField = '';
    var addType = document.querySelector('input[name="aa_add_type"]:checked').value;

    if (addType === 'field') {
        var rows = document.querySelectorAll('#aa_fields_table tbody tr');
        rows.forEach(function(tr){
            var fidEl   = tr.querySelector('input[name="aa_field_id[]"]');
            var ftypeEl = tr.querySelector('select[name="aa_field_type[]"]');
            var fsqlEl  = tr.querySelector('input[name="aa_field_sql[]"]');
            var fvalsEl = tr.querySelector('input[name="aa_field_enum[]"]');

            var fid = fidEl ? fidEl.value.trim().replace(/[^A-Za-z0-9_]/g, '_') : '';
            if (fid === ''){ return; }

            var ftype = ftypeEl ? ftypeEl.value : 'AttributeString';
            var fsql  = fsqlEl ? fsqlEl.value.trim() : '';
            fsql = (fsql === '') ? fid : fsql.replace(/[^A-Za-z0-9_]/g, '_');
            var fvals = fvalsEl ? fvalsEl.value : '';

            var valsXml = '';
            if (ftype === 'AttributeEnum' && fvals && fvals.trim() !== ''){
                var parts = fvals.split(',').map(function(s){return s.trim();}).filter(function(x){return !!x;});
                if (parts.length){
                    valsXml = '            <values>\\n' +
                        parts.map(function(v){ return '              <value>'+v+'</value>'; }).join('\\n') +
                        '\\n            </values>\\n';
                }
            }

            sField += '          <field id="'+fid+'" xsi:type="'+ftype+'" _delta="define">\\n'+
                '            <sql>'+fsql+'</sql>\\n'+
                '            <default_value></default_value>\\n'+
                '            <is_null_allowed>true</is_null_allowed>\\n'+
                valsXml+
                '          </field>\\n';
        });
    } else {
        var selects = document.querySelectorAll('select[name="aa_linkset_type[]"]');
        var ids = [];
        selects.forEach(function(s){ var v = s.value; if (v) ids.push(v); });
        ids = ids.filter(function(v, i, a){ return a.indexOf(v) === i; }); // 去重
        ids.forEach(function(linksetId){
            var selectedAttr = Array.isArray(availableLinksets) ? availableLinksets.find(function(attr){ return attr.id === linksetId; }) : null;
            if (selectedAttr && selectedAttr.xml_b64) {
                var xml = atob(selectedAttr.xml_b64);
                xml = xml.replace(/<field(.*?)>/i, '<field$1 _delta=\"define\">');
                sField += xml + '\\n';
            }
        });
    }

    var pres = '';
    function presItems(){
      var items = '';
      var rank = 500;
      var rows = document.querySelectorAll('#aa_fields_table tbody tr');
      rows.forEach(function(tr){
        var fidEl = tr.querySelector('input[name="aa_field_id[]"]');
        var fid = fidEl ? fidEl.value : '';
        if (!fid) return;
        items += '            <item id="'+fid+'" _delta="define"><rank>'+rank+'</rank></item>\\n';
        rank += 10;
      });
      return items;
    }
    if (bDet){ pres += '        <details>\\n          <items>\\n'+ presItems() +'          </items>\\n        </details>\\n'; }
    if (bSea){ pres += '        <search>\\n          <items>\\n'+ presItems() +'          </items>\\n        </search>\\n'; }
    if (bList){ pres += '        <list>\\n          <items>\\n'+ presItems() +'          </items>\\n        </list>\\n'; }

    var xml = ''+
      '<?xml version="1.0" encoding="UTF-8"?>\\n'+
      '<itop_design xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.6">\\n'+
      '  <classes>\\n'+
      '    <class id="'+cls+'" _delta="must_exist">\\n'+
      '      <fields>\\n'+ sField +'      </fields>\\n'+
      '      <methods/>\\n'+
      '      <presentation>\\n'+ pres +'      </presentation>\\n'+
      '    </class>\\n'+
      '  </classes>\\n'+
      '</itop_design>\\n';

    var pre = document.getElementById('preview_delta_box');
    if (pre){ pre.textContent = xml; pre.style.display = 'block'; }
  });
}

var copyBtn = document.getElementById('copy_delta_btn');
if (copyBtn){
  copyBtn.addEventListener('click', function(){
    var pre = document.getElementById('preview_delta_box');
    var txt = pre ? pre.textContent : '';
    if (!txt){ return; }
    if (navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(txt).then(function(){
        pre.style.outline = '2px solid #4caf50';
        setTimeout(function(){ pre.style.outline = 'none'; }, 1000);
      });
    }
  });
}

var addLinksetBtn = document.getElementById('aa_add_linkset_btn');
if (addLinksetBtn){
  addLinksetBtn.addEventListener('click', function(){
    var row = '<tr>'+
      '<td><select name="aa_linkset_type[]"></select></td>'+
      '<td><button type="button" class="ibo-button ibo-is-regular ibo-is-danger aa-remove-linkset">删除</button></td>'+
    '</tr>';
    var tbody = document.querySelector('#aa_linksets_table tbody');
    if (tbody){
      tbody.insertAdjacentHTML('beforeend', row);
      var clsEl = document.querySelector('select[name="aa_class_id"]');
      var cls   = clsEl ? clsEl.value : '';
      updateLinksetDropdown(cls);
      // 绑定删除事件（事件委托）
      tbody.querySelectorAll('.aa-remove-linkset').forEach(function(btn){
        btn.addEventListener('click', function(){ removeLinksetRow(this); });
      });
    }
  });
}
JS
);

$oP->output();
