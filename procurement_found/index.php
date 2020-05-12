<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
global $APPLICATION;
$APPLICATION->SetPageProperty('title', 'ТАБЛО ДЕЙСТВУЮЩИХ ПРОЦЕДУР');
CJSCore::Init(array('date'));
?>

<style type="text/css">
    .search-wrapper_ {
        text-align: left;
        height: 210px;
        margin-top: 30px;
        width: 100%;
        display: flex;
        flex-direction: row;
    }

    .search-fields-wrapper_ {
        display: inline-block;
        width: 300px;
        margin-left: 5px;
        vertical-align: top;
    }

    .search-fields-wrapper_ input {
        margin-top: 8px;
        width: 300px;
        border-radius: 3px;
    }

    .search-form-button-wrapper_ {
        text-align: center;
    }

    .search-button-wrapper_ {
        display: inline-block;
        width: 160px;
        height: 210px;
        margin-left: 5px;
        text-align: center;
        vertical-align: top;
    }

    .search-button-wrapper_ input {
        height: 40px;
        width: 120px;
        border: 4px solid #2b8a86;
    }


    .search-form-button-wrapper_ input[type="submit"] {
        width: 120px;
        height: 40px;
        border: 2px solid #2b8a86;
        margin-top: 70px;
        line-height: 0 !important;

    }

    .search-archive-button-wrapper_ {
        text-align: center;
    }

    .search-archive-button-wrapper_ input {
        width: 120px;
        height: 40px;
        border: 2px solid #2b8a86;
        margin-top: 55px;
        line-height: 0 !important;
    }

    .test {
        display: flex;
        flex-direction: row;
        justify-content: space-around;
        margin-top: 15px;
    }

    .test label {
        width: 40%;
        padding-right: 5px;
        text-align: right;
    }

    .test input {
        border-radius: 2px;
        width: 535px;
        color: black;
    }

    .test select {
        border-radius: 2px;
        width: 535px;
        color: black;
    }

    .search-label-wrapper_ {
        display: inline-block;
        height: 210px;
        text-align: right;
        vertical-align: top;
    }

    .search-label-wrapper_ label {
        color: #2b8a86;
    }

    .alert-wrapper_ {
        margin-top: 75px;
    }

</style>

<?
/*
 * Костыль для select'ов, т.к. забираются первые значения,
 * то просто ансетим их в глобальном массиве, если 1ый селект не вызывал их
 * */
if($_POST['select1'] == '0') {
    unset($_POST['select2']);
    unset($_POST['select3']);
    unset($_POST['select4']);
} elseif ($_POST['select1'] == '1') {
    unset($_POST['select3']);
    unset($_POST['select4']);
} elseif ($_POST['select1'] == '2') {
    unset($_POST['select2']);
    unset($_POST['select4']);
} elseif ($_POST['select1'] == '3') {
    unset($_POST['select2']);
    unset($_POST['select3']);
}

//костыльная сессия для пост запроса на странице
function _cacheSession($name, $else=null)
{
    return isset($_POST[$name]) ? $_POST[$name] : $else;
}

//удаляем двойные ковычки из строки
function _deleteDoubleQuote($str)
{
    $resultStr = '';
    $tempStr = str_split($str);
    var_export($tempStr);
    foreach ($tempStr as $char) {
        echo '1';
        if($char == '"') {
            echo '3';
            continue;
        } else {
            echo '4';
            $resultStr .= $char;
        }

    }

    echo 'end';
    return $resultStr;
}

$organizationList = CIBlockElement::GetList(
        array(

        ),
        array(
                "IBLOCK_ID" => 305,
                "ACTIVE" => "Y",
                "PROPERTY_SOURCE_SYSTEM" => "SRQCLNT100",
                "PROPERTY_STATUS_PURCHASE" => "Прием предложений",
        ),
        array(
                "PROPERTY_PURCH_OTDEL"
        ),
        false,
        array()
);

$arResultItems = array();
$arResultItems["ITEMS"] = array();
while($arItem = $organizationList->GetNext(true, false)) {
    $arResultItems["ITEMS"][$arItem["PROPERTY_PURCH_OTDEL_VALUE"]] = $arItem["CNT"];
}

?>

<form name="blabla" method="post" action="<?$APPLICATION->GetCurPage();?>">
    <div>
        <div>
            <span style="color: grey; font-size: 22px;">Уважаемые партнеры, здесь Вы можете посмотреть актуальные закупочные процедуры и контактную информацию по ним.</span>
        </div>

        <div class="search-wrapper_">

            <div class="search-label-wrapper_">
                <div class="test">
                    <label>Категория МТР:</label>
                    <select name="select1" id="select1" onchange="change_select(this)">
                        <option selected value="0" <?if(_cacheSession('select1') == '0') { echo 'selected'; }?>>--Выберите категорию--</option>
                        <option value="1" <?if(_cacheSession('select1') == '1') { echo 'selected'; }?>>Оборудование и материалы</option>
                        <option value="2" <?if(_cacheSession('select1') == '2') { echo 'selected'; }?>>Химическая продукция</option>
                        <option value="3" <?if(_cacheSession('select1') == '3') { echo 'selected'; }?>>Прочее</option>
                    </select>
                </div>
                <div class="test" style="<?if(isset($_POST['select2']) || isset($_POST['select3']) || isset($_POST['select4'])) { echo "display:flex;"; } else { echo "display:none;"; }?>" id="selectorDiv">
                    <label>Номенклатурная категория:</label>
                    <select name="select2" id="select2" onchange="change_select(this)" style="<?if(!empty($_POST['select2']))  { echo "display:inline;"; } else { echo "display:none;"; }?>">
                        <option value="Запорная арматура" <?if(_cacheSession('select2') == 'Запорная арматура') { echo 'selected'; }?>>Запорная арматура</option>
                        <option value="Кабельно-проводниковая продукция" <?if(_cacheSession('select2') == 'Кабельно-проводниковая продукция') { echo 'selected'; }?>>Кабельно-проводниковая продукция</option>
                        <option value="Транспорт и спецтехника, ж/д транспорт и оборудование к нему" <?if(_cacheSession('select2') == 'Транспорт и спецтехника, ж/д транспорт и оборудование к нему') { echo 'selected'; }?>>Транспорт и спецтехника, ж/д транспорт и оборудование к нему</option>
                        <option value="Спецзащита и средства защиты" <?if(_cacheSession('select2') == 'Спецзащита и средства защиты') { echo 'selected'; }?>>Спецзащита и средства защиты</option>
                        <option value="Насосно-компрессорное оборудование и запчасти к нему" <?if(_cacheSession('select2') == 'Насосно-компрессорное оборудование и запчасти к нему') { echo 'selected'; }?>>Насосно-компрессорное оборудование и запчасти к нему</option>
                        <option value="Трубы и металлопрокат" <?if(_cacheSession('select2') == 'Трубы и металлопрокат') { echo 'selected'; }?>>Трубы и металлопрокат</option>
                        <option value="Электрооборудование" <?if(_cacheSession('select2') == 'Электрооборудование') { echo 'selected'; }?>>Электрооборудование</option>
                        <option value="Системы и средства автоматизации, метрологии и КИП" <?if(_cacheSession('select2') == 'Системы и средства автоматизации, метрологии и КИП') { echo 'selected'; }?>>Системы и средства автоматизации, метрологии и КИП</option>
                        <option value="Технологическое оборудование" <?if(_cacheSession('select2') == 'Технологическое оборудование') { echo 'selected'; }?>>Технологическое оборудование</option>
                        <option value="Емкостное и теплообменное оборудование" <?if(_cacheSession('select2') == 'Емкостное и теплообменное оборудование') { echo 'selected'; }?>>Емкостное и теплообменное оборудование</option>
                        <option value="Детали трубопроводной арматуры" <?if(_cacheSession('select2') == 'Детали трубопроводной арматуры') { echo 'selected'; }?>>Детали трубопроводной арматуры</option>
                        <option value="Общезаводское оборудование" <?if(_cacheSession('select2') == 'Общезаводское оборудование') { echo 'selected'; }?>>Общезаводское оборудование</option>
                        <option value="Лабораторное оборудование и реактивы" <?if(_cacheSession('select2') == 'Лабораторное оборудование и реактивы') { echo 'selected'; }?>>Лабораторное оборудование и реактивы</option>
                        <option value="Металлоизделия и инструмент" <?if(_cacheSession('select2') == 'Металлоизделия и инструмент') { echo 'selected'; }?>>Металлоизделия и инструмент</option>
                        <option value="Строительные и изоляционные материалы" <?if(_cacheSession('select2') == 'Строительные и изоляционные материалы') { echo 'selected'; }?>>Строительные и изоляционные материалы</option>
                        <option value="Вспомогательные и общехозяйственные" <?if(_cacheSession('select2') == 'Вспомогательные и общехозяйственные') { echo 'selected'; }?>>Вспомогательные и общехозяйственные</option>
                        <option value="Здания и сооружения" <?if(_cacheSession('select2') == 'Здания и сооружения') { echo 'selected'; }?>>Здания и сооружения</option>
                        <option value="Компьютеры, оргтехника и комплектующие к ним" <?if(_cacheSession('select2') == 'Компьютеры, оргтехника и комплектующие к ним') { echo 'selected'; }?>>Компьютеры, оргтехника и комплектующие к ним</option>
                        <option value="Шины(все типоразмеры)" <?if(_cacheSession('select2') == 'Шины(все типоразмеры)') { echo 'selected'; }?>>Шины(все типоразмеры)</option>
                        <option value="Продукция резино-техническая" <?if(_cacheSession('select2') == 'Продукция резино-техническая') { echo 'selected'; }?>>Продукция резино-техническая</option>
                        <option value="Тара для сырья и готовой продукции 1 раздела" <?if(_cacheSession('select2') == 'Тара для сырья и готовой продукции 1 раздела') { echo 'selected'; }?>>Тара для сырья и готовой продукции 1 раздела</option>
                        <option value="Автокомплектующие и запчасти" <?if(_cacheSession('select2') == 'Автокомплектующие и запчасти') { echo 'selected'; }?>>Автокомплектующие и запчасти</option>
                        <option value="МТР технологического обеспечения" <?if(_cacheSession('select2') == 'МТР технологического обеспечения') { echo 'selected'; }?>>МТР технологического обеспечения</option>
                        <option value="Синтетические волокна, нити и ткани" <?if(_cacheSession('select2') == 'Синтетические волокна, нити и ткани') { echo 'selected'; }?>>Синтетические волокна, нити и ткани</option>
                    </select>
                    <select name="select3" id="select3" onchange="change_select(this)" style="<?if(!empty(_cacheSession('select3')))  { echo "display:inline;"; } else { echo "display:none;"; }?>">
                        <option value="Газы технические" <?if(_cacheSession('select3') == 'Газы технические') { echo 'selected'; }?>>Газы технические</option>
                        <option value="Горюче-смазочные материалы(ГСМ)" <?if(_cacheSession('select3') == 'Горюче-смазочные материалы(ГСМ)') { echo 'selected'; }?>>Горюче-смазочные материалы(ГСМ)</option>
                        <option value="Химическая продукция и реагенты" <?if(_cacheSession('select3') == 'Химическая продукция и реагенты') { echo 'selected'; }?>>Химическая продукция и реагенты</option>
                        <option value="Реактивы(лабораторное оборудование)" <?if(_cacheSession('select3') == 'Реактивы(лабораторное оборудование)') { echo 'selected'; }?>>Реактивы(лабораторное оборудование)</option>
                        <option value="Потери" <?if(_cacheSession('select3') == 'Потери') { echo 'selected'; }?>>Потери</option>
                        <option value="Газ сухой" <?if(_cacheSession('select3') == 'Газ сухой') { echo 'selected'; }?>>Газ сухой</option>
                        <option value="Сжиженные углеводородные газы" <?if(_cacheSession('select3') == 'Сжиженные углеводородные газы') { echo 'selected'; }?>>Сжиженные углеводородные газы</option>
                        <option value="Жидкие и мономеросодержащие углеводородные фракции" <?if(_cacheSession('select3') == 'Жидкие и мономеросодержащие углеводородные фракции') { echo 'selected'; }?>>Жидкие и мономеросодержащие углеводородные фракции</option>
                        <option value="Мономеры" <?if(_cacheSession('select3') == 'Мономеры') { echo 'selected'; }?>>Мономеры</option>
                    </select>
                    <select name="select4" id="select4" onchange="change_select(this)" style="<?if(!empty(_cacheSession('select4')))  { echo "display:inline;"; } else { echo "display:none;"; }?>">
                        <option value="Природные ресурсы" <?if(_cacheSession('select4') == 'Природные ресурсы') { echo 'selected'; }?>>Природные ресурсы</option>
                        <option value="Минеральные удобрения и сырье для них" <?if(_cacheSession('select4') == 'Минеральные удобрения и сырье для них') { echo 'selected'; }?>>Минеральные удобрения и сырье для них</option>
                        <option value="Земельные участки и объекты недвижимости" <?if(_cacheSession('select4') == 'Земельные участки и объекты недвижимости') { echo 'selected'; }?>>Земельные участки и объекты недвижимости</option>
                        <option value="Геодезическое оборудование и запасные части к нему" <?if(_cacheSession('select4') == 'Геодезическое оборудование и запасные части к нему') { echo 'selected'; }?>>Геодезическое оборудование и запасные части к нему</option>
                        <option value="Прочая номенклатура" <?if(_cacheSession('select4') == 'Прочая номенклатура') { echo 'selected'; }?>>Прочая номенклатура</option>
                        <option value="Продукция асбестовая техническая" <?if(_cacheSession('select4') == 'Продукция асбестовая техническая') { echo 'selected'; }?>>Продукция асбестовая техническая</option>
                        <option value="Сырье для обеспечения шинных заводов" <?if(_cacheSession('select4') == 'Сырье для обеспечения шинных заводов') { echo 'selected'; }?>>Сырье для обеспечения шинных заводов</option>
                        <option value="Углеводородное сырье(УВС)" <?if(_cacheSession('select4') == 'Углеводородное сырье(УВС)') { echo 'selected'; }?>>Углеводородное сырье(УВС)</option>
                        <option value="Каучуки" <?if(_cacheSession('select4') == 'Каучуки') { echo 'selected'; }?>>Каучуки</option>
                        <option value="Полимеры" <?if(_cacheSession('select4') == 'Полимеры') { echo 'selected'; }?>>Полимеры</option>
                        <option value="Изделия из полимеров" <?if(_cacheSession('select4') == 'Изделия из полимеров') { echo 'selected'; }?>>Изделия из полимеров</option>
                        <option value="Драгоценные металлы" <?if(_cacheSession('select4') == 'Драгоценные металлы') { echo 'selected'; }?>>Драгоценные металлы</option>
                        <option value="Изделия, получаемые технологиями послойного синтеза материалов" <?if(_cacheSession('select4') == 'Изделия, получаемые технологиями послойного синтеза материалов') { echo 'selected'; }?>>Изделия, получаемые технологиями послойного синтеза материалов</option>
                    </select>
                </div>
                <div class="test">
                    <label>Номер процедуры:</label>
                    <input type="text" name="search_purchase_id" value="<?=_cacheSession('search_purchase_id');?>">
                </div>
                <div class="test">
                    <label>Предмет закупки:</label>
                    <input type="text" name="search_subject_purchase" value="<?=_cacheSession('search_subject_purchase');?>">
                </div>
                <div class="test">
                    <label>Дата публикации процедуры:</label>
                    <input type="text" name="search_date_pub" value="<?=_cacheSession('search_date_pub');?>" onclick="BX.calendar({node: this, field: this, bTime: false})">
                </div>
                <div class="test">
                    <label>Дата окончания приема предложений:</label>
                    <input type="text" name="search_submission_deadline" value="<?=_cacheSession('search_submission_deadline');?>" onclick="BX.calendar({node: this, field: this, bTime: true})">
                </div>
                <div class="test">
                    <label>Организатор:</label>
                    <input type="text" name="search_organization" list="organization_list" value='<?=_cacheSession('search_organization');?>'>
                    <datalist id="organization_list">
                        <?foreach ($arResultItems["ITEMS"] as $key => $count):?>
                            <option value="<?=$key;?>"></option>
                        <?endforeach;?>
                    </datalist>
                </div>
            </div>

            <div class="search-button-wrapper_">
                <div class="search-archive-button-wrapper_">
                    <input type="submit" name="submitArchiveForm" value="Архив">
                </div>

                <div class="search-form-button-wrapper_">
                    <input type="hidden" name="submitKey" value="Y">
                    <input type="submit" name="submitSearchForm" value="Найти">
                </div>
            </div>

        </div>
        <div class="alert-wrapper_" id="alert-wrapper_">
            <p><em style="color: grey; font-size: 12px;">*Множественные значения вводите через точку с запятой(;) без пробелов и других символов в качестве разделителей</em></p>
        </div>
    </div>
</form>

<?

    if($_SERVER['REQUEST_METHOD'] == 'POST') {
        if($_POST['submitKey'] == 'Y') {

            if(isset($_POST['submitArchiveForm'])) {
                LocalRedirect("./archive");
            } else {
                //костыль для селектов
                /*if($_POST['select1'] == '0') {
                    unset($_POST['select2']);
                    unset($_POST['select3']);
                    unset($_POST['select4']);
                } elseif ($_POST['select1'] == '1') {
                    unset($_POST['select3']);
                    unset($_POST['select4']);
                } elseif ($_POST['select1'] == '2') {
                    unset($_POST['select2']);
                    unset($_POST['select4']);
                } elseif ($_POST['select1'] == '3') {
                    unset($_POST['select2']);
                    unset($_POST['select3']);
                }*/

                if(strlen($_POST['search_submission_deadline']) < 18) {
                    $_POST['search_submission_deadline'] = ConvertDateTime($_POST['search_submission_deadline'], "YYYY-MM-DD");
                } else {
                    $_POST['search_submission_deadline'] = ConvertDateTime($_POST['search_submission_deadline'], "YYYY-MM-DD HH:II:SS");
                }

                $EkmtrText = '';
                if($_POST['select1'] != '0') {

                    if($_POST['select1'] == '1') {
                        if(isset($_POST['select2'])){
                            $EkmtrText = $_POST['select2'];
                        }
                    }

                    if($_POST['select1'] == '2') {
                        if(isset($_POST['select3'])){
                            $EkmtrText = $_POST['select3'];
                        }
                    }

                    if($_POST['select1'] == '3') {
                        if(isset($_POST['select4'])){
                            $EkmtrText = $_POST['select4'];
                        }
                    }
                }

                $arParams = array(
                    'PROPERTY_PURCHASE_ID' => explode(';', $_POST['search_purchase_id']),
                    'PROPERTY_SUBJECT_PURCHASE' => explode(';', $_POST['search_subject_purchase']),
                    'PROPERTY_DATE_PUB' => ConvertDateTime($_POST['search_date_pub'], "YYYY-MM-DD"),
                    'PROPERTY_SUBMISSION_DEADLINE' => $_POST['search_submission_deadline'],
                    /*'PROPERTY_EKMTR_TEXT' => explode(';', $_POST['search_ekmtr_text']),*/
                    'PROPERTY_EKMTR_TEXT' => $EkmtrText,
                    'PROPERTY_PURCH_OTDEL' => explode(';', $_POST['search_organization']),
                );

            }

        }
    }
?>


<?
/*$GLOBALS['arrFilter'] = array(
    'PROPERTY_PURCHASE_ID' => $arParams['PROPERTY_PURCHASE_ID'],
    '%PROPERTY_SUBJECT_PURCHASE' => $arParams['PROPERTY_SUBJECT_PURCHASE'],
    '%PROPERTY_DATE_PUB' => $arParams['PROPERTY_DATE_PUB'],
    '%PROPERTY_SUBMISSION_DEADLINE' => $arParams['PROPERTY_SUBMISSION_DEADLINE'],
    '%PROPERTY_PURCH_OTDEL' => $arParams['PROPERTY_PURCH_OTDEL'],
);*/

$GLOBALS['arrFilter'] = array(
    '%PROPERTY_PURCHASE_ID' => $arParams['PROPERTY_PURCHASE_ID'],
    '%PROPERTY_SUBJECT_PURCHASE' => $arParams['PROPERTY_SUBJECT_PURCHASE'],
    '%PROPERTY_DATE_PUB' => $arParams['PROPERTY_DATE_PUB'],
    '%PROPERTY_SUBMISSION_DEADLINE' => $arParams['PROPERTY_SUBMISSION_DEADLINE'],
    '%PROPERTY_EKMTR_TEXT' => $arParams['PROPERTY_EKMTR_TEXT'],
    '%PROPERTY_PURCH_OTDEL' => $arParams['PROPERTY_PURCH_OTDEL'],
    'PROPERTY_STATUS_PURCHASE' => 'Прием предложений',
    "PROPERTY_SOURCE_SYSTEM" => "SRQCLNT100",
);

$arProperty = array('SOURCE_SYSTEM', 'PURCHASE_ID', 'PURCHASE_NAME', 'SUBJECT_PURCHASE', 'PURCHASE_LANG', 'PURCHASE_TEXT', 'DATE_PUB', 'SUBMISSION_DEADLINE',
    'PURCH_OTDEL', 'STATUS_PURCHASE', 'NAME_PURCHASER', 'TELEPHONE_PURCHASER', 'EMAIL_PURCHASER', 'EKMTR',
    'EKMTR_LANG', 'EKMTR_TEXT', 'DOCUMENTATION');


$APPLICATION->IncludeComponent(
	"bitrix:news.list", 
	"procurement", 
	array(
		"DISPLAY_DATE" => "Y",
		"DISPLAY_NAME" => "Y",
		"DISPLAY_PICTURE" => "Y",
		"DISPLAY_PREVIEW_TEXT" => "Y",
		"AJAX_MODE" => "Y",
		"IBLOCK_TYPE" => "purchases",
		"IBLOCK_ID" => "305",
		"NEWS_COUNT" => "100",
		"SORT_BY1" => "",
		"SORT_ORDER1" => "DESC",
		"SORT_BY2" => "SORT",
		"SORT_ORDER2" => "ASC",
		"FILTER_NAME" => "arrFilter",
		"FIELD_CODE" => "",
		"PROPERTY_CODE" => $arProperty,
		"CHECK_DATES" => "Y",
		"DETAIL_URL" => "",
		"PREVIEW_TRUNCATE_LEN" => "",
		"ACTIVE_DATE_FORMAT" => "d.m.Y",
		"SET_TITLE" => "N",
		"SET_BROWSER_TITLE" => "Y",
		"SET_META_KEYWORDS" => "Y",
		"SET_META_DESCRIPTION" => "Y",
		"SET_LAST_MODIFIED" => "Y",
		"INCLUDE_IBLOCK_INTO_CHAIN" => "N",
		"ADD_SECTIONS_CHAIN" => "Y",
		"HIDE_LINK_WHEN_NO_DETAIL" => "Y",
		"PARENT_SECTION" => "",
		"PARENT_SECTION_CODE" => "",
		"INCLUDE_SUBSECTIONS" => "Y",
		"CACHE_TYPE" => "A",
		"CACHE_TIME" => "3600",
		"CACHE_FILTER" => "Y",
		"CACHE_GROUPS" => "Y",
		"DISPLAY_TOP_PAGER" => "Y",
		"DISPLAY_BOTTOM_PAGER" => "Y",
		"PAGER_TITLE" => "",
		"PAGER_SHOW_ALWAYS" => "Y",
		"PAGER_TEMPLATE" => "",
		"PAGER_DESC_NUMBERING" => "Y",
		"PAGER_DESC_NUMBERING_CACHE_TIME" => "36000",
		"PAGER_SHOW_ALL" => "Y",
		"PAGER_BASE_LINK_ENABLE" => "Y",
		"SET_STATUS_404" => "Y",
		"SHOW_404" => "Y",
		"MESSAGE_404" => "",
		"PAGER_BASE_LINK" => "",
		"PAGER_PARAMS_NAME" => "arrPager",
		"AJAX_OPTION_JUMP" => "N",
		"AJAX_OPTION_STYLE" => "Y",
		"AJAX_OPTION_HISTORY" => "N",
		"AJAX_OPTION_ADDITIONAL" => "",
		"COMPONENT_TEMPLATE" => "procurement",
		"STRICT_SECTION_CHECK" => "N",
		"FILE_404" => ""
	),
	false
);?>
<script type="text/javascript">
    function change_select(elem) {
        switch (elem.value) {
            case '0':
                document.getElementById('selectorDiv').style.display = 'none';
                document.getElementById('select4').style.display = 'none';
                document.getElementById('select3').style.display = 'none';
                document.getElementById('select2').style.display = 'none';
                break;
            case '1':
                document.getElementById('selectorDiv').style.display = 'flex';
                document.getElementById('select4').style.display = 'none';
                document.getElementById('select3').style.display = 'none';
                document.getElementById('select2').style.display = 'inline';
                break;
            case '2':
                document.getElementById('selectorDiv').style.display = 'flex';
                document.getElementById('select4').style.display = 'none';
                document.getElementById('select3').style.display = 'inline';
                document.getElementById('select2').style.display = 'none';
                break;
            case '3':
                document.getElementById('selectorDiv').style.display = 'flex';
                document.getElementById('select4').style.display = 'inline';
                document.getElementById('select3').style.display = 'none';
                document.getElementById('select2').style.display = 'none';
                break;
        }
    }
</script>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
