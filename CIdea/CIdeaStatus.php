<?php
require_once(__DIR__.'/CIdeaConst.php');

class CIdeaStatus {
    protected $el;
    protected $cIT;
    public $cache = [];

    function __construct()
    {
        $this->el = new CIBlockElement();
        $this->cIT = new CIdeaTools();
    }

    /**
     * Возвращает массив статусов идеи
     * @param $id
     * @param bool $date
     * @return array
     */
    function getStatus( $id, $sort=["ACTIVE_FROM" => "ASC"]) {
        $rs = $this->el->GetList(
            $sort,
            [
                "CODE" => $id
            ],
            false, false,
            $arSelect = [
                'DATE_CREATE',      // #12.10.2018 00:00:00
                'DATE_CREATE_UNIX', // 1539291600
                'SORT',             // Код статуса
                'ID',
                'NAME'
            ]
        );

        $res = [];
        while($ob = $rs->GetNextElement()){
            $arFields = $ob->GetFields();
            //debugmessage($arFields);
            if($arFields['SORT']<70){
                $arFields['STATUS'] = $this->cIT->getStatusCodeById($arFields['SORT']);
                //debugmessage($arFields);
                $res[] = $arFields;
                //$arProps = $ob->GetProperties();
                //debugmessage($arFields['STATUS']);
                //echo "<hr>";
            }
        }

        $this->cache[$id] = $res;
        return $res;
    }

    /**
     * Вычисляет срок нахождения идеи в актуальном статусе до определенной даты($dateTo)
     * @param $id - ид идеи
     * @param $dateTo - на какую дату вычисляем период(в актуальном статусе на дату) # "текущая дата" | "14.10.2018" | "12.10.2018 00:00:00"
     * @param bool|string|array $statusL - строка или массив статусов(кодов)
     * @return bool|int
     */
    function getPeriod ($id, $dateTo=false, $statusL = false) {
        $statusArr = $this->getStatus($id, $sort=["ACTIVE_FROM" => "DESC"]);

        if($dateTo===false){
            $dateTo = MakeTimeStamp(date("d.m.Y H:i:s"));
        }
        // Переводим дату в unix stamp
        if($dateTo != intval($dateTo))
            $dateTo = MakeTimeStamp(array_shift(explode(' ', $dateTo)) .' 23:59:59');

        // Если статус не массив, то преобразуем
        if($statusL!==false && !is_array($statusL)) {
            $statusL = [$statusL];
        }

        foreach ($statusArr as $key => $status) {
            if($status['DATE_CREATE_UNIX'] < $dateTo) {
                if( $statusL!==false && !in_array($status['STATUS'], $statusL) ) {
                    return 0; //Идея не находится в нужных статусах
                }
                /*echo $status['DATE_CREATE'];
                $d1 = new DateTime($dateFrom);
                $d2 = new DateTime($status['DATE_CREATE']);
                $interval = $d1->diff($d2);
                debugmessage($interval->format('%a'));*/

                $days = ceil(( $dateTo - $status['DATE_CREATE_UNIX'] ) / (3600*24));
                return $days;
            }
            else {
                echo "<br>". $status['DATE_CREATE'] . " - не подходит";
            }
        }

        return false;
    }




    /**
     * Метод получает статусы для ID Идеи
     * @param $id ID Идеи
     * @param mixed $date Дата на которую нужно найти самую актуальную инфу по статусу
     *                      Строка - d.m.Y
     *                      Число - unix stamp
     *                      Массив - содержит ключи FROM и TO, в формате d.m.Y или unix stamp
     * @param bool $nStatusID ID статуса который искать, если не задан то возвращает самый актуальный
     * @return array|bool массив со статусами идей | false при ошибке
     */
    function getStatusByDate($id, $date, $nStatusID = false) {
        $statuses = $this->getStatus($id);

        if(!empty($date) && (!is_array($date) || (is_array($date) && !empty($date['FROM']) && !empty($date['TO'])))) {
            if(is_array($date)) {
                $nFrom = intval($date['FROM']) == $date['FROM']? $date['FROM'] : MakeTimeStamp(array_shift(explode(' ', $date['FROM'])));
                $nTo = intval($date['TO']) == $date['TO']? $date['TO'] : MakeTimeStamp(array_shift(explode(' ', $date['TO'])) .' 23:59:59');

                foreach($statuses AS $k => $arStatus) {
                    if($arStatus['DATE_CREATE_UNIX'] < $nFrom || $arStatus['DATE_CREATE_UNIX'] > $nTo)
                        unset($statuses[$k]);
                }
                return $statuses;
            } else {
                // Переводим дату в unix stamp
                if($date != intval($date))
                    $date = MakeTimeStamp(array_shift(explode(' ', $date)) .' 23:59:59');

                $arParse = $statuses;
                $arResult = false;

                // Проходимся по статусам в поисках самого актуального
                foreach($arParse AS $arStatus) {
                    if(
                        $arStatus['DATE_CREATE_UNIX'] < $date
                        && (!$arResult || $arResult['DATE_CREATE_UNIX'] <= $arStatus['DATE_CREATE_UNIX'])
                        && (!$nStatusID || $nStatusID == $arStatus['CODE'])
                    ) {
                        $arResult = $arStatus;
                    }
                }
                return $arResult;
            }
        }

        return false;
    }

    /**
     * @param $id
     * @param $date
     * @param $acceptDate UF_DATE_ACCEPT
     * @return int
     */
    function getRealiseDuration ($id, $date, $acceptDate, $closeDate=false) {
        $statuses= $this->getStatusByDate($id, $date);
        debugmessage($statuses);
        $result = false;
        if($statuses!==false) {
            $arFinedIdea = end($statuses);
            // Проходимся по статусу
            switch ($arFinedIdea/* статус на конкретную  дату | ююю */) {
                // В работе, Принята (несуществующий, для надежности)
                case 'ACCEPT':
                case 'PROCESSING':
                    if (!empty($acceptDate)) {
                        // Парсим дату принятия в timestamp
                        $acceptDate = explode('.', $acceptDate);
                        $acceptDate = mktime(0, 0, 0, $acceptDate[1], $acceptDate[0], $acceptDate[2]);

                        // Парсим дату
                        $date = $this->convertDateToTimestamp($date);
                        // Расчитываем количество дней прошедших с даты подачи
                        $result/*$arFinedIdea['REALISE_DURATION']*/ = ceil(($date - $acceptDate) / (24 * 60 * 60));
                    }

                    break;

                // Выполнено, Реализовано
                case 'COMPLETED':
                case 'FINISH':
                    if($closeDate) {
                        $closeDate = $this->convertDateToTimestamp($closeDate);
                        debugmessage($closeDate);
                        if($closeDate > $date) {
                            $result = ceil(($date - $acceptDate) / (24 * 60 * 60));
                        } else {
                            $result = ceil(($closeDate - $acceptDate) / (24 * 60 * 60));
                        }
                    }
                    break;
            }
        }

        return $result;
    }

    function convertDateToTimestamp ($date) {
        // Парсим дату
        $date = explode(' ', $date);
        if(count($date)>1) { //d.m.Y 23:59:59
            return MakeTimeStamp(implode(" ", $date));
        } else {// d.m.Y
            $date = explode('.', $date[0]);
            return mktime(0, 0, 0, $date[1], $date[0], $date[2]);
        }
    }

}