<?php

/**
 * 简单工具：去重 + 去空 + 保持顺序
 */
function _acwNormalizeList(array $aList)
{
    $out = array();
    foreach ($aList as $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        if (!in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

/**
 * 生成 datamodel.<module>.xml 的 XML 内容
 *
 * @param string      $sClassId        新类 ID，例如 Robot
 * @param string      $sParentClass    父类 ID，例如 FunctionalCI / PhysicalDevice
 * @param string      $sDbTable        数据表名，例如 robot
 * @param string      $sIcon           图标路径，相对扩展目录，例如 images/robot.png
 * @param array       $aFields         新增字段定义（id/type/sql/...）
 * @param array|null  $aParentDetails  父类 details 字段列表
 * @param array|null  $aParentSearch   父类 search 字段列表
 * @param array|null  $aParentList     父类 list 字段列表
 *
 * @return string XML
 */
function GenerateDataModelXml(
    $sClassId,
    $sParentClass,
    $sDbTable,
    $sIcon,
    array $aFields,
    array $aParentDetails = null,
    array $aParentSearch  = null,
    array $aParentList    = null
) {
    // ---------- 基本转义 ----------
    $sClassId     = htmlspecialchars($sClassId, ENT_QUOTES, 'UTF-8');
    $sParentClass = htmlspecialchars($sParentClass, ENT_QUOTES, 'UTF-8');
    $sDbTable     = htmlspecialchars($sDbTable, ENT_QUOTES, 'UTF-8');
    $sIcon        = htmlspecialchars($sIcon, ENT_QUOTES, 'UTF-8');

    // ---------- fields ----------
    $sFieldsXml    = '';
    $aNewFieldIds  = array();

    foreach ($aFields as $aField) {
        if (empty($aField['id']) || empty($aField['type'])) {
            continue;
        }

        $fid   = htmlspecialchars($aField['id'], ENT_QUOTES, 'UTF-8');
        $ftype = htmlspecialchars($aField['type'], ENT_QUOTES, 'UTF-8');
        $fsql  = htmlspecialchars($aField['sql'] ?? $fid, ENT_QUOTES, 'UTF-8');

        $isNull  = isset($aField['is_null_allowed']) && $aField['is_null_allowed'] ? 'true' : 'false';
        $default = htmlspecialchars($aField['default_value'] ?? '', ENT_QUOTES, 'UTF-8');

        $sFieldsXml .= "            <field id=\"{$fid}\" xsi:type=\"{$ftype}\">\n";

        // Enum 取值
        if ($ftype === 'AttributeEnum' && !empty($aField['values']) && is_array($aField['values'])) {
            $sFieldsXml .= "              <values>\n";
            foreach ($aField['values'] as $v) {
                $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $sFieldsXml .= "                <value>{$v}</value>\n";
            }
            $sFieldsXml .= "              </values>\n";
        }

        $sFieldsXml .= "              <sql>{$fsql}</sql>\n";
        $sFieldsXml .= "              <default_value>{$default}</default_value>\n";
        $sFieldsXml .= "              <is_null_allowed>{$isNull}</is_null_allowed>\n";

        // 可选展示风格
        if (!empty($aField['display_style'])) {
            $style = htmlspecialchars($aField['display_style'], ENT_QUOTES, 'UTF-8');
            $sFieldsXml .= "              <display_style>{$style}</display_style>\n";
        }

        $sFieldsXml .= "            </field>\n";

        $aNewFieldIds[] = $aField['id'];
    }

    $aNewFieldIds = _acwNormalizeList($aNewFieldIds);

    // ---------- presentation：继承父类 ZList + 追加新字段 ----------

    // details 基础：若传入父类列表则用之，否则根据父类类型给默认
    if (is_array($aParentDetails) && !empty($aParentDetails)) {
        $aDetailsBase = _acwNormalizeList($aParentDetails);
    } else {
        $aDetailsBase = ($sParentClass === 'FunctionalCI')
            ? array('name', 'org_id', 'business_criticity', 'move2production', 'description')
            : array('name');
    }

    // search 基础：优先用父类 search，fallback 到 details
    if (is_array($aParentSearch) && !empty($aParentSearch)) {
        $aSearchBase = _acwNormalizeList($aParentSearch);
    } else {
        $aSearchBase = $aDetailsBase;
    }

    // list 基础：优先用父类 list，否则给一个安全的简版
    if (is_array($aParentList) && !empty($aParentList)) {
        $aListBase = _acwNormalizeList($aParentList);
    } else {
        $aListBase = ($sParentClass === 'FunctionalCI')
            ? array('org_id', 'business_criticity', 'move2production', 'name')
            : array('name');
    }

    // 追加新字段：details / search 都追加全部新字段；list 只追加第一个新字段，避免过长
    $aDetailsFinal = $aDetailsBase;
    foreach ($aNewFieldIds as $fid) {
        if (!in_array($fid, $aDetailsFinal, true)) {
            $aDetailsFinal[] = $fid;
        }
    }

    $aSearchFinal = $aSearchBase;
    foreach ($aNewFieldIds as $fid) {
        if (!in_array($fid, $aSearchFinal, true)) {
            $aSearchFinal[] = $fid;
        }
    }

    $aListFinal = $aListBase;
    if (!empty($aNewFieldIds)) {
        $firstNew = $aNewFieldIds[0];
        if (!in_array($firstNew, $aListFinal, true)) {
            $aListFinal[] = $firstNew;
        }
    }

    // 生成 <items> 片段
    $buildItemsXml = function (array $aIds) {
        $iRank = 10;
        $sOut  = '';
        foreach ($aIds as $id) {
            $id = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            $sOut .= "                <item id=\"{$id}\"><rank>{$iRank}</rank></item>\n";
            $iRank += 10;
        }
        return $sOut;
    };

    $sDetailsItemsXml = $buildItemsXml($aDetailsFinal);
    $sSearchItemsXml  = $buildItemsXml($aSearchFinal);
    $sListItemsXml    = $buildItemsXml($aListFinal);

    // ---------- final XML ----------
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<itop_design xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.6">
  <classes>
    <class id="{$sClassId}" _delta="define">
      <parent>{$sParentClass}</parent>
      <properties>
        <category>bizmodel,searchable</category>
        <abstract>false</abstract>
        <key_type>autoincrement</key_type>
        <db_table>{$sDbTable}</db_table>
        <db_key_field>id</db_key_field>
        <naming>
          <format>%1\$s</format>
          <attributes><attribute id="name"/></attributes>
        </naming>
        <style>
          <icon>{$sIcon}</icon>
        </style>
        <reconciliation>
          <attributes>
            <attribute id="name"/>
          </attributes>
        </reconciliation>
      </properties>
      <fields>
{$sFieldsXml}      </fields>
      <methods>
      </methods>
      <presentation>
        <details>
          <items>
{$sDetailsItemsXml}          </items>
        </details>
        <search>
          <items>
{$sSearchItemsXml}          </items>
        </search>
        <list>
          <items>
{$sListItemsXml}          </items>
        </list>
      </presentation>
    </class>
  </classes>
  <menus>
    <menu id="ConfigManagementOverview" xsi:type="DashboardMenuNode" _delta="must_exist">
      <definition>
        <cells>
          <cell id="0">
            <dashlets>
              <dashlet id="{$sClassId}-badge" xsi:type="DashletBadge" _delta="define">
                <rank>7.5</rank>
                <class>{$sClassId}</class>
              </dashlet>
            </dashlets>
          </cell>
        </cells>
      </definition>
    </menu>
  </menus>
  <user_rights>
    <groups/>
  </user_rights>
</itop_design>
XML;

    return $xml;
}

/**
 * 生成 module.<module>.php 内容
 */
function GenerateModulePhp($sModuleName, $sModuleLabel, $sModuleVer, $sCategory, $sDependencies)
{
    // 依赖解析：支持逗号 / 换行分隔
    $aDeps = array();
    $tmp   = str_replace(array("\r\n", "\n", "\r"), ',', (string) $sDependencies);
    foreach (explode(',', $tmp) as $d) {
        $d = trim($d);
        if ($d !== '') {
            $aDeps[] = $d;
        }
    }

    $depStr = "        'dependencies' => array(\n";
    foreach ($aDeps as $d) {
        $d      = addslashes($d);
        $depStr .= "            '{$d}',\n";
    }
    $depStr .= "        ),\n";

    $sModuleNameEsc  = addslashes($sModuleName);
    $sModuleLabelEsc = addslashes($sModuleLabel);
    $sCategoryEsc    = addslashes($sCategory);
    $sDataModel      = 'datamodel.' . $sModuleName . '.xml';

    return <<<PHP
<?php

SetupWebPage::AddModule(
    __FILE__,
    '{$sModuleNameEsc}/{$sModuleVer}',
    array(
        'label' => '{$sModuleLabelEsc}',
        'category' => '{$sCategoryEsc}',
{$depStr}
        'mandatory' => false,
        'visible'   => true,

        'datamodel' => array(
            '{$sDataModel}',
        ),

        'webservice' => array(),
        'data.struct' => array(),
        'data.sample' => array(),

        'doc.manual_setup' => '',
        'doc.more_information' => '',

        'settings' => array(),
    )
);

PHP;
}

/**
 * 生成 en.dict.<module>.php
 */
function GenerateDictPhp($sModuleName, $sClassId, $sClassLabel, array $aFields)
{
    $sClassIdEsc    = addslashes($sClassId);
    $sClassLabelEsc = addslashes($sClassLabel);

    $s = "<?php\n";
    $s .= "Dict::Add('EN US', 'English', 'English', array(\n";
    // 类本身
    $s .= "    'Class:{$sClassIdEsc}' => '{$sClassLabelEsc}',\n";
    $s .= "    'Class:{$sClassIdEsc}+' => '',\n";

    // 字段
    foreach ($aFields as $f) {
        if (empty($f['id'])) {
            continue;
        }
        $fidEsc = addslashes($f['id']);
        $s .= "    'Class:{$sClassIdEsc}/Attribute:{$fidEsc}' => '{$fidEsc}',\n";
        $s .= "    'Class:{$sClassIdEsc}/Attribute:{$fidEsc}+' => '{$fidEsc}',\n";
    }
    $s .= "));\n";

    return $s;
}

function GenerateAddAttributeDeltaXml($sClassId, array $aField, $bDetails = true, $bSearch = true, $bList = true)
{
    $sClassId     = htmlspecialchars($sClassId, ENT_QUOTES, 'UTF-8');
    $fid          = htmlspecialchars($aField['id'] ?? '', ENT_QUOTES, 'UTF-8');
    $ftype        = htmlspecialchars($aField['type'] ?? 'AttributeString', ENT_QUOTES, 'UTF-8');
    $fsql         = htmlspecialchars($aField['sql'] ?? $fid, ENT_QUOTES, 'UTF-8');
    $isNull       = isset($aField['is_null_allowed']) && $aField['is_null_allowed'] ? 'true' : 'false';
    $default      = htmlspecialchars($aField['default_value'] ?? '', ENT_QUOTES, 'UTF-8');

    $sFieldXml = "          <field id=\"{$fid}\" xsi:type=\"{$ftype}\" _delta=\"define\">\n".
        "            <sql>{$fsql}</sql>\n".
        "            <default_value>{$default}</default_value>\n".
        "            <is_null_allowed>{$isNull}</is_null_allowed>\n";

    if ($ftype === 'AttributeEnum' && !empty($aField['values']) && is_array($aField['values'])) {
        $sFieldXml .= "            <values>\n";
        foreach ($aField['values'] as $v) {
            $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $sFieldXml .= "              <value>{$v}</value>\n";
        }
        $sFieldXml .= "            </values>\n";
    }

    if (!empty($aField['display_style'])) {
        $style = htmlspecialchars($aField['display_style'], ENT_QUOTES, 'UTF-8');
        $sFieldXml .= "            <display_style>{$style}</display_style>\n";
    }

    $sFieldXml .= "          </field>\n";

    $sPres = '';
    if ($bDetails) {
        $sPres .= "        <details>\n          <items>\n            <item id=\"{$fid}\" _delta=\"define\"><rank>500</rank></item>\n          </items>\n        </details>\n";
    }
    if ($bSearch) {
        $sPres .= "        <search>\n          <items>\n            <item id=\"{$fid}\" _delta=\"define\"><rank>500</rank></item>\n          </items>\n        </search>\n";
    }
    if ($bList) {
        $sPres .= "        <list>\n          <items>\n            <item id=\"{$fid}\" _delta=\"define\"><rank>500</rank></item>\n          </items>\n        </list>\n";
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<itop_design xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.6">
  <classes>
    <class id="{$sClassId}" _delta="must_exist">
      <fields>
{$sFieldXml}      </fields>
      <methods/>
      <presentation>
{$sPres}      </presentation>
    </class>
  </classes>
</itop_design>
XML;

    return $xml;
}
