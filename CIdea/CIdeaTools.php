<?php
require_once(__DIR__.'/CIdeaConst.php');

class CIdeaTools {
    protected static $cache = array(
        'fullDepartmentTree'=> array(),
        'myBranch'          => array(
            'defaultList'   => array(), // Список который получается при инициализации (::init())
            'fullList'      => array(), // Список всех департаментов, которе были найдены у этого пользователя во всех вариантах кеша
        ),
        'membersCount'      => array(),
        'graphCoefficient'  => array(),
        'lists'             => array(),
        'ideasStatuses'     => array(),
        'lastIdeaPerDep'    => array('in'=>array(), 'sub'=>array()),
        'lastIdeaInnerNumberPerDep'    => array('in'=>array(), 'sub'=>array()),
        'filterManager'     => array(),
        'iblocks'           => array(),
        'statuses'          => array(),
        'moduleGroups'      => array('ID' => array(),'CODE' => array(),'ROLE' => array()),
    );

    protected static $user = array();

    /**
     * Получает ID IBlock по его Типу и Коду
     * @param string $sType
     * @param string $sCode
     * @return int ID или -1 если не найдено
     */
    public static function getIBlockID($sType, $sCode) {
        if(empty($sType) || empty($sCode))
            return -1;

        // Проверяем в кеше
        if(empty(self::$cache['iblocks']["_{$sType}_{$sCode}_"])) {
            $obIBlock = new CIBlock();
            $arIBlock = $obIBlock->GetList(array(), array('TYPE' => $sType, 'CODE' => $sCode))->Fetch();

            // Записываем в кеш
            self::$cache['iblocks']["_{$sType}_{$sCode}_"] = $arIBlock['ID']?:-1;
        }

        return self::$cache['iblocks']["_{$sType}_{$sCode}_"];

    }

    /**
     * Получить все элементы инфоблока
     * @param int $ib
     * @return array|int
     */
    public static function getIbElements($ib=5603, $filter=array(), $select=array()) {
        if(empty($ib))
            return -1;
        $result = [];
        $arSelect = array_merge($select, Array("ID", "IBLOCK_ID", "NAME", "SORT", "PROPERTY_*"));
        $arFilter =  $filter + Array("IBLOCK_ID"=>IntVal($ib), "ACTIVE_DATE"=>"Y", "ACTIVE"=>"Y");
        $res = CIBlockElement::GetList(Array("SORT"=>"ASC"), $arFilter, false, Array(), $arSelect);
        while($ob = $res->GetNextElement()){
            $arFields = $ob->GetFields();
            $result[$arFields['ID']] = [
                'FIELDS' => $arFields,
                'PROPS'  => $ob->GetProperties()
            ];
        }
        return $result;
    }

    public static function getElId($sCode, $select=array("ID"), $sIbCode="umshSettings", $sType="umsh") {
        if(empty($sType) || empty($sCode))
            return -1;
        // Проверяем в кеше
        $select_str = implode($select);
        if(empty(self::$cache['iblocks']["_{$sType}_{$select_str}_{$sIbCode}_{$sType}_"])) {
            $el = new CIBlockElement();
            $rsSettingsEl = $el->GetList(
                array('created_date' => 'ASC'),
                array(
                    'CODE' => $sCode,//'notifications',
                    'IBLOCK_ID' => CIdeaTools::getIBlockID($sType, $sIbCode)
                ),
                false,
                false,
                $select
            );
            $arResults = array();
            // Проходимся по результатам
            while($arRes = $rsSettingsEl->Fetch()) {
                $arResults[] = $arRes;
            }
            // Записываем в кеш
            self::$cache['iblocks']["_{$sType}_{$select_str}_{$sIbCode}_{$sType}_"] = $arResults?:-1;
        }

        return self::$cache['iblocks']["_{$sType}_{$select_str}_{$sIbCode}_{$sType}_"];
    }

    public static function getElById($id, $select, $sIbCode="umshSettings", $sType="umsh") {
        $el = new CIBlockElement();
        $rsSettings = $el->GetList(
            array('created_date' => 'ASC'),
            array(
                'ID' => $id,
                'IBLOCK_ID' => self::getIBlockID($sType, $sIbCode)
            ),
            false,
            false,
            $select
        );
        /*while($settings = $rsSettings->Fetch()) {
            $arrSettings=$settings;
        }*/
        return $rsSettings->Fetch();
    }

    /*
     * получить значения для немножественного свойства
     */
    public static function getIbProperties($property, $sIbCode="umshSettings", $sType="umsh") {
        $res = array();
        $property_enums = CIBlockPropertyEnum::GetList(Array("DEF"=>"DESC", "SORT"=>"ASC"), Array("IBLOCK_ID"=>self::getIBlockID($sType, $sIbCode), "CODE"=> $property));
        while($enum_fields = $property_enums->GetNext())
        {
            $res[]= $enum_fields;
        }

        if(empty($res)) return false;
        return $res;
    }/*
    public static function getIbProperties($sIbCode="umshSettings", $sType="umsh") {
        $res = array();
        $properties = CIBlockProperty::GetList(Array("sort"=>"asc", "name"=>"asc"), Array("ACTIVE"=>"Y", "IBLOCK_ID"=>self::getIBlockID($sType, $sIbCode)));
        while ($prop_fields = $properties->GetNext())
        {
            $res[]= $prop_fields;// echo $prop_fields["ID"]." - ".$prop_fields["NAME"]."<br>";
        }
        if(empty($res)) return false;
        return $res;
    }*/


    /**
     * init
     * @param bool $doNotCheckDep
     */
    public function init($doNotCheckDep = false) {
        // данные по группа модуля
        self::getModuleGroups();

        // данные по пользователю
        self::userInit();

        // моя ветка структуры
        self::$cache['myBranch']['defaultList'] = self::getMyBranch('', $doNotCheckDep);

        // Получаем роль
        self::getUserRole();

        // Получаем роль
        self::getUserRights();

        // Получаем статусы для идей
        self::getStatusesCodeForIdes();
    } //


    /**
     * Метод получения коэффициентов влияния для идей
     *
     * @param int    $iDepartmentID
     * @param string $sDate
     * @param bool   $bWithData возвращать ввиде массива с данными по каждому уровню влияния коэффициентов
     *
     * @return array|bool false при ошибке | массив при успехе
     */
    public static function getIdeaCoefficient($iDepartmentID, $sDate, $bWithData = false) {
        $arResult = array('DATA' => array('1' => array(), '2' => array(), '3' => array()), 'CURRENT' => array());
        // Проверяем ID подразделения
        if(!$iDepartmentID || !filter_var($iDepartmentID, FILTER_VALIDATE_INT) || intval($iDepartmentID) < 1)
            return false;

        // Проверяем дату
        if(!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', array_shift(explode(' ', $sDate)), $arMatch))
            return false;
        $sDate = $arMatch[0];

        // Фильтр элементов
        $arFilter = array(
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
            '<=DATE_ACTIVE_FROM' => $sDate,
            array(
                'LOGIC' => 'OR',
                array('DATE_ACTIVE_TO' => false),
                array('>=DATE_ACTIVE_TO' => $sDate),
            ),

        );

        // Получаем список свойст типов записей
        $arTypeList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'TYPE');

        if(!isset($arTypeList['coefficients']['ID']))
            return false;

        // Дополняем фильтр по типу записи
        $arFilter['PROPERTY_TYPE'] = $arTypeList['coefficients']['ID'];


        // Фильтр по подразделениям
        $arFilter['SECTION_ID'] = array_keys(self::getBranch($iDepartmentID, 0, true, false)?:array());

        // Если секция пустая, то значит где то ошибка! FALSE
        if(empty($arFilter['SECTION_ID']))
            return false;


        // Получаем элементы для поиска самых свежих коэфициентов
        $rsResult = CIBlockElement::GetList(
            array('DATE_ACTIVE_FROM' => 'DESC'),
            $arFilter,
            false,
            false,
            array(
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'ACTIVE_FROM',
                'ACTIVE_TO',
                'PROPERTY_CFF_COST',
                'PROPERTY_CFF_PROTECTION',
                'PROPERTY_CFF_QUALITY',
                'PROPERTY_CFF_OUTPUT',
                'PROPERTY_CFF_ORDERLINESS',
                'PROPERTY_CFF_LABORIOUSNESS',
                'PROPERTY_CFF_EFFICIENCY',
                'PROPERTY_CFF_LOSSES',
                'PROPERTY_CFF_CUSTOMER_FOCUS',
                'PROPERTY_CFF_DEMAND_SALES',
                'PROPERTY_CFF_SALES_EFFECTIVENESS',
                'PROPERTY_CFF_NO_ALLOWANCES',
            )
        );

        while($arRow = $rsResult->Fetch()) {
            $arBranch = array_shift(self::getBranch($arRow['IBLOCK_SECTION_ID'], 0, false, false));
            if(empty($arBranch['DEPTH_LEVEL']))
                continue;

            // Собираем данные по уровням
            if(empty($arResult['DATA'][$arBranch['DEPTH_LEVEL']]))
                $arResult['DATA'][$arBranch['DEPTH_LEVEL']] = array(
                    'ID'            => $arRow['ID'],
                    'ACTIVE_FROM'   => $arRow['ACTIVE_FROM'],
                    'ACTIVE_TO'     => $arRow['ACTIVE_TO'],
                    'SECTION_ID'    => $arRow['IBLOCK_SECTION_ID'],
                    'DEPTH_LEVEL'   => $arBranch['DEPTH_LEVEL'],
                    'DATA'          => array(
                        'COST'          => $arRow['PROPERTY_CFF_COST_VALUE'],
                        'PROTECTION'    => $arRow['PROPERTY_CFF_PROTECTION_VALUE'],
                        'QUALITY'       => $arRow['PROPERTY_CFF_QUALITY_VALUE'],
                        'OUTPUT'        => $arRow['PROPERTY_CFF_OUTPUT_VALUE'],
                        'ORDERLINESS'   => $arRow['PROPERTY_CFF_ORDERLINESS_VALUE'],
                        'LABORIOUSNESS' => $arRow['PROPERTY_CFF_LABORIOUSNESS_VALUE'],
                        'EFFICIENCY'    => $arRow['PROPERTY_CFF_EFFICIENCY_VALUE'],
                        'LOSSES'        => $arRow['PROPERTY_CFF_LOSSES_VALUE'],
                        'CUSTOMER_FOCUS'=> $arRow['PROPERTY_CFF_CUSTOMER_FOCUS_VALUE'],
                        'DEMAND_SALES'  => $arRow['PROPERTY_CFF_DEMAND_SALES_VALUE'],
                        'SALES_EFFECTIVENESS' => $arRow['PROPERTY_CFF_SALES_EFFECTIVENESS_VALUE'],
                        'NO_ALLOWANCES'       => $arRow['PROPERTY_CFF_NO_ALLOWANCES_VALUE'],
                    ),
                );
        }
        /*
         * Расчитываем значения учета автора в расчетах
         */
        for($i=1; $i < 4; $i++) {
            if(empty($arResult['DATA'][$i])) continue;
            $arCurrentData = &$arResult['DATA'][$i]['DATA'];

            // Значение родителя (если первый уровень, то рассчитываем на своих же данных)
            if($i == 1) {
                $arCurrentData['NO_ALLOWANCES_PARENT'] = $arCurrentData['NO_ALLOWANCES'] == 'Y'?'Y':'N';
            } else {
                $iParent = $i -1;
                do {
                    $arCurrentData['NO_ALLOWANCES_PARENT'] = $arResult['DATA'][$iParent]['DATA']['NO_ALLOWANCES_CURRENT'];
                } while(--$iParent > 0 && !$arCurrentData['NO_ALLOWANCES_PARENT']);
            }

            // Получаем значение на этом уровне (свое или родителя)
            $arCurrentData['NO_ALLOWANCES_CURRENT'] = $arCurrentData['NO_ALLOWANCES']?:$arCurrentData['NO_ALLOWANCES_PARENT'];
        }
        unset($arCurrentData);

        /*
         * Выставляем как самые актуальные те коэффициенте, которые на болольшей вложености
         */
        $arResult['CURRENT'] = $arResult['DATA']['3']?:($arResult['DATA']['2']?:$arResult['DATA']['1']);


        if(empty($arResult['CURRENT']))
            return false;


        return $bWithData?$arResult:$arResult['CURRENT']['DATA'];
    }


    /**
     * Метод получения коэффициентов влияния для идей в конкретном департаменте
     *
     * @param int    $iDepartmentID
     *
     * @return array|bool false при ошибке | массив при успехе
     */
    public static function getIdeaCoefficientList($iDepartmentID) {
        $arResult = array();
        // Проверяем ID подразделения
        if(!$iDepartmentID || !filter_var($iDepartmentID, FILTER_VALIDATE_INT) || intval($iDepartmentID) < 1)
            return false;

        // Фильтр элементов
        $arFilter = array(
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
        );

        // Получаем список свойст типов записей
        $arTypeList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'TYPE');

        if(!isset($arTypeList['coefficients']['ID']))
            return false;

        // Дополняем фильтр по типу записи
        $arFilter['PROPERTY_TYPE'] = $arTypeList['coefficients']['ID'];


        // Фильтр по подразделениям
        $arFilter['SECTION_ID'] = array_keys(self::getBranch($iDepartmentID, 0, false, false)?:array());
        // Если секция пустая, то значит где то ошибка! FALSE
        if(empty($arFilter['SECTION_ID']))
            return false;


        // Получаем элементы для поиска самых свежих коэфициентов
        $rsResult = CIBlockElement::GetList(
            array('ACTIVE_FROM' => 'DESC'),
            $arFilter,
            false,
            false,
            array(
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'ACTIVE_FROM',
                'ACTIVE_TO',
                'PROPERTY_CFF_COST',
                'PROPERTY_CFF_PROTECTION',
                'PROPERTY_CFF_QUALITY',
                'PROPERTY_CFF_OUTPUT',
                'PROPERTY_CFF_ORDERLINESS',
                'PROPERTY_CFF_LABORIOUSNESS',
                'PROPERTY_CFF_EFFICIENCY',
                'PROPERTY_CFF_LOSSES',
                'PROPERTY_CFF_CUSTOMER_FOCUS',
                'PROPERTY_CFF_DEMAND_SALES',
                'PROPERTY_CFF_SALES_EFFECTIVENESS',
                'PROPERTY_CFF_NO_ALLOWANCES',
            )
        );

        while($arRow = $rsResult->Fetch()) {
            $arResult[$arRow['ID']] = array(
                'ID'            => $arRow['ID'],
                'ACTIVE_FROM'   => $arRow['ACTIVE_FROM'],
                'ACTIVE_TO'     => $arRow['ACTIVE_TO'],
                'SECTION_ID'    => $arRow['IBLOCK_SECTION_ID'],
                'DATA'          => array(
                    'COST'          => $arRow['PROPERTY_CFF_COST_VALUE'],
                    'PROTECTION'    => $arRow['PROPERTY_CFF_PROTECTION_VALUE'],
                    'QUALITY'       => $arRow['PROPERTY_CFF_QUALITY_VALUE'],
                    'OUTPUT'        => $arRow['PROPERTY_CFF_OUTPUT_VALUE'],
                    'ORDERLINESS'   => $arRow['PROPERTY_CFF_ORDERLINESS_VALUE'],
                    'LABORIOUSNESS' => $arRow['PROPERTY_CFF_LABORIOUSNESS_VALUE'],
                    'EFFICIENCY'    => $arRow['PROPERTY_CFF_EFFICIENCY_VALUE'],
                    'LOSSES'        => $arRow['PROPERTY_CFF_LOSSES_VALUE'],
                    'CUSTOMER_FOCUS'=> $arRow['PROPERTY_CFF_CUSTOMER_FOCUS_VALUE'],
                    'DEMAND_SALES'  => $arRow['PROPERTY_CFF_DEMAND_SALES_VALUE'],
                    'SALES_EFFECTIVENESS' => $arRow['PROPERTY_CFF_SALES_EFFECTIVENESS_VALUE'],
                    'NO_ALLOWANCES'       => $arRow['PROPERTY_CFF_NO_ALLOWANCES_VALUE'],
                ),
            );
        }

        return $arResult;
    }


    /**
     * Метод получения коэффициента графика для подразделения
     *
     *
     * @param $iDepartmentID
     * @param $sGraphCode
     * @param $sDate
     * @param bool $bWithData
     *
     * @return array|bool false при ошибке | массив при успехе
     */
    public static function getGraphCoefficient($iDepartmentID, $sGraphCode, $sDate, $bWithData = false) {
        $arResult = array('DATA' => array('1' => array(), '2' => array(), '3' => array()), 'CURRENT' => array());
        // Проверяем ID подразделения
        if(!$iDepartmentID || !filter_var($iDepartmentID, FILTER_VALIDATE_INT) || intval($iDepartmentID) < 1)
            return false;

        // Проверяем код графика
        if(empty(CGraphs::$graphs[$sGraphCode]))
            return false;

        // Проверяем дату
        if(!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', array_shift(explode(' ', $sDate)), $arMatch))
            return false;
        $sDate = $arMatch[0];

        // Фильтр элементов
        $arFilter = array(
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
            '<=DATE_ACTIVE_FROM' => $sDate,
            array(
                'LOGIC' => 'OR',
                array('DATE_ACTIVE_TO' => false),
                array('>=DATE_ACTIVE_TO' => $sDate),
            ),
        );

        // Получаем список свойств типов записей
        $arTypeList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'TYPE');

        if(!isset($arTypeList['graph']['ID']))
            return false;

        // Получаем список свойст графиков записей
        $arGraphList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'GRH_TYPE');

        if(!isset($arGraphList[$sGraphCode]['ID']))
            return false;

        // Дополняем фильтр по типу записи
        $arFilter['PROPERTY_TYPE'] = $arTypeList['graph']['ID'];

        // Дополняем фильтр по типу графика
        $arFilter['PROPERTY_GRH_TYPE'] = $arGraphList[$sGraphCode]['ID'];

        // Фильтр по подразделениям
        $arFilter['SECTION_ID'] = array_keys(self::getBranch($iDepartmentID, 0, true, false)?:array());

        // Если секция пустая, то значит где то ошибка! FALSE
        if(empty($arFilter['SECTION_ID']))
            return false;

        // Получаем элементы для поиска самых свежих коэфициентов
        $rsResult = CIBlockElement::GetList(
            array('DATE_ACTIVE_FROM' => 'DESC'),
            $arFilter,
            false,
            false,
            array(
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'ACTIVE_FROM',
                'ACTIVE_TO',
                'PROPERTY_GRH_TARGET',
                'PROPERTY_GRH_THRESHOLD',
            )
        );

        while($arRow = $rsResult->Fetch()) {
            $arBranch = array_shift(self::getBranch($arRow['IBLOCK_SECTION_ID'], 0, false, false));
            if(empty($arBranch['DEPTH_LEVEL']))
                continue;

            // Собираем данные по уровням
            if(empty($arResult['DATA'][$arBranch['DEPTH_LEVEL']]))
                $arResult['DATA'][$arBranch['DEPTH_LEVEL']] = array(
                    'ID'            => $arRow['ID'],
                    'ACTIVE_FROM'   => $arRow['ACTIVE_FROM'],
                    'ACTIVE_TO'     => $arRow['ACTIVE_TO'],
                    'SECTION_ID'    => $arRow['IBLOCK_SECTION_ID'],
                    'DEPTH_LEVEL'   => $arBranch['DEPTH_LEVEL'],
                    'DATA'          => array(
                        'TARGET'    => $arRow['PROPERTY_GRH_TARGET_VALUE'],
                        'THRESHOLD' => $arRow['PROPERTY_GRH_THRESHOLD_VALUE'],
                    ),
                );
        }
        /*
         * Выставляем как самые актуальные те коэффициенте, которые на болольшей вложености
         */
        $arResult['CURRENT'] = $arResult['DATA']['3']?:($arResult['DATA']['2']?:$arResult['DATA']['1']);


        if(empty($arResult['CURRENT']))
            return false;


        return $bWithData?$arResult:$arResult['CURRENT']['DATA'];

    }


    /**
     * Метод возвращает список значений графика для подразделения
     *
     *
     * @param $iDepartmentID
     * @param $sGraphCode
     *
     * @return bool|array false при ошибке | массив при успехе
     */
    public static function getGraphCoefficientList($iDepartmentID, $sGraphCode) {
        $arResult = array();
        // Проверяем ID подразделения
        if(!$iDepartmentID || !filter_var($iDepartmentID, FILTER_VALIDATE_INT) || intval($iDepartmentID) < 1)
            return false;

        // Проверяем код графика
        if(empty(CGraphs::$graphs[$sGraphCode]))
            return false;

        // Фильтр элементов
        $arFilter = array(
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
        );


        // Получаем список свойств типов записей
        $arTypeList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'TYPE');

        if(!isset($arTypeList['graph']['ID']))
            return false;

        // Получаем список свойст графиков записей
        $arGraphList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'GRH_TYPE');

        if(!isset($arGraphList[$sGraphCode]['ID']))
            return false;

        // Дополняем фильтр по типу записи
        $arFilter['PROPERTY_TYPE'] = $arTypeList['graph']['ID'];

        // Дополняем фильтр по типу графика
        $arFilter['PROPERTY_GRH_TYPE'] = $arGraphList[$sGraphCode]['ID'];


        // Фильтр по подразделениям
        $arFilter['SECTION_ID'] = array_keys(self::getBranch($iDepartmentID, 0, false, false)?:array());
        // Если секция пустая, то значит где то ошибка! FALSE
        if(empty($arFilter['SECTION_ID']))
            return false;


        // Получаем элементы
        $rsResult = CIBlockElement::GetList(
            array('ACTIVE_FROM' => 'DESC'),
            $arFilter,
            false,
            false,
            array(
                'ID',
                'CODE',
                'IBLOCK_SECTION_ID',
                'ACTIVE_FROM',
                'ACTIVE_TO',
                'PROPERTY_GRH_TARGET',
                'PROPERTY_GRH_THRESHOLD',
            )
        );

        while($arRow = $rsResult->Fetch()) {
            $arResult[$arRow['ID']] = array(
                'ID'            => $arRow['ID'],
                'ACTIVE_FROM'   => $arRow['ACTIVE_FROM'],
                'ACTIVE_TO'     => $arRow['ACTIVE_TO'],
                'SECTION_ID'    => $arRow['IBLOCK_SECTION_ID'],
                'DATA'          => array(
                    'TARGET'    => $arRow['PROPERTY_GRH_TARGET_VALUE'],
                    'THRESHOLD' => $arRow['PROPERTY_GRH_THRESHOLD_VALUE'],
                ),
            );
        }

        return $arResult;
    }


    /**
     * Метод получает среднесписочную численность на Предприятии/Подразделении/Цехе
     * @param int    $iDepartmentID ID элемента структуры ПСС
     * @param string $sDate Дата на которую смотреть актульные данные
     * @param bool   $bWithData возвращает массив с информацией за последний год и текущим количеством
     *
     * @return bool|int|array false при ошибке | число сотрудников при успехе или массив с данными, если запрашивалось
     */
    public static function getEmployersCount($iDepartmentID, $sDate, $bWithData = false) {
        // Проверяем id
        if($iDepartmentID && (intval($iDepartmentID) < 1 || intval($iDepartmentID).'' != $iDepartmentID))
            return false;

        // Проверяем дату
        if(!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', array_shift(explode(' ', $sDate)), $arMatch))
            return false;

        $sDate          = $arMatch[0];
        $iDate          = MakeTimestamp($sDate .' 00:00:00');
        $iDepartmentID  = $iDepartmentID?:'all';
        $arResult       = array('COUNT' => 0, 'DATA' => array());

        // Возвращаем кэш
        // TODO - кешировать пул-дат, а не конкретное число. т.к. шанс запроса той же даты - крайне мал
        //if(isset(self::$cache['membersCount'][$iDepartmentID][$sDate]))
        //    return self::$cache['membersCount'][$iDepartmentID][$sDate]; - $bWithData?

        // Фильтр элементов
        $arFilter = array(
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
        );

        if(!$bWithData)
            $arFilter['<=DATE_ACTIVE_FROM'] = $sDate;


        // Получаем свойства списка
        $arTypeList = self::getPropertyEnumList(self::getIBlockID('umsh', 'umshCompStructure'), 'TYPE');
        if(!isset($arTypeList['members']['ID'])) return false;
        // Дополняем фильтр по типу записи
        $arFilter['PROPERTY_TYPE'] = $arTypeList['members']['ID'];

        // Фильтр по подразделениям
        $arFilter['SECTION_ID'] = array_keys(self::getBranch($iDepartmentID, 3)?:array());

        // Если секция пустая, то значит где то ошибка! FALSE
        if(empty($arFilter['SECTION_ID']))
            return false;

        // Получаем элементы для расчета кол-ва сотрудников
        $rsMembersCounts = CIBlockElement::GetList(
            array('DATE_ACTIVE_FROM' => 'DESC'),
            $arFilter,
            false,
            false,
            array(
                'ID',
                'PROPERTY_MBR_COUNT',
                'IBLOCK_SECTION_ID',
                'ACTIVE_FROM'
            )
        );

        $arWasCounted = array();
        while($arRow = $rsMembersCounts->Fetch()) {
            if($bWithData) {
                $arResult['DATA'][] = $arRow;

                // Пропускаем данную запись для расчета если она не проходит по дате
                if(MakeTimestamp($arRow['ACTIVE_FROM']) > $iDate)
                    continue;
            }

            if(!$arWasCounted[$arRow['IBLOCK_SECTION_ID']]) {
                $arResult['COUNT'] += $arRow['PROPERTY_MBR_COUNT_VALUE'];
                $arWasCounted[$arRow['IBLOCK_SECTION_ID']] = true;
            }
        }

        return $bWithData?$arResult:$arResult['COUNT'];
    }


    /**
     *  Получить полное дерево всех департаментов меппера (BOSS кадровика)
     *
     * @param bool $bUseCache
     * @return array 'LINKS' -> Плоское дерево с ссылками на основное дерево; 'TREE' -> дерево департаментов
     *
     */
    public static function getFullBossMapperTree($bUseCache = true) {

        if(!$bUseCache || empty(self::$cache['fullBossMapperTree'])) {
            $arTree = $arLinks = array();
            // Формируем запрос в БД
            $rsResult = CIBlockSection::GetList(
                array('DEPTH_LEVEL' => 'ASC', 'SORT' => 'DESC', 'NAME' => 'ASC'),
                array(
                    'IBLOCK_ID' => self::getIBlockID('umsh', 'umshBossMapper'),
                    'ACTIVE' 	=> 'Y'
                ),
                false,
                array('ID', 'CODE', 'NAME', 'LEFT_MARGIN', 'RIGHT_MARGIN', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'UF_*')
            );
            // Парсим каждый департамент
            while($arDepartment = $rsResult->GetNext()) {
                $iDepID = $arDepartment['ID'];

                // Верхний уровень
                if(empty($arDepartment['IBLOCK_SECTION_ID'])) {
                    $arTree[$iDepID] = true;
                    $arLinks[$iDepID] = &$arTree[$iDepID];

                }
                // Вложенные уровни
                elseif(isset($arLinks[$arDepartment['IBLOCK_SECTION_ID']])) {
                    $arLinks[$arDepartment['IBLOCK_SECTION_ID']]['CHILDREN'][$iDepID] = true;
                    $arLinks[$iDepID] = &$arLinks[$arDepartment['IBLOCK_SECTION_ID']]['CHILDREN'][$iDepID];
                }

                if(empty($arLinks[$iDepID]))
                    continue;

                $arLinks[$iDepID] = array(
                    'DATA'        => $arDepartment,
                    'ID'          => $iDepID,
                    'NAME'        => $arDepartment['NAME'],
                    'DEPTH_LEVEL' => $arDepartment['DEPTH_LEVEL'],
                    'PARENT'      => false,
                    'PARENTS'     => false,
                    'CHILDREN'    => array()
                );

                // Выстраиваем структуру родителей
                if($arDepartment['DEPTH_LEVEL'] > 1) {
                    // Устанавливаем ссылку на родителя
                    $arLinks[$iDepID]['PARENT'] = &$arLinks[$arDepartment['IBLOCK_SECTION_ID']];

                    // Формиреум массив со всеми родителями по ключу уровня вложености (ссылка на себя самого тоже)
                    $arLinks[$iDepID]['PARENTS'] = array($arDepartment['DEPTH_LEVEL'] => &$arLinks[$iDepID]);
                    $arParent = &$arLinks[$iDepID]['PARENT'];

                    while(!empty($arParent)) {
                        $arLinks[$iDepID]['PARENTS'][$arParent['DEPTH_LEVEL']?:1] = &$arParent;
                        $arParent = &$arParent['PARENT'];
                    }

                    unset($arParent);
                }
            }
            unset($rsResult);

            self::$cache['fullBossMapperTree'] = array(
                'LINKS' => &$arLinks,
                'TREE'  => &$arTree
            );
        }


        return self::$cache['fullBossMapperTree'];
    }


    /**
     * Переводит многоуровневое дерево разделов в плоское последовательное дерево
     *
     * @param array $arTree Многоуровневое дерево (многомерный асскоциативный массив)
     * @return array
     */
    public static function getFloatTreeByTree($arTree) {
        $arResult = array();

        if(!empty($arTree) && is_array($arTree)) {
            foreach($arTree AS $iID => $arData) {
                $arResult[$iID] = $arData;
                $arResult += self::getFloatTreeByTree($arData['CHILDREN']);
            }
        }
        return $arResult;
    }


    /**
     *  Получить полное дерево всех департаментов
     *
     * @param bool $bUseCache
     * @return array 'LINKS' -> Плоское дерево с ссылками на основное дерево; 'TREE' -> дерево департаментов
     *
     * TODO:
     * в идеале запускать данный метод при ините и потом работать с данными из кеша,
     * а то расплодилось уйму странных методов, которые дублируют друг друга!
     * (для их очистаки нужно проработать всю логику УМШ на поиск мест использования методов и их результатов)
     */
    public static function getFullBranchTree($bUseCache = true) {
        if(!$bUseCache || empty(self::$cache['fullDepartmentTree'])) {
            $arTree = $arLinks = array();

            $cache = new CPHPCache();
            $cache_time = 3600;
            $cache_id = 'getFullBranchTree';
            $cache_path = 'UMSH';
            if ($cache_time > 0 && $cache->InitCache($cache_time, $cache_id, $cache_path))
            {
                $res = $cache->GetVars();
                if (is_array($res["data"]) && (count($res["data"]) > 0))
                {
                    $cachedData = $res["data"];
                    //list($arTree,$arLinks) = $cachedData;
                    $arTree = $cachedData['TREE'];
                    $arLinks= $cachedData['LINKS'];
                }
                //debugmessage("From cache");
            }
            if (!is_array($cachedData))
            {
                //debugmessage("Not from cache");
                // Формируем запрос в БД
                $rsResult = CIBlockSection::GetList(
                    array('DEPTH_LEVEL' => 'ASC', 'SORT' => 'DESC', 'NAME' => 'ASC'),
                    array(
                        'IBLOCK_ID' => self::getIBlockID('umsh', 'umshCompStructure'),
                        'ACTIVE' 	=> 'Y'
                    ),
                    false,
                    array('ID', 'CODE', 'NAME', 'LEFT_MARGIN', 'RIGHT_MARGIN', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'UF_*')
                );
                // Парсим каждый департамент
                while($arDepartment = $rsResult->GetNext()) {
                    $iDepID = $arDepartment['ID'];

                    // Верхний уровень
                    if(empty($arDepartment['IBLOCK_SECTION_ID'])) {
                        $arTree[$iDepID] = true;
                        $arLinks[$iDepID] = &$arTree[$iDepID];

                    }
                    // Вложенные уровни
                    elseif(isset($arLinks[$arDepartment['IBLOCK_SECTION_ID']])) {
                        $arLinks[$arDepartment['IBLOCK_SECTION_ID']]['CHILDREN'][$iDepID] = true;
                        $arLinks[$iDepID] = &$arLinks[$arDepartment['IBLOCK_SECTION_ID']]['CHILDREN'][$iDepID];
                    }

                    if(empty($arLinks[$iDepID]))
                        continue;

                    $arLinks[$iDepID] = array(
                        'DATA'        => $arDepartment,
                        'ID'          => $iDepID,
                        'NAME'        => $arDepartment['NAME'],
                        'NAME_FULL'   => $arDepartment['UF_NAME_FULL']?:$arDepartment['NAME'],
                        'NAME_SHORT'  => $arDepartment['UF_NAME_SHORT']?:$arDepartment['NAME'],
                        'DEPTH_LEVEL' => $arDepartment['DEPTH_LEVEL'],
                        'PARENT'      => false,
                        'PARENTS'     => false,
                        'CHILDREN'    => array()
                    );

                    // Выстраиваем структуру родителей
                    if($arDepartment['DEPTH_LEVEL'] > 1) {
                        // Устанавливаем ссылку на родителя
                        $arLinks[$iDepID]['PARENT'] = &$arLinks[$arDepartment['IBLOCK_SECTION_ID']];

                        // Формиреум массив со всеми родителями по ключу уровня вложености (ссылка на себя самого тоже)
                        $arLinks[$iDepID]['PARENTS'] = array($arDepartment['DEPTH_LEVEL'] => &$arLinks[$iDepID]);
                        $arParent = &$arLinks[$iDepID]['PARENT'];

                        while(!empty($arParent)) {
                            $arLinks[$iDepID]['PARENTS'][$arParent['DEPTH_LEVEL']?:1] = &$arParent;
                            $arParent = &$arParent['PARENT'];
                        }

                        unset($arParent);
                    }
                }
                unset($rsResult);


                //////////// end cache /////////
                if ($cache_time > 0)
                {
                    $cache->StartDataCache($cache_time, $cache_id, $cache_path);
                    $cache->EndDataCache(array("data"=>[
                        'LINKS' => &$arLinks,
                        'TREE'  => &$arTree
                    ]));
                }
            }
            /**/



            self::$cache['fullDepartmentTree'] = array(
                'LINKS' => &$arLinks,
                'TREE'  => &$arTree
            );
        }



        return self::$cache['fullDepartmentTree'];
    }

    /**
     * Метод возвращает данные об подразделении из кеша дерева
     * (переделать все методы на этот, пока он почти не используется)
     *
     * @param int|string $iDepartmentID ID или 'all'
     * @param int $iDepthLevel (можно дополнить в будущем '<' и '>' для поиска не только конкретного уровня)
     * @param bool $bGetParents
     * @param bool $bGetChildren
     *
     * @return array
     */
    public static function getBranch($iDepartmentID, $iDepthLevel = 0, $bGetParents = false, $bGetChildren = true) {
        if(empty(self::$cache['fullDepartmentTree']))
            self::getFullBranchTree();

        $arResult = array();

        // Запрашивается вся структура
        if($iDepartmentID == 'all' && $bGetChildren) {
            foreach(self::$cache['fullDepartmentTree']['TREE'] AS $arRow)
                $arResult += self::getBranch($arRow['ID'], $iDepthLevel, false, true);

        }
        // Проверяем конкретный департамент
        if(!empty(self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID])) {
            // Родители
            if(
                $bGetParents
                && !empty(self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['PARENTS'])
                && (!$iDepthLevel || $iDepthLevel < self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['DEPTH_LEVEL'])
            ) {
                foreach(self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['PARENTS'] AS $arRow)
                    if($arRow['ID'] != $iDepartmentID && (!$iDepthLevel || $iDepthLevel == $arRow['DEPTH_LEVEL']))
                        $arResult = array($arRow['ID'] => $arRow) + $arResult;
            }
            // Себя
            if(!$iDepthLevel || $iDepthLevel == self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['DEPTH_LEVEL'])
                $arResult[$iDepartmentID] = self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID];
            // Детей
            if(
                $bGetChildren
                && !empty(self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['CHILDREN'])
                && (!$iDepthLevel || $iDepthLevel > self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['DEPTH_LEVEL'])
            ) {
                foreach(self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID]['CHILDREN'] AS $arRow)
                    $arResult += self::getBranch($arRow['ID'], $iDepthLevel, false, true);
            }
        }

        return $arResult;
    }


    /**
     * Получить департамент пользователя, включая все подчненные департаменты
     *
     * @param string $depthLVL
     * @param bool $doNotCheckDep
     * @param bool $bFullTree Включая родителей
     *
     * @return array плоское дерево департаментов
     */
    public static function getMyBranch($depthLVL = '', $doNotCheckDep = false, $bFullTree = false) {
        $sFirstLVLSection = $doNotCheckDep?'unchecked':'check';
        $sSecondLVLSection = $bFullTree?'withParent':'children';

        if(!empty(self::$cache['myBranch'][$sFirstLVLSection][$sSecondLVLSection]))
            return self::$cache['myBranch'][$sFirstLVLSection][$sSecondLVLSection];

        if($doNotCheckDep || self::getUserAttr('UF_IDEA_DEPARTMENT')) {
            self::$cache['myBranch'][$sFirstLVLSection][$sSecondLVLSection] = self::getBranch(self::getUserAttr('UF_IDEA_DEPARTMENT'), $depthLVL?:0, $bFullTree, true);
            self::$cache['myBranch']['fullList'] += self::$cache['myBranch'][$sFirstLVLSection][$sSecondLVLSection];
            return  self::$cache['myBranch'][$sFirstLVLSection][$sSecondLVLSection];
        }
        else
            return false;
    }


    /**
     * @static
     * @return array
     */
    public static function getMyBranchList() {
        $arResult = array();
        foreach(self::$cache['myBranch']['defaultList'] AS $arDep)
            $arResult[$arDep['ID']] = $arDep['NAME'];

        return $arResult;
    }

    /**
     * Получает массив с именами подразделений
     *
     * @param int $iDepartmentID
     * @return array|false
     */
    public static function getBranchName($iDepartmentID) {
        $arResult = array();
        $arCurrentDep = self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID];

        // Проверяем найден ли департамент
        if(!$arCurrentDep)
            return false;

        do {
            $arResult[$arCurrentDep['DEPTH_LEVEL']] = $arCurrentDep['NAME'];
            $arCurrentDep = $arCurrentDep['PARENT'];
        } while($arCurrentDep);

        // Сортируем по уровню
        ksort($arResult);

        return $arResult;
    }

    /**
     * Получает массив с ответственными пользователями в подразделении
     * -- Отвественные --
     * Пользователи с привязкой к конкретному подразделению или выше и состоящие в одной из групп:
     * - Модератор (сотрудник ОНУ)
     * - Наблюдатель
     * - Администратор УМШ
     *
     * @param int $iDepartmentID
     * @return array|false
     */
    public static function getBranchResponsibleUsers($iDepartmentID) {
        $arResult = array();
        /*
         * Получаем список департаментов для поиска
         */
        $arDepID = array();
        $arCurrentDep = self::$cache['fullDepartmentTree']['LINKS'][$iDepartmentID];
        // Проверяем найден ли департамент
        if(!$arCurrentDep)
            return false;
        $res = self::getBranchResponsibleUsersFromSettings($iDepartmentID);
        if($res!==false && !empty($res)) {
            return $res;
        }

        // Собираем ID департаментов для поиска пользователей
        do {
            $arDepID[] = $arCurrentDep['ID'];
            $arCurrentDep = $arCurrentDep['PARENT'];
        } while($arCurrentDep);


        /*
         * Получаем список пользователей
         */
        $obUser = new CUser;
        $rsResult = $obUser->GetList($by='ID',$order="asc",
            array(
                'UF_IDEA_DEPARTMENT' => $arDepID,
                'GROUPS_ID'          => array(
                    self::$cache['moduleGroups']['ROLE']['MODERATOR']['ID'],
                    self::$cache['moduleGroups']['ROLE']['SPECTATOR']['ID'],
                    self::$cache['moduleGroups']['ROLE']['ADMIN']['ID'],
                ),
            ),
            array('FIELDS' => array(
                'ID',
                'NAME',
                'LAST_NAME',
                'SECOND_NAME',
                'LOGIN',
                'ACTIVE',
                'EMAIL',
            ))
        );
        while($arRow = $rsResult->GetNext(true, false))
            $arResult[$arRow['ID']] = $arRow;

        return $arResult;
    }

    public static function getBranchResponsibleUsersFromSettings($departmentId) {
        $arrBranch = self::getArrayIdBranch($departmentId);
        $arFilter = array('IBLOCK_ID' => 3104, 'ID' => $arrBranch);
        $rsSections = CIBlockSection::GetList(array('DEPTH_LEVEL'=> 'DESC', 'LEFT_MARGIN' => 'ASC'), $arFilter, false, ['UF_RESPONSIBLE']);
        $arResult = [];
        while ($arSection = $rsSections->Fetch())
        {
            if(!is_null($arSection['UF_RESPONSIBLE'])) {
                foreach ($arSection['UF_RESPONSIBLE'] as $responsible)
                {
                    $user = CUser::GetByID($responsible)->Fetch();
                    if($user) {
                        $arResult[$user['ID']] = $user;
                    }
                }
                if(!empty($arResult))
                    return $arResult;
            }
        }
        return false;
    }

    /**
     * Получает массив с email'ами ответственных пользователей в подразделении
     * -- Отвественные --
     * Пользователи с привязкой к конкретному подразделению или выше и состоящие в одной из групп:
     * - Модератор (сотрудник ОНУ)
     * - Наблюдатель
     * - Администратор УМШ
     *
     * @param int $iDepartmentID
     * @return array|false
     */
    public static function getBranchResponsibleUserEmails($iDepartmentID) {
        $arResult = array();
        $arUsers  = self::getBranchResponsibleUsers($iDepartmentID);

        // Проверяем найденый список пользователей
        if($arUsers === false)
            return false;

        // Формируем результирующий массив с Email'ами
        foreach($arUsers AS $arUser)
            if($arUser['EMAIL'])
                $arResult[$arUser['ID']] = $arUser['EMAIL'];


        return $arResult;
    }

    /**
     * Получает настройки департамента
     *
     * @param int   $iDepartmentID
     * @param array $arSection
     * @return array
     */
    public static function getBranchSettings($iDepartmentID, $arSection = array('all')) {
        $iTimeOffset = timezone_offset_get(new DateTimeZone(date_default_timezone_get()), new DateTime);
        $arBranches = self::getBranch($iDepartmentID);

        if(empty($arBranches[$iDepartmentID]))
            return array();

        if(!is_array($arSection))
            $arSection = array($arSection);

        $arResult = array();
        $bGetAllData = in_array('all', $arSection);
        $arBranch = $arBranches[$iDepartmentID];

        // Секция подразделения
        if($bGetAllData || in_array('branch', $arSection)) {
            $arIdeas  = self::getIdeasIdPerBranch($iDepartmentID);

            $arResult['BRANCH'] = array(
                'DATA'      => $arBranch,
                'CHILDREN'  => count($arBranches) -1,
                'IDEAS'     => $arIdeas['RETURNED'],
            );
        }

        // Секция сотрудников
        if($bGetAllData || in_array('employers', $arSection)) {
            $iEmployersBranch = count(self::getBranch($iDepartmentID, 3))?:0;
            $iMonthCount      = 12;
            $iCurrentMonth    = date('n');
            $arGraphData      = array();
            $iParseStartDate  = mktime(0,0,0, $iCurrentMonth+1 - $iMonthCount, 1);

            // Расчитываем показатели для третьего уровня
            if($arBranch['DEPTH_LEVEL'] == '3') {
                $arTmpData = array();
                // Получаем данные по сотрудникам в подразделении
                $arEmployersData   = self::getEmployersCount($iDepartmentID, date('d.m.Y'), true);
                // Получаем первый год и месяц в графике
                list($iFY, $iFM) = explode('-', date('Y-n', $iParseStartDate));

                // Формируем временной блок
                $iParseDate = $iParseStartDate;
                do {
                    list($y, $m) = explode('-', date('Y-n', $iParseDate));
                    $arTmpData[$m.'.'.$y] = array();
                    // Следующий месяц
                    $iParseDate = mktime(0,0,0, $m+1, 1, $y);
                    $iMonthCount--;
                } while($iMonthCount);

                // Проходимся по найденым записям и раскидываем их по временному блоку
                foreach($arEmployersData['DATA'] AS $arRowData) {
                    $iActiveFrom = MakeTimeStamp(array_shift(explode(' ', $arRowData['ACTIVE_FROM'])). ' 00:00:00');
                    list($y, $m, $d) = explode('-', date('Y-n-d', $iActiveFrom));

                    // Записываем данные по сотрудникам в месяц их присвоения
                    if(is_array($arTmpData[$m.'.'.$y]) && empty($arTmpData[$m.'.'.$y][$d]))
                        $arTmpData[$m.'.'.$y][$d] = $arRowData['PROPERTY_MBR_COUNT_VALUE'];

                    // Заполняем первый месяц, если он еще не заполнен
                    // (заполнится 1 раз, т.к. сортировка идет от самых свежих к более старым)
                    elseif($iActiveFrom && $iActiveFrom < $iParseStartDate && empty($arTmpData[$iFM.'.'.$iFY])) {
                        $arTmpData[$iFM.'.'.$iFY][date('t', mktime(0,0,0,$iFM,1,$iFY))] = $arRowData['PROPERTY_MBR_COUNT_VALUE'];
                    }

                }

                // Проходимя еще раз временному блоку и формируем данные для графика
                $iLastDayData = 0;
                foreach($arTmpData AS $sDate => $arDays) {
                    list($m, $y) = explode('.', $sDate);
                    ksort($arDays);
                    if(empty($arDays)) {
                        $arDays = array(date('t', mktime(0,0,0,$m,1,$y)) => $iLastDayData);
                    }


                    foreach($arDays AS $iDay => $iEmployers) {
                        $iLastDayData = intval($iEmployers);
                        $arGraphData[] = array(intval((mktime(0,0,0,$m,$iDay,$y) +$iTimeOffset) .'000'), $iLastDayData);
                    }
                }

                // Выставляем результат
                $iCurrentEmployers = $arEmployersData['COUNT'];

            }

            // Расчитываем показатели для подразделений 1 и 2 уровня
            else {
                $arGraphData   = array();
                $iParseDate    = $iParseStartDate;
                do {
                    list($y, $m) = explode('-', date('Y-n', $iParseDate));
                    $iSearchDate = mktime(0,0,0-1, $m+1, 1, $y);
                    $arGraphData[] = array(intval(($iSearchDate +$iTimeOffset) .'000'), self::getEmployersCount($iDepartmentID, date('d.m.Y', $iSearchDate)));

                    // Следующий месяц
                    $iParseDate = $iSearchDate+1;
                    $iMonthCount--;
                } while($iMonthCount);

                // Выставляем результат
                $iCurrentEmployers = end(end($arGraphData));
                // Сбрасываем курсор
                reset(end($arGraphData));
                reset($arGraphData);
            }


            $arResult['EMPLOYERS'] = array(
                'BRANCHES'  => $iEmployersBranch,
                'CURRENT'   => $iCurrentEmployers,
                'GRAPH'     => $arGraphData,
                'LIST'      => !empty($arEmployersData['DATA'])?$arEmployersData['DATA']:array(),
            );

        }

        // Секция коэффициентов
        if($bGetAllData || in_array('coefficients', $arSection)) {
            $arResult['COEFFICIENTS'] = array(
                'LIST'    => self::getIdeaCoefficientList($iDepartmentID),
                'CURRENT' => self::getIdeaCoefficient($iDepartmentID, date('d.m.Y'), true),
            );
        }

        // Секция графиков
        if($bGetAllData || in_array('graphs', $arSection)) {
            $arResult['GRAPHS'] = array('LIST' => array());

            foreach(CGraphs::$graphs AS $sCode => $arData) {
                $arResult['GRAPHS']['LIST'][$sCode] = self::getGraphCoefficientList($iDepartmentID, $sCode);
            }
        }

        // Прочее
        if ($bGetAllData || in_array('others', $arSection)) {
            // Определяем обязательность поля "Внутренний номер"
            $arDep = $arBranches[$iDepartmentID];
            $UF_DATE_PERIODIC_NOT = null;
            $UF_DATE_PERIODIC_NOT_Parent = null;
            $UF_DURATION_IN_NEW = null;
            $UF_DURATION_IN_NEW_Parent = null;
            $UF_DURATION_IN_WORK = null;
            $UF_DURATION_IN_WORK_Parent = null;
            $UF_DURATION_IN_REWORK = null;
            $UF_DURATION_IN_REWORK_Parent = null;
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_DATE_PERIODIC_NOT'] && $UF_DATE_PERIODIC_NOT === null)
                    $UF_DATE_PERIODIC_NOT = $arDep['DATA']['UF_DATE_PERIODIC_NOT'];

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_DATE_PERIODIC_NOT'] && $UF_DATE_PERIODIC_NOT_Parent === null)
                    $UF_DATE_PERIODIC_NOT_Parent = $arDep['DATA']['UF_DATE_PERIODIC_NOT'];

            } while ($arDep && ($UF_DATE_PERIODIC_NOT === null || $UF_DATE_PERIODIC_NOT_Parent === null));

            $arDep = $arBranches[$iDepartmentID];
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_DURATION_IN_NEW'] && $UF_DURATION_IN_NEW === null)
                    $UF_DURATION_IN_NEW = $arDep['DATA']['UF_DURATION_IN_NEW'];

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_DURATION_IN_NEW'] && $UF_DURATION_IN_NEW_Parent === null)
                    $UF_DURATION_IN_NEW_Parent = $arDep['DATA']['UF_DURATION_IN_NEW'];

            } while ($arDep && ($UF_DURATION_IN_NEW === null || $UF_DURATION_IN_NEW_Parent === null));

            $arDep = $arBranches[$iDepartmentID];
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_DURATION_IN_WORK'] && $UF_DURATION_IN_WORK === null)
                    $UF_DURATION_IN_WORK = $arDep['DATA']['UF_DURATION_IN_WORK'];

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_DURATION_IN_WORK'] && $UF_DURATION_IN_WORK_Parent === null)
                    $UF_DURATION_IN_WORK_Parent = $arDep['DATA']['UF_DURATION_IN_WORK'];

            } while ($arDep && ($UF_DURATION_IN_WORK === null || $UF_DURATION_IN_WORK_Parent === null));

            $arDep = $arBranches[$iDepartmentID];
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_DURATION_IN_REW'] && $UF_DURATION_IN_REWORK === null)
                    $UF_DURATION_IN_REWORK = $arDep['DATA']['UF_DURATION_IN_REW'];

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_DURATION_IN_REW'] && $UF_DURATION_IN_REWORK_Parent === null)
                    $UF_DURATION_IN_REWORK_Parent = $arDep['DATA']['UF_DURATION_IN_REW'];

            } while ($arDep && ($UF_DURATION_IN_REWORK === null || $UF_DURATION_IN_REWORK_Parent === null));


            // Определяем обязательность поля "Внутренний номер"
            $arDep = $arBranches[$iDepartmentID];
            $bRequiredInnerNumber = null;
            $bRequiredInnerNumberParent = null;
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_REQ_FL_INNER_NUM'] && $bRequiredInnerNumber === null)
                    $bRequiredInnerNumber = ($arDep['DATA']['UF_REQ_FL_INNER_NUM'] == 'Y');

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_REQ_FL_INNER_NUM'] && $bRequiredInnerNumberParent === null)
                    $bRequiredInnerNumberParent = ($arDep['DATA']['UF_REQ_FL_INNER_NUM'] == 'Y');

            } while ($arDep && ($bRequiredInnerNumber === null || $bRequiredInnerNumberParent === null));

            // Выводим ли BOSS-ID в поле Автор
            $arDep = $arBranches[$iDepartmentID];
            $bHideBossIdNumber = null;
            $bHideBossIdNumberParent = null;
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_REQ_BOSS_ID'] && $bHideBossIdNumber === null)
                    $bHideBossIdNumber = ($arDep['DATA']['UF_REQ_BOSS_ID'] == 'Y');

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_REQ_BOSS_ID'] && $bHideBossIdNumberParent === null)
                    $bHideBossIdNumberParent = ($arDep['DATA']['UF_REQ_BOSS_ID'] == 'Y');

            } while ($arDep && ($bHideBossIdNumber === null || $bHideBossIdNumberParent === null));

            // Определяем обязательность заполнения поля График работы
            $arDep = $arBranches[$iDepartmentID];
            $bHideSchedule = null;
            $bHideScheduleParent = null;
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_HIDE_SCHEDULE'] && $bHideSchedule === null)
                    $bHideSchedule = ($arDep['DATA']['UF_HIDE_SCHEDULE'] == 'Y');

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_HIDE_SCHEDULE'] && $bHideScheduleParent === null)
                    $bHideScheduleParent = ($arDep['DATA']['UF_HIDE_SCHEDULE'] == 'Y');

            } while ($arDep && ($bHideSchedule === null || $bHideScheduleParent === null));

            // Определяем обязательность вывода поля График работы
            $arDep = $arBranches[$iDepartmentID];
            $bHideScheduleInp = null;
            $bHideScheduleParentInp = null;
            do {
                // Значение для текущего подразделения
                if ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] && $bHideScheduleInp === null)
                    $bHideScheduleInp = ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] == 'Y');

                // Прокидываем родителя на текущий уровень для прохода в while
                $arDep = $arDep['PARENT'];

                // Значение родителя
                if ($arDep && $arDep['DATA']['UF_HIDE_SCHEDULE_INP'] && $bHideScheduleParentInp === null)
                    $bHideScheduleParentInp = ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] == 'Y');

            } while ($arDep && ($bHideScheduleInp === null || $bHideScheduleParentInp === null));

            // Привязка к BOSS родителей
            $arDep = $arBranches[$iDepartmentID]['PARENT'];
            $iBossHeadIdParent = null;
            // Значение родителя
            while ($arDep) {
                if ($arDep['DATA']['UF_BOSS_HEAD_MAP_ID']) {
                    $iBossHeadIdParent = $arDep['DATA']['UF_BOSS_HEAD_MAP_ID'];
                    break;
                }

                // Прокидываем родителя на уровень выше для прохода в while
                $arDep = $arDep['PARENT'];
            }


            // Дополняем результурующий массив
            $arResult['OTHERS'] = array(
                'INNER_NUMBER_FIELD' => $arBranch['DATA']['UF_REQ_FL_INNER_NUM'],
                'MANAGER' => $arBranch['DATA']['UF_BOSS'],
                'RESPONSIBLE' => $arBranch['DATA']['UF_RESPONSIBLE'],
                'INNER_NUMBER_REQUIRED' => $bRequiredInnerNumber,
                'INNER_NUMBER_REQUIRED_PARENT' => $bRequiredInnerNumberParent !== null ? $bRequiredInnerNumberParent : $bRequiredInnerNumber,
                'UF_DATE_PERIODIC_NOT' => $UF_DATE_PERIODIC_NOT,
                'UF_DATE_PERIODIC_NOT_PARENT' => $UF_DATE_PERIODIC_NOT_Parent !== null ? $UF_DATE_PERIODIC_NOT_Parent : $UF_DATE_PERIODIC_NOT,
                'UF_DURATION_IN_NEW' => $UF_DURATION_IN_NEW,
                'UF_DURATION_IN_NEW_PARENT' => $UF_DURATION_IN_NEW_Parent !== null ? $UF_DURATION_IN_NEW_Parent : $UF_DURATION_IN_NEW,
                'UF_DURATION_IN_WORK' => $UF_DURATION_IN_WORK,
                'UF_DURATION_IN_WORK_PARENT' => $UF_DURATION_IN_WORK_Parent !== null ? $UF_DURATION_IN_WORK_Parent : $UF_DURATION_IN_WORK,
                'UF_DURATION_IN_REWORK' => $UF_DURATION_IN_REWORK,
                'UF_DURATION_IN_REWORK_PARENT' => $UF_DURATION_IN_REWORK_Parent !== null ? $UF_DURATION_IN_REWORK_Parent : $UF_DURATION_IN_REWORK,

                'HIDE_BOSS_ID_VALUE' => $arBranch['DATA']['UF_REQ_BOSS_ID'],
                'HIDE_BOSS_ID' => $bHideBossIdNumber,
                'HIDE_BOSS_ID_PARENT' => $bHideBossIdNumberParent !== null ? $bHideBossIdNumberParent : $bHideBossIdNumber,

                'HIDE_SCHEDULE_VALUE' => $arBranch['DATA']['UF_HIDE_SCHEDULE'],
                'HIDE_SCHEDULE' => $bHideSchedule,
                'HIDE_SCHEDULE_PARENT' => $bHideScheduleParent !== null ? $bHideScheduleParent : $bHideSchedule,

                'HIDE_SCHEDULE_INPUT_VALUE' => $arBranch['DATA']['UF_HIDE_SCHEDULE_INP'],
                'HIDE_SCHEDULE_INPUT' => $bHideScheduleInp,
                'HIDE_SCHEDULE_INPUT_PARENT' => $bHideScheduleParentInp !== null ? $bHideScheduleParentInp : $bHideScheduleInp,

                'BOSS_HEAD_MAP_ID' => $arBranch['DATA']['UF_BOSS_HEAD_MAP_ID'],
                'BOSS_HEAD_MAP_ID_PARENT' => $iBossHeadIdParent,
                'BOSS_HEAD_MAP_ID_LIST' => self::getFullBossMapperTree(),
            );
        }

        // Кнопки для маршрутизации идеи
        $arResult['BUTTONS'] =  self::getTransferButtons($arResult['BRANCH']['DATA']['ID'], false);//CIdeaTools::getIbElements(CIdeaTools::getIBlockID("umsh","buttonSettings"), $filter = ['PROPERTY_USER_DEPARTMENT' => $arResult['BRANCH']['DATA']['ID'], 'ACTIVE'=>""], $select = [6=>'ACTIVE']);


        return $arResult;
    }

    /**
     * Сохраняет лог изменения настроек если были внесены изменения
     *
     * @param $sType
     * @param $sAction
     * @param $iDepartment
     * @param $mOldValue
     * @param $mNewValue
     * @param string $sMoreInfo
     *
     * @return bool
     * @throws Exception
     */
    public static function saveToSettingsChangeLog($sType, $sAction, $iDepartment, $mOldValue, $mNewValue, $sMoreInfo = '') {
        $iIBlockID = self::getIBlockID('umsh', 'umshSettingsChangesLog');
        /*
         * Получаем Enum
         */
        $arEnumTypeList = self::getPropertyEnumList($iIBlockID, 'TYPE');
        $arEnumActionList = self::getPropertyEnumList($iIBlockID, 'ACTION');

        /*
         * Проверяем тип и действие
         */
        if(empty($arEnumTypeList[$sType]['ID']))
            throw new Exception('Передан неверный тип записи изменения.');
        if(empty($arEnumActionList[$sAction]['ID']))
            throw new Exception('Передан неверный тип действия изменения.');

        /*
         * Проверяем необходимость записи при измененеии
         */
        if($sAction == 'edit') {
            if(is_array($mOldValue)) {
                if(!is_array($mNewValue))
                    throw new Exception('Отличается тип значения');

                if(count($mOldValue) == count($mNewValue) && !count(array_diff_assoc($mOldValue, $mNewValue)))
                    return true;

                /*
                 * Очищаем, оставляя только различия
                 */
                $arTmp = $mOldValue;
                $mOldValue = array_diff_assoc($mOldValue, $mNewValue);
                $mNewValue = array_diff_assoc($mNewValue, $arTmp);
                unset($arTmp);

            } elseif($mOldValue == $mNewValue)
                return true;
        }

        /*
         * Выстравиваем пути к подразделению
         */
        // Небольшая хитрость для нового подразделения... т.к. его еще нет getBranch
        $iDepToFind = $sType == 'structure' && $sAction == 'add'?$sMoreInfo:$iDepartment;
        $arBranchPath = array(
            1 => array(),
            2 => array(),
            3 => array(),
        );
        $arBranches = self::getBranch($iDepToFind, 0, true, false);
        if(empty($arBranches[$iDepToFind]))
            throw new Exception('Подразделение не найдено для записи в лог');
        foreach($arBranches AS $arDepData) {
            $arBranchPath[$arDepData['DEPTH_LEVEL']] = array(
                'ID'   => $arDepData['ID'],
                'NAME' => $arDepData['NAME'],
            );
        }

        // Дополняем если это новое подразделение
        if($sType == 'structure' && $sAction == 'add') {
            // Очищаем инфо от ID родителя
            $sMoreInfo = '';

            // Дополняем
            $arBranchPath[$arBranches[$iDepToFind]['DEPTH_LEVEL'] +1] = array(
                'ID'   => $iDepartment,
                'NAME' => $mNewValue,
            );
        }
        unset($arBranches, $iDepToFind);

        /*
         * Пользователь
         */
        $arUserData = $GLOBALS['USER']->GetList(
            $foo='',
            $bar='',
            array('ID' => $GLOBALS['USER']->GetId()),
            array('FIELDS' => array(
                'ID',
                'LOGIN',
                'EMAIL',
                'LAST_NAME',
                'NAME',
                'SECOND_NAME',
            ))
        )->Fetch()?:array();

        /*
         * Вспомогательная функция для замены ключей для вывода
         */
        $fnArrayChangeToText = function($arValues, $arKeysReplace) {
            $arTemp = array();
            foreach($arValues AS $k => $v)
                $arTemp[] = ($arKeysReplace[$k]?:$k).': '.$v;

            return implode("\n", $arTemp);
        };

        /*
         * Формируем "было" и "стало" в зависимости от типа
         */
        switch($sType) {
            case 'structure':
                $arKeyValues = array(
                    'NAME'  => 'Название',
                    'NAME_FULL'  => 'Полное название',
                    'NAME_SHORT'  => 'Аббревиатура',
                );

                $sOldValue = $fnArrayChangeToText($mOldValue, $arKeyValues);
                $sNewValue = $fnArrayChangeToText($mNewValue, $arKeyValues);
                break;

            case 'members':
                $arKeyValues = array(
                    'DATE'  => 'Дата',
                    'COUNT' => 'Численность',
                );

                $sOldValue = $fnArrayChangeToText($mOldValue, $arKeyValues);
                $sNewValue = $fnArrayChangeToText($mNewValue, $arKeyValues);
                break;

            case 'coefficients':
                $arKeyValues = array(
                    'DATE_FROM'     => 'Начало действия',
                    'DATE_TO'       => 'Окончание действия',
                    'COST'          => 'Цена балла',
                    'NO_ALLOWANCES' => 'Игнорировать участие автора',
                );
                foreach(CRationList::getList() AS $arData)
                    $arKeyValues[$arData['code']] = $arData['title'];

                $sOldValue = $fnArrayChangeToText($mOldValue, $arKeyValues);
                $sNewValue = $fnArrayChangeToText($mNewValue, $arKeyValues);
                break;

            case 'graph':
                $arKeyValues = array(
                    'DATE_FROM' => 'Начало действия',
                    'DATE_TO'   => 'Окончание действия',
                    'TARGET'    => 'Цель',
                    'THRESHOLD' => 'Порог',
                );

                $sOldValue = $fnArrayChangeToText($mOldValue, $arKeyValues);
                $sNewValue = $fnArrayChangeToText($mNewValue, $arKeyValues);
                break;

            case 'others':
                $arKeyValues = array(
                    'INNER_NUMBER_FIELD' => 'Поле «Внутренний номер идеи» обязательно к заполнению',
                    'BOSS_HEAD_MAP_ID'   => 'Головная организация в BOSS кадровике',
                );

                $sOldValue = $fnArrayChangeToText($mOldValue, $arKeyValues);
                $sNewValue = $fnArrayChangeToText($mNewValue, $arKeyValues);

                break;

            default:
                $sOldValue = is_array($mOldValue)? $fnArrayChangeToText($mOldValue, array()) : $mOldValue;
                $sNewValue = is_array($mNewValue)? $fnArrayChangeToText($mNewValue, array()) : $mNewValue;;
        }


        $arFields = array(
            'IBLOCK_ID' => $iIBlockID,
            'ACTIVE'    => 'Y',
            'NAME'      => "{$arEnumTypeList[$sType]['VALUE']} [{$arEnumActionList[$sAction]['VALUE']}][{$iDepartment}]",
            'PREVIEW_TEXT'  => $sOldValue,
            'DETAIL_TEXT'   => $sNewValue,
            'PROPERTY_VALUES' => array(
                'USER_DATA' => serialize($arUserData),
                'TYPE' => $arEnumTypeList[$sType]['ID'],
                'ACTION' => $arEnumActionList[$sAction]['ID'],
                'DEPARTMENT_ROOT_ID' => $arBranchPath[1]['ID'],
                'DEPARTMENT_ID' => $iDepartment,
                'DEPARTMENT_PATH' => serialize($arBranchPath),
                'MORE_INFO' => $sMoreInfo,
            ),
        );

        $obIBlockElement = new CIBlockElement;
        return $obIBlockElement->Add($arFields);
    }


    /**
     * @static
     * @param string $url
     * @return string
     */
    public static function clearGetCurPageParam($url) {
        $url = explode('?', $url);
        return $url[0].'?'.implode('&', array_map(array(self, '__clearGetCurPageParam'), explode('&', $url[1])));
    }
    private static function __clearGetCurPageParam($string) {
        list($param, $value) = explode('=', $string);
        while(strpos($param, '%'))
            $param = rawurldecode($param);

        return $param.'='.$value;
    }


    /**
     * Метод формирует массив с идеями по заданным фильтрам и настройкам
     * ПС: используется просто для того, что бы не дублировать код формирования списка идей в компонентах
     * ПС2: написан в первую очередь для idea.list.table
     * @param array|bool $arSortResult Массив сортировки
     * @param array|bool $arFilterResult Массив фильтрации
     * @param array|bool $arPagination &Массив с кастумной пагинацией (PAGE, SIZE) - будет добавлен (TOTAL и PAGES)
     * @param array $arParams Массив с параметрами (PATH_TO_SMILE, NAV_TEMPLATE, DATE_TIME_FORMAT, PATH_TO_BLOG_CATEGORY, PATH_TO_POST, PATH_TO_POST_EDIT, ALLOW_POST_CODE, POST_PROPERTY_LIST, IMAGE_MAX_WIDTH, IMAGE_MAX_HEIGHT)
     * @param array $arReleasedWays Массив путей реализации и их сроков
     * @param array $arStatusList Массив статусов с их кодами и ID
     * @param array $arRequiredData Ассоциативный массив, ключ массива - имя раздела, а значение его необходимость
     * @param bool $bParseByFile Сохраняет идеи в файл в сериализованном виде, используется для выгрузки очень больших списков (более 10к)
     *
     * @return array
     * @throws Exception
     */
    public static function getIdeasListWithData($arSortResult, $arFilterResult, &$arPagination, $arParams, $arReleasedWays = null, $arStatusList = null, $arRequiredData = array(), $bParseByFile = false) {
        //$obCBlog            = new CBlog();
        $obCBlogPost        = new CBlogPost();
        $obCBlogUser        = new CBlogUser();
        $obCBlogCategory    = new CBlogCategory();

        $USER               = $GLOBALS['USER'];
        $arResult           = array('LIST' => array(), 'NAV' => '', 'RETURNED' => 0, 'FILE' => null);
        $arDefaultRequiredData = array(
            'PAGINATION'    => true,
            'IDEA_RATING'   => true,
            'SYSTEM_USER'   => true,
            'TAGS'          => true,
            'DATE'          => true,
            'ACTION'        => true,
            'URL'           => true,
            'PROPERTIES'    => true,
            'DEPARTMENT'    => true,
            'EMPLOYEES'     => true,
            'COMMENTS'      => false,
            'REWORK_DURATION' => true,
        );
        $arRequiredData = array_merge($arDefaultRequiredData, $arRequiredData);

        /*
         * Восстанавлеваем переменные, если не заданы
         */
        if(!$arReleasedWays)
            $arReleasedWays = self::getRealiseWays();
        if(!$arStatusList)
            $arStatusList   = CIdeaManagment::getInstance()->Idea()->GetStatusList();



        /*
         * Выводим для дебага фильтры
         */
        if(array_key_exists('D_SHOW_FILTER', $_REQUEST)) {
            echo '<br><br><h3>Используемые фильтры:</h3><br>';

            foreach($arFilterResult AS $sRule => $mValue) {
                echo '<b>'.htmlspecialchars($sRule).':</b><p>';
                if(is_array($mValue))
                    echo 'Массив: '. implode('<b>,</b> ', $mValue);
                else
                    echo htmlspecialchars($mValue);
                echo '</p>';
            }

            echo '-----------------------------------------<br><br>';

        }


        /*
         * Если нужно выгружать идеи в файл, то создадим временный файл
         */
        if($bParseByFile) {
            if(!$arResult['FILE'] = tmpfile())
                throw new Exception('Не удалось создать файл для выгрузки идей.');
        }


        /**
         * Ищем идеи по фильтру
         */
        /** @noinspection PhpParamsInspection */
        // Выборка
        $arSelect = array(
            'BLOG_ID',
            'ID',
            'CODE',
            'TITLE',
            'PUBLISH_STATUS',
            'DATE_CREATE',
            'DATE_PUBLISH',
            'CATEGORY_ID',
            'VIEWS',
            'NUM_COMMENTS',
            'DETAIL_TEXT',
            'AUTHOR_ID',
            'AUTHOR_LOGIN',
            'AUTHOR_NAME',
            'AUTHOR_LAST_NAME',
            'AUTHOR_EMAIL',
            'UF_*',
        );
        // Пагинация
        $mPagination = $arPagination
            ?array(
                //'bDescPageNumbering' => true,  - нужно отключить иначе переписывать кастумную пагинацию
                'iNumPage' => $arPagination['PAGE'],
                'nPageSize' => $arPagination['SIZE'],
                'bShowAll' => false
            )
            :false;
        // Сортировка
        /*
            Есть бага с сортировкой по дате (возможно у всех не уникальных полей)! если сортировать только по дате, то на разных страницах
            одна и та же инфа вылазит, видимо оракл не может полноценно сортировать много записей по одинаковому значению поля.
            Для фикса вставляем принудительно еще и ID, если он не передавался.
        */
        if(
            count($arSortResult) == 1
            && in_array(strtoupper(array_shift(array_keys($arSortResult))), array('DATE_CREATE', 'DATE_PUBLISH'))
        )
            $arSortResult['ID'] = array_shift(array_values($arSortResult));


        /**
         * ПОИСК
         *
         * По рекомендации Битрикса разделяем этот запрос на 2
         * 1) Выбирает тольео ID
         * 2) уже по конкретным ИД вытаскивает данные
         */
        $arIDs = false;
        // Если задана пагинация
        if($mPagination) {
            // Первая выборка
            // (получаем ID идей с первой страницы для фильтра и пагинацию)
            $dbPost = $obCBlogPost->GetList(
                $arSortResult,
                $arFilterResult,
                false,
                $mPagination,
                array('ID') //$arSelect
            );
            $arIDs = array();
            while($arIdea = $dbPost->Fetch())
                $arIDs[] = $arIdea['ID'];

            // Обновляем фильтры
            $arFilterResult = array('ID' => $arIDs?:-1);



            /*
             * Инициализируем навигацию
             */
            if($arRequiredData['PAGINATION']) {
                $arResult['NAV'] = $dbPost->GetPageNavString(GetMessage('MESSAGE_COUNT')?:'Message count', $arParams['NAV_TEMPLATE']);
                if(is_array($arPagination)) {
                    $arPagination['TOTAL'] = $dbPost->NavRecordCount;
                    // Дополняем пагинацию
                    $arPagination['PAGES'] = ceil($arPagination['TOTAL'] / $arPagination['SIZE']);
                    // Проверяем валидность текущей страницы
                    if ($arPagination['PAGES'] < $arPagination['PAGE'])
                        $arPagination['PAGE'] = 1;

                    /*
                     * Если при формировании фильтров поиск не дал результат, то выводим ошибку
                     */
                    //if($arPagination['TOTAL'] < 1)
                    //   throw new Exception(GetMessage('BLOG_BLOG_BLOG_IDEAS_NOT_FOUND'));

                }
            }

        }

        // Вторая выборка
        if($arIDs || $arIDs === false) {
            $dbPost = $obCBlogPost->GetList(
                $arSortResult,
                $arFilterResult,
                false,
                false,
                $arSelect
            );
        }


        /*
         * Массивы с данными для повторного прохода
         */
        // Идеи, которые не имеют автономных статусов
        $arReworkDurationIdeas = array();
        // Список ID пользователей которые создавали найденные идеи
        $arIdeasUsers = array();
        // Список ID тэгов, которые присутствуют в найденных идеях
        $arIdeasTags = array();
        // Список ID идей у которых есть комментарии
        $arIdeasComments = array();

        /**
         * Преран по идеям с целью сбора информации
         */
        if($arRequiredData['REWORK_DURATION'] || $arRequiredData['TAGS'] || $arRequiredData['SYSTEM_USER'] || $arRequiredData['COMMENTS']) {

            while($arFinedIdea = $dbPost->Fetch()) {

                // Пользователь системы
                if($arRequiredData['SYSTEM_USER']) {
                    $arIdeasUsers[$arFinedIdea['AUTHOR_ID']] = array(
                        'DATA' => array(),
                        'NAME' => '',
                    );
                }

                // Тэги (категории)
                if($arRequiredData['TAGS']) {
                    if(!empty($arFinedIdea['CATEGORY_ID']))
                        $arIdeasTags = array_merge($arIdeasTags, explode(',', $arFinedIdea['CATEGORY_ID']));
                }

                // Срок доработки
                if(empty($arFinedIdea['UF_SA_STATUS_HISTORY']))
                    $arReworkDurationIdeas[] = $arFinedIdea['ID'];

                // Комментарии
                if($arRequiredData['COMMENTS'] && $arFinedIdea['NUM_COMMENTS'])
                    $arIdeasComments[$arFinedIdea['ID']] = array();
            }

            /*
             * Сбрасываем каретку результата
             */
            if(!empty($dbPost->result)) {
                oci_free_statement($dbPost->result);
                $dbPost = $obCBlogPost->GetList(
                    $arSortResult,
                    $arFilterResult,
                    false,
                    $mPagination,
                    $arSelect
                );
            }
            else
                reset($dbPost->arResult);
        }



        /**
         * Выполняем поиск необходимой информации
         * перед повторным проходом по найденым идеям
         */
        /*
         * Запрашиваем комментарии для всех идей, т.к. по отдельности будет значительно дольше
         */
        if(!empty($arIdeasComments)) {
            $obIdeaParser = new blogTextParser(false, $arParams["PATH_TO_SMILE"]);
            $rsResult = CBlogComment::GetList(
                array('ID' => 'ASC'),
                array(
                    'BLOG_ID' => self::getIdeaBlogId(),
                    'POST_ID' => array_keys($arIdeasComments),
                ),
                false,
                false,
                array(
                    'ID',
                    'POST_ID',
                    'AUTHOR_ID',
                    'DATE_CREATE',
                    'POST_TEXT',
                )
            );
            while($arRow = $rsResult->Fetch()) {
                $arRow['POST_TEXT'] = $obIdeaParser->convert4mail($arRow["POST_TEXT"]);
                $arIdeasComments[$arRow['POST_ID']][$arRow['ID']] = $arRow;

                if($arRow['AUTHOR_ID'])
                    $arIdeasUsers[$arRow['AUTHOR_ID']] = array(
                        'DATA' => array(),
                        'NAME' => '',
                    );
            }
        }

        /*
         * Расчитываем срок доработки
         * Вынесли в отдельный цикл, т.к. требуется объединить запрос
         * статусов для всех идей сразу, а не для каждого в отдельности
         */
        if($arRequiredData['REWORK_DURATION']) {
            // Получаем из истории все статусы всех идеи
            if(!empty($arReworkDurationIdeas))
                self::findIdeasStatuses($arReworkDurationIdeas);
        }

        /*
         * Получаем информацию по тэгам одним запросом,
         * т.к. по отдельности на 1000 идей эта процедура занимает 4 секунды
         */
        if(!empty($arIdeasTags)) {
            $rsResult = $obCBlogCategory->GetList(
                array(),
                array(
                    'BLOG_ID' => CIdeaTools::getIdeaBlogId(),
                    'ID'      => array_unique($arIdeasTags)
                )
            );
            $arIdeasTags = array();
            while($arRow = $rsResult->Fetch()) {
                $arIdeasTags[$arRow['ID']] = $arRow;
                $arIdeasTags[$arRow['ID']]['URL'] = CComponentEngine::MakePathFromTemplate(
                    $arParams['PATH_TO_BLOG_CATEGORY'],
                    array('blog' => CIdeaConst::C_BLOG_URL, 'category_id' => $arRow['ID'])
                );
            }
            unset($rsResult, $arRow);
        }

        /*
         * Получаем информацию по юзерам одним запросом,
         * т.к. по отдельности на 1000 идей эта процедура занимает 4 секунды
         */
        if(!empty($arIdeasUsers)) {
            $rsResult = $USER->GetList($by='ID',$order="asc",
                array('ID' => join(' | ', array_keys($arIdeasUsers))),
                array('FIELDS' => array(
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'LOGIN',
                    'ACTIVE',
                    'EMAIL',
                    'WORK_COMPANY',
                    'AUTO_TIME_ZONE',
                    'TIME_ZONE',
                ))
            );
            while($arRow = $rsResult->Fetch()) {
                $arIdeasUsers[$arRow['ID']]['DATA'] = $arRow;
                $arIdeasUsers[$arRow['ID']]['NAME'] = $obCBlogUser->GetUserName('', $arRow['NAME'], $arRow['LAST_NAME'], $arRow['LOGIN']);
            }
            unset($rsResult, $arRow);
        }



        /**
         * Проходимся по каждой найденой идеи
         */
        $iCount = 0;
        $sWriteRow = '';
        while($arFinedIdea = $dbPost->GetNext()) {
            ++$arResult['RETURNED'];

            /**
             * Подготовка данных
             */
            try {
                // Ансериализуем массив с данными о экспертной оценки идеи
                if($arRequiredData['IDEA_RATING'])
                    $arFinedIdea['UF_IDEA_RATING'] = unserialize($arFinedIdea['~UF_IDEA_RATING'][0]);

                // Ансериализуем массив с данными об истории статусов идеи
                $arFinedIdea['UF_SA_STATUS_HISTORY'] = unserialize($arFinedIdea['~UF_SA_STATUS_HISTORY']);


                // Подготавлеваем детальный текст
                /*
                $arAllow = array('ANCHOR' => 'Y', 'IMG' => 'Y', 'NL2BR' => 'N', 'QUOTE' => 'Y', 'CODE' => 'Y', 'SMILES' => 'Y',);
                $arAllow['VIDEO'] = COption::GetOptionString('blog','allow_video', 'Y') == 'Y'? 'Y' : 'N';
                if($arFinedIdea['DETAIL_TEXT_TYPE'] == 'html' && COption::GetOptionString('blog','allow_html', 'N') == 'Y')
                    $arAllow = $arAllow + array('HTML' => 'Y', 'SMILES' => 'Y');
                else
                    $arAllow = $arAllow + array('HTML' => 'N', 'BIU' => 'Y', 'FONT' => 'Y', 'LIST' => 'Y');

                $arFinedIdea['TEXT_FORMATTED'] = $obBlogTextParser->convert($arFinedIdea['~DETAIL_TEXT'], true, array(), $arAllow, $arParserParams);
                unset($arAllow);
                */


                /*
                 * Пользователь
                 */
                if($arRequiredData['SYSTEM_USER'] && !empty($arIdeasUsers[$arFinedIdea['AUTHOR_ID']])) {
                    $arFinedIdea['arUser']      = $arIdeasUsers[$arFinedIdea['AUTHOR_ID']]['DATA'];
                    $arFinedIdea['AuthorName']  = $arIdeasUsers[$arFinedIdea['AUTHOR_ID']]['NAME'];
                }

                /*
                 * Тэги
                 */
                if($arRequiredData['TAGS']) {
                    $arFinedIdea['CATEGORY'] = array();
                    $arFinedIdea['AR_CATEGORY_ID'] = array();
                    if(!empty($arFinedIdea['CATEGORY_ID']) && !empty($arIdeasTags)) {
                        $arFinedIdea['AR_CATEGORY_ID'] = explode(',', $arFinedIdea['CATEGORY_ID']);
                        foreach($arFinedIdea['AR_CATEGORY_ID'] AS $iTagId) {
                            if(!empty($arIdeasTags[$iTagId]))
                                $arFinedIdea['CATEGORY'][$iTagId] = $arIdeasTags[$iTagId];
                        }
                    }
                }


            } catch(Exception $ex) {
                throw $ex;
            }


            /**
             * Формируем разные варианты отображения времени
             */
            if($arRequiredData['DATE']) {
                $arFinedIdea['DATE_PUBLISH_FORMATED'] = FormatDate($arParams['DATE_TIME_FORMAT'], MakeTimeStamp($arFinedIdea['DATE_PUBLISH'], CSite::GetDateFormat('FULL')));
                $arFinedIdea['DATE_PUBLISH_DATE'] = ConvertDateTime($arFinedIdea['DATE_PUBLISH'], FORMAT_DATE);
                $arFinedIdea['DATE_PUBLISH_TIME'] = ConvertDateTime($arFinedIdea['DATE_PUBLISH'], 'HH:MI');
                $arFinedIdea['DATE_PUBLISH_D'] = ConvertDateTime($arFinedIdea['DATE_PUBLISH'], 'DD');
                $arFinedIdea['DATE_PUBLISH_M'] = ConvertDateTime($arFinedIdea['DATE_PUBLISH'], 'MM');
                $arFinedIdea['DATE_PUBLISH_Y'] = ConvertDateTime($arFinedIdea['DATE_PUBLISH'], 'YYYY');
            }


            /**
             * Формируем доступные действия
             */
            if($arRequiredData['ACTION']) {
                $arFinedIdea['ACTION'] = array(
                    'EDIT' => false,
                    'DELETE' => false,
                );


                // Редактирование идеи
                if (self::canIdeaEdit($arFinedIdea['ID'], $arFinedIdea['UF_AUTHOR_DEP'], $arFinedIdea['UF_STATUS'], $arFinedIdea['AUTHOR_ID'], $arFinedIdea['UF_TRANSFER_DEP'])) {
                    $arFinedIdea['ACTION']['EDIT'] = true;
                }

                // Удаление идеи
                if (self::canIdeaDelete($arFinedIdea['ID'], $arFinedIdea['UF_AUTHOR_DEP'])) {
                    $arFinedIdea['ACTION']['DELETE'] = true;
                }
            }


            /**
             * Формируем ссыки
             */
            if($arRequiredData['URL']) {
                $arFinedIdea['URL'] = array();

                // Ссылка на детальную страницу идеи
                //$arFinedIdea['URL']['POST'] = CComponentEngine::MakePathFromTemplate($arParams['PATH_TO_POST'], array('blog' => CIdeaConst::C_BLOG_URL, 'post_id' => $obCBlogPost->GetPostID($arFinedIdea['ID'], $arFinedIdea['CODE'], $arParams['ALLOW_POST_CODE'])));
                $arFinedIdea['URL']['POST'] = CComponentEngine::MakePathFromTemplate($arParams['PATH_TO_POST'], array('blog' => CIdeaConst::C_BLOG_URL, 'post_id' => $arFinedIdea['ID']));

                // Ссылка на редактирование идеи
                if ($arFinedIdea['ACTION']['EDIT']) {
                    $arFinedIdea['URL']['EDIT'] = CComponentEngine::MakePathFromTemplate($arParams['PATH_TO_POST_EDIT'], array('blog' => CIdeaConst::C_BLOG_URL, 'post_id' => $arFinedIdea['ID']));
                }
            }



            /**
             * Обработка UF_ и SF_ полей
             */
            if($arRequiredData['PROPERTIES']) {

                /**
                 * Дополнительные свойства отображения,
                 * для сборных полей
                 */
                /*
                 * Принадлежность
                 */
                if($arRequiredData['DEPARTMENT']) {
                    // Цепочка вложености департаментов
                    $arFinedIdea['SF_DEPARTMENT_ENTERPRISE'] = CIdeaTools::getBranch($arFinedIdea['UF_AUTHOR_DEP'],0,true,false);

                    // Структура департаментов идеи
                    $arFinedIdea['SF_DEPARTMENT_LIST'] = array_values(array_map(
                        function ($v) {
                            return $v['NAME'];
                        },
                        $arFinedIdea['SF_DEPARTMENT_ENTERPRISE']
                    ));
                    // Компания
                    $arFinedIdea['SF_COMPANY'] = $arFinedIdea['SF_DEPARTMENT_LIST'][0] ?: '';
                    // Департамент
                    $arFinedIdea['SF_DEPARTMENT'] = $arFinedIdea['SF_DEPARTMENT_LIST'][1] ?: '';
                    // Отдел
                    $arFinedIdea['SF_DIVISION'] = $arFinedIdea['SF_DEPARTMENT_LIST'][2] ?: '';

                }
                /*
                 * Передано
                 */
                if($arRequiredData['DEPARTMENT']) {
                    // Цепочка вложености департаментов в которое передали идею
                    $arFinedIdea['SF_TRANSFER_ENTERPRISE'] = CIdeaTools::getBranch($arFinedIdea['UF_TRANSFER_DEP'],0,true,false);

                    // Структура департаментов идеи
                    $arFinedIdea['SF_TRANSFER_LIST'] = array_values(array_map(
                        function ($v) {
                            return $v['NAME'];
                        },
                        $arFinedIdea['SF_TRANSFER_ENTERPRISE']
                    ));
                    // Компания
                    $arFinedIdea['SF_TRANSFER_COMPANY'] = $arFinedIdea['SF_TRANSFER_LIST'][0] ?: '';
                    // Департамент
                    $arFinedIdea['SF_TRANSFER_DEPARTMENT'] = $arFinedIdea['SF_TRANSFER_LIST'][1] ?: '';
                    // Отдел
                    $arFinedIdea['SF_TRANSFER_DIVISION'] = $arFinedIdea['SF_TRANSFER_LIST'][2] ?: '';

                }

                /*
                 * Статус
                 */
                $arFinedIdea['SF_STATUS'] = $arStatusList[$arFinedIdea['UF_STATUS']]['VALUE'] ?: '';
                $arFinedIdea['SF_STATUS_CODE'] = $arStatusList[$arFinedIdea['UF_STATUS']]['XML_ID'] ?: '';

                /*
                 * Сотрудники (Авторы)
                 */
                if($arRequiredData['EMPLOYEES']) {
                    // Авторы идей с табелями (TODO: переделать на _DATA)
                    $arFinedIdea['SF_EMPLOYEES'] = !empty($arFinedIdea['UF_EMPLOYEE_NAME'])
                        ? array_map(
                            function ($c, $n) {
                                return "[{$c}] $n";
                            },
                            $arFinedIdea['UF_EMPLOYEE_CODE'],
                            $arFinedIdea['UF_EMPLOYEE_NAME']
                        )
                        : array();
                }


                /*
                 * Категория влияния (оценка совета)
                 */
                if ($arRequiredData['IDEA_RATING']) {
                    foreach (CRationList::getList() AS $iKey => $arData)
                        $arFinedIdea['SF_CAT_' . $arData['code']] = implode(';', array_map(function($v){return $v?:0;}, ($arFinedIdea['UF_IDEA_RATING'][$iKey]?:array(0,0,0))) ) ?: '';
                }


                /**
                 * Расчитываем дополнительные данные для идеи
                 * - Комментарии
                 * - Ожидаемый срок исполнения
                 * - Текущий срок исполнения
                 * - 'Светофор'
                 * - Уровень просрочки в %
                 * - Срок доработки
                 */
                /*
                 * Комментари
                 */
                if($arRequiredData['COMMENTS']) {
                    $arFinedIdea['SF_COMMENTS'] = array();
                    if($arFinedIdea['NUM_COMMENTS']) {
                        foreach($arIdeasComments[$arFinedIdea['ID']] AS $arComment) {
                            $arFinedIdea['SF_COMMENTS'][$arComment['ID']] = array(
                                'ID'          => $arComment['ID'],
                                'AUTHOR_ID'   => $arComment['AUTHOR_ID'],
                                'AUTHOR_NAME' => $arComment['AUTHOR_ID']? $arIdeasUsers[$arComment['AUTHOR_ID']]['NAME'] : 'Аноним',
                                'DATE'        => $arComment['DATE_CREATE'],
                                'TEXT'        => $arComment['POST_TEXT'],
                            );
                        }
                    }
                }

                /*
                 * Устанавливаем ожидаемый срок исполнения
                 */
                $arFinedIdea['SOLUTION_DURATION']
                    = $arFinedIdea['SF_RELEASED_WAY_SOLUTION_DURATION']
                    = 0;
                $arFinedIdea['SF_RELEASED_WAY']
                    = $arFinedIdea['SF_RELEASED_WAY_CODE']
                    = '';

                // Если задан путь реализации, то выставляем срок в переменные и имя
                if (!empty($arFinedIdea['UF_RELEASED_WAY'])) {
                    $arFinedIdea['SF_RELEASED_WAY']      = $arReleasedWays[$arFinedIdea['UF_RELEASED_WAY']]['NAME'];
                    $arFinedIdea['SF_RELEASED_WAY_CODE'] = $arReleasedWays[$arFinedIdea['UF_RELEASED_WAY']]['CODE'];
                    $arFinedIdea['SOLUTION_DURATION']
                        = $arFinedIdea['SF_RELEASED_WAY_SOLUTION_DURATION']
                        = $arReleasedWays[$arFinedIdea['UF_RELEASED_WAY']]['UF_SOLUTION_DURATION'];
                }
                // Если задано поле UF_SOLUTION_DURATION, то оно имеет приоритет
                if (!empty($arFinedIdea['UF_SOLUTION_DURATION']))
                    $arFinedIdea['SOLUTION_DURATION'] = $arFinedIdea['UF_SOLUTION_DURATION'];


                /*
                 * Расчитывает 'Светофор' и текущий срок реализации
                 */
                $arFinedIdea['REALISE_DURATION'] = false;
                $arFinedIdea['TRAFFIC_LIGHT_STATUS'] = 'gray';

                // Проходимся по статусу
                switch ($arFinedIdea['UF_STATUS']) {
                    // В работе, Принята (несуществующий, для надежности)
                    case self::getStatusIdByCode('ACCEPT'):
                    case self::getStatusIdByCode('PROCESSING'):
                        if (!empty($arFinedIdea['UF_DATE_ACCEPT'])) {
                            // Парсим дату принятия в timestamp
                            $acceptDate = explode('.', $arFinedIdea['UF_DATE_ACCEPT']);
                            $acceptDate = mktime(0, 0, 0, $acceptDate[1], $acceptDate[0], $acceptDate[2]);

                            // Расчитываем количество дней прошедших с даты подачи
                            $arFinedIdea['REALISE_DURATION'] = ceil((time() - $acceptDate) / (24 * 60 * 60));

                            // Смотрим статус светофора
                            $arFinedIdea['TRAFFIC_LIGHT_STATUS'] = 'red';
                            if (time() < $acceptDate + ($arFinedIdea['SOLUTION_DURATION'] * 24 * 60 * 60))
                                $arFinedIdea['TRAFFIC_LIGHT_STATUS'] = 'yellow';
                        }

                        break;

                    // Выполнено, Реализовано
                    case self::getStatusIdByCode('COMPLETED'):
                    case self::getStatusIdByCode('FINISH'):
                        $arFinedIdea['TRAFFIC_LIGHT_STATUS'] = 'green';

                        break;
                }


                /*
                 * Уровень просрочки в %
                 */
                $arFinedIdea['EXPIRED_PERCENT'] = 0;
                $iDuration = $arFinedIdea['REALISE_DURATION'] ?: $arFinedIdea['UF_TIME_NEED'];
                $iNormalDuration = $arFinedIdea['UF_SOLUTION_DURATION'] ?: 0;

                if ($iNormalDuration > 0 && $iDuration > 0 && $iDuration > $iNormalDuration)
                    $arFinedIdea['EXPIRED_PERCENT'] = round(($iDuration - $iNormalDuration) / $iNormalDuration * 100);

                unset($iDuration, $iNormalDuration);

                /*
                 * Срок доработки
                 */
                $arFinedIdea['REWORK_DURATION'] = 0;

                // Все статусы данной идеи
                $arIdeaStatuses = array_values(is_array($arFinedIdea['UF_SA_STATUS_HISTORY'])
                    ?$arFinedIdea['UF_SA_STATUS_HISTORY']
                    :CIdeaTools::getIdeaStatuses($arFinedIdea['ID'])?:array());
                $iReworkDate = 0;
                $iOtherDate = 0;

                // Проходимся по статусам и получаем дату "На доработку" и другой статус
                foreach ($arIdeaStatuses AS $arStatus) {
                    // Если это статус "На доработке" и он был раньше уже найденого "На доработке", то записываем его
                    if ($arStatus['CODE'] == CIdeaTools::getStatusIdByCode('REWORK')) {
                        if (!$iReworkDate || $arStatus['TIME_STAMP'] < $iReworkDate)
                            $iReworkDate = $arStatus['TIME_STAMP'];
                    } // Если этот статус выше по приоритету, чем "На доработке" и он был раньше, чем предыдущий найденый, то записываем его
                    elseif (CIdeaConst::C_STATUS_PRIORITY_REWORK < $arStatus['PRIORITY'] && (!$iOtherDate || $arStatus['TIME_STAMP'] < $iOtherDate)) {
                        $iOtherDate = $arStatus['TIME_STAMP'];
                    }
                }

                // Если время "На доработке" найдено, то расчитываем кол-во дней в этом состоянии
                if ($iReworkDate) {
                    $arFinedIdea['REWORK_DURATION'] = ceil((($iOtherDate ?: time()) - $iReworkDate) / (24 * 60 * 60))/* + 1*/;
                }
                unset($arStatus, $arIdeaStatuses, $iReworkDate, $iOtherDate);

            }


            // Записываем результат
            if($bParseByFile) {
                ++$iCount;
                $sWriteRow .= str_replace("\r\n", '#_pc2nl_#', serialize($arFinedIdea))."\r\n";
                // Записываем строки в файл (записываем скопом для сокращения операций записи)
                if($iCount && !($iCount % 100)) {
                    if(!fwrite($arResult['FILE'], $sWriteRow))
                        throw new Exception('Не удалось записать строку во временный файл с идеями.');
                    $sWriteRow = '';
                }
            } else
                $arResult['LIST'][$arFinedIdea['ID']] = $arFinedIdea;

            unset($arFinedIdea);
        }
        // Дозаписываем файл и скидываем каретку в начало ресурса
        if($bParseByFile) {
            if($sWriteRow && !fwrite($arResult['FILE'], $sWriteRow))
                throw new Exception('Не удалось записать строку во временный файл с идеями.');
            $sWriteRow = '';
            rewind($arResult['FILE']);
        }
        unset($arFinedIdea, $dbPost, $sWriteRow, $sRow, $fnSecondParse, $arIdeasComments, $arIdeasUsers, $arIdeasTags, $arReworkDurationIdeas);


        // Удаляем парсер
        //unset($obBlogTextParser, $arParserParams);

        // Возвращаем данные
        return $arResult;

    }


    /**
     * Метод получает количество идей подходящие под фильтры и время затраченное на их поск,
     * после чего высчитывает уровень нагрузки (пока просто на кол-ве идей, без учета необходимых полей для обработки)
     * ПС: используется просто для того, что бы не дублировать код определнеия кол-ва идей в компонентах
     * ПС2: написан в первую очередь для агентов выгрузки CReports::addAgent()
     *
     * TODO: когда нибудь сделать многоитерацинных агентов:
     * 1) Запись агента в базу
     * 2) Обработка агента для определения его нагрузки
     * 3) Генерация отчета по агенту с учетом его нагрузки
     *
     * @param array|bool $arFilterResult Массив фильтрации
     * @param array $arRequiredData TODO - учитывать при расчете нагрузки обрабатываемые поля
     *
     * @return array
     * @throws Exception
     */
    public static function getIdeasPreloadData($arFilterResult, $arRequiredData = array()) {
        $obCBlogPost= new CBlogPost();
        $iStart     = time();
        $arResult   = array(
            'LOAD_LEVEL'    => 'M',
            'PRELOAD_TIME'  => 0,
            'PRELOAD_IDEAS' => 0,
        );


        // Поиск
        $arResult['PRELOAD_IDEAS'] = $obCBlogPost->GetList(
            array('ID' => 'ASC'),
            $arFilterResult,
            array(),
            false,
            array('ID')
        );
        // Время
        $arResult['PRELOAD_TIME'] = time() - $iStart?:1;
        // Уровень нагрузки
        $arResult['LOAD_LEVEL'] = $arResult['PRELOAD_IDEAS'] <= CIdeaConst::C_AGENT_LOAD_LEVEL_LOW
            ? 'L'
            : ($arResult['PRELOAD_IDEAS'] <= CIdeaConst::C_AGENT_LOAD_LEVEL_MEDIUM
                ? 'M'
                : 'H'
            );


        return $arResult;
    }


    /**
     * Метод формирует массив с ID идей по заданным фильтрам
     *
     * @param int   $iDepartmentID
     * @param array $arFilter
     * @return array
     */
    public static function getIdeasIdPerBranch($iDepartmentID, $arFilter = array()) {
        $obCBlogPost= new CBlogPost();
        $arResult   = array('LIST' => array(), 'RETURNED' => 0);
        $arFilter   = $arFilter?:array();


        if(empty($iDepartmentID) || !$arBranch = self::getBranch($iDepartmentID))
            return $arResult;

        $arFilter['UF_AUTHOR_DEP'] =  array_keys($arBranch);

        /**
         * Ищем идеи по фильтру
         */
        $dbPost = $obCBlogPost->GetList(
            array(),
            $arFilter,
            false,
            false,
            array(
                'BLOG_ID',
                'ID',
            )
        );


        /**
         * Проходимся по каждой найденой идеи
         */
        while($arFinedIdea = $dbPost->GetNext())
            $arResult['LIST'][$arFinedIdea['ID']] = $arFinedIdea['ID'];
        unset($arFinedIdea, $dbPost);

        $arResult['RETURNED'] = count($arResult['LIST']);

        // Возвращаем данные
        return $arResult;
    }

    public static function getIdeaBlogId() {
        static $iBlogId = 0;
        if(!$iBlogId) {
            $obBlog = new CBlog();
            // Получаем ID блога для фильтра
            $arBlog = $obBlog->GetByUrl(CIdeaConst::C_BLOG_URL);
            $iBlogId = $arBlog['ID'];
        }

        return $iBlogId?:-1;
    }

    /**
     * Ищет последние идеи по департаметам
     *
     * @param array $arDepartmentsID Массив ID департаментов по которым нужно найти последнии идеи
     * @param bool $bIncludeSubDep При поиске последней идеи учитывать подразделения
     * @param array $arFilter
     *
     * @return array|массив последних идей в департаментах
     */
    public static function getLastIdeasPerDep($arDepartmentsID = array(), $bIncludeSubDep = true, $arFilter = array()) {
        $arResult = array();
        $sCacheSection = $bIncludeSubDep?'sub':'in';
        if(!is_array($arFilter))
            $arFilter = array();

        // Если департаменты не переданы, то получаем департаменты для текущего пользователя
        if(empty($arDepartmentsID)) {
            $arDepartmentsID = self::getMyBranch('', false, $bIncludeSubDep);
            $arDepartmentsID = $arDepartmentsID?array_keys($arDepartmentsID):array();
        } elseif(!is_array($arDepartmentsID))
            $arDepartmentsID = array($arDepartmentsID);


        // Проверяем кеш и удаляем уже найденные ID (если нет доп фильтров)
        if($arFilter) {
            foreach($arDepartmentsID AS $k => $id) {
                if(isset(self::$cache['lastIdeaPerDep'][$sCacheSection][$id])) {
                    if(self::$cache['lastIdeaPerDep'][$sCacheSection][$id])
                        $arResult[$id] = self::$cache['lastIdeaPerDep'][$sCacheSection][$id];

                    unset($arDepartmentsID[$k]);
                }
            }
        }



        // Получаем последнии идеи
        if(!empty($arDepartmentsID)) {
            $obBlogPost = new CBlogPost();

            // Фильтр для поиска
            $arFilter['BLOG_ID'] = self::getIdeaBlogId();


            // Проходимся циклично по всем департаментам и вытаскиваем по 1 последний идеи из каждого раздела с подразделами
            foreach($arDepartmentsID AS $iDepID) {
                $mDepartmentsIdToFind = $iDepID;

                // Если нужно найти со всеми подразделами, то получаем их ID
                if($bIncludeSubDep) {
                    $mDepartmentsIdToFind = self::getBranch($iDepID);
                    $mDepartmentsIdToFind = $mDepartmentsIdToFind? array_keys($mDepartmentsIdToFind) : $iDepID;
                }

                // Дополняем массив поиска
                $arFilter['UF_AUTHOR_DEP'] = $mDepartmentsIdToFind;

                // Получаем идею
                $arIdea = $obBlogPost->GetList(
                    array('ID' => 'DESC'),
                    $arFilter,
                    false,
                    array('nTopCount'=>1),
                    array('ID')
                )->Fetch();

                if($arIdea)
                    $arResult[$iDepID]
                        = self::$cache['lastIdeaPerDep'][$sCacheSection][$iDepID]
                        = $arIdea['ID'];
                else
                    self::$cache['lastIdeaPerDep'][$sCacheSection][$iDepID] = 0;

            }
        }

        // Массив найденных идей
        return $arResult;
    }


    /**
     * Выдает в департаментах последние внутренние номера
     *
     * @param array $arDepartmentsID Массив ID департаментов по которым нужно найти последнии внутренние номера идеи
     * @param bool $bIncludeSubDep При поиске идей учитывать подразделения
     *
     * @return bool|array false при ошибке | массив департаментов и их последних внутренних номеров
     */
    public static function getLastIdeasInnerNumberPerDep($arDepartmentsID = array(), $bIncludeSubDep = true) {
        $arResult = array();
        $sCacheSection = $bIncludeSubDep?'sub':'in';

        // Если департаменты не переданы, то получаем департаменты для текущего пользователя
        if(empty($arDepartmentsID)) {
            $arDepartmentsID = self::getMyBranch('', false, $bIncludeSubDep);
            $arDepartmentsID = $arDepartmentsID?array_keys($arDepartmentsID):array();
        } elseif(!is_array($arDepartmentsID))
            $arDepartmentsID = array($arDepartmentsID);

        $cache = new CPHPCache();
        $cache_time = 3600*24;
        $cache_id = 'getLastIdeasInnerNumberPerDep'.md5(implode(" ", $arDepartmentsID));
        $cache_path = 'UMSH';
        if ($cache_time > 0 && $cache->InitCache($cache_time, $cache_id, $cache_path))
        {
            $res = $cache->GetVars();
            if (isset($res["data"]))
            {
                $arResult = $cachedData = $res["data"];
            }
            //debugmessage("From cache");
        }
        if (!is_array($cachedData))
        {
            //debugmessage("Not from cache");
            // Получаем последнии идеи
            if(!empty($arDepartmentsID)) {
                $obBlogPost = new CBlogPost();


                // Фильтр для поиска
                $arFilter = array(
                    'BLOG_ID' => self::getIdeaBlogId(),
                    '!UF_STATUS' => CIdeaTools::getStatusIdByCode('DRAFT'),
                    '!UF_INTERNAL_CODE' => false,
                );

                // Проходимся циклично по всем департаментам и получаем их внутренние номера
                foreach($arDepartmentsID AS $iDepID) {
                    $sIdeaNumber = 'Нет';
                    $arIdeasPerDep = self::getLastIdeasPerDep($iDepID, $bIncludeSubDep, $arFilter);


                    if(!empty($arIdeasPerDep[$iDepID])) {
                        // Получаем идею
                        $arIdea = $obBlogPost->GetList(
                            array(),
                            $arFilter + array('ID' => $arIdeasPerDep[$iDepID]), // Дополняем массив поиска ID идеи которая была найдена
                            false,
                            false,
                            array('ID', 'UF_INTERNAL_CODE')
                        )->Fetch();

                        if(!empty($arIdea['UF_INTERNAL_CODE']))
                            $sIdeaNumber = $arIdea['UF_INTERNAL_CODE'];

                        // Кеш
                        //self::$cache['lastIdeaInnerNumberPerDep'][$sCacheSection][$iDepID] = $sIdeaNumber;
                        $arResult[$iDepID] = $sIdeaNumber;

                    }
                }
            }


            //////////// end cache /////////
            if ($cache_time > 0)
            {
                $cache->StartDataCache($cache_time, $cache_id, $cache_path);
                $cache->EndDataCache(array("data"=>$arResult));
            }
        }
        /**/


        // Количество найденных идей
        return $arResult;
    }


    /**
     * Проверяет внутренний номер на уникальность
     *
     * @param string    $sInnerNumber       Внутренний номер для проверки
     * @param int       $iDepartmentID      Индификатор депертамента в который сохраняется идея
     * @param int       $iIdeaID            Индификатор идеи которая сохраняется
     * @param bool      $bIncludeParentsDep При поиске идей учитывать родителей
     *
     * @return int Возвращает Индификатор идеи с таким внутренним номером
     */
    public static function checkInnerNumberByUnique($sInnerNumber, $iDepartmentID, $iIdeaID, $bIncludeParentsDep = true) {
        $mFined = false;
        $arDepartmentsID = self::getBranch($iDepartmentID, 0, $bIncludeParentsDep);
        $arDepartmentsID = $arDepartmentsID?array_keys($arDepartmentsID):array($iDepartmentID);

        // Ищем идею с таким номером
        if(!empty($arDepartmentsID)) {
            $obBlogPost = new CBlogPost();

            // Фильтр для поиска
            $arFilter = array(
                'BLOG_ID'           => self::getIdeaBlogId(),
                'UF_AUTHOR_DEP'     => $arDepartmentsID,
                'UF_INTERNAL_CODE'  => strtoupper($sInnerNumber),
                '!UF_STATUS'         => self::getStatusIdByCode('DRAFT'),
            );

            if($iIdeaID)
                $arFilter['!ID'] = $iIdeaID;


            // Получаем идею
            $arIdea = $obBlogPost->GetList(
                array(),
                $arFilter,
                false,
                false,
                array('ID', 'UF_INTERNAL_CODE', 'UF_AUTHOR_DEP')
            )->Fetch();

            if($arIdea)
                $mFined = $arIdea;
        }

        // Количество найденных идей
        return $mFined;
    }


    /**
     * Получить список полей с их свойствами
     *
     * @return array
     */
    public static function getIdeaViewFieldProperties() {
        $arResult = array();

        foreach(CIdeaConst::$arAllowedViewFields AS $sFieldName => $arFieldData) {
            // Дублируем поле
            $arFieldData['FIELD'] = $sFieldName;
            // Поле для отображения
            $arFieldData['DISPLAY'] = $arFieldData['DISPLAY'] !== false? ($arFieldData['DISPLAY']?:$sFieldName) : false;
            // Поле для поиска
            $arFieldData['FILTER']['FIELD'] = $arFieldData['FILTER']['FIELD']?:$sFieldName;

            // Формируем типы поиска у поля
            if($arFieldData['FILTER']['AVAILABLE'])
                $arFieldData['FILTER']['TYPES'] = $arFieldData['FILTER']['AVAILABLE'];
            elseif($arFieldData['FILTER']['FORBIDDEN'])
                $arFieldData['FILTER']['TYPES'] = array_diff(CIdeaConst::$arAllowedRuleType, $arFieldData['FILTER']['FORBIDDEN']);
            else
                $arFieldData['FILTER']['TYPES'] = CIdeaConst::$arAllowedRuleType;

            $arResult[$sFieldName] = $arFieldData;
        }
        unset($arFieldData);

        return $arResult;
    }


    /**
     * Получить массив форматированных правил фильтрации
     * Вовращает список тех же правил, но уже с замененными алиасами фильтрации
     *
     * @param $arRules Массив не отформатированных правил
     * @param null $arFormattedViewFieldsProperties
     * @return array
     */
    public static function getFormattedFiltersRules($arRules, $arFormattedViewFieldsProperties = null, &$arMainRuleList = array()) {
        $arResult = array();
        if(empty($arFormattedViewFieldsProperties))
            $arFormattedViewFieldsProperties = self::getIdeaViewFieldProperties();

        foreach($arRules AS $sFieldName => $arRules) {
            $arViewFilterProp = $arFormattedViewFieldsProperties[$sFieldName]['FILTER']?:array();
            if(!empty($arViewFilterProp['ENABLE']) && !empty($arViewFilterProp['FIELD'])) {
                foreach($arRules AS $sRuleType => $sRuleValue) {
                    if($sRuleType == 'ALL') {
                        $arMainRuleList[$arViewFilterProp['FIELD']] = array();
                        $arResult[$arViewFilterProp['FIELD']] = array();
                    } else {
                        self::parseFieldRuleValue(
                            $sFieldName,
                            $sRuleType,
                            $sRuleValue,
                            $arResult[$arViewFilterProp['FIELD']],
                            $arFormattedViewFieldsProperties
                        );
                    }

                }
            }
        }

        // Мержим контейнер
        $arMainRuleList = array_merge_recursive($arMainRuleList, $arResult);

        return $arResult;
    }



    public static function getPropertyEnumList($iIBlockID, $sPropertyCode) {
        // Получаем свойства списка
        if(!isset(self::$cache['lists'][$iIBlockID][$sPropertyCode])) {
            $arResult = array();
            $obEnum   = new CIBlockPropertyEnum();
            $rsResult = $obEnum->GetList(array('SORT' => 'ASC'), array('IBLOCK_ID' => $iIBlockID, 'CODE' => $sPropertyCode));

            while($arRow = $rsResult->GetNext(true, false))
                $arResult[$arRow['XML_ID']] = $arRow;

            self::$cache['lists'][$iIBlockID][$sPropertyCode] = $arResult;
        }

        return self::$cache['lists'][$iIBlockID][$sPropertyCode];
    }


    public static function getRealiseWays() {
        $arWays= array();
        $rSections = CIBlockSection::GetList(
            array(),
            array('IBLOCK_ID' => self::getIBlockID('umsh', 'umshRealiseWays')),
            false,
            array('ID', 'CODE', 'NAME', 'UF_SOLUTION_DURATION'));
        while($arWay = $rSections->GetNext(true))
            $arWays[$arWay['ID']] = $arWay;

        return $arWays;
    }

    // Структура департаментов идеи

    /**
     * Метод возвращает массив в виде цепочки подразделений
     *
     * @param $idepartment
     * @return array
     */
    public static function getArrayNamesBranch($idepartment){
        $arFinedIdea = [];
        $arFinedIdea['SF_TRANSFER_DEPARTMENTS'] = self::getBranch($idepartment,0,true,false);

        // Структура департаментов идеи
        $arFinedIdea['SF_TRANSFER_DEPARTMENTS_LIST'] = array_values(array_map(
            function ($v) {
                return $v['NAME'];
            },
            $arFinedIdea['SF_TRANSFER_DEPARTMENTS']
        ));
        return $arFinedIdea['SF_TRANSFER_DEPARTMENTS_LIST'];
    }

    /**
     * Метод возвращает массив с id цепочки подразделений
     *
     * @param $idepartment
     * @return array
     */
    public static function getArrayIdBranch($idepartment){
        $arFinedIdea = [];
        $arFinedIdea['SF_TRANSFER_DEPARTMENTS'] = self::getBranch($idepartment,0,true,false);

        // Структура департаментов идеи
        $arFinedIdea['SF_TRANSFER_DEPARTMENTS_LIST'] = array_values(array_map(
            function ($v) {
                return $v['ID'];
            },
            $arFinedIdea['SF_TRANSFER_DEPARTMENTS']
        ));
        return $arFinedIdea['SF_TRANSFER_DEPARTMENTS_LIST'];
    }

    /**
     * @param int $departmentId
     * @param bool $active true- только активные кнопки, false - все(активные и неактивные)
     * @return array
     */
    public static function getTransferButtons($departmentId = false, $active = true)
    {
        if(!$departmentId) {
            global $USER;
            $rsUser = CUser::GetByID(intval($USER->GetID()));
            $arUser = $rsUser->Fetch();
            if (empty($arUser['UF_IDEA_DEPARTMENT']))
            {
                $departmentId = $arUser['UF_IDEA_DEPARTMENT'] = 19935;
            } else {
                $departmentId = $arUser['UF_IDEA_DEPARTMENT'];
            }
        }
        $tree = self::getArrayIdBranch($departmentId);

        $buttons = [];
        if (!empty($tree))
        {
            foreach (array_reverse ($tree) as $department)
            {
                $btns = CIdeaTools::getIbElements(
                    CIdeaTools::getIBlockID("umsh","buttonSettings"),
                    $filter = [
                        'PROPERTY_USER_DEPARTMENT' => $department,
                        'ACTIVE' => ($active)?'Y':'',
                    ],
                    [
                        'NAME', 'PREVIEW_TEXT', 'PROPERTY_DEPARTMENT' ,'PROPERTY_USER_DEPARTMENT', 'ACTIVE'
                    ]
                );
                foreach ($btns as $btn) {
                    $buttons[] = $btn;
                }
            }
        }
        return $buttons;
    }

    /**
     * Сотрудник КЦ?
     * @return bool
     */
    public static function corpCenterUser($user_id=false){
        /*if($user_id) {
          return in_array($group_id = 11, CUser::GetUserGroup($user_id));
        } else {
            global $USER;
            return in_array($group_id = 11, $USER->GetUserGroupArray());
        }*/
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();

        if($user_id) {
            if(in_array($user_id, [50845,60244])) {
                return true;
            }
            return in_array($group_id = 11, CUser::GetUserGroup($user_id)) && strpos($user['EMAIL'],"@sibur.ru")!==false;
        } else {
            global $USER;
            if(in_array($USER->GetID(), [50845,60244])) {
                return true;
            }
            return in_array($group_id = 11, $USER->GetUserGroupArray() ) && strpos($user['EMAIL'],"@sibur.ru")!==false;
        }
    }

    public static function SnhUser($user_id=false) {
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();
        if(strpos($user['EMAIL'],"@snh.sibur.ru")===false) {
            return false;
        } else {
            return true;
        }
    }

    public static function VskUser($user_id=false) {
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();
        if(strpos($user['EMAIL'],"@vsk.sibur.ru")===false) {
            return false;
        } else {
            return true;
        }
    }

    public static function ShpUser($user_id=false) {
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();
        if(strpos($user['EMAIL'],"@shp.sibur.ru")===false) {
            return false;
        } else {
            return true;
        }
    }

    public static function BscUser($user_id=false) {
        global $USER;
        if(in_array($USER->GetID(), [
            1122207/*benizhinskiyao@sibur.ru*/,
            37165/*Пятницын Андрей Александрович */,
            60244,
            1308633/*Мосеева Яна Вячеславовна*/,
            1333210/*Идрисова Диана*/,
            1286063/*Никулин Ярослав Евгеньевич*/
        ])) {
            return true;
        }
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();
        if(strpos($user['EMAIL'],"@bsc.sibur.ru")===false) {
            return false;
        } else {
            return true;
        }
    }

    public static function SiUser($user_id=false) {
        if(!$user_id) {
            global $USER;
            $user_id = $USER->GetID();
        }
        $user = CUser::GetByID($user_id)->Fetch();
        if(strpos($user['EMAIL'],"@sibur-int.com")===false) {//Puchkova Veronika <puchkovav@sibur-int.com>
            return false;
        } else {
            return true;
        }
    }

    /**
     * Показывать ли BOSS ID пользователю(настраивается в настройках для каждого подразделения)
     * @return bool|null
     */
    public static function showBossId()
    {
        global $USER;
        $rsUser = CUser::GetByID(intval($USER->GetID()));
        $arUser = $rsUser->Fetch();
        if (empty($arUser['UF_IDEA_DEPARTMENT'])) {
            $arUser['UF_IDEA_DEPARTMENT'] = 19935;
        }

        $arBranches = self::getBranch($arUser['UF_IDEA_DEPARTMENT']);
        $arDep = $arBranches[$arUser['UF_IDEA_DEPARTMENT']];

        $bHideBossId = null;
        $bHideBossIdParent = null;
        do {
            // Значение для текущего подразделения
            if ($arDep['DATA']['UF_REQ_BOSS_ID'] && $bHideBossId === null)
                $bHideBossId = ($arDep['DATA']['UF_REQ_BOSS_ID'] == 'Y');

            // Прокидываем родителя на текущий уровень для прохода в while
            $arDep = $arDep['PARENT'];

            // Значение родителя
            if ($arDep && $arDep['DATA']['UF_REQ_BOSS_ID'] && $bHideBossIdParent === null)
                $bHideBossIdParent = ($arDep['DATA']['UF_REQ_BOSS_ID'] == 'Y');

        } while ($arDep && ($bHideBossId === null || $bHideBossIdParent === null));

        if ($bHideBossId === null)
        {
            return $bHideBossIdParent;
        } else {
            return $bHideBossId;
        }
    }

    /**
     * Обязательно ли заполнение графика работы для пользователя(настраивается в настройках для каждого подразделения)
     * @return bool|null
     */
    public static function hideSchedule( $department = null )
    {
        if (is_null($department)) {
            global $USER;
            $rsUser = CUser::GetByID(intval($USER->GetID()));
            $arUser = $rsUser->Fetch();
            if (empty($arUser['UF_IDEA_DEPARTMENT']) || $USER->isAdmin()) {
                $arUser['UF_IDEA_DEPARTMENT'] = 19935;
            }
            $department = $arUser['UF_IDEA_DEPARTMENT'];
        }

        $arBranches = self::getBranch($department);
        $arDep = $arBranches[$department];

        $bHideScheduleId = null;
        $bHideScheduleIdParent = null;
        do {
            // Значение для текущего подразделения
            if ($arDep['DATA']['UF_HIDE_SCHEDULE'] && $bHideScheduleId === null)
                $bHideScheduleId = ($arDep['DATA']['UF_HIDE_SCHEDULE'] == 'Y');

            // Прокидываем родителя на текущий уровень для прохода в while
            $arDep = $arDep['PARENT'];

            // Значение родителя
            if ($arDep && $arDep['DATA']['UF_HIDE_SCHEDULE'] && $bHideScheduleIdParent === null)
                $bHideScheduleIdParent = ($arDep['DATA']['UF_HIDE_SCHEDULE'] == 'Y');

        } while ($arDep && ($bHideScheduleId === null || $bHideScheduleIdParent === null));

        if ($bHideScheduleId === null)
        {
            return $bHideScheduleIdParent;
        } else {
            return $bHideScheduleId;
        }
    }
    /**
     * Скрывать ли график работы для пользователя(настраивается в настройках для каждого подразделения)
     * @return bool|null
     */
    public static function hideScheduleInp( $department = null )
    {
        if (is_null($department)) {
            global $USER;
            $rsUser = CUser::GetByID(intval($USER->GetID()));
            $arUser = $rsUser->Fetch();
            if (empty($arUser['UF_IDEA_DEPARTMENT']) || $USER->isAdmin()) {
                $arUser['UF_IDEA_DEPARTMENT'] = 19935;
            }
            $department = $arUser['UF_IDEA_DEPARTMENT'];
        }

        $arBranches = self::getBranch($department);
        $arDep = $arBranches[$department];

        $bHideScheduleId = null;
        $bHideScheduleIdParent = null;
        do {
            // Значение для текущего подразделения
            if ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] && $bHideScheduleId === null)
                $bHideScheduleId = ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] == 'Y');

            // Прокидываем родителя на текущий уровень для прохода в while
            $arDep = $arDep['PARENT'];

            // Значение родителя
            if ($arDep && $arDep['DATA']['UF_HIDE_SCHEDULE_INP'] && $bHideScheduleIdParent === null)
                $bHideScheduleIdParent = ($arDep['DATA']['UF_HIDE_SCHEDULE_INP'] == 'Y');

        } while ($arDep && ($bHideScheduleId === null || $bHideScheduleIdParent === null));

        if ($bHideScheduleId === null)
        {
            return $bHideScheduleIdParent;
        } else {
            return $bHideScheduleId;
        }
    }

    /**
     * Метод создает HTML скелет и JS функционал для работы с департаментами.
     * Выводит три уровня депортамента для выбора подразделений.
     * При изменении одно из уровней мняются остальные вложенные списки.
     *
     * @param string $sSelectName Имя HTML поля в форме
     * @param int $iSelected ID выбранного департамента (выстраивается вся иерархия)
     * @param int $iAllowed ID департамента на уровне которого и выше блокируется выбор, а сам департамент становится выбранным на этом уровне
     * @param int $iMinLevel Минимальный уровень на котором начинет заполняться скрытое поле
     * @param bool|string $bInit Первичная инициализация (выводить JS на страницу), если выставить auto, то определяет автоматом
     * @param bool $bLockSelectors Блокировать ли select'ы при выводе
     */
    public static function getDepartmentsSelectHTMLStructure($sSelectName = 'f[UF_AUTHOR_DEP]', $iSelected = 0, $iAllowed = 0, $iMinLevel = 1, $bInit = 'auto', $bLockSelectors = false) {
        if($bInit == 'auto')
            $bInit = !($GLOBALS['DEPARTMENT_HTML_STRUCTURE_LOAD']);

        $iSelected = intval($iSelected);
        $iAllowed  = intval($iAllowed);
        $iMinLevel = intval($iMinLevel);
        // Если это первая инициализация, то выводим JS код
        if($bInit):
            $arDepartmentTree = self::getFullBranchTree();
            $arJSDepartmentObject = array('1' => array(),'2' => array(),'3' => array());
            foreach($arDepartmentTree['LINKS'] AS $arDepartment) {
                $arJSDepartmentObject[$arDepartment['DEPTH_LEVEL']][$arDepartment['NAME'].$arDepartment['ID']] = array(
                    'ID'          => $arDepartment['ID'],
                    'NAME'        => htmlspecialchars_decode($arDepartment['NAME']),
                    'DEPTH_LEVEL' => $arDepartment['DEPTH_LEVEL'],
                    'PARENT'      => $arDepartment['PARENT']['ID'],
                );

                $arJSDepartmentLinksObject[$arDepartment['ID']] = array(
                    'LEVEL' => $arDepartment['DEPTH_LEVEL'],
                    'CODE'  => $arDepartment['NAME'].$arDepartment['ID']
                );
            }
            ?>
            <script type="text/javascript">
                if(typeof(fullDepartmentTree) == 'undefined') {
                    var fullDepartmentTree = <?=json_encode($arJSDepartmentObject)?>,
                        fullDepartmentTreeLink = <?=json_encode($arJSDepartmentLinksObject)?>;
                }
                $(function(){
                    /**
                     *  Проходимся по каждому блоку выбора
                     */
                    $('.sys_department_selector_wrap').each(function(){
                        // Переменные этого блока
                        var selectWrap = $(this),
                            hiddenField = $('input:hidden', selectWrap),
                            selectedDepartments = {},
                            selected = 0,
                            allowed  = 0,
                            minLevel = 0,
                            levelOpen = 1,
                            levelBlocked = 0,
                            isLocked = false,
                            obPrevDepartmentIDSelected = {last:{id:null,lvl:null}},
                            fnInit = function(){
                                // Скидываем все настройки
                                selected    = parseInt(selectWrap.attr('select'));
                                allowed     = parseInt(selectWrap.attr('allowed'));
                                minLevel    = parseInt(selectWrap.attr('minlevel'));
                                levelOpen   = 1;
                                levelBlocked = 0;
                                isLocked    = selectWrap.attr('lock') === 'Y';
                                selectedDepartments = {};
                                obPrevDepartmentIDSelected = {last:{id:null,lvl:null}};


                                /*
                                 * Формируем информаци о блокированных уровнях и выбранных разделах
                                 */
                                if(allowed > 0) {
                                    var iBlockedID = allowed;

                                    // Все родители этого уровня  и данный департамент должны быть выбранны
                                    do {
                                        var level = parseInt(fullDepartmentTreeLink[iBlockedID].LEVEL),
                                            code = fullDepartmentTreeLink[iBlockedID].CODE;

                                        selectedDepartments[fullDepartmentTree[level][code]['ID']] = true;

                                        if(level > levelBlocked) {
                                            levelBlocked = level;
                                            levelOpen = level+1;
                                        }

                                        iBlockedID = parseInt(fullDepartmentTree[level][code].PARENT);
                                    } while(iBlockedID > 0)
                                }

                                /*
                                 * Формируем информацию о выбранных уровнях и выбранных департаментах
                                 */
                                if(selected > 0) {
                                    var sSelectedID = selected;

                                    // Проверяем в цикле доступность уровней и дополняем выбранные департаменты
                                    do {
                                        var level = parseInt(fullDepartmentTreeLink[sSelectedID].LEVEL),
                                            code = fullDepartmentTreeLink[sSelectedID].CODE;

                                        if(level > levelBlocked) {
                                            selectedDepartments[fullDepartmentTree[level][code]['ID']] = true;
                                            // Увеличиваем открытый уровень
                                            if(level >= levelOpen) {
                                                levelOpen = level+1;
                                            }
                                        }

                                        sSelectedID = parseInt(fullDepartmentTree[level][code].PARENT);
                                    } while(sSelectedID > 0);

                                }

                                //console.log(selectedDepartments);

                                /**
                                 * Заполянем селеторы
                                 */
                                for(var lvl in fullDepartmentTree) {
                                    if(lvl > levelOpen) {
                                        $('select[level="'+lvl+'"]', selectWrap).attr('disabled', 'disabled').addClass('disabled').show();
                                        continue;
                                    }

                                    var departmentSelector = $('<select>');
                                    if(lvl > levelBlocked && !isLocked) {
                                        departmentSelector.html('<option value=""></option>');
                                        $('select[level="'+lvl+'"]', selectWrap).removeAttr('disabled').removeClass('disabled');
                                    } else
                                        $('select[level="'+lvl+'"]', selectWrap).attr('disabled', 'disabled').addClass('disabled');

                                    // Проходимся по департаментам этого уровня
                                    for(var departmentKey in fullDepartmentTree[lvl]) {
                                        var department = fullDepartmentTree[lvl][departmentKey];

                                        // Проверяем выбран ли родитель департамента до этого
                                        if(department.PARENT > 0 && department.PARENT != obPrevDepartmentIDSelected[lvl-1].id)
                                            continue;

                                        var option = $('<option>').text(department.NAME).val(department['ID']);

                                        // Выбран?
                                        if(selectedDepartments[department['ID']] != undefined) {
                                            // Выставляем в дату объекта выбранный пункт
                                            $.data($('select[level="'+lvl+'"]', selectWrap)[0], 'selectedID', department['ID']);
                                            // Отмечаем выбранный пункт
                                            option.attr('selected', 'selected');
                                            var lastSelectedDepartment = {
                                                lvl: lvl,
                                                id: department['ID']
                                            }
                                            obPrevDepartmentIDSelected.last = lastSelectedDepartment;
                                            obPrevDepartmentIDSelected[lvl] = lastSelectedDepartment;
                                        } else {
                                            if(lvl <= levelBlocked)
                                                continue;
                                        }

                                        // Добавляем департамент в очередь на вывод
                                        option.appendTo(departmentSelector);
                                    }

                                    // Выводим данный уровень департаментов в HTML
                                    $('select[level="'+lvl+'"]', selectWrap).show().html(departmentSelector.html()).change();
                                    // Очищаем
                                    departmentSelector = null;
                                }


                                // Устанавливаем у скрытого элемента ID выбранного департамента
                                if($('select[level="'+minLevel+'"]', selectWrap).length && $('select[level="'+minLevel+'"]', selectWrap).val().length)
                                    hiddenField.val(obPrevDepartmentIDSelected.last.id).change();
                                else
                                    hiddenField.val('').change();

                            };



                        // Блочим от повторной инициализации
                        if($.data(selectWrap[0], 'init')) return;
                        $.data(selectWrap[0], 'init', true);

                        // Инициализаруем
                        fnInit();


                        // Подвешиваем обновление инициализации
                        selectWrap.on('refresh', function(){
                            fnInit();
                        });


                        // Подвешиваем события изменения
                        $('select', selectWrap).change(function(){
                            var lvl = parseInt($(this).attr('level')),
                                id = parseInt($(this).val());

                            if(isNaN(id))
                                id = null;

                            // Проверяем уровень на блокировку
                            if(levelBlocked > lvl) {
                                $(this).val($.data(this, 'selectedID'));
                                //console.log('LVL IS BLOCKED!');
                                return;
                            }

                            // Записываем новое значение выбранного ИД в свойства селекта
                            $.data(this, 'selectedID', id);
                            // Устанавливаем значение скрытого элемента
                            if(lvl >= minLevel && $('select[level="'+minLevel+'"]', selectWrap).val().length) {
                                if(!id && lvl > 1)
                                    hiddenField.val($('select[level="'+(lvl-1)+'"]', selectWrap).val());
                                else
                                    hiddenField.val(id).change();
                            } else {
                                hiddenField.val('').change();
                            }

                            // Обновляем нижние уровни департаментов
                            for(var treeLvl in fullDepartmentTree) {
                                // Проверяем уровень
                                if(treeLvl <= lvl)
                                    continue;

                                // Если это следующий уровень и в этом уровне выбран элемент, то даем к нему доступ и заполняем его
                                if(treeLvl == lvl+1 && id) {
                                    var departmentSelector = $('<select>').html('<option value=""></option>');

                                    // Заполняем список
                                    for(var departmentKey in fullDepartmentTree[treeLvl]) {
                                        var department = fullDepartmentTree[treeLvl][departmentKey];

                                        // Проверяем является ли
                                        if(department.PARENT > 0 && department.PARENT != id)
                                            continue;

                                        var option = $('<option>').text(department.NAME).val(department.ID);


                                        // Добавляем департамент в очередь на вывод
                                        option.appendTo(departmentSelector);
                                    }

                                    // Вставляем список и открываем доступ
                                    $('select[level="'+treeLvl+'"]', selectWrap).html(departmentSelector.html()).removeAttr('disabled').removeClass('disabled');
                                    departmentSelector = null;

                                } else {
                                    // очищаем список и отключаем от него доступ
                                    $('select[level="'+treeLvl+'"]', selectWrap)
                                        .val('')
                                        .attr('disabled', 'disabled')
                                        .addClass('disabled')
                                        .find('option').remove();
                                }

                            }
                            //console.log(hiddenField.val(), id)
                        });
                    });
                });

            </script>
        <?endif;

        // Увеличиваем кол-во инициализаций
        $GLOBALS['DEPARTMENT_HTML_STRUCTURE_LOAD']++;

        // Выводим HTML
        $arDepartmentTree = $arDepartmentTree?:self::getFullBranchTree();
        ?>
        <div class="sys_department_selector_wrap" select="<?=$iSelected?>" allowed="<?=$iAllowed?>" minlevel="<?=$iMinLevel?>" lock="<?=$bLockSelectors?'Y':'N'?>">
            <input
                    type="hidden"
                    name="<?=$sSelectName?>"
                    value="<?=$arDepartmentTree['LINKS'][$iSelected]['DEPTH_LEVEL'] >= $iMinLevel? $iSelected : ''?>"
            >
            <select level="1" style="display: none"></select>
            <select level="2" style="display: none"></select>
            <select level="3" style="display: none"></select>
        </div>
        <?
    }


    /**
     * Метод получает все сохраненные фильтры пользователя
     *
     * @param null|int $iFilterID
     * @return array
     */
    public static function getFilterManager($iFilterID = null) {
        $arFilterManagerList = array();

        // Проверяем КЕШ
        if(empty(self::$cache['filterManager']) && self::$cache['filterManager'] !== false) {
            $obCIBlockElement = new CIBlockElement();

            $rsResult = $obCIBlockElement->GetList(
                array('SORT' => 'ASC', 'NAME' => 'ASC'),
                array(
                    'IBLOCK_ID'   => self::getIBlockID('umsh', 'userFilterManager'),
                    'CREATED_BY'  => $GLOBALS['USER']->GetID()?:-1,
                ),
                false,
                false,
                array('ID', 'NAME', 'DETAIL_TEXT')
            );

            while($arRow = $rsResult->GetNext()) {
                $arDataInfo = array(
                    'ID'    => $arRow['ID'],
                    'NAME'  => $arRow['NAME'],
                    'SORT'  => $arRow['SORT'],
                );
                self::$cache['filterManager'][$arRow['ID']] = $arDataInfo + array(
                        'DATA'  => unserialize($arRow['~DETAIL_TEXT'])
                            + array('DATA' => $arDataInfo),
                    );
            }

            if(empty(self::$cache['filterManager']))
                self::$cache['filterManager'] = false;
        }

        if(self::$cache['filterManager'])
            $arFilterManagerList = $iFilterID? self::$cache['filterManager'][$iFilterID] : self::$cache['filterManager'];


        return $arFilterManagerList;
    }


    /**
     * В зависимости от типа правила возвращает его префикс для поля
     * PS: не забываем проверять на EMPTY и NOT_EMPTY в коде, т.к. нужно в поиск установить false
     */
    static public function getSearchPrefixByType($sRuleType) {
        $sPrefix = '';

        if($sRuleType == 'EQUAL')       $sPrefix = '';
        elseif($sRuleType == 'NOT_EQUAL')   $sPrefix = '!';
        elseif($sRuleType == 'CONTAINS')    $sPrefix = '%';
        elseif($sRuleType == 'NOT_CONTAINS')$sPrefix = '!%';
        elseif($sRuleType == 'LESS')        $sPrefix = '<';
        elseif($sRuleType == 'LESS_EQUAL')  $sPrefix = '<=';
        elseif($sRuleType == 'GREAT')       $sPrefix = '>';
        elseif($sRuleType == 'GREAT_EQUAL') $sPrefix = '>=';
        elseif($sRuleType == 'EMPTY')       $sPrefix = '';
        elseif($sRuleType == 'NOT_EMPTY')   $sPrefix = '!';

        return $sPrefix;
    }


    /**
     * Выстраивает
     *
     * @param array $arNewRulesList             Массив добавляемых правил (неформатированных)
     * @param array $arCurrentFormattedRules    Массив текущих форматированных правил
     * @param bool $bForcedFieldRewrite         Очищать ли предыдущие правила, если такое поле уже есть в списке
     *
     * @return array                            Массив форматированных правил
     */
    static public function formatListToSearchRules(array &$arNewRulesList, array $arCurrentFormattedRules, $bForcedFieldRewrite = false, $arFormattedViewFieldsProperties = null) {
        $arForcedList = array();
        if(!is_array($arCurrentFormattedRules))
            $arCurrentFormattedRules = array();

        if(empty($arFormattedViewFieldsProperties))
            $arFormattedViewFieldsProperties = self::getIdeaViewFieldProperties();

        foreach($arNewRulesList AS $iKey => $arRule) {
            $arFieldData = $arFormattedViewFieldsProperties[$arRule['FIELD']];
            $arFieldTypeRules = $arFieldData['FILTER']['RULES'][$arRule['TYPE']]?:false;
            $bNeedValue = array_key_exists('WITH_VALUE', $arFieldTypeRules)
                ? $arFieldTypeRules['WITH_VALUE']
                : !in_array($arRule['TYPE'], array('ALL', 'EMPTY', 'NOT_EMPTY'));

            // Проверяем переданные даныне, если не подходит, то очищаем
            if(
                empty($arRule)
                || empty($arRule['FIELD'])
                //|| !in_array($arRule['TYPE'], CIdeaConst::$arAllowedRuleType)
                || !in_array($arRule['TYPE'], $arFieldData['FILTER']['TYPES'])
                || (empty($arRule['VALUE']) && $bNeedValue)
                || empty($arFormattedViewFieldsProperties[$arRule['FIELD']]['FILTER']['ENABLE'])
                || empty($arFormattedViewFieldsProperties[$arRule['FIELD']]['FILTER']['FIELD'])
            ) {
                unset($arNewRulesList[$iKey]);
                continue;
            }
            $arRealFilterFieldName = $arFormattedViewFieldsProperties[$arRule['FIELD']]['FILTER']['FIELD'];

            // Принудительно очищаем
            if($bForcedFieldRewrite && empty($arForcedList[$arRule['FIELD']])) {
                $arForcedList[$arRule['FIELD']] = true;
                $arCurrentFormattedRules[$arRealFilterFieldName] = array();
            }

            // Если нужно очистить предыдущие фильтры или такого поля в правилах еще нет,
            // то восстанавливаем пустой список правил
            if($arRule['TYPE'] == 'ALL' || !isset($arCurrentFormattedRules[$arRealFilterFieldName]))
                $arCurrentFormattedRules[$arRealFilterFieldName] = array();


            // Добавляем в итоговый массив
            if($arRule['TYPE'] != 'ALL') {
                self::parseFieldRuleValue(
                    $arRule['FIELD'],
                    $arRule['TYPE'],
                    $arRule['VALUE'],
                    $arCurrentFormattedRules[$arRealFilterFieldName],
                    $arFormattedViewFieldsProperties
                );
            }

        }
        unset($iKey, $arRule);

        return $arCurrentFormattedRules;
    }


    /**
     *
     */
    public static function parseFieldRuleValue($sField, $sType, $mValue, &$arFieldRuleContainer, $arFormattedViewFieldsProperties = null){
        if(empty($arFormattedViewFieldsProperties))
            $arFormattedViewFieldsProperties = self::getIdeaViewFieldProperties();

        $mValue = trim($mValue);
        switch($arFormattedViewFieldsProperties[$sField]['TYPE']) {
            case 'DATE':
                $mValue = strtolower($mValue);
                if(in_array($mValue, array('прошлый год','год','прошлый месяц','месяц', 'вчера', 'сегодня'))) {
                    // Проверяем тип, если неподходящий, то пропускаем
                    if($sType != 'EQUAL') // !in_array($sType, array('EQUAL', 'NOT_EQUAL'))
                        break;

                    // Получаем временные метки
                    if($mValue == 'сегодня') {
                        $arFieldRuleContainer[$sType] = date('d.m.Y');

                    } elseif($mValue == 'вчера') {
                        $arFieldRuleContainer[$sType] = date('d.m.Y', time() - 24*60*60);

                    } else {
                        list($m,$y) = explode('.', date('m.Y'));
                        switch($mValue) {
                            case 'прошлый год':
                                $sDateFrom  = '01.01.'.($y-1);
                                $sDateTo    = '31.12.'.($y-1);
                                break;
                            case 'год':
                                $sDateFrom  = '01.01.'.$y;
                                $sDateTo    = '31.12.'.$y;
                                break;
                            case 'прошлый месяц':
                                $sDateFrom  = date('d.m.Y', mktime(0,0,0,$m-1,1));
                                $sDateTo    = date('d.m.Y', mktime(0,0,-1,$m,1));
                                break;
                            case 'месяц':
                                $sDateFrom  = '01.'.$m.'.'.$y;
                                $sDateTo    = date('d.m.Y', mktime(0,0,-1,$m+1,1));
                                break;
                        }

                        $arFieldRuleContainer['GREAT_EQUAL'] = $sDateFrom;
                        $arFieldRuleContainer['LESS_EQUAL'] = $sDateTo;
                    }

                } else {
                    $arFieldRuleContainer[$sType] = $mValue;
                }

                break;
            case 'DIALOG':
                // Проверяем данные
                if(!is_array($mValue) && preg_match('/^\[(\d+)\].*/', $mValue, $arMatch))
                    $mValue = $arMatch[1];
            default:
                $arFieldRuleContainer[$sType] = $mValue;
        }
    }


    /**
     * Функция возвращает слово из масива с верным окончанием в зависимости от числа
     *
     * @param int   $iNumber   число для поиска верного ему окончания
     * @param array $arEndings массив со словами с разными окончаниями ["1,21,31", "2,3,4,22,32", "остальное (5,11,12,13,26)"]
     *
     * @return string
     */
    public static function wordByCount($iNumber, $arEndings) {
        $sResult = '';
        $iNumber = $iNumber % 100;

        if($iNumber >= 11 && $iNumber <= 19)
            $sResult = $arEndings[2];
        else {
            switch ($iNumber % 10) {
                case(1):
                    $sResult = $arEndings[0];
                    break;
                case(2):
                case(3):
                case(4):
                    $sResult = $arEndings[1];
                    break;
                default:
                    $sResult = $arEndings[2];
            }
        }

        return $sResult;
    }


    /**
     * Метод исправляет дату без ведущих нулей
     *
     * @param string $sDate
     *
     * @return string
     */
    public static function dateCorrection($sDate) {
        preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})(?:[^\d]+(\d{1,2})(?::(\d{1,2}))?(?::(\d{1,2}))?)?$/', trim($sDate), $arDateMatch);

        if(!empty($arDateMatch)) {
            $sNewDate = '';
            foreach($arDateMatch AS $iKey => &$sDatePart) {
                if(!$iKey) continue;

                // Дополняем ведущими нулями
                if($iKey != 3) {
                    if(strlen($sDatePart) == 1)
                        $sDatePart = '0'.$sDatePart;
                } else {
                    if(strlen($sDatePart) == 2)
                        $sDatePart = '20'.$sDatePart;
                }

                // Вставляем разделитель
                if($iKey > 1) {
                    if($iKey < 4)
                        $sNewDate .= '.';
                    elseif($iKey == 4)
                        $sNewDate .= ' ';
                    elseif($iKey > 4)
                        $sNewDate .= ':';
                }

                // Вставляем часть даты
                $sNewDate .= $sDatePart;
            }

            $sDate = $sNewDate;
        }

        return trim($sDate);
    }


    /**
     * mb_ucfirst - преобразует первый символ в верхний регистр
     * @param string $str - строка
     * @param string $encoding - кодировка, по-умолчанию UTF-8
     * @return string
     */
    public static function mb_ucfirst($str, $encoding='UTF-8') {
        if(!BX_UTF)
            return ucfirst($str);

        $str = mb_ereg_replace('^[\ ]+', '', $str);
        $str = self::mb_strtoupper(self::mb_substr($str, 0, 1, $encoding), $encoding).
            self::mb_substr($str, 1, self::mb_strlen($str), $encoding);
        return $str;
    }


    /**
     *
     */
    public static function mb_strlen($v, $encoding = 'UTF-8') {
        return BX_UTF?mb_strlen($v, $encoding):strlen($v);
    }


    /**
     *
     */
    public static function mb_substr($v,$s,$l, $encoding = 'UTF-8') {
        return BX_UTF?mb_substr($v,$s,$l, $encoding):substr($v,$s,$l);
    }


    /**
     *
     */
    public static function mb_strtoupper($v, $encoding = 'UTF-8') {
        return BX_UTF?mb_strtoupper($v,$encoding):strtoupper($v);
    }


    /**
     *
     */
    public static function mb_strtolower($v, $encoding = 'UTF-8') {
        return BX_UTF?mb_strtolower($v,$encoding):strtolower($v);
    }


    /**
     * Формирует из имени и отчества инициалы
     *
     * @param $sName
     * @param $sSecondName
     * @return string
     */
    public static function getInitialsByName($sName, $sSecondName) {
        $sResult = '';
        if(!empty($sName))
            $sResult .= self::mb_strtoupper(self::mb_substr($sName, 0, 1, 'UTF-8')) .'.';
        if(!empty($sSecondName))
            $sResult .= ' '. self::mb_strtoupper(self::mb_substr($sSecondName, 0, 1), 'UTF-8') .'.';

        return trim($sResult);
    }

    /**
     * Возвращает массив разбитого ФИО полученого из строки
     *
     * @param $sFullName
     * @return string
     */
    public static function getNameDataByString($sFullName) {
        $sFullName = trim(preg_replace(array('/,+/', '/\.+/', '/\s+/'), ' ', $sFullName));
        list($sLastName, $sName, $sSecondName) = explode(' ', $sFullName);
        // Фамилия
        $sLastName = self::mb_ucfirst($sLastName);

        // Ищем отчество
        if(empty($sSecondName) && self::mb_strlen($sName) == 2 && $sName == self::mb_strtoupper($sName)) {
            $sSecondName = self::mb_substr($sName, 1,1);
            $sName       = self::mb_substr($sName, 0,1);
        }

        // Имя
        $sName = $sName?self::mb_ucfirst($sName):'';
        // Отчество
        $sSecondName = $sSecondName?self::mb_ucfirst($sSecondName):'';

        return array('LAST_NAME'=> $sLastName, 'NAME'=> $sName, 'SECOND_NAME'=> $sSecondName);
    }

    /**
     * Метод вывода строки времени с разбивкой от секунд до дней
     *
     * @param int  $iTimeSpent
     * @param bool $bShort
     * @return string
     */
    public static function humanTimeSpent($iTimeSpent, $bShort = true) {
        $sResult = '';
        $iMin  = 60;
        $iHour = $iMin*60;
        $iDay  = $iHour*24;

        // Дни
        if($iTimeSpent > $iDay) {
            $iBlock = floor($iTimeSpent / $iDay);
            $iTimeSpent = $iTimeSpent - $iBlock*$iDay;
            $sResult .= $iBlock .(!$bShort?' '.self::wordByCount($iBlock, array('день', 'дня', 'дней')):'д') .' ';
        }
        // Часы
        if($iTimeSpent >= $iHour) {
            $iBlock = floor($iTimeSpent / $iHour);
            $iTimeSpent = $iTimeSpent - $iBlock*$iHour;
            $sResult .= $iBlock .(!$bShort?' '.self::wordByCount($iBlock, array('час', 'часа', 'часов')):'ч') .' ';
        }
        // Минуты
        if($iTimeSpent >= $iMin) {
            $iBlock = floor($iTimeSpent / $iMin);
            $iTimeSpent = $iTimeSpent - $iBlock*$iMin;
            $sResult .= $iBlock .(!$bShort?' '.self::wordByCount($iBlock, array('минута', 'минуты', 'минут')):'м') .' ';
        }
        // Секунды
        $sResult .= $iTimeSpent .(!$bShort?' '.self::wordByCount(intval($iTimeSpent), array('секунда', 'секунды', 'секунд')):'с');


        return trim($sResult);
    }

    /**
     * Функция отсылает запрос к странице
     * и прекращает свою работу не дожидаясь ответа
     *
     * @param string $sURL
     * @throws Exception
     */
    public static function touchWeb($sURL) {
        $sURL = trim($sURL);
        if(empty($sURL))
            throw new Exception('Запрашивается пустая ссылка.');

        /*
         * Выполняем через EXEC... тогда не придется ждать!
         * Если данный вариант заблокирован на продакшене, то нужно вернуть cURL
         */
        exec('wget -q --spider "'.$sURL .'" > /dev/null 2>&1 &');
        return;

        // создание нового ресурса cURL
        $ch = curl_init();

        // установка URL и других необходимых параметров
        curl_setopt_array($ch, array(
            CURLOPT_URL             => $sURL,
            //CURLOPT_HEADER          => false,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_FORBID_REUSE    => true,
            CURLOPT_FRESH_CONNECT   => true,
            //CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 10,
        ));

        // загрузка страницы и выдача её браузеру
        curl_exec($ch);

        // завершение сеанса и освобождение ресурсов
        curl_close($ch);
    }

    /**
     * Название временных промежутков на русском языке
     * @param bool $bShort
     * @return array
     */
    public static function getTimePeriodName($bShort = false) {
        if($bShort)
            return array(
                'year' => array(
                    '0' => 'Г'
                ),
                'halfYear' => array(
                    '0' => 'Пг',
                    '1' => '1 пг',
                    '2' => '2 пг'
                ),
                'quarter' => array(
                    '0' => 'Кв',
                    '1' => 'I кв',
                    '2' => 'II кв',
                    '3' => 'III кв',
                    '4' => 'IV кв'
                ),
                'month' => array(
                    '0' => 'Мес',
                    '1' => 'янв',
                    '2' => 'фев',
                    '3' => 'март',
                    '4' => 'апр',
                    '5' => 'май',
                    '6' => 'июнь',
                    '7' => 'июль',
                    '8' => 'авг',
                    '9' => 'сен',
                    '10' => 'окт',
                    '11' => 'ноя',
                    '12' => 'дек'
                )
            );


        return array(
            'year' => array(
                '0' => 'Год'
            ),
            'halfYear' => array(
                '0' => 'Полугодие',
                '1' => 'I полугодие',
                '2' => 'II полугодие'
            ),
            'quarter' => array(
                '0' => 'Квартал',
                '1' => 'I квартал',
                '2' => 'II квартал',
                '3' => 'III квартал',
                '4' => 'IV квартал'
            ),
            'month' => array(
                '0' => 'Месяц',
                '1' => 'январь',
                '2' => 'февраль',
                '3' => 'март',
                '4' => 'апрель',
                '5' => 'май',
                '6' => 'июнь',
                '7' => 'июль',
                '8' => 'август',
                '9' => 'сентябрь',
                '10' => 'октябрь',
                '11' => 'ноябрь',
                '12' => 'декабрь'
            )
        );
    }


    /**
     * Методы для работы с историей статусов идей
     * ===========================================================================
     */

    /**
     * Метод получает все статусы идей из настроек блога
     */
    public static function getStatusesCodeForIdes() {
        if(empty(self::$cache['statuses'])) {
            foreach(CIdeaManagment::getInstance()->Idea()->GetStatusList()?:array() AS $arStatusData) {
                self::$cache['statuses']['ID'][$arStatusData['ID']] = $arStatusData;
                self::$cache['statuses']['CODE'][strtoupper($arStatusData['XML_ID'])] = &self::$cache['statuses']['ID'][$arStatusData['ID']];
            }
        }
    }

    /**
     * Метод получает список статусов
     */
    public static function getStatusesListByCode() {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        return self::$cache['statuses']['CODE'];
    }

    /**
     * Метод получает список статусов
     */
    public static function getStatusesListById() {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        return self::$cache['statuses']['ID'];
    }

    /**
     * Метод возвращает ID статуса по его коду
     * @param $sStatusCode
     * @return int
     */
    public static function getStatusIdByCode($sStatusCode) {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        $iStatusId = !empty(self::$cache['statuses']['CODE'][$sStatusCode])
            ?self::$cache['statuses']['CODE'][$sStatusCode]['ID']
            :-1;

        if($iStatusId < 1)
            die('Ошибка запроса информации по статусу модуля. Код: '. $sStatusCode);

        return $iStatusId;
    }

    /**
     * Метод возвращает Code статуса по его ID
     * @param $iStatusID
     * @return string
     */
    public static function getStatusCodeById($iStatusID) {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        $sResult = !empty(self::$cache['statuses']['ID'][$iStatusID])
            ?self::$cache['statuses']['ID'][$iStatusID]['XML_ID']
            :'';

        if(empty($sResult))
            die('Ошибка запроса информации по статусу модуля. ID: '. $iStatusID);

        return $sResult;
    }

    /**
     * Метод возвращает имя статуса по его ID
     * @param $iStatusID
     * @return string
     */
    public static function getStatusNameById($iStatusID) {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        $sResult = !empty(self::$cache['statuses']['ID'][$iStatusID])
            ?self::$cache['statuses']['ID'][$iStatusID]['VALUE']
            :'';

        if(empty($sResult))
            die('Ошибка запроса информации по статусу модуля. ID: '. $iStatusID);

        return $sResult;
    }

    /**
     * Метод возвращает имя статуса по его коду
     * @param $sStatusCode
     * @return string
     */
    public static function getStatusNameByCode($sStatusCode) {
        if(empty(self::$cache['statuses']))
            self::getStatusesCodeForIdes();

        $sResult = !empty(self::$cache['statuses']['CODE'][$sStatusCode])
            ?self::$cache['statuses']['CODE'][$sStatusCode]['VALUE']
            :'';

        if(empty($sResult))
            die('Ошибка запроса информации по статусу модуля. Code: '. $sStatusCode);

        return $sResult;
    }

    /**
     * Метод получает статусы для всех переданных ID идей
     * @param array $arID Массив ID идей для которых осуществляется поиск
     * @param bool $bUseCache
     *
     * @return bool|int false при ошибке | кол-во идей для которых нашли статусы
     */
    public static function findIdeasStatuses($arID, $bUseCache = true) {
        // Проверяем id
        if(!is_array($arID) || empty($arID))
            return false;

        $cStatusFined = 0;

        // Проверяем кеш и удаляем уже найденные ID
        foreach($arID AS $k => $id) {
            if($bUseCache && isset(self::$cache['ideasStatuses'][$id])) {
                $cStatusFined += count(self::$cache['ideasStatuses'][$id]);
                unset($arID[$k]);
            } else {
                self::$cache['ideasStatuses'][$id] = array();
            }
        }

        // Получаем все статусы идей
        if(!empty($arID)) {
            $el = new CIBlockElement();
            $rsStatuses = $el->GetList(
                array('created_date' => 'ASC'),
                array(
                    'ID',
                    'XML_ID' => $arID,
                    'IBLOCK_ID' => self::getIBlockID('umsh', 'umshIdeaStatusChanges')
                ),
                false,
                false,
                array(
                    'ID',
                    'XML_ID',
                    'CODE',
                    'DATE_CREATE',
                    'MODIFIED_BY'
                )
            );

            // Проходимся по результатам
            while($arStatus = $rsStatuses->Fetch()) {
                $cStatusFined++;
                $timeCreate = MakeTimeStamp($arStatus['DATE_CREATE']);
                $iPriority = self::getStatusPriority($arStatus['CODE']);
                $arStatus['TIME_STAMP'] = $timeCreate;
                $arStatus['PRIORITY'] = $iPriority;

                // Запись в кеш
                self::$cache['ideasStatuses']
                [$arStatus['XML_ID']]
                [$timeCreate + $iPriority]
                    = $arStatus;
            }

            // Сортируем статусы (для багфикса ввода левых данных (0013г, 0213г и тд))
            foreach($arID AS $iID) {
                if(isset(self::$cache['ideasStatuses'][$iID])) {
                    ksort(self::$cache['ideasStatuses'][$iID]);
                }
            }
        }

        // Количество найденных статусов
        return $cStatusFined;
    }

    /**
     * Метод получения приоритета идеи
     */
    public static function getStatusPriority($iStatusId) {
        switch($iStatusId) {
            case self::getStatusIdByCode('NEW'):
                return CIdeaConst::C_STATUS_PRIORITY_NEW;
            case self::getStatusIdByCode('FAILED'):
                return CIdeaConst::C_STATUS_PRIORITY_FAILED;
            case self::getStatusIdByCode('REWORK'):
                return CIdeaConst::C_STATUS_PRIORITY_REWORK;
            case self::getStatusIdByCode('ACCEPT'):
                return CIdeaConst::C_STATUS_PRIORITY_ACCEPT;
            case self::getStatusIdByCode('PROCESSING'):
                return CIdeaConst::C_STATUS_PRIORITY_PROCESSING;
            case self::getStatusIdByCode('COMPLETED'):
                return CIdeaConst::C_STATUS_PRIORITY_COMPLETED;
            case self::getStatusIdByCode('FINISH'):
                return CIdeaConst::C_STATUS_PRIORITY_FINISH;
            default:
                return 0;
        }
    }

    public static function getTransferHistory($nID) {
        $el = new CIBlockElement();
        $ibHistory = self::getIBlockID('umsh', 'umshIdeaTransferChanges');
        $rsStatusesInHistory = $el->GetList(
            false,
            array(
                'IBLOCK_ID'     => $ibHistory,
                'SECTION_ID' => self::getSectionId($ibHistory, "transfer"),
                'CODE'   => $nID
            ),
            false,
            false,
            array(
                'ID',
                'SORT',
                'CREATED_BY',
                'TIMESTAMP_X',
                'PROPERTY_DEPARTMENT'
            )
        );
        $result = [];
        while($arCurrentTransferInHistory = $rsStatusesInHistory->Fetch()) {
            $rsUser = CUser::GetByID($arCurrentTransferInHistory['CREATED_BY']);
            $arUser = $rsUser->Fetch();
            if($arUser) {
                $name = $arUser['NAME']." ".$arUser['SECOND_NAME']." ".$arUser['LAST_NAME'];
            } else {
                $name = "";
            }
            //debugmessage(unserialize($arCurrentTransferInHistory['PROPERTY_DEPARTMENT_VALUE'])['VALUE']);
            //debugmessage($arCurrentTransferInHistory);
            $result[] = [
                'DATE'          => $arCurrentTransferInHistory['TIMESTAMP_X'],
                'DEPARTMENTS'   => $arCurrentTransferInHistory['PROPERTY_DEPARTMENT_VALUE'],
                'AUTHOR'        => $arCurrentTransferInHistory['CREATED_BY'],
                'AUTHOR_NAME'   => $name,
                'CROSS'         => intval($arCurrentTransferInHistory['SORT'])===1 ? true:false
            ];
        }
        return $result;
    }

    /**
     * Метод возвращает количество дней в статусе на доработке
     *
     */
    public static function reworkDuration($ideaId) {
        // Все статусы данной идеи
        $arIdeaStatuses = CIdeaTools::getIdeaStatuses($ideaId)?:array();
        $iReworkDate = 0;
        $iOtherDate = 0;

        // Проходимся по статусам и получаем дату "На доработку" и другой статус
        foreach ($arIdeaStatuses AS $arStatus) {
            // Если это статус "На доработке" и он был раньше уже найденого "На доработке", то записываем его
            if ($arStatus['CODE'] == CIdeaTools::getStatusIdByCode('REWORK')) {
                if (!$iReworkDate || $arStatus['TIME_STAMP'] < $iReworkDate)
                    $iReworkDate = $arStatus['TIME_STAMP'];
            } // Если этот статус выше по приоритету, чем "На доработке" и он был раньше, чем предыдущий найденый, то записываем его
            elseif (CIdeaConst::C_STATUS_PRIORITY_REWORK < $arStatus['PRIORITY'] && (!$iOtherDate || $arStatus['TIME_STAMP'] < $iOtherDate)) {
                $iOtherDate = $arStatus['TIME_STAMP'];
            }
        }

        // Если время "На доработке" найдено, то расчитываем кол-во дней в этом состоянии
        if ($iReworkDate) {
            return ceil((($iOtherDate ?: time()) - $iReworkDate) / (24 * 60 * 60)) /*+ 1*/;
        } else {
            return 0;
        }
    }

    /**
     * Метод возвращает количество дней, когда идея была в в определенном статусе $statusCode (например, 'REWORK')
     *
     * Параметры:
     * ID идеи
     * Код статуса ('REWORK', 'PROCESSING' и т.д)
     * Дата для лимитирования - TimeStamp
     *
     * TODO Написать правильные комменты
     */
    public static function processDuration($ideaId, $statusCode, $iLimitRightDate = false) {
        // Все статусы данной идеи
        $arIdeaStatuses = CIdeaTools::getIdeaStatuses($ideaId)?:array();
        $iLeftDate = 0;
        $iRightDate = 0;
        $inStatus = false;
        $iPeriod = 0;

        // Проходимся по статусам и получаем дату $statusCode и другой статус
        foreach ($arIdeaStatuses AS $arStatus) {
            // Если это наш статус и он был раньше уже найденого такого статуса, то записываем его
            if ($arStatus['CODE'] == CIdeaTools::getStatusIdByCode($statusCode) && !$inStatus) {
                if (!$iLimitRightDate || ($iLimitRightDate && $arStatus["TIME_STAMP"] <= $iLimitRightDate)) {
                    $iLeftDate = $arStatus["TIME_STAMP"];
                    $inStatus = true;
                } else {
                    $iLeftDate = 0;
                    $iRightDate = 0;
                    break;
                }
            } // Если этот статус был раньше, чем предыдущий найденый, то записываем его
            elseif ($inStatus && $iLeftDate && $arStatus["TIME_STAMP"] >= $iLeftDate && $arStatus['CODE'] != CIdeaTools::getStatusIdByCode($statusCode)) {
                if (!$iLimitRightDate || ($iLimitRightDate && $arStatus["TIME_STAMP"] <= $iLimitRightDate)) {
                    $iRightDate = $arStatus["TIME_STAMP"];
                } else {
                    $iRightDate = $iLimitRightDate;
                }
                $inStatus = false;
            }

//            debugfile("\n-------hello--------\n"
//                    ."arStatus[CODE] = " . $arStatus['CODE']
//                    . "\nИдея №".$ideaId
//                    . "\nleftdate = " . $iLeftDate
//                    . "\nrightdate = " . $iRightDate
//                    . "\nПериод = " . $iPeriod
//            .'\n-------bye-------\n', "umsh_processduration.php");
            if ($iLeftDate && $iRightDate) {
                $iPeriod += ceil(($iRightDate - $iLeftDate + 1) / (24 * 60 * 60));
                $iLeftDate = 0;
                $iRightDate = 0;
            }
        }

        if ($inStatus && $iLeftDate && !$iRightDate) {
            $iPeriod += ceil((time() - $iLeftDate) / (24 * 60 * 60));
        }

        // Если время "В работе" найдено, то расчитываем кол-во дней в этом состоянии
        if ($iPeriod) {
            return $iPeriod;
        } else {
            return 0;
        }
    }



    /**
     * Метод получает статусы для ID Идеи
     * @param int $nID ID Идеи
     *
     * @param mixed $date Дата на которую нужно найти самую актуальную инфу по статусу
     *                      Строка - d.m.Y
     *                      Число - unix stamp
     *                      Массив - содержит ключи FROM и TO, в формате d.m.Y или unix stamp
     * @param bool|int $nStatusID ID статуса который искать, если не задан то возвращает самый актуальный
     *
     * @return bool|array false при ошибке | массив со статусами идей
     */
    public static function getIdeaStatuses($nID, $date = false, $nStatusID = false) {
        if(!isset(self::$cache['ideasStatuses'][$nID]))
            return false;

        $arResult = self::$cache['ideasStatuses'][$nID];

        if($nStatusID) {
            foreach($arResult AS $k => $arStatus) {
                if($arStatus['CODE'] != $nStatusID)
                    unset($arResult[$k]);
            }
        }

        if(!empty($date) && (!is_array($date) || (is_array($date) && !empty($date['FROM']) && !empty($date['TO'])))) {
            if(is_array($date)) {
                $nFrom = intval($date['FROM']) == $date['FROM']? $date['FROM'] : MakeTimeStamp(array_shift(explode(' ', $date['FROM'])));
                $nTo = intval($date['TO']) == $date['TO']? $date['TO'] : MakeTimeStamp(array_shift(explode(' ', $date['TO'])) .' 23:59:59');

                foreach($arResult AS $k => $arStatus) {
                    if($arStatus['TIME_STAMP'] < $nFrom || $arStatus['TIME_STAMP'] > $nTo)
                        unset($arResult[$k]);
                }

            } else {

                // Переводим дату в unix stamp
                if($date != intval($date))
                    $date = MakeTimeStamp(array_shift(explode(' ', $date)) .' 23:59:59');

                $arParse = $arResult;
                $arResult = false;

                // Проходимся по статусам в поисках самого актуального
                foreach($arParse AS $arStatus) {
                    if(
                        $arStatus['TIME_STAMP'] < $date
                        && (!$arResult || $arResult['TIME_STAMP'] < $arStatus['TIME_STAMP'])
                        && (!$nStatusID || $nStatusID == $arStatus['CODE'])
                    ) {
                        $arResult = $arStatus;
                    }
                }
            }
        }


        return $arResult;
    }

    /**
     * Метод обновления всех статусов идей
     * @author Smagin AS
     *
     * !!!! УДАЛЯЕТ ВСЕ ТЕКУЩИЕ СТАТУСЫ !!!!!
     *
     * Использовать в крайнем случаи, когда нужно востановить
     * максимально полно историю всех идей
     * Будет утеряна информация по промежуточным статусам (на доработке и тд)
     *
     * Информация по статусам строится на основании текущих
     * статусов идей и заполненых дат заседания, создания и тд
     *
     * PS: метод удаляем все статусы!!! метод не проверяет уже существующие статусы, а значит
     * лучше всю историю перед этим очистить, либо дописать метод на проверку
     *
     */
    public static function updateAllIdeasStatuses($iStartIdeaID = 0, $iTimeout = false, $iDepartment = false) {
        global $USER;
        $cIdeasParse = 0;
        $iLastParseID = $iStartIdeaID;
        $iStart = time();
        $arDepartments = false;
        $arDepIdeas = false;

        if(!empty($iDepartment)) {
            if((string)intval($iDepartment) == $iDepartment && $iDepartment > 0)
                $arDepartments = self::getBranch($iDepartment);

            if(empty($arDepartments)) {
                ShowError('Неверный департамент');
                return;
            } else {
                $arDepartments = array_keys($arDepartments);
            }
        }


        // Переменные
        if(
            $iStartIdeaID != intval($iStartIdeaID)
            || $iTimeout != intval($iTimeout)
        ) {
            ShowError("Wrong params value");
            return false;
        }
        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            ShowError("NOT FOUND ALL DEPENDENCIES CLASSES. Line: ".__LINE__);
            return false;
        }

        // Проверяем модуль Блог и ИБ
        if (!CModule::IncludeModule("blog")) {
            ShowError("BLOG MODULE NOT INSTALL");
            return;
        }
        if (!CModule::IncludeModule("iblock")) {
            ShowError("IBLOCK MODULE NOT INSTALL");
            return;
        }

        // Проверяем доступ
        if(!self::isUserRole('ADMIN')) {
            ShowError("PERMISSION DENIED");
            return;
        }


        // Если задан департамент, то получаем ID идей которые к ним относятся
        if($arDepartments) {
            $rsIdeas = CBlogPost::GetList(
                array('ID' => 'ASC'),
                array('BLOG_ID' => self::getIdeaBlogId(), 'UF_AUTHOR_DEP' => $arDepartments),
                false,
                false,
                array( 'ID', 'BLOG_ID', 'DATE_PUBLISH')
            );
            // Проходимся по идеям
            while($arIdea = $rsIdeas->GetNext(true, false)) {
                $arDepIdeas[] = $arIdea['ID'];
            }

            if(empty($arDepIdeas)) {
                ShowError('В данном департаменте идей нет');
                return;
            }
        }



        // Если это первый запуск (START_ID не задан), то удаляем текущую историю идей
        if($iStartIdeaID == 0) {
            $el = new CIBlockElement();
            $rsStatusesInHistory = $el->GetList(
                false,
                array(
                    'IBLOCK_ID'     => self::getIBlockID('umsh', 'umshIdeaStatusChanges'),
                    'EXTERNAL_ID'   => !empty($arDepIdeas)?$arDepIdeas:null
                ),
                false,
                false,
                array(
                    'ID',
                    'NAME'
                )
            );
            $cDelete = 0;
            while($arCurrentStatusInHistory = $rsStatusesInHistory->Fetch()) {
                $cDelete++;
                $el->Delete($arCurrentStatusInHistory['ID']);

                // Проверяем таймаут
                if(!empty($iTimeout) && $iTimeout <= time() - $iStart)
                    return array('PARSE_COUNT' => $cDelete, 'STOP_ID' => 0, 'DELETING' => true);
            }
            // Возвращаем этап с удалением
            if($cDelete)
                return array('PARSE_COUNT' => $cDelete, 'STOP_ID' => 0, 'DELETING' => true);
        }


        // Получаем все идеи
        $rsIdeas = CBlogPost::GetList(
            array('ID' => 'ASC'),
            array(
                'BLOG_ID' => self::getIdeaBlogId(),
                '>ID' => $iStartIdeaID,
                'ID'  => !empty($arDepIdeas)?$arDepIdeas:null
            ),
            false,
            false,
            array( 'ID', 'BLOG_ID', 'DATE_PUBLISH', 'UF_STATUS', 'UF_DATE_RELEASED', 'UF_DATE_ACCEPT' )
        );


        // Проходимся по идеям
        while($arIdea = $rsIdeas->GetNext(true, false)) {
            // Увеличиваем кол-во пропаршеных идей
            $cIdeasParse++;
            // Записываем последний ID пропаршенной идеи
            $iLastParseID = $arIdea['ID'];

            // Текущий статус
            $sCurrentStatus = self::getStatusCodeById($arIdea['UF_STATUS']);
            if($sCurrentStatus != 'DRAFT') {

                // Новый статус
                $sDate = self::getIdeaUpdateDateByStatus(self::getStatusIdByCode('NEW'), $arIdea['DATE_PUBLISH'], $arIdea['UF_DATE_ACCEPT'], $arIdea['UF_DATE_RELEASED']);
                if (!empty($sDate))
                    self::saveIdeaStatusToHistory(
                        $arIdea['ID'],
                        self::getStatusIdByCode('NEW'),
                        $sDate
                    );


                // TODO: нужно проверить, что это не новый?
                $sDate = self::getIdeaUpdateDateByStatus($arIdea['UF_STATUS'], $arIdea['DATE_PUBLISH'], $arIdea['UF_DATE_ACCEPT'], $arIdea['UF_DATE_RELEASED']);
                if (!empty($sDate))
                    self::saveIdeaStatusToHistory(
                        $arIdea['ID'],
                        $arIdea['UF_STATUS'],
                        $sDate
                    );

                // Если текущий статус Завершено\Реализовано
                // то записываем статус в Работе
                if (
                in_array(
                    $arIdea['UF_STATUS'],
                    array(
                        self::getStatusIdByCode('COMPLETED'),
                        self::getStatusIdByCode('FINISH'),
                    )
                )
                ) {
                    $sDate = self::getIdeaUpdateDateByStatus(self::getStatusIdByCode('PROCESSING'), $arIdea['DATE_PUBLISH'], $arIdea['UF_DATE_ACCEPT'], $arIdea['UF_DATE_RELEASED']);
                    if (!empty($sDate))
                        self::saveIdeaStatusToHistory(
                            $arIdea['ID'],
                            self::getStatusIdByCode('PROCESSING'),
                            $sDate
                        );
                }
            }


            // Сохраняем актуализированные статусы в саму идею для автономной информации при выгрузке идей
            self::updateIdeaStandAloneStatusHistory($arIdea['ID']);

            // Проверяем лимит по кол-во идей
            if($cIdeasParse >= 1500) {
                echo 'Обработано 1500 идей, обновляем запрос.<br>';
                break;
            }

            // Проверяем таймаут
            if(!empty($iTimeout) && $iTimeout <= time() - $iStart)
                break;

        }
        return array('PARSE_COUNT' => $cIdeasParse, 'STOP_ID' => $iLastParseID, 'DELETING' => false);

    }

    /**
     * Метод записи в историю статуса идеи
     * @author Smagin AS
     *
     * @param int $iIdeaID Индификатор идеи (id)
     * @param int $iStatusID Индификатор статуса (id)
     * @param string $sDate Дата в формате дд.мм.гггг     *
     * @param array $arErrors
     *
     * @return bool
     */
    public static function saveIdeaStatusToHistory($iIdeaID, $iStatusID, $sDate, &$arErrors = array()) {
        global $USER;

        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            $arErrors[] = ("Не найдены все зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Подключаем модули
        if (!CModule::IncludeModule("iblock")) {
            $arErrors[] = ("Модуль 'IBlock' не найден.");
            return false;
        }
        if (!CModule::IncludeModule('idea')) {
            $arErrors[] = ("Модуль 'Idea' не найден.");
            return false;
        }

        // Проверяем доступность классов
        if(!class_exists('CIdeaManagment')) {
            $arErrors[] = ("Не найдены все зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Проверяем ID
        if($iIdeaID != intval($iIdeaID) || $iStatusID != intval($iStatusID)) {
            $arErrors[] = ("Невозможно установить статус идеи, неверные параметры.");
            return false;
        }
        // Проверяем и дополняем дату
        $sDate = array_shift(explode(' ', $sDate));
        if(!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $sDate)) {
            $arErrors[] = ("Невозможно установить статус идеи, неверная дата ({$sDate})");
            return false;
        }
        $sDate .= ' 00:00:00';


        // Получаем список статусов
        $arStatusList = CIdeaManagment::getInstance()->Idea()->GetStatusList();
        $el = new CIBlockElement();


        $arHistoryIdeaFiled = array(
            'IBLOCK_ID'     => self::getIBlockID('umsh', 'umshIdeaStatusChanges'),
            'NAME'          => "IDEA #{$iIdeaID} ({$arStatusList[$iStatusID]['VALUE']})",
            'ACTIVE'        => 'Y',
            'MODIFIED_BY'   => $USER->GetID(),
            'XML_ID'        => $iIdeaID,
            'CODE'          => $iStatusID,
            'DATE_CREATE'   => $sDate
        );

        if(!$el->Add($arHistoryIdeaFiled)) {
            $arErrors[] = ("Не удалось добавить статус идеи в историю.");
            return false;
        }

        // Добавляем в доп.инфоблок данные(для вывода расширенной истории)
        $ibHistory = self::getIBlockID('umsh', 'umshIdeaTransferChanges');
        $arHistoryIdeaFiled['IBLOCK_ID'] = $ibHistory;
        $arHistoryIdeaFiled['IBLOCK_SECTION_ID'] = self::getSectionId($ibHistory, "status");
        $arHistoryIdeaFiled['CODE'] = $iIdeaID;
        $arHistoryIdeaFiled['SORT'] = $iStatusID;

        if(!$el->Add($arHistoryIdeaFiled)) {
            $arErrors[] = ("Не удалось добавить статус идеи в историю.2". $el->LAST_ERROR);
            return false;
        }

        return true;
    }

    public static function getSections($iblockId, $sort = array('DEPTH_LEVEL' => 'ASC', 'SORT' => 'DESC', 'NAME' => 'ASC'), $select = array('ID', 'CODE', 'NAME') ) {
        $rsResult = CIBlockSection::GetList(
            $sort,
            array(
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' 	=> 'Y',
            ),
            false,
            $select
        );
        $result =[];
        while($arSection = $rsResult->GetNext()) {
            $result[] = $arSection;
        }
        return $result;
    }

    public static function getSectionId($iblockId, $code="") {
        $sections = self::getSections($iblockId);
        foreach ($sections as $section) {
            if($section['CODE'] == $code) {
                return $section['ID'];
            }
        }
        return false;
    }

    /**
     * Метод обновления даты записи в истории статуса идеи
     * @author Smagin AS
     *
     * @param int $iIdeaID Индификатор идеи (id)
     * @param int $iRecordID Индификатор записи статуса в истории (id)
     * @param int $iStatusID Индификатор статуса (id)
     * @param string $sDate Дата в формате дд.мм.гггг
     * @param array $arErrors
     *
     * @return bool
     */
    public static function updateIdeaStatusInHistory($iIdeaID, $iRecordID, $iStatusID, $sDate, &$arErrors = array()) {
        global $USER;

        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            $arErrors[] = ("Не найдены все зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Подключаем модули
        if (!CModule::IncludeModule("iblock")) {
            $arErrors[] = ("Модуль 'IBlock' не найден.");
            return false;
        }
        if (!CModule::IncludeModule('idea')) {
            $arErrors[] = ("Модуль 'Idea' не найден.");
            return false;
        }

        // Проверяем доступность классов
        if(!class_exists('CIdeaManagment')) {
            $arErrors[] = ("Не найдены все зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Проверяем ID
        if($iIdeaID != intval($iIdeaID) || $iRecordID != intval($iRecordID) || $iStatusID != intval($iStatusID)) {
            $arErrors[] = ("Невозможно установить статус идеи, неверные параметры.");
            return false;
        }
        // Проверяем и дополняем дату
        $sDate = array_shift(explode(' ', $sDate));
        if(!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $sDate)) {
            $arErrors[] = ("Невозможно установить статус идеи, неверная дата ({$sDate})");
            return false;
        }
        $sDate .= ' 00:00:00';

        $el = new CIBlockElement();
        // Получаем свойства идеи
        $arIdea = $el->GetList(false, array('ID'=>$iRecordID), false,false,array('CODE','XML_ID'))->GetNext(true, false);

        // Проверяем соответствие данных
        if(!$arIdea || $arIdea['CODE'] != $iStatusID || $arIdea['XML_ID'] != $iIdeaID ) {
            $arErrors[] = ("Статус идеи в истории не найден.");
            return false;
        }

        $arHistoryIdeaFiled = array(
            'MODIFIED_BY'   => $USER->GetID(),
            'DATE_CREATE'   => $sDate
        );

        if(!$el->Update($iRecordID, $arHistoryIdeaFiled)) {
            $arErrors[] = ("Не удалось обновить статус идеи в истории.");
            return false;
        }
        debugfile($iRecordID. " " .$iStatusID );


        return true;
    }

    /**
     * Метод удаления записи в истории статусов идеи
     * @author Smagin AS
     *
     * @param int $iIdeaID Индификатор идеи (id)
     * @param int $iRecordID Индификатор записи статуса в истории (id)
     * @param int $iStatusID Индификатор статуса (id)
     * @param array $arErrors
     *
     * @return bool
     */
    public static function deleteIdeaStatusFromHistory($iIdeaID, $iRecordID, $iStatusID, &$arErrors = array()) {
        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            $arErrors[] = ("Не найдены зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Подключаем модули
        if (!CModule::IncludeModule("iblock")) {
            $arErrors[] = ("Не найден модуль 'IBLOCK'");
            return false;
        }

        // Проверяем ID
        if($iIdeaID != intval($iIdeaID) || $iRecordID != intval($iRecordID) || $iStatusID != intval($iStatusID)) {
            $arErrors[] = ("Невозможно удалить статус, неверные параметры.");
            return false;
        }

        $el = new CIBlockElement();
        // Получаем свойства идеи
        $arIdea = $el->GetList(false, array('ID'=>$iRecordID), false,false,array('CODE','XML_ID'))->GetNext(true, false);

        // Проверяем соответствие данных
        if(!$arIdea || $arIdea['CODE'] != $iStatusID || $arIdea['XML_ID'] != $iIdeaID ) {
            $arErrors[] = ("Статус идеи в истории не найден.");
            return false;
        }

        // Удаляем запись из истории
        if(!$el->Delete($iRecordID)) {
            $arErrors[] = ("Невозможно удалить запись в истории идей.");
            return false;
        }

        return true;
    }

    /**
     * Метод выбирает актуальную дату из переданных для переданного статуса
     * @author Smagin AS
     *
     * @param int $iStatusID Индификатор статуса (id)
     * @param $sPublishDate Дата создания
     * @param $sAcceptDate Дата заседания (принятия)
     * @param $sReleaseDate Дата реализации
     *
     * @return bool|string
     */
    public static function getIdeaUpdateDateByStatus($iStatusID, $sPublishDate, $sAcceptDate, $sReleaseDate) {
        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            ShowError("NOT FOUND ALL DEPENDENCIES CLASSES. Line: ".__LINE__);
            return false;
        }

        // Проверяем ID
        if($iStatusID != intval($iStatusID)) {
            ShowError("CAN'T SET IDEA STATUS! Wrong status value");
            return false;
        }

        // Новая идея - дата создания
        if($iStatusID == self::getStatusIdByCode('NEW')) {
            if(!empty($sPublishDate))
                return $sPublishDate;
        }
        // Идея Принята, В работе, Отклонена, На доработке - дата заседания (принятия)
        elseif(
        in_array(
            $iStatusID,
            array(
                self::getStatusIdByCode('ACCEPT'),
                self::getStatusIdByCode('PROCESSING'),
                self::getStatusIdByCode('FAILED'),
                self::getStatusIdByCode('REWORK'),
            )
        )
        ) {
            if(!empty($sAcceptDate))
                return $sAcceptDate;
        }
        // Идея Реализована\Завершена - дата реализации
        elseif(
        in_array(
            $iStatusID,
            array(
                self::getStatusIdByCode('COMPLETED'),
                self::getStatusIdByCode('FINISH'),
            )
        )
        ) {
            if(!empty($sReleaseDate))
                return $sReleaseDate;
        }

        // Если нет даты или по другим причинам произошла ошибка, то возвращаем false
        return false;
    }

    /**
     * Получить доступные статусы у переданного
     * @param $iStatusCode
     * @param string $sType (NEXT, PREV, BOTH)
     *
     * @return bool|array
     */
    public static function getAllowedStatus($iStatusCode, $sType = 'BOTH'){
        $arResult = array('NEXT' => array(), 'PREV' => array());
        $arStatusesProperty = CIdeaConst::getStatusesProperty();

        // Проверяем такой статус
        if(empty($arStatusesProperty[$iStatusCode]))
            return false;


        // Проходимся по направлениям построения и собираем массив
        foreach(array('NEXT', 'PREV') AS $sBuildWay) {
            // Если нужно получить данное направление
            if($sType == $sBuildWay || $sType == 'BOTH') {
                // Проходимся по статусам доступным и добавляем их
                foreach($arStatusesProperty[$iStatusCode][$sBuildWay] AS $iInnerStatusCode => $bIsRequired) {
                    if($iStatusCode != $iInnerStatusCode && !array_key_exists($iInnerStatusCode, $arResult[$sBuildWay])) {
                        // Пробуем найти доступные статусы глубже
                        if($arReturn = self::getAllowedStatus($iInnerStatusCode, $sBuildWay))
                            $arResult[$sBuildWay] += $arReturn;
                    }

                    $arResult[$sBuildWay][$iInnerStatusCode] = $bIsRequired;
                }
            }

        }

        if($sType == 'BOTH')
            return $arResult;

        return $arResult[$sType];

    }

    /**
     * Метод проверяет идею в базе после чего актуализирует все ее статусы в истории
     * @author Smagin AS
     *
     * @param int $iIdeaID
     * @param array $arErrors
     *
     *
     * @return bool|string
     */
    public static function ActualizationIdeaStatuses($iIdeaID, &$arErrors = array()) {
        // Проверяем доступность классов
        if(
            !class_exists('CIdeaConst')
            || !class_exists('CModule')
        ) {
            $arErrors[] = ("Не найдены зависимые классы. Line: ".__LINE__);
            return false;
        }

        // Проверяем ID
        if($iIdeaID != intval($iIdeaID)) {
            $arErrors[] = ("Невозможно установить статус, неверный ID статуса.");
            return false;
        }
        // Подключаем Блог
        if(!CModule::IncludeModule("blog")) {
            $arErrors[] = ('Модуль "Блог" не найден.');
            return false;
        }


        // Заглушки
        $arStatuses = array();
        $arStatusesByCode = array();
        $arIdea = array();
        $el = new CBlogPost();
        $arStatusesProperty = CIdeaConst::getStatusesProperty();


        /**
         * Получаем текущее состояние идеи
         * Статус, даты...
         */
        $arFilter = array(
            'BLOG_ID' => self::getIdeaBlogId(),
            'ID'      => $iIdeaID
        );
        $arFields = array(
            'ID', 'DATE_PUBLISH', 'TITLE', 'BLOG_ID', 'UF_*',
        );
        if(!$arIdea = $el->GetList(array(),$arFilter,false,false,$arFields)->GetNext(true, false)) {
            $arErrors[] = ('Идея не найдена.');
            return false;
        }
        /**
         * Получаем текущие статусы идеи
         */
        if(self::findIdeasStatuses(array($iIdeaID)))
            $arStatuses = self::getIdeaStatuses($iIdeaID);


        // Удаляем статусы из истории которые не могли быть в истории у текущего статуса
        foreach($arStatuses AS &$arStatus) {
            if(!isset($arStatusesProperty[$arIdea['UF_STATUS']]['PREV'][$arStatus['CODE']]) && $arStatus['CODE'] != $arIdea['UF_STATUS']) {
                if(!self::deleteIdeaStatusFromHistory($iIdeaID,$arStatus['ID'],$arStatus['CODE'],$arErrors)) {
                    $arErrors[] = ('Не удалось удалить не актуальный статус из истории.');
                    return false;
                }
                continue;
            }
            // Строим массив по коду
            if(empty($arStatusesByCode[$arStatus['CODE']])
                || $arStatusesByCode[$arStatus['CODE']]['TIME_STAMP'] < $arStatus['TIME_STAMP']
            )
                $arStatusesByCode[$arStatus['CODE']] = $arStatus;
        }

        // Проходимся по тем статусам, что должны были быть, и дополняем обязательные, если они отсутствуют
        foreach($arStatusesProperty[$arIdea['UF_STATUS']]['PREV'] AS $iStatusCode => $bIsRequired) {
            // Текущая дата создания статуса в истории
            $sCurrentStatusDate = array_shift(explode(' ',$arStatusesByCode[$iStatusCode]['DATE_CREATE']));
            // Получаем актуальную дату для этого статуса из текущих свойст идеи
            $sCurrentStatusActualDate = array_shift(explode(' ', self::getIdeaUpdateDateByStatus(
                $iStatusCode,
                $arIdea['DATE_PUBLISH'],
                $arIdea['UF_DATE_ACCEPT'],
                $arIdea['UF_DATE_RELEASED']
            )));

            // Обязательный статус
            if($bIsRequired) {
                // Если нет такого статуса в истории, то добавляем статус в историю
                if(empty($arStatusesByCode[$iStatusCode])) {
                    if(!self::saveIdeaStatusToHistory(
                        $iIdeaID,
                        $iStatusCode,
                        $sCurrentStatusActualDate,
                        $arErrors
                    )) {
                        $arErrors[] = ('Не удалось сохранить актуальный статус в истории.');
                        return false;
                    }
                }
            }

            // Если есть такой статус, то проверяем его дату, если дата отличается то обновляем
            if(!empty($arStatusesByCode[$iStatusCode]) && $sCurrentStatusActualDate != $sCurrentStatusDate) {
                // Если статус "На доработке" и текущий статус идеи "На доработке" то добавляем новую запись в историю
                // Если это не статус "На доработке" то обновляем ("На доработке" обрабатываем ниже, при занесении тек. статуса)
                if($iStatusCode != self::getStatusIdByCode('REWORK')) {
                    if(!self::updateIdeaStatusInHistory($iIdeaID, $arStatusesByCode[$iStatusCode]['ID'], $iStatusCode, $sCurrentStatusActualDate, $arErrors)) {
                        $arErrors[] = ('Не удалось обновить статус в истории.');
                        return false;
                    }
                }
            }
        }

        /*
         * Сохраняем текущий статус
         * ----------------------------------------------
         */
        // Получаем актуальную дату для этого статуса из текущих свойст идеи
        $sCurrentStatusActualDate = array_shift(explode(' ', self::getIdeaUpdateDateByStatus(
            $arIdea['UF_STATUS'],
            $arIdea['DATE_PUBLISH'],
            $arIdea['UF_DATE_ACCEPT'],
            $arIdea['UF_DATE_RELEASED']
        )));
        // Если такого статуса еще нет, то добавляем
        if(empty($arStatusesByCode[$arIdea['UF_STATUS']])) {
            if(!self::saveIdeaStatusToHistory(
                $iIdeaID,
                $arIdea['UF_STATUS'],
                $sCurrentStatusActualDate,
                $arErrors
            )) {
                $arErrors[] = ('Не удалось сохранить актуальный статус в истории.');
                return false;
            }
        }
        // Если такой статус есть, а дата не совпадает, то обрабатываем
        else {
            // Текущая дата создания статуса в истории
            $sCurrentStatusDate = array_shift(explode(' ',$arStatusesByCode[$arIdea['UF_STATUS']]['DATE_CREATE']));

            if($sCurrentStatusActualDate != $sCurrentStatusDate) {
                // Если статус "На доработке" то добавляем новую запись в историю
                if($arIdea['UF_STATUS'] == self::getStatusIdByCode('REWORK') ) {
                    if(!self::saveIdeaStatusToHistory(
                        $iIdeaID,
                        $arIdea['UF_STATUS'],
                        $sCurrentStatusActualDate,
                        $arErrors
                    )) {
                        $arErrors[] = ('Не удалось сохранить актуальный статус в истории.');
                        return false;
                    }
                }
                // Если это не статус "На доработке" то обновляем
                else {
                    if(!self::updateIdeaStatusInHistory($iIdeaID, $arStatusesByCode[$arIdea['UF_STATUS']]['ID'], $arIdea['UF_STATUS'], $sCurrentStatusActualDate, $arErrors)) {
                        $arErrors[] = ('Не удалось обновить статус в истории.');
                        return false;
                    }
                }

            }
        }

        // Сохраняем актуализированные статусы в саму идею для автономной информации при выгрузке идей
        self::updateIdeaStandAloneStatusHistory($iIdeaID, $arErrors);

    }

    public static function updateIdeaStandAloneStatusHistory($iIdeaID, &$arErrors = array()) {
        // Проверям ID идеи
        if(!$iIdeaID || !filter_var($iIdeaID, FILTER_VALIDATE_INT) || $iIdeaID < 1) {
            $arErrors[] = 'Передан невалидный ID идеи.';
            return false;
        }


        // Ищем все статусы идеи игнорируя кеш
        self::findIdeasStatuses(array($iIdeaID), false);
        // Получаем список всех статусов игнорируя кеш
        if(!$arStatusesList = self::getIdeaStatuses($iIdeaID)) {
            $arErrors[] = 'История статусов не найдена для идеи #'.$iIdeaID.'.';
            return false;
        };

        // Обновляем свойство
        $obBlogPost = new CBlogPost();

        if(!$obBlogPost->Update($iIdeaID, array('BLOG_ID' => self::getIdeaBlogId(), 'UF_SA_STATUS_HISTORY' => serialize($arStatusesList)))) {
            $arErrors[] = 'Не удалось обновить историю статусов в идеи #'.$iIdeaID.'.';
            return false;
        }

        return true;
    }

    /*
     * Конец методов для работы со статусыми идей
     * ===========================================================================
     */

    /**
     * Методы для работы с таблицей статусов- umsh_status_changes_table
     * ===========================================================================
     */
    /* Обновляем дату последнего изменения статуса */
    public static function updateStatusChangesTable($IdeaID, $statusID, $lastStatusChange=false) {
        global $DB;
        if(false==$lastStatusChange) {
            $lastStatusChange = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        }
        $sql = "INSERT INTO `umsh_status_changes_table` (`ID`,`STATUS_ID`,`LAST_STATUS_CHANGE`) VALUES ({$IdeaID},{$statusID},{$lastStatusChange}) ON DUPLICATE KEY UPDATE `STATUS_ID`={$statusID}, `LAST_STATUS_CHANGE`={$lastStatusChange}";

        return $DB->Query($sql);
    }

    /**
     * Конец методов для работы с таблицей статусов- umsh_status_changes_table
     * ===========================================================================
     */


    /**
     * Методы для работы с таблицей Лучших Практик
     * ===========================================================================
     */

    /**
     * Функция возвращает имена LOB полей
     */
    public static function getSummaryTableLobFields() {
        return array(
            'TITLE'         => 'CLOB',
            'IDEA'          => 'CLOB',
            'IDEA_RATING'   => 'CLOB',
            'AUTHORS'       => 'CLOB',

        );
    }

    /**
     * Функция возвращает подготовленный массив для вставки в запрос БД
     * основанный на массиве данных идеи
     *
     * @param array|int $arIdeaData
     * @return array|bool
     */
    public static function getArrayForSummaryTableSQL($arIdeaData) {
        /*
         * Если $arIdeaData int, то запрашиваем данные по идеи
         */
        if(filter_var($arIdeaData, FILTER_VALIDATE_INT)) {
            $arIdeaData = self::getIdeasListWithData(
                false,
                array('ID' => $arIdeaData),
                $foo=false,
                array(),
                null,
                null,
                array(
                    'TAGS'          => false,
                    'ACTION'        => false,
                    'URL'           => false,
                    'DATE'          => false,
                    'DEPARTMENT'    => false,
                    'EMPLOYEES'     => false,
                    'REWORK_DURATION' => false,
                )
            );
            $arIdeaData = array_shift($arIdeaData['LIST']);
        }

        /*
         * Проверяем минимальные данные идеи
         */
        if(empty($arIdeaData) || !is_array($arIdeaData) || !filter_var($arIdeaData['ID'], FILTER_VALIDATE_INT))
            return false;


        $fnJSONinUTF  = function($mData) {
            if(is_array($mData)) {
                array_walk_recursive($mData, function(&$v){ if(is_string($v)) $v = mb_encode_numericentity($v, array(0x80, 0xffff, 0, 0xffff), 'UTF-8'); });
            } else {
                $mData = mb_encode_numericentity($mData, array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
            }

            return mb_decode_numericentity(json_encode($mData), array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
        };
        $fnDateToUnix = function($sDate) {
            $iResult = null;
            if(preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})(?: (\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $sDate, $arDate))
                $iResult = mktime($arDate[4]?:0,$arDate[5]?:0,$arDate[6]?:0,$arDate[2],$arDate[1],$arDate[3])?:null;

            return $iResult;
        };

        $arSQLData = array(
            'ID'             => $arIdeaData['ID'],                           // Номер идеи
            'INTERNAL_CODE'  => $arIdeaData['UF_INTERNAL_CODE'],             // Внутренний номер идеи
            'TITLE'          => $arIdeaData['TITLE'],                        // Заголовок (проблема)
            'IDEA'           => $arIdeaData['DETAIL_TEXT'],                  // Идея (решение)
            'STATUS'         => $arIdeaData['SF_STATUS'],                    // Статус
            'STATUS_ID'      => $arIdeaData['UF_STATUS'],                    // ID статуса
            'STATUS_CODE'    => self::getStatusCodeById($arIdeaData['UF_STATUS']), // Код статуса
            'IDEA_RELEASED'  => '',                                          // Идея реализована (0/1)
            'HEAD_DEPARTMENT'            => '',                              // Название департамента из УМШ (уровень 1 - компания)
            'HEAD_DEPARTMENT_ID'         => '',                              // ID департамента из УМШ (уровень 1 - компания)
            'SUBDIVISION_DEPARTMENT'     => '',                              // Название департамента из УМШ (уровень 2 - подразделение)
            'SUBDIVISION_DEPARTMENT_ID'  => '',                              // ID департамента из УМШ (уровень 2 - подразделение)
            'IDEA_DEPARTMENT'            => '',                              // Название департамента из УМШ (уровень 3 - уровень идей)
            'IDEA_DEPARTMENT_ID'         => '',                              // ID департамента из УМШ (уровень 3 - уровень идей)
            'BOSS_HEAD_COMPANY'          => '',                              // Название головной организации из BOSS
            'BOSS_HEAD_COMPANY_ID'       => '',                              // ID головной организации из BOSS
            'BP_CONTENDER'   => intval($arIdeaData['UF_COMP_BP_CONTENDER']), // Претендент на лучшие практики (0/1)
            'DATE_CREATE'    => $fnDateToUnix($arIdeaData['DATE_CREATE']),   // Дата создания (в системе)
            'DATE_PUBLISH'   => $fnDateToUnix($arIdeaData['DATE_PUBLISH']),  // Дата подачи
            'DATE_ACCEPT'    => $fnDateToUnix($arIdeaData['UF_DATE_ACCEPT']),// Дата принятия
            'DATE_RELEASED'  => $fnDateToUnix($arIdeaData['UF_DATE_RELEASED']),  // Дата реализации
            'REWORK_EMPLOYEE'            => $arIdeaData['UF_USER_DEV'],      // Ответственный сотрудник за внедрение
            'IMPLEMENTATION_EMPLOYEE'    => $arIdeaData['UF_USER_APP'],      // Ответственный сотрудник за внедрение
            'RELEASED_WAY'               => $arIdeaData['SF_RELEASED_WAY'],  // Название пути реализации
            'RELEASED_WAY_ID'            => $arIdeaData['UF_RELEASED_WAY'],  // ID пути реализации
            'RELEASED_WAY_CODE'          => strtoupper($arIdeaData['SF_RELEASED_WAY_CODE']), // Код пути реализации
            'INNER_ORDER_NUM'=> $arIdeaData['UF_INNER_ORDER_NUM'],           // Внутренний номер заказа (для ТМЦ)
            'S_NUMBER'       => $arIdeaData['UF_S_NUMBER'],                  // Номер заседания совета
            'EXPECTED_SOLUTION_DURATION' => $arIdeaData['UF_SOLUTION_DURATION'], // Оценочный срок реализации (дней)
            'REAL_SOLUTION_DURATION'     => $arIdeaData['UF_TIME_NEED'],     // Фактический срок реализации (дней)
            'TOTAL_RATING'   => $arIdeaData['UF_TOTAL_RATING'],              // Суммарная оценка
            'IDEA_PRICE'     => $arIdeaData['UF_IDEA_PRICE'],                // Сумма вознаграждения
            'SOFT'           => $arIdeaData['UF_TOTAL_EFF_SOFT'],            // Суммарный эффект Soft
            'HARD'           => $arIdeaData['UF_TOTAL_EFF_HARD'],            // Суммарный эффект Hard
            'IDEA_RATING'    => null,                                        // Оценка совета (JSON)
            'AUTHORS'        => $fnJSONinUTF(unserialize($arIdeaData['~UF_EMPLOYEE_DATA'])), // Авторы (JSON)
            'AUTHOR_SHIFT'   => $arIdeaData['UF_AUTHOR_SHIFT'],              // График работы автора
            'AUTHOR_PHONE'   => $arIdeaData['UF_AUTHOR_PHONE'],              // Контактный телефон автора
            'AUTHOR_PART'    => intval($arIdeaData['UF_AUTHOR_PART_CHECK']), // Участие автора в реализации (0/1)
            'CREATOR_ID'     => $arIdeaData['AUTHOR_ID'],                    // ID пользователя который создал идею
            'CREATOR_NAME'   => $arIdeaData['AuthorName'],                   // ФИО пользователя который создал идею
            'CREATOR_EMAIL'  => $arIdeaData['AUTHOR_EMAIL'],                 // Email пользователя который создал идею
            'SYS_MAPPER_ID'  => '',                                          // Системное свойство хранящее ID раздела в ИБ маппера
            'SYS_MAPPER_TIED_LVL' => '',                                     // Системное свойство хранящее на каком уровне идет связку УМШ к BOSS
            // 'SYS_DATE_ADD'   => '',                                       // Системное свойство хранящее время последнего добавления этого поля (добавляется автоматически)
            // 'SYS_DATE_UPDATE'=> '',                                       // Системное свойство хранящее время последнего обновления этого поля (добавляется автоматически)
        );



        /*
         * ID головного департамента УМШ и подразделения второго уровня
         */
        $arBranch = reset(self::getBranch($arIdeaData['UF_AUTHOR_DEP'], 3));
        if(!$arBranch || $arBranch['DEPTH_LEVEL'] != 3) return false;
        $arSQLData['HEAD_DEPARTMENT']           = $arBranch['PARENT']['PARENT']['NAME'];
        $arSQLData['HEAD_DEPARTMENT_ID']        = $arBranch['PARENT']['PARENT']['ID'];
        $arSQLData['SUBDIVISION_DEPARTMENT']    = $arBranch['PARENT']['NAME'];
        $arSQLData['SUBDIVISION_DEPARTMENT_ID'] = $arBranch['PARENT']['ID'];
        $arSQLData['IDEA_DEPARTMENT']           = $arBranch['NAME'];
        $arSQLData['IDEA_DEPARTMENT_ID']        = $arBranch['ID'];



        /*
         * Привязка подразделения идеи к БОСС
         * TODO: Убрать return если будем вставлять все, а не только ЛП
         */
        $arDepartmentWithBoss = self::getUmshBranchWithBossID($arIdeaData['UF_AUTHOR_DEP']);
        $arBOSS = self::getBossByUmsh($arIdeaData['UF_AUTHOR_DEP']);
        if(!$arBOSS) return null;
        $arSQLData['BOSS_HEAD_COMPANY']      = $arBOSS['NAME'];
        $arSQLData['BOSS_HEAD_COMPANY_ID']   = $arBOSS['DATA']['CODE'];
        $arSQLData['SYS_MAPPER_ID']          = $arBOSS['ID'];
        $arSQLData['SYS_MAPPER_TIED_LVL']    = $arDepartmentWithBoss['DEPTH_LEVEL'];

        /*
         * Идея реализована
         */
        $arSQLData['IDEA_RELEASED'] = intval($arSQLData['STATUS_CODE'] == 'COMPLETED' || $arSQLData['STATUS_CODE'] == 'FINISH');


        /*
         * Подставляем рейтинг
         */
        if($arIdeaData['UF_TOTAL_RATING']) {
            $arRating = array();
            foreach (CRationList::getList() AS $iKey => $arData) {
                $arRating[$arData['code']] = array(
                    'ORDER' => $arData['order'],
                    'TITLE' => $arData['title'],
                    'DATA'  => array(
                        $arIdeaData['UF_IDEA_RATING'][$iKey]['1']?:0,
                        $arIdeaData['UF_IDEA_RATING'][$iKey]['2']?:0,
                        $arIdeaData['UF_IDEA_RATING'][$iKey]['3']?:0,
                    )?: array(0, 0, 0),
                );
            }

            $arSQLData['IDEA_RATING'] = $fnJSONinUTF($arRating);
        }


        return $arSQLData;
    }

    /**
     * Сравнивает массив подготовленый к вставке с массивом из таблицы ЛП.
     * Если они различаются, то возвращает false
     *
     * @param array $arIdeaDataArraySQL
     * @param array $arCurrentTableRow
     * @return array|bool
     */
    public static function isNeedToUpdateRecord($arIdeaDataArraySQL, $arCurrentTableRow) {
        unset($arIdeaDataArraySQL['SYS_DATE_ADD'], $arIdeaDataArraySQL['SYS_DATE_UPDATE']);
        unset($arCurrentTableRow['SYS_DATE_ADD'], $arCurrentTableRow['SYS_DATE_UPDATE']);

        /* pre($arIdeaDataArraySQL['ID']);
         foreach($arIdeaDataArraySQL AS $k => $v) {
             if($v != $arCurrentTableRow[$k]) {
                 pre($k, $v, $arCurrentTableRow[$k], $v == $arCurrentTableRow[$k]);
             }
         }*/

        return $arIdeaDataArraySQL != $arCurrentTableRow;
    }


    /**
     * Метод по актуалзицаии информации
     * @param  array|int $arIdeaData
     * @param  string $sAction
     * @param  bool $bIsArrayForSummaryTableSQL
     *
     * @return bool
     * @throws Exception
     */
    public static function persistIdeaDataAtSummaryTable($arIdeaData, $sAction = 'auto', $bIsArrayForSummaryTableSQL = false) {
        global $DB;

        /*
         * Если $arIdeaData int, то запрашиваем данные по идеи
         */
        if(filter_var($arIdeaData, FILTER_VALIDATE_INT)) {
            $bIsArrayForSummaryTableSQL = false;
            $arIdeaData = self::getIdeasListWithData(
                false,
                array('ID' => $arIdeaData),
                $foo=false,
                array(),
                null,
                null,
                array(
                    'TAGS'          => false,
                    'ACTION'        => false,
                    'URL'           => false,
                    'DATE'          => false,
                    'DEPARTMENT'    => false,
                    'EMPLOYEES'     => false,
                    'REWORK_DURATION' => false,
                )
            );
            $arIdeaData = array_shift($arIdeaData['LIST']);
        }


        /*
         * Проверяем данные минимальные данные идеи
         */
        if(empty($arIdeaData) || !is_array($arIdeaData) || !filter_var($arIdeaData['ID'], FILTER_VALIDATE_INT))
            return null;


        /*
         * Формируем данные
         */
        if($bIsArrayForSummaryTableSQL)
            $arSQLData = $arIdeaData;
        else
            $arSQLData = self::getArrayForSummaryTableSQL($arIdeaData);

        if(!$arSQLData)
            return $arSQLData;


        // Проверяем тип действия
        if($sAction != 'insert' && $sAction != 'update')
            $sAction = 'auto';


        /*
         * Так как в эту таблицу пока вставляются только идеи ЛП, то сразу определяем у идеи ЛП, если ее нет, то удаляем
         * TODO: удалить этот блок, если вдруг в таблице будут не только ЛП
         */
        if(!$bIsArrayForSummaryTableSQL && $arIdeaData['UF_COMP_BP_CONTENDER'] != 1)
            return ($sAction == 'insert'? true : self::deleteIdeaDataAtSummaryTable($arIdeaData['ID']));


        // Если действие авто определение, то запрашиваем в таблице данные по этой идеи
        if($sAction == 'auto') {
            $sSQL = "SELECT ID, TITLE FROM ".SDQC::tName('UMSH_SUMMARY_TABLE')." WHERE ID = {$arIdeaData['ID']}";
            $sAction  = $DB->Query($sSQL)->Fetch()?'update':'insert';
        }



        /*
         * В зависимости от типа запроса выстаиваем SQL 
         */
        if($sAction == 'insert') {
            // Добавляем временную метку добавления и обновления
            $arSQLData['SYS_DATE_ADD'] = $arSQLData['SYS_DATE_UPDATE'] = time();

            $arPrepareQuery = $DB->PrepareInsert(SDQC::tName('UMSH_SUMMARY_TABLE'), $arSQLData);
            $sSQL = "INSERT INTO ".SDQC::tName('UMSH_SUMMARY_TABLE')." ({$arPrepareQuery[0]}) VALUES ({$arPrepareQuery[1]})";

        } else {
            // Добавляем временную метку обновления
            $arSQLData['SYS_DATE_UPDATE'] = time();

            $arPrepareQuery = $DB->PrepareUpdate(SDQC::tName('UMSH_SUMMARY_TABLE'), $arSQLData);
            $sSQL = "UPDATE ".SDQC::tName('UMSH_SUMMARY_TABLE')." SET {$arPrepareQuery} WHERE ID = {$arSQLData['ID']}";
        }


        try {
            if(!$DB->QueryBind($sSQL, array_intersect_key($arSQLData, self::getSummaryTableLobFields())))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;

    }

    /**
     * Удаляет идею из таблицы по ID
     *
     * @param $iIdeaID
     * @return bool
     */
    public static function deleteIdeaDataAtSummaryTable($iIdeaID) {
        global $DB;
        if(!filter_var($iIdeaID, FILTER_VALIDATE_INT))
            return false;

        $sSQL = "DELETE FROM ".SDQC::tName('UMSH_SUMMARY_TABLE')." WHERE ID = {$iIdeaID}";
        try {
            if(!$DB->Query($sSQL))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Получаем информацию о департаменте где есть BOSS по ID структуры из УМШ
     *
     * @param $iUmshStructureId
     * @param bool $bOnlyParent
     * @param bool $bUseCache
     * @return array|bool
     */
    public static function getUmshBranchWithBossID($iUmshStructureId, $bOnlyParent = false, $bUseCache = true) {
        $arResult = false;
        $arStructureTree = self::getFullBranchTree($bUseCache);

        if(
            !empty($arStructureTree['LINKS'][$iUmshStructureId])
            && (
                !$bOnlyParent
                || ($bOnlyParent && !empty($arStructureTree['LINKS'][$iUmshStructureId]['PARENT']))
            )
        ) {
            $arResult = $bOnlyParent
                ?$arStructureTree['LINKS'][$iUmshStructureId]['PARENT']
                :$arStructureTree['LINKS'][$iUmshStructureId];
            while($arResult && !$arResult['DATA']['UF_BOSS_HEAD_MAP_ID'])
                $arResult = $arResult['PARENT'];

            if(!$arResult || !$arResult['DATA']['UF_BOSS_HEAD_MAP_ID'])
                $arResult = false;

        }

        return $arResult;
    }

    /**
     * Получаем информацию о маппенге BOSS по ID структуры из УМШ
     * @param $iUmshStructureId
     * @param bool $bOnlyParent
     * @param bool $bUseCache
     * @return array|bool
     */
    public static function getBossByUmsh($iUmshStructureId, $bOnlyParent = false, $bUseCache = true) {
        $arResult = false;
        $arDepWithBoss = self::getUmshBranchWithBossID($iUmshStructureId, $bOnlyParent, $bUseCache);

        if($arDepWithBoss) {
            $arMapperTree = self::getFullBossMapperTree($bUseCache);
            if(!empty($arMapperTree['LINKS'][$arDepWithBoss['DATA']['UF_BOSS_HEAD_MAP_ID']]))
                $arResult = $arMapperTree['LINKS'][$arDepWithBoss['DATA']['UF_BOSS_HEAD_MAP_ID']];
        }

        return $arResult;
    }

    /**
     * Обновить данные в таблице ЛП по привязке к ID структуры маппера BOSS
     *
     * @param int   $iMapperID  ID маппера у записей в таблице, которые нужно обновить
     * @param array $arFields   Массив полей, которые нужно обновить (кроме ID)
     *
     * @return bool
     */
    public static function updateSummaryTableByMapperId($iMapperID, $arFields) {
        global $DB;

        if(!filter_var($iMapperID, FILTER_VALIDATE_INT) || !is_array($arFields))
            return false;

        // Запрещено менять ID по мапперу
        unset($arFields['ID']);

        if(empty($arFields))
            return true;

        // Добавляем временную метку обновления
        $arFields['SYS_DATE_UPDATE'] = time();

        $arPrepareQuery = $DB->PrepareUpdate(SDQC::tName('UMSH_SUMMARY_TABLE'), $arFields);
        $sSQL = "UPDATE ".SDQC::tName('UMSH_SUMMARY_TABLE')." SET {$arPrepareQuery} WHERE SYS_MAPPER_ID = {$iMapperID}";

        try {
            if(!$DB->QueryBind($sSQL, array_intersect_key($arFields, self::getSummaryTableLobFields())))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Удалить данные из таблице ЛП по привязке к ID структуры маппера BOSS
     *
     * @param int $iMapperID ID маппера у записей в таблице, которые нужно удалить
     *
     * @return bool
     */
    public static function deleteSummaryTableByMapperId($iMapperID) {
        global $DB;

        if(!filter_var($iMapperID, FILTER_VALIDATE_INT))
            return false;

        $sSQL = "DELETE FROM ".SDQC::tName('UMSH_SUMMARY_TABLE')." WHERE SYS_MAPPER_ID = {$iMapperID}";

        try {
            if(!$DB->Query($sSQL))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Обновить данные в таблице ЛП по привязке к ID структуры УМШ
     *
     * @param int   $iDepartmentID ID депертамента (любого уровня) у записей в таблице, которые нужно обновить
     * @param int   $iMinLevel     Уровень привязки УМШ к BOSS на котором или выше будет произведено обновление
     * @param array $arFields      Массив полей, которые нужно обновить (кроме ID)
     *
     * @return bool
     */
    public static function updateSummaryTableByDepartmentId($iDepartmentID, $iMinLevel, $arFields) {
        global $DB;

        if(!filter_var($iDepartmentID, FILTER_VALIDATE_INT) || !filter_var($iMinLevel, FILTER_VALIDATE_INT) || !is_array($arFields))
            return false;

        // Запрещено менять ID
        unset($arFields['ID']);

        if(empty($arFields))
            return true;

        // Добавляем временную метку обновления
        $arFields['SYS_DATE_UPDATE'] = time();

        $arPrepareQuery = $DB->PrepareUpdate(SDQC::tName('UMSH_SUMMARY_TABLE'), $arFields);
        $sSQL = "
            UPDATE ".SDQC::tName('UMSH_SUMMARY_TABLE')."
            SET {$arPrepareQuery}
            WHERE
                SYS_MAPPER_TIED_LVL <= {$iMinLevel}
                AND (
                    HEAD_DEPARTMENT_ID = {$iDepartmentID}
                    OR SUBDIVISION_DEPARTMENT_ID = {$iDepartmentID}
                    OR IDEA_DEPARTMENT_ID = {$iDepartmentID}
                )
        ";

        try {
            if(!$DB->QueryBind($sSQL, array_intersect_key($arFields, self::getSummaryTableLobFields())))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Удаление данные в таблице ЛП по привязке к ID структуры УМШ
     *
     * @param int $iDepartmentID ID депертамента (любого уровня) у записей в таблице, которые нужно удалить
     * @param int $iMinLevel     Уровень привязки УМШ к BOSS на котором или выше будет произведено удаление
     *
     * @return bool
     */
    public static function deleteSummaryTableByDepartmentId($iDepartmentID, $iMinLevel) {
        global $DB;

        if(!filter_var($iDepartmentID, FILTER_VALIDATE_INT) || !filter_var($iMinLevel, FILTER_VALIDATE_INT))
            return false;

        $sSQL = "
            DELETE FROM ".SDQC::tName('UMSH_SUMMARY_TABLE')."
            WHERE
                SYS_MAPPER_TIED_LVL <= {$iMinLevel}
                AND (
                    HEAD_DEPARTMENT_ID = {$iDepartmentID}
                    OR SUBDIVISION_DEPARTMENT_ID = {$iDepartmentID}
                    OR IDEA_DEPARTMENT_ID = {$iDepartmentID}
                )
        ";

        try {
            if(!$DB->Query($sSQL))
                return false;
        } catch(Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Получает все идеи из таблицы ЛП
     *
     * @param bool $bHtmlSpecialCharactersDecoding
     * @return array|bool
     */
    public static function getAllIdeasFromSummaryTable($bHtmlSpecialCharactersDecoding = false) {
        global $DB;
        $arResult = array();

        $sSQL = "SELECT * FROM ".SDQC::tName('UMSH_SUMMARY_TABLE');

        try {
            $rsResult = $DB->Query($sSQL);
            while($arRow = ($bHtmlSpecialCharactersDecoding?$rsResult->Fetch():$rsResult->Getnext(true, false)))
                $arResult[$arRow['ID']] = $arRow;

        } catch(Exception $e) {
            return false;
        }

        return $arResult;
    }


    /**
     * Получает идеи из таблице ЛП по привязке к ID структуры УМШ
     *
     * @param int $iDepartmentID ID депертамента (любого уровня) у записей в таблице, которые нужно выбрать
     * @param int $iMinLevel     Уровень привязки УМШ к BOSS на котором или выше будет произведена выборка
     *
     * @return bool|array
     */
    public static function getIdeasFromSummaryTableByDepartmentId($iDepartmentID, $iMinLevel = 9) {
        global $DB;
        $arResult = array();

        if(!filter_var($iDepartmentID, FILTER_VALIDATE_INT) || !filter_var($iMinLevel, FILTER_VALIDATE_INT))
            return false;

        $sSQL = "
            SELECT * FROM ".SDQC::tName('UMSH_SUMMARY_TABLE')."
            WHERE
                SYS_MAPPER_TIED_LVL <= {$iMinLevel}
                AND (
                    HEAD_DEPARTMENT_ID = {$iDepartmentID}
                    OR SUBDIVISION_DEPARTMENT_ID = {$iDepartmentID}
                    OR IDEA_DEPARTMENT_ID = {$iDepartmentID}
                )
        ";

        try {
            $rsResult = $DB->Query($sSQL);
            while($arRow = $rsResult->GetNext(true, false))
                $arResult[$arRow['ID']] = $arRow;

        } catch(Exception $e) {
            return false;
        }

        return $arResult;
    }


    /*
     * Конец методов для работы с таблицей Лучших Практик
     * ===========================================================================
     */




    /**
     * Методы проверки доступа
     * ===========================================================================
     */

    /**
     * Инициализация пользователя
     */
    public static function userInit() {
        $USER = $GLOBALS['USER'];
        if(!$USER->IsAuthorized())
            return false;

        if(empty(self::$user['ID'])) {
            self::$user = array(
                'ID'        => $USER->GetId(),
                'PARAMS'    => $USER->GetById($USER->GetId())->Fetch()?:array(),
                'GROUPS_ID' => $USER->GetUserGroup($USER->GetId()),
                'ROLE'      => $USER->IsAdmin()?'ADMIN':'',
                'RIGHTS'    => array(),
            );
            self::$user['BRANCH'] = self::getMyBranch()?:array();
            self::$user['DEPTH_LEVEL'] = self::$user['BRANCH']['DEPTH_LEVEL']?:0;

            self::getUserRole();
            self::getUserRights();
        }
    }


    /**
     * Возвращает свойство пользователя из переменной
     *
     * @param string $sPropName
     * @return mixed
     */
    public static function getUserProp($sPropName) {
        return self::$user[$sPropName];
    }


    /**
     * Возвращает значение параметра пользователя
     *
     * @param string $sAttrName
     * @return mixed
     */
    public static function getUserAttr($sAttrName) {
        return self::$user['PARAMS'][$sAttrName];
    }


    /**
     * Получает информацию о группах модуля
     * @return array
     */
    public static function getModuleGroups() {
        if(empty(self::$cache['moduleGroups']['ID'])) {
            $obGroup = new CGroup();
            $rsResult = $obGroup->GetList($foo="c_sort", $bar="asc", array('CODE' => 'umsh', 'NAME' => 'УМШ - '));
            while($arRow = $rsResult->Fetch()) {
                self::$cache['moduleGroups']['ID'][$arRow['ID']] = $arRow;
                self::$cache['moduleGroups']['CODE'][$arRow['STRING_ID']] = &self::$cache['moduleGroups']['ID'][$arRow['ID']];
                self::$cache['moduleGroups']['ROLE'][strtoupper(str_replace('umsh', '', $arRow['STRING_ID']))] = &self::$cache['moduleGroups']['ID'][$arRow['ID']];
            }
        }

        return self::$cache['moduleGroups'];
    }


    /**
     * Возвращает значение параметра пользователя
     * @return array
     */
    public static function getUserRole() {
        if(empty(self::$user['ROLE'])) {
            if(self::isUserInRoleModuleGroup('ADMIN'))
                self::$user['ROLE'] = 'ADMIN';
            elseif(self::isUserInRoleModuleGroup('SPECTATOR'))
                self::$user['ROLE'] = 'SPECTATOR';
            else {
                $iUserDepLevel = self::$user['BRANCH'][self::getUserAttr('UF_IDEA_DEPARTMENT')]['DEPTH_LEVEL']?:0;

                if(self::isUserInRoleModuleGroup('MODERATOR') && $iUserDepLevel)
                    self::$user['ROLE'] = 'MODERATOR';
                elseif(self::isUserInRoleModuleGroup('COORDINATOR') && $iUserDepLevel >= 2)
                    self::$user['ROLE'] = 'COORDINATOR';
                elseif(self::isUserInRoleModuleGroup('AUTHOR') && $iUserDepLevel == 3)
                    self::$user['ROLE'] = 'AUTHOR';
                else
                    self::$user['ROLE'] = 'USER';

            }
        }

        return self::$user['ROLE'];
    }


    /**
     * Проверяет роль пользователя
     * @param $sRole
     * @return array
     */
    public static function isUserRole($sRole) {
        if(empty(self::$user['ROLE']))
            self::getUserRole();

        return self::$user['ROLE'] == $sRole;
    }


    /**
     * Возвращает права пользователя
     * @return array
     */
    public static function getUserRights() {
        if(empty(self::$user['RIGHTS'])) {
            $arRights = array(
                'VIEW'      => false,
                'CREATE'    => false,
                'EDIT'      => false,
                'TRANSFER'  => false,
                'DELETE'    => false,
                'STRUCTURE' => false,
                'GRAPHS'    => false,
                'REPORTS'   => false,
            );
            switch(self::getUserRole()) {
                // Админ
                case 'ADMIN':
                    foreach($arRights AS &$bRight)
                        $bRight = true;
                    break;

                // Модератор (ОНУ)
                case 'MODERATOR':
                    foreach($arRights AS &$bRight)
                        $bRight = true;
                    break;
                    $arRights = array(
                            'DELETE'   => false,
                        ) + $arRights;
                    break;

                // Наблюдатель
                case 'SPECTATOR':
                    $arRights = array(
                            'VIEW'   => true,
                        ) + $arRights;
                    break;

                // Координатор
                case 'COORDINATOR':
                    foreach($arRights AS &$bRight)
                        $bRight = true;
                    $arRights = array(
                            'DELETE'    => false,
                            'STRUCTURE' => false,
                        ) + $arRights;
                    break;

                // Автор
                case 'AUTHOR':
                    $arRights = array(
                            'VIEW'   => true,
                            'CREATE' => true,
                            'EDIT'   => true,
                        ) + $arRights;
                    break;

                // Посетитель (по умолчанию)
                case 'USER':
                    $arRights = array(
                            'VIEW'   => true,
                        ) + $arRights;
                    break;
            }

            // Проверяем доступ к отчетам и графикам
            $arRights['GRAPHS']  = CGraphs::checkPermission(self::getUserRole());
            $arRights['REPORTS'] = CReports::checkPermission(self::getUserRole());

            self::$user['RIGHTS'] = $arRights;
        }

        return self::$user['RIGHTS'];
    }


    /**
     * Проверяет роль пользователя
     * @param $sRight
     * @return array
     */
    public static function isUserRight($sRight) {
        if(empty(self::$user['RIGHTS']))
            self::getUserRights();

        return self::$user['RIGHTS'][$sRight]?:false;
    }


    /**
     * Проверяет состоит ли пользователь в группе
     *
     * @param $sType - тип поиска (ID, CODE, ROLE)
     * @param $mGroupID - ИД поиска в зависимости от типа (13, umshAuthor, AUTHOR)
     * @return bool
     */
    public static function isUserInModuleGroup($sType, $mGroupID) {
        if(empty(self::$user))
            self::userInit();
        if(empty(self::$cache['moduleGroups']['ID']))
            self::getModuleGroups();

        if(empty(self::$cache['moduleGroups'][$sType][$mGroupID]))
            return false;

        return in_array(self::$cache['moduleGroups'][$sType][$mGroupID]['ID'], self::$user['GROUPS_ID']);
    }


    /**
     * Проверяет у пользователя наличие группы роли
     * @param $sRoleID - ИД роли для проверки
     * @return bool
     */
    public static function isUserInRoleModuleGroup($sRoleID) {
        return self::isUserInModuleGroup('ROLE', $sRoleID);
    }


    /**
     * Проверяет, а привязана ли идея к департаменту,
     * к которому привязан пользователь
     *
     * @param int $iIdeaID ID идеи
     * @param int $iDepartmentID ID департамента идеи
     * @param int $iIdeaStatusID ID статуса идеи
     * @param int $iIdeaAuthorID ID автора идеи
     * @return bool
     */
    public static function isIdeaOwnDep($iIdeaID, $iDepartmentID = 0, $iIdeaStatusID = 0, $iIdeaAuthorID = 0){
        if(!filter_var($iIdeaID, FILTER_VALIDATE_INT) || $iIdeaID < 1)
            return false;

        /*
         * Запрос в базу для поиска инфы,
         * если она необходима
         */
        $sUserRole = self::getUserRole();
        if(
            empty($iDepartmentID)
            || (
                $sUserRole == 'AUTHOR'
                && (empty($iIdeaStatusID) || empty($iIdeaAuthorID))
            )
        ) {
            // Не используем self::getIdeasListWithData т.к. очень избыточно
            $obBlogPost = new CBlogPost;
            $arIdea = $obBlogPost->GetList(
                array(),
                array('ID' => $iIdeaID),
                false, false,
                array('ID', 'AUTHOR_ID', 'UF_AUTHOR_DEP', 'UF_STATUS')
            )->Fetch();
            $iDepartmentID = $arIdea['UF_AUTHOR_DEP'];
            $iIdeaAuthorID = $arIdea['AUTHOR_ID'];
            $iIdeaStatusID = $arIdea['UF_STATUS'];
        }

        // Проверяем принадлежность
        if($iDepartmentID && self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3)
            return true;

        // Проверяем статус и авторство, если это черновик и департамент еще не указан
        if(
            !$iDepartmentID
            && $sUserRole == 'AUTHOR'
            && $iIdeaStatusID == self::getStatusIdByCode('DRAFT')
            && $iIdeaAuthorID == self::$user['ID']
        )
            return true;

        return false;
    }



    /**
     * Проверяет мождет ли пользователь создавать новые идеи
     * @param int $iDepartmentID - в конкретном департаменте
     * @return bool
     */
    public static function canIdeaCreate($iDepartmentID = 0){
        if(!self::isUserRight('CREATE'))
            return false;

        if(!$iDepartmentID || self::isUserRole('ADMIN'))
            return true;

        return !empty(self::$user['BRANCH'][$iDepartmentID]) && self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3;
    }


    /**
     * Проверяет мождет ли пользователь редактировать идею
     * Если не указан департамент, статус или автор,
     * то при необходимости будет выполнен запрос в базу для поиска идеи
     *
     * @param int $iIdeaID ID идеи
     * @param int $iDepartmentID ID департамента идеи
     * @param int $iIdeaStatusID ID статуса идеи
     * @param int $iIdeaAuthorID ID автора идеи
     * @param int|bool $iTransferID департамент в который передали идею
     * @param int|bool $iCrossTransfer департамент в который передали кроссфункциональную идею
     * @return bool
     */
    public static function canIdeaEdit($iIdeaID, $iDepartmentID = 0, $iIdeaStatusID = 0, $iIdeaAuthorID = 0, $iTransferID = false, $iCrossTransfer = false){
        if(!self::isUserRight('EDIT') || !filter_var($iIdeaID, FILTER_VALIDATE_INT) || $iIdeaID < 1)
            return false;
        if(self::isUserRole('ADMIN'))
            return true;

        /*
         * Запрос в базу для поиска инфы,
         * если она необходима
         */
        $sUserRole = self::getUserRole();
        if(
            empty($iDepartmentID)
            || ($iTransferID !== false && !$iTransferID)
            || ($iCrossTransfer !== false && !$iCrossTransfer )
            || (
                $sUserRole == 'AUTHOR'
                && (empty($iIdeaStatusID) || empty($iIdeaAuthorID))
            )
        ) {
            // Не используем self::getIdeasListWithData т.к. очень избыточно
            $obBlogPost = new CBlogPost;
            $arIdea = $obBlogPost->GetList(
                array(),
                array('ID' => $iIdeaID),
                false, false,
                array('ID', 'AUTHOR_ID', 'UF_AUTHOR_DEP', 'UF_TRANSFER_DEP', 'UF_STATUS', 'UF_DEPARTMENTS')
            )->Fetch();
            $iTransferID   = $arIdea['UF_TRANSFER_DEP'];
            $iDepartmentID = $arIdea['UF_AUTHOR_DEP'];
            $iIdeaAuthorID = $arIdea['AUTHOR_ID'];
            $iIdeaStatusID = $arIdea['UF_STATUS'];
            $iCrossTransfer = $arIdea['UF_DEPARTMENTS'];
        }

        if($iIdeaStatusID == self::getStatusIdByCode('DRAFT')) {
            // Если это автор, то попутно проверяем принадлежность и статус
            $bResult = $iIdeaAuthorID == self::$user['ID'];

        } else {
            // Проверяем доступ по кросс подразделениям и устанавливаем флаг
            $iCrossTransferFlag = false;
            foreach ($iCrossTransfer as $transferDep) {

                if( $transferDep && self::$user['BRANCH'][$transferDep]['DEPTH_LEVEL']  == 3
                    // Проверяем статус идеи, может быть только "На доработке", "В работе"
                    && ($iIdeaStatusID == self::getStatusIdByCode('REWORK') || $iIdeaStatusID == self::getStatusIdByCode('PROCESSING'))
                ) {
                    $iCrossTransferFlag = true;
                    break;
                }
            }

            // Проверяем доступ по подразделению (создателю и переданному)
            $bResult = (
                (!empty(self::$user['BRANCH'][$iDepartmentID]) && self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3)
                || (
                    // Проверяем подразделение в которое передали
                    $iTransferID && self::$user['BRANCH'][$iTransferID]['DEPTH_LEVEL']  == 3
                    // Проверяем статус идеи, может быть только "На доработке", "В работе"
                    && ($iIdeaStatusID == self::getStatusIdByCode('NEW') || $iIdeaStatusID == self::getStatusIdByCode('REWORK') || $iIdeaStatusID == self::getStatusIdByCode('PROCESSING'))
                )
                || (
                    // Проверяем подразделение(я) в которое передали кроссфункциональную идую
                $iCrossTransferFlag
                )
            );

            // Если это автор, то попутно проверяем принадлежность и статус
            $bResult = $sUserRole == 'AUTHOR'?($bResult && $iIdeaAuthorID == self::$user['ID'] && $iIdeaStatusID == self::getStatusIdByCode('NEW')):$bResult;
        }

        return $bResult;
    }


    /**
     * Проверяет мождет ли пользователь передавать идею
     * Если не указаны департаменты, статус,
     * то при необходимости будет выполнен запрос в базу для поиска идеи
     *
     * @param int $iIdeaID ID идеи
     * @param bool $bCheckStatus проверять статус идеи?
     * @param int $iDepartmentID ID департамента идеи
     * @param int $iTransferID департамент в который передали идею
     * @param int $iIdeaStatusID ID статуса идеи
     * @return bool
     */
    public static function canIdeaTransfer($iIdeaID, $bCheckStatus = true, $iDepartmentID = 0, $iTransferID = 0, $iIdeaStatusID = 0){
        // Проверяем минимальные права и данные
        if(!self::isUserRight('TRANSFER') || !filter_var($iIdeaID, FILTER_VALIDATE_INT) || $iIdeaID < 1)
            return false;


        /*
         * Запрос в базу для поиска инфы,
         * если она необходима
         */
        if(
            empty($iDepartmentID)
            || $iTransferID === 0
            || ($bCheckStatus && empty($iIdeaStatusID))
        ) {
            // Не используем self::getIdeasListWithData т.к. очень избыточно
            $obBlogPost = new CBlogPost;
            $arIdea = $obBlogPost->GetList(
                array(),
                array('ID' => $iIdeaID),
                false, false,
                array('ID', 'UF_AUTHOR_DEP', 'UF_TRANSFER_DEP', 'UF_STATUS')
            )->Fetch();
            $iTransferID   = $arIdea['UF_TRANSFER_DEP'];
            $iDepartmentID = $arIdea['UF_AUTHOR_DEP'];
            $iIdeaStatusID = $arIdea['UF_STATUS'];
        }

        // Проверяем статус, передавать можно только "На даработке"
        if($bCheckStatus && $iIdeaStatusID != self::getStatusIdByCode('REWORK'))
            return false;


        // Проверяем принадлежность
        return (
            self::getUserRole() == 'ADMIN'
            || self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3
            || self::$user['BRANCH'][$iTransferID]['DEPTH_LEVEL'] == 3
        );

    }


    /**
     * Проверяет мождет ли пользователь вернуть переданную идею
     * Если не указаны департаменты, статус,
     * то при необходимости будет выполнен запрос в базу для поиска идеи
     *
     * @param int $iIdeaID ID идеи
     * @param bool $bExtendedCheck расширенная ли проверка? (статус, передача)
     * @param int $iDepartmentID ID департамента идеи
     * @param int $iTransferID департамент в который передали идею
     * @param int $iIdeaStatusID ID статуса идеи
     * @return bool
     */
    public static function canIdeaCancelTransfer($iIdeaID, $bExtendedCheck = true, $iDepartmentID = 0, $iTransferID = 0, $iIdeaStatusID = 0){
        // Проверяем минимальные права и данные
        if(!self::isUserRight('TRANSFER') || ($iIdeaID !== 0 && (!filter_var($iIdeaID, FILTER_VALIDATE_INT) || $iIdeaID < 1)))
            return false;


        /*
         * Запрос в базу для поиска инфы,
         * если она необходима
         */
        if(
            empty($iDepartmentID)
            || ($bExtendedCheck && $iTransferID === 0)
            || ($bExtendedCheck && empty($iIdeaStatusID))
        ) {
            // Не используем self::getIdeasListWithData т.к. очень избыточно
            $obBlogPost = new CBlogPost;
            $arIdea = $obBlogPost->GetList(
                array(),
                array('ID' => $iIdeaID),
                false, false,
                array('ID', 'UF_AUTHOR_DEP', 'UF_TRANSFER_DEP', 'UF_STATUS')
            )->Fetch();
            $iTransferID   = $arIdea['UF_TRANSFER_DEP'];
            $iDepartmentID = $arIdea['UF_AUTHOR_DEP'];
            $iIdeaStatusID = $arIdea['UF_STATUS'];
        }


        // Проверяем, а идея вообще была ли передана?
        if($bExtendedCheck && !$iTransferID)
            return false;

        // Проверяем статус, возвращать можно только "На даработке"
        if($bExtendedCheck && $iIdeaStatusID != self::getStatusIdByCode('REWORK'))
            return false;


        // Проверяем принадлежность
        return (
            self::getUserRole() == 'ADMIN'
            || self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3
        );

    }


    /**
     * Проверяет мождет ли пользователь удалять идею
     * Если не указан департамент,
     * то при необходимости будет выполнен запрос в базу для поиска идеи
     *
     * @param int $iIdeaID ID идеи
     * @param int $iDepartmentID ID департамента идеи
     * @return bool
     */
    public static function canIdeaDelete($iIdeaID, $iDepartmentID = 0){
        if(self::isUserRole('ADMIN'))
            return true;
        else return false;
        /*
        if(!self::isUserRight('DELETE') || $iIdeaID != (string)intval($iIdeaID) || $iIdeaID < 1)
            return false;
        if(self::isUserRole('ADMIN'))
            return true;

        /*
         * Запрос в базу для поиска инфы,
         * если она необходима
         */
        /*
        if(empty($iDepartmentID)) {
            // Не используем self::getIdeasListWithData т.к. очень избыточно
            $obBlogPost = new CBlogPost;
            $arIdea = $obBlogPost->GetList(
                array(),
                array('ID' => $iIdeaID),
                false, false,
                array('ID', 'AUTHOR_ID', 'UF_AUTHOR_DEP')
            )->Fetch();
            $iDepartmentID = $arIdea['UF_AUTHOR_DEP'];
        }


        return !empty(self::$user['BRANCH'][$iDepartmentID]) && self::$user['BRANCH'][$iDepartmentID]['DEPTH_LEVEL'] == 3;
        */
    }


    /**
     * Проверяет мождет ли пользователь редактировать структуру
     *
     * @param int $iDepartmentID ID департамента в котором производится изменение
     * @return bool
     */
    public static function canStructureEdit($iDepartmentID = 0){
        if(!self::isUserRight('STRUCTURE'))
            return false;
        if(self::isUserRole('ADMIN') || !$iDepartmentID)
            return true;

        return !empty(self::$user['BRANCH'][$iDepartmentID]);
    }


    /**
     * Имеет ли пользователь доступ к указанному департаменту?
     *
     * Пользователь имеет доступ к департаменту в случае, если
     * он принадлежит указанному департаменту, или же указанный
     * департамент находится в подчинении к тому департаменту,
     * к которому принадлежит пользователь.
     *
     * @param int $iDepartmentID ID департамента для проверки
     * @return bool
     */
    public static function isUserHaveAccessToDepartment($iDepartmentID) {
        if(empty(self::$user))
            self::userInit();

        return self::isUserRole('ADMIN') || self::isUserRole('SPECTATOR') || array_key_exists($iDepartmentID, self::$user['BRANCH']);
    }

    /**
     * Проверяет мождет ли пользователь выгружать графики
     *
     * @param int $iDepartmentID ID департамента для которого выгружаются графики
     * @param string $sGraphCode
     * @return bool
     */
    public static function canGetGraph($iDepartmentID = 0, $sGraphCode = '') {
        if(empty($iDepartmentID) && empty($sReportCode))
            return self::isUserRight('GRAPHS');

        return CGraphs::checkPermission(self::getUserRole(), $iDepartmentID, $sGraphCode);
    }

    /**
     * Проверяет мождет ли пользователь выгружать отчеты
     *
     * @param int $iDepartmentID ID департамента для которого выгружаются отчеты (заглушка)
     * @param string $sReportCode
     * @return bool
     */
    public static function canGetReport($iDepartmentID = 0, $sReportCode = '') {
        if(empty($iDepartmentID) && empty($sReportCode))
            return self::isUserRight('REPORTS');

        return CReports::checkPermission(self::getUserRole(), $iDepartmentID, $sReportCode);
    }






    /**
     * Получить данные сотрудника по поданным идеям (статистика)
     * @param int $iUserID
     * @return array
     */
    public static function getUserIdeasStatistic($iUserID = 0){
        if(empty($iUserID))
            $iUserID = $GLOBALS['USER']->GetID();

        $arResult     = array(
            'TOTAL'     => 0,
            'REWARD'    => 0,
            'DRAFT'     => 0,
            'NEW'       => 0,
            'FAILED'    => 0,
            'REWORK'    => 0,
            'PROCESSING'=> 0,
            'COMPLETED' => 0,
            'FINISH'    => 0,
        );
        $arFinedIdeas = array();
        $obCBlogPost  = new CBlogPost();
        $arSelect     = array(
            'BLOG_ID',
            'ID',
            'TITLE',
            'DATE_CREATE',
            'UF_*',
        );


        // Выполняем поиск
        $rsResult = $obCBlogPost->GetList(
            array(),
            array(
                'BLOG_ID' => self::getIdeaBlogId(),
                'UF_EMPLOYEE_USER' => $iUserID,
            ),
            false,
            false,
            $arSelect
        );
        while($arIdea = $rsResult->GetNext()) {
            // Защита от двойной обработки одной идеи при множественных полях
            // (вроде встречалось при поиске по автору, а может путаю с другим =) )
            if($arFinedIdeas[$arIdea['ID']]++)
                continue;

            if(!$arIdea['UF_STATUS'])
                $sStatus = 'NEW';
            else
                $sStatus = CIdeaTools::getStatusCodeById($arIdea['UF_STATUS']);

            // Приводим к единому виду значения статусов
            if($sStatus == 'ACCEPT')
                $sStatus = 'NEW';

            $arResult['TOTAL']++;
            $arResult[$sStatus]++;
            $arResult['REWARD'] += $arIdea['UF_IDEA_PRICE']?:0;
        }

        // Округляем сумму до целых чисел
        $arResult['REWARD'] = floor($arResult['REWARD']);

        return $arResult;
    }


    /**
     * Получить данный сотрудника по пользователю Битрикс
     * @param int $iUserID
     * @return array|bool
     */
    public static function getUserEmployeeData($iUserID = 0){
        if(empty($iUserID))
            $iUserID = $GLOBALS['USER']->GetID();

        if(empty($iUserID))
            return false;

        if(!$arData = self::getEmployeeData(array('BITRIX_USER_ID' => $iUserID))->Fetch())
            return false;

        return array(
            'FINED'         => 1,
            'ROW_ID'        => $arData['ID'],
            'BOSS_ID'       => $arData['BOSS_ID'],
            'COMPANY_ID'    => $arData['COMPANY_ID'],
            'CODE'          => $arData['PERSONNEL_NUMBER']?:'-'.$arData['ID'],
            'USER'          => $iUserID,
            'BIRTHDAY'      => $arData['BIRTHDAY']?:'',
            'PHONE'         => $arData['PHONE']?:'',
            'LAST_NAME'     => trim(self::mb_ucfirst(self::mb_strtolower($arData['LAST_NAME']))),
            'NAME'          => trim(self::mb_ucfirst(self::mb_strtolower($arData['NAME']))),
            'SECOND_NAME'   => trim(self::mb_ucfirst(self::mb_strtolower($arData['SECOND_NAME']))),
            'INITIALS'      => self::getInitialsByName($arData['NAME'], $arData['SECOND_NAME']),
        );
    }


    /**
     * Поиск данных сотрудника по известной информации
     *
     * Логика поиска:
     * - Ищем по Босс ID + инициалы
     * (off)- Ищем по порядковому номеру в таблице + ФИО
     * - Ищем по табелю, если меньше 8 символов, то еще и по фамилии и инициалам, если возможно
     * - Ищем по ФИО + ID компании
     *
     * @param array $arEmployeeData Массив с известной информацией об сотруднике
     * @param bool $bGreedy
     * @return array
     */
    public static function findEmployeeData($arEmployeeData, $bGreedy = false){
        $arResult = array('DATA' => array(), 'COUNT' => 0, 'TYPE' => '');
        $iGreedyLength = 2;

        // Переменные
        $iRowID     = filter_var($arEmployeeData['ROW_ID'], FILTER_VALIDATE_INT)?:0;
        $sBossID    = $arEmployeeData['BOSS_ID']?:'';
        $mCompanyID = filter_var($arEmployeeData['COMPANY_ID'], FILTER_VALIDATE_INT)?:0;
        $iCode      = preg_replace('/[^\d\-]/', '', $arEmployeeData['CODE'])?:0;
        $sLastName  = $arEmployeeData['LAST_NAME']?:'';
        $sName      = $arEmployeeData['NAME']?:'';
        $sSecondName = $arEmployeeData['SECOND_NAME']?:'';


        /*
         * Проверка табеля
         */
        if(strpos($iCode, '-', 1))
            $iCode = 0;

        // Проверяем табель, если он меньше 0, то это скорей всего ID
        if($iCode < 0) {
            $iRowID = $iRowID?:abs($iCode);
            $iCode = 0;
        }


        /*
         * Компания ввиде массива
         */
        if(!empty($arEmployeeData['COMPANY_ID']) && is_array($arEmployeeData['COMPANY_ID']))
            $mCompanyID = array_diff(
                array_map(
                    function($v){return filter_var($v, FILTER_VALIDATE_INT)?:0;},
                    $arEmployeeData['COMPANY_ID'])
                , array(0)
            )?:0;


        /*
         * ФИО
         */
        $arEmployeeData['FIO'] = trim($arEmployeeData['FIO']?:'');
        if(!empty($arEmployeeData['FIO'])) {
            $arNameData = self::getNameDataByString($arEmployeeData['FIO']);
            // Фамилия
            if(empty($sLastName))
                $sLastName = $arNameData['LAST_NAME'];
            // Имя
            if(empty($sName))
                $sName = $arNameData['NAME'];
            // Отчество
            if(empty($sSecondName))
                $sSecondName = $arNameData['SECOND_NAME'];

            unset($arNameData);
        }


        /**
         * Пробуем найти
         */
        do {
            /*
             * По BOSS_ID и инициалам
             */
            if(!empty($sBossID) && (!empty($sName) || !empty($sSecondName))) {
                $arFilter = array('BOSS_ID' => $sBossID);
                if(!empty($sName))
                    $arFilter['NAME%'] = $sName;
                if(!empty($sSecondName))
                    $arFilter['SECOND_NAME%'] = $sSecondName;

                // Поиск
                if($rsResult = self::getEmployeeData($arFilter, 10)) {
                    while($arRow = $rsResult->Fetch())
                        $arResult['DATA'][] = $arRow;
                    if(!empty($arResult['DATA'])) {
                        $arResult['TYPE'] = 'BOSS_ID';
                        break;
                    }

                    // TODO Greedy
                }
            }

            /*
             * По ID, фамилии и инициалам
             * (отключили, т.к. поменяется если поменяется босс ID)
             */
            if(0 && !empty($iRowID) && !empty($sLastName) && (!empty($sName) || !empty($sSecondName))) {
                $arFilter = array('ID' => $iRowID, 'LAST_NAME' => $sLastName);
                if(!empty($sName))
                    $arFilter['NAME%'] = $sName;
                if(!empty($sSecondName))
                    $arFilter['SECOND_NAME%'] = $sSecondName;

                // Поиск
                if($rsResult = self::getEmployeeData($arFilter, 10)) {
                    while($arRow = $rsResult->Fetch())
                        $arResult['DATA'][] = $arRow;
                    if(!empty($arResult['DATA'])) {
                        $arResult['TYPE'] = 'ROW_ID';
                        break;
                    }

                    // TODO Greedy
                }
            }

            /*
             * По табелю, если меньше 8 символов, то еще и по фамилии и инициалам
             */
            if(!empty($iCode)) {
                do {
                    $arFilter = array('PERSONNEL_NUMBER' => $iCode);
                    if(self::mb_strlen($iCode) < 8) {
                        if(empty($sLastName))
                            break;

                        $arFilter['LAST_NAME'] = $sLastName;
                        if(!empty($sName))
                            $arFilter['NAME%'] = $sName;
                        if(!empty($sSecondName))
                            $arFilter['SECOND_NAME%'] = $sSecondName;

                    }

                    // Поиск
                    if($rsResult = self::getEmployeeData($arFilter, 10)) {
                        while($arRow = $rsResult->Fetch())
                            $arResult['DATA'][] = $arRow;
                        if(!empty($arResult['DATA'])) {
                            $arResult['TYPE'] = 'CODE';
                            break(2);
                        }
                    }

                    /*
                     * Попытка поиска по табельному номеру с опечатками в ФИО
                     */
                    if($bGreedy && self::mb_strlen($iCode) < 8) {
                        $arFilter = array('PERSONNEL_NUMBER' => $iCode);
                        if($rsResult = self::getEmployeeData($arFilter, 100)) {
                            while($arRow = $rsResult->Fetch()) {
                                // Проверяем фамилию
                                if(levenshtein($sLastName, $arRow['LAST_NAME']) > $iGreedyLength) {
                                    // Пробуем удалить повторяющиеся символы и проверить еще раз
                                    $sLastName = preg_replace('/(.)\1+/u', '$1', $sLastName);
                                    if(levenshtein($sLastName, $arRow['LAST_NAME']) > $iGreedyLength)
                                        continue;
                                }

                                // Проверяем имя
                                if(!(
                                    (self::mb_strlen($sName) == 1 && $sName == self::mb_substr($arRow['NAME'], 0,1))
                                    || levenshtein($sName, $arRow['NAME']) <= $iGreedyLength
                                ))
                                    continue;

                                // Проверяем отчество
                                if(!empty($sSecondName) && !empty($arRow['SECOND_NAME'])) {
                                    if(!(
                                        (self::mb_strlen($sSecondName) == 1 && $sSecondName == self::mb_substr($arRow['SECOND_NAME'], 0,1))
                                        || levenshtein($sSecondName, $arRow['SECOND_NAME']) <= $iGreedyLength
                                    ))
                                        continue;
                                }

                                $arResult['DATA'][] = $arRow;
                            }
                            if(!empty($arResult['DATA'])) {
                                $arResult['TYPE'] = 'CODE_GREEDY';
                                break(2);
                            };
                        }
                    }

                    /*
                     * Попытка поиска по табельному номеру и инициалам + ID компании (для тех, кто сменил фамилию (замужних))
                     */
                    if($bGreedy && !empty($sName) && !empty($sSecondName) && !empty($mCompanyID)) {
                        $arFilter = array(
                            'PERSONNEL_NUMBER' => $iCode,
                            'COMPANY_ID'    => $mCompanyID,
                            'NAME%'         => $sName,
                            'SECOND_NAME%'  => $sSecondName,
                        );

                        if($rsResult = self::getEmployeeData($arFilter, 10)) {
                            while($arRow = $rsResult->Fetch())
                                $arResult['DATA'][] = $arRow;
                            if(!empty($arResult['DATA'])) {
                                $arResult['TYPE'] = 'CODE_GREEDY_LAST_NAME';
                                break(2);
                            };
                        }
                    }

                } while(0);
            }


            /*
             * Поиск по ФИО + ID компании
             */
            if($bGreedy && !empty($sLastName) && !empty($mCompanyID) && (!empty($sName) || !empty($sSecondName))) {
                $arFilter = array(
                    'COMPANY_ID' => $mCompanyID,
                    'LAST_NAME'  => $sLastName,
                );
                if(!empty($sName))
                    $arFilter['NAME%'] = $sName;
                if(!empty($sSecondName))
                    $arFilter['SECOND_NAME%'] = $sSecondName;

                if($rsResult = self::getEmployeeData($arFilter, 10)) {
                    while($arRow = $rsResult->Fetch())
                        $arResult['DATA'][] = $arRow;
                    if(!empty($arResult['DATA'])) {
                        $arResult['TYPE'] = 'FIO_COMPANY_GREEDY';
                        break;
                    };
                }
            }

        } while(0);

        $arResult['COUNT'] = count($arResult['DATA']);
        return $arResult;
    }

    /**
     * Получает даныне по сотруднику из таблицы BOSS
     *
     * @param $arSearchRule
     * @param int $iLimit
     * @param array $arOrder
     *
     * @return bool|object
     */
    public static function getEmployeeData($arSearchRule, $iLimit = 1, $arOrder = array('LAST_NAME' => 'ASC', 'NAME' => 'ASC')){

        $arFieldsTypes = array(
            'NAME'              => 'string',
            'PHONE'             => 'string',
            'COMPANY'           => 'string',
            'LAST_NAME'         => 'string',
            'SECOND_NAME'       => 'string',
            'BOSS_ID'           => 'string',
            'PHOTO_URL'         => 'string',
            'ID'                => 'int',
            'COMPANY_ID'        => 'int',
            'EMPLOYEE_ID'       => 'int',
            'BITRIX_USER_ID'    => 'int',
            'PERSONNEL_NUMBER'  => 'int',
            'BIRTHDAY'          => 'date',
        );
        $arSelect = array(
            'ID',
            'BOSS_ID',
            'EMPLOYEE_ID',
            'PERSONNEL_NUMBER',
            'LAST_NAME',
            'NAME',
            'SECOND_NAME',
            'BIRTHDAY',
            'COMPANY',
            'COMPANY_ID',
            'PHONE',
            'PHOTO_URL',
            'BITRIX_USER_ID',
        );
        $fnSearchRuleParse = function($arRules) use (&$fnSearchRuleParse, $arFieldsTypes) {
            $arResult = array();

            foreach($arRules AS $mField => $mValue) {
                /*
                 * Проверяем, если $mField цифры и $mValue массив, то это сложная логика
                 */
                if(filter_var($mField, FILTER_VALIDATE_INT) !== false) {
                    if(empty($mValue['LOGIC']))
                        continue;

                    $arLogicResult = array();
                    foreach($mValue AS $k => $arLogicRules) {
                        if($k == 'LOGIC')
                            continue;

                        $arLogicResult[] = $fnSearchRuleParse($arLogicRules);
                    }

                    $arResult[] = '('. join(' '.$mValue['LOGIC'].' ', $arLogicResult) .')';
                    continue;
                }



                /*
                 * Обычное правило для поиска
                 */
                // Подготавливаем значение
                if(is_array($mValue))
                    $mValue = array_map(function($v){return '\''. $GLOBALS['DB']->ForSql($v) .'\'';}, $mValue);
                else
                    $mValue = $GLOBALS['DB']->ForSql($mValue);
                $sFieldClear = str_replace('%', '', $mField);



                /**
                 * Проверка значений
                 */
                if(empty($arFieldsTypes[$sFieldClear]))
                    continue;

                // Пока только инт
                // Убивает поиск по 0 - можно переделать на === 0
                if($arFieldsTypes[$sFieldClear] == 'int') {

                    if(is_array($mValue)) {
                        $mField = $sFieldClear;
                        $mValue = array_map(function($v){return filter_var(trim($v, '\''), FILTER_VALIDATE_INT)?:-1;}, $mValue);

                    } else if(!filter_var($mValue, FILTER_VALIDATE_INT)) {
                        $mField = $sFieldClear;
                        $mValue = -1;
                    }
                }

                /*
                 * Определяем зону поиска
                 */
                // IN
                if(is_array($mValue)) {
                    $arResult[] = "{$sFieldClear} IN (". join(', ', $mValue) .")";
                }
                // LIKE?
                elseif(strpos($mField, '%') !== false) {
                    if($mField[0] == '%')
                        $mValue = '%'.$mValue;
                    if($mField[strlen($mField)-1] == '%')
                        $mValue .= '%';
                    $arResult[] = "{$sFieldClear} LIKE '{$mValue}'";
                }
                // =
                else {
                    $arResult[] = "{$sFieldClear} = '{$mValue}'";
                }

            }

            // Возвращаем результат
            return $arResult?'('. join(' AND ', $arResult) .')':'';
        };

        $sWhere = $fnSearchRuleParse($arSearchRule);

        if(!$sWhere)
            return false;

        // Запрос к БД
        return self::getDataFromBoss($arSelect, $sWhere, $iLimit, $arOrder);
    }

    /**
     * Запрос в БД к таблице BOSS
     * (useraddressbook)
     *
     * @param string $arSelect
     * @param string $sWhere
     * @param int $iLimit
     * @param array $arOrder
     *
     * @return bool|object
     */
    public static function getDataFromBoss($arSelect, $sWhere = '', $iLimit = 10, $arOrder = array('LAST_NAME' => 'ASC', 'NAME' => 'ASC')) {
        if(empty($arSelect))
            return false;

        // Базовый запрос
        $sSQL = 'SELECT '.join(', ', $arSelect).' FROM '.SDQC::tName('USERADDRESSBOOK').''
            .($sWhere?' WHERE '.$sWhere:'')
            .($arOrder?' ORDER BY '.join(', ', array_map(function($f, $t){return "$f $t";}, array_keys($arOrder), $arOrder)):'')
            .($iLimit?SDQC::limOffset($iLimit, 0):'');

        return $GLOBALS['DB']->Query($sSQL);
    }

    /*
     * Конец методов для проверки доступа
     * ===========================================================================
     */



} // class

if(!function_exists('pre')) {
    function pre($expression, $_expression = null) {
        global $USER;
        $arArgs = func_get_args();

        if(count($arArgs) > 1 && ($arArgs[0] === 'toAll' || $arArgs[0] === 'all'))
            array_shift($arArgs);
        elseif(!$USER->IsAdmin())
            return;

        $trace = debug_backtrace();

        echo '<br><b>Debug:</b> '. $trace[0]['file'] .' ('. $trace[0]['line'] .')';
        foreach($arArgs AS $arg) {
            echo '<pre>';
            var_dump($arg);
            echo '</pre>';
        }
        echo '<b>End:</b> @-------------------------@<br>';
    }
}


if(!function_exists('printToFile')) {
    // Запись в файл лога
    function printToFile($sString){
        if(!$rs = fopen($_SERVER['DOCUMENT_ROOT']. CIdeaConst::C_AGENT_FILE_SAVE_PATH .'printToFile.txt', 'a'))
            die('fileopen error');
        if(!fwrite($rs, $sString."\r\n"))
            die('fwrite error');
        fclose($rs);
        echo "{$sString}<br>";
    }
}